<?php

namespace Boralp\Pixelite\Jobs;

use Boralp\Pixelite\Models\ClickId;
use Boralp\Pixelite\Models\Geo;
use Boralp\Pixelite\Models\Referer;
use Boralp\Pixelite\Models\Screen;
use Boralp\Pixelite\Models\UserAgent;
use Boralp\Pixelite\Models\UtmParam;
use Boralp\Pixelite\Models\Visit;
use Boralp\Pixelite\Models\VisitRaw;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

class ProcessVisitRaw implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $maxExceptions = 3;

    public int $batchSize;

    private ?Reader $geoReader = null;

    private array $hashCache = [];

    public function __construct(int $batchSize = 500)
    {
        $this->batchSize = max(1, min($batchSize, 1000)); // Clamp between 1-1000
    }

    public function handle(): void
    {
        if (! $this->hasUnprocessedRecords()) {
            return;
        }

        try {
            $this->initializeGeoReader();
            $this->processBatch();
        } catch (Exception $e) {
            Log::error('ProcessVisitRaw job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    private function hasUnprocessedRecords(): bool
    {
        return VisitRaw::exists();
    }

    private function initializeGeoReader(): void
    {
        $geoDbPath = storage_path('app/GeoLite2-City.mmdb');

        if (! file_exists($geoDbPath)) {
            Log::warning('GeoLite2 database not found', ['path' => $geoDbPath]);

            return;
        }

        try {
            $this->geoReader = new Reader($geoDbPath);
        } catch (Exception $e) {
            Log::error('Failed to initialize GeoIP reader', ['error' => $e->getMessage()]);
        }
    }

    private function processBatch(): void
    {
        $rawVisits = $this->fetchBatch();

        if ($rawVisits->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($rawVisits) {
            $processedIds = [];

            foreach ($rawVisits as $raw) {
                try {
                    $this->processVisit($raw);
                    $processedIds[] = $raw->id;
                } catch (Exception $e) {
                    Log::error('Failed to process visit', [
                        'visit_id' => $raw->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue processing other records
                }
            }

            // Bulk delete processed records
            if (! empty($processedIds)) {
                VisitRaw::whereIn('id', $processedIds)->delete();
            }
        });
    }

    private function fetchBatch()
    {
        return VisitRaw::query()
            ->select(['id', 'user_id', 'session_id', 'route_name', 'route_params', 'ip', 'user_agent', 'payload', 'payload_js', 'total_time', 'created_at'])
            ->orderBy('id')
            ->limit($this->batchSize)
            ->get();
    }

    private function processVisit(VisitRaw $raw): void
    {
        $payload = $raw->payload ?? [];
        $payload_js = $raw->payload_js ?? [];

        // Process all normalized data
        $geoData = $this->processGeo($raw->ip);
        $userAgentData = $this->processUserAgent($raw->user_agent);
        $refererData = $this->processReferer($payload['referer'] ?? null);
        $utmId = $this->processUtm($payload['utm'] ?? null);
        $clickId = $this->processClickIds($payload);
        $screenId = $this->processScreen($payload_js['screen'] ?? null);

        // Create visit record
        Visit::create([
            'user_id' => $raw->user_id,
            'session_id' => $raw->session_id,
            'route_name' => $raw->route_name,
            'route_params' => $raw->route_params,
            'ip' => $raw->ip,
            'geo_id' => $geoData['id'] ?? null,
            'user_agent_id' => $userAgentData['id'] ?? null,
            'referer_id' => $refererData['id'] ?? null,
            'referrer_domain' => $refererData['domain'] ?? null,    // From processReferer
            'device_category' => $userAgentData['device_category'] ?? false,    // From processUserAgent
            'country_code' => $geoData['country_code'] ?? null,     // From processGeo
            'utm_id' => $utmId,
            'click_id' => $clickId,
            'screen_id' => $screenId,
            'timezone' => $payload_js['timezone'] ?? null,
            'locale' => $payload['locale'] ?? null,
            'payload' => $payload,
            'payload_js' => $payload_js,
            'left_at' => $raw->left_at,
            'total_time' => $raw->total_time,
            'created_at' => $raw->created_at,
        ]);
    }

    private function processGeo(?string $ip): array
    {
        if (! $ip || ! $this->geoReader) {
            return ['id' => null, 'country_code' => null];
        }

        try {
            // Convert binary IP to string for MaxMind
            $ipString = inet_ntop($ip);
            if ($ipString === false) {
                return ['id' => null, 'country_code' => null];
            }

            $record = $this->geoReader->get($ipString);
            if (! $record) {
                return ['id' => null, 'country_code' => null];
            }

            $geoData = [
                'country_code' => $record['country']['iso_code'] ?? null,
                'region' => $record['subdivisions'][0]['names']['en'] ?? null,
                'city' => $record['city']['names']['en'] ?? null,
                'postal_code' => $record['postal']['code'] ?? null,
                'latitude' => isset($record['location']['latitude']) ?
                    round((float) $record['location']['latitude'], 8) : null,
                'longitude' => isset($record['location']['longitude']) ?
                    round((float) $record['location']['longitude'], 8) : null,
            ];

            $geoHash = $this->generateHash([
                $geoData['country_code'],
                $geoData['region'],
                $geoData['city'],
                $geoData['postal_code'],
                $geoData['latitude'],
                $geoData['longitude'],
            ]);

            $geoId = $this->getOrCreateRecord(Geo::class, $geoHash, $geoData);

            return ['id' => $geoId, 'country_code' => $countryCode];

        } catch (Exception $e) {
            Log::warning('GeoIP lookup failed', [
                'ip' => $ipString ?? 'conversion_failed',
                'error' => $e->getMessage(),
            ]);

            return ['id' => null, 'country_code' => null];
        }
    }

    private function processUserAgent(?string $userAgent): array
    {
        if (empty($userAgent)) {
            return [
                'id' => null,
                'device_category' => null,
                'os_name' => null,
            ];
        }

        // Detect mobile
        $device_category = $this->detectDevice($userAgent);
        $os_name = $this->detectOS($userAgent);

        // Truncate if too long
        $userAgent = mb_substr($userAgent, 0, 1000);
        $hash = $this->generateHash([$userAgent]);

        $userAgentId = $this->getOrCreateRecord(UserAgent::class, $hash, [
            'raw' => $userAgent,
            'device_category' => $device_category,
            'os_name' => $os_name,
            'browser_name' => $this->detectBrowser($userAgent),
        ]);

        return [
            'id' => $userAgentId,
            'device_category' => $device_category,
            'os_name' => $os_name,
        ];
    }

    private function detectBrowser(?string $userAgent): ?string
    {
        if (empty($userAgent)) {
            return null;
        }

        // Order matters - check more specific patterns first
        $browsers = [
            // Microsoft Edge (check before Chrome as it contains "Chrome")
            '/Edg\//i' => 'Edge',
            '/Edge\//i' => 'Edge Legacy',

            // Opera (check before Chrome as newer versions contain "Chrome")
            '/OPR\//i' => 'Opera',
            '/Opera/i' => 'Opera',

            // Chrome-based browsers (check before Chrome)
            '/Brave\//i' => 'Brave',
            '/Vivaldi/i' => 'Vivaldi',
            '/YaBrowser/i' => 'Yandex Browser',
            '/SamsungBrowser/i' => 'Samsung Browser',

            // Chrome (check after Chrome-based browsers)
            '/Chrome/i' => 'Chrome',
            '/CriOS/i' => 'Chrome', // Chrome on iOS

            // Firefox
            '/Firefox/i' => 'Firefox',
            '/FxiOS/i' => 'Firefox', // Firefox on iOS

            // Safari (check after other WebKit browsers)
            '/Safari/i' => 'Safari',

            // Internet Explorer
            '/MSIE/i' => 'Internet Explorer',
            '/Trident/i' => 'Internet Explorer',

            // Mobile browsers
            '/UCBrowser/i' => 'UC Browser',
            '/MiuiBrowser/i' => 'MIUI Browser',
            '/DuckDuckGo/i' => 'DuckDuckGo Browser',

            // Bots and crawlers
            '/Googlebot/i' => 'Googlebot',
            '/Bingbot/i' => 'Bingbot',
            '/facebookexternalhit/i' => 'Facebook Bot',
            '/Twitterbot/i' => 'Twitter Bot',
            '/LinkedInBot/i' => 'LinkedIn Bot',
            '/WhatsApp/i' => 'WhatsApp',
            '/bot/i' => 'Bot',
            '/crawl/i' => 'Crawler',
        ];

        foreach ($browsers as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    private function detectOS(?string $userAgent): ?string
    {
        if (empty($userAgent)) {
            return null;
        }

        $operatingSystems = [
            // Windows (simplified)
            '/Windows NT/i' => 'Windows',
            '/Windows/i' => 'Windows',

            // macOS
            '/Mac OS X/i' => 'macOS',
            '/Macintosh/i' => 'macOS',

            // iOS
            '/iPhone OS/i' => 'iOS',
            '/OS.*like Mac OS X/i' => 'iOS', // iPad/iPhone
            '/iPad/i' => 'iOS',

            // Android
            '/Android/i' => 'Android',

            // Linux
            '/Linux/i' => 'Linux',
            '/X11/i' => 'Linux',

            // Other mobile OS
            '/BlackBerry/i' => 'BlackBerry',
            '/Windows Phone/i' => 'Windows Phone',
            '/Windows Mobile/i' => 'Windows Mobile',
            '/webOS/i' => 'webOS',
            '/Palm/i' => 'Palm OS',
            '/Symbian/i' => 'Symbian',

            // Gaming consoles
            '/PlayStation/i' => 'PlayStation',
            '/Xbox/i' => 'Xbox',
            '/Nintendo/i' => 'Nintendo',

            // Smart TV OS
            '/Tizen/i' => 'Tizen',
            '/Smart-TV/i' => 'Smart TV',

            // Unix-like
            '/FreeBSD/i' => 'FreeBSD',
            '/SunOS/i' => 'Solaris',
        ];

        foreach ($operatingSystems as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    private function detectDevice(?string $userAgent): ?string
    {
        if (empty($userAgent)) {
            return null;
        }

        // Bot patterns - check first as bots can mimic other devices
        $botPatterns = [
            '/bot/i',
            '/crawl/i',
            '/slurp/i',
            '/spider/i',
            '/mediapartners/i',
            '/facebookexternalhit/i',
            '/WhatsApp/i',
            '/Googlebot/i',
            '/Bingbot/i',
            '/YandexBot/i',
            '/Applebot/i',
            '/LinkedInBot/i',
            '/Twitterbot/i',
        ];

        foreach ($botPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return 'bot';
            }
        }

        // Tablet patterns - check before mobile since tablets can contain "Mobile"
        $tabletPatterns = [
            '/iPad/i',
            '/Tablet/i',
            '/Nexus 7/i',
            '/Nexus 9/i',
            '/Nexus 10/i',
            '/KFAPWI/i', // Kindle Fire
            '/KFTT/i',   // Kindle Fire HD
            '/KFJWI/i',  // Kindle Fire HD 8.9
            '/KFOT/i',   // Kindle Fire HDX 7
            '/PlayBook/i', // BlackBerry PlayBook
            '/Galaxy Tab/i',
            '/SM-T/i',   // Samsung Galaxy Tab series
            '/Xoom/i',   // Motorola Xoom
        ];

        foreach ($tabletPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return 'tablet';
            }
        }

        // Special case for Android - check if it's NOT mobile (likely tablet)
        if (preg_match('/Android/i', $userAgent) && ! preg_match('/Mobile/i', $userAgent)) {
            return 'tablet';
        }

        // Mobile patterns
        $mobilePatterns = [
            '/Mobile/i',
            '/Android.*Mobile/i', // Android mobile specifically
            '/iPhone/i',
            '/iPod/i',
            '/BlackBerry/i',
            '/Windows Phone/i',
            '/Windows Mobile/i',
            '/Opera Mini/i',
            '/Opera Mobi/i',
            '/IEMobile/i',
            '/Mobile Safari/i',
            '/Nokia/i',
            '/webOS/i',
            '/Palm/i',
            '/Symbian/i',
        ];

        foreach ($mobilePatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return 'mobile';
            }
        }

        // Smart TV / Console patterns (optional - you might want these as separate categories)
        $tvPatterns = [
            '/Smart-TV/i',
            '/SmartTV/i',
            '/GoogleTV/i',
            '/Apple TV/i',
            '/NetCast/i',
            '/PlayStation/i',
            '/Xbox/i',
            '/Nintendo/i',
        ];

        foreach ($tvPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return 'tv'; // or you could return 'desktop' if you don't want a separate category
            }
        }

        // Default fallback
        return 'desktop';
    }

    private function processReferer(?string $referer): array
    {
        if (empty($referer)) {
            return [
                'id' => null,
                'domain' => null,
            ];
        }

        // Validate and truncate URL to match 'raw' field length
        $referer = mb_substr($referer, 0, 1024);

        // Parse URL components
        $parsedUrl = parse_url($referer);

        if (! $parsedUrl || ! isset($parsedUrl['host'])) {
            return ['id' => null, 'domain' => null, 'path' => null];
        }

        $domain = $parsedUrl['host'];
        $path = $parsedUrl['path'] ?? '/';

        // Truncate domain to match field length
        $domain = mb_substr($domain, 0, 255);

        // Truncate path to match field length
        $path = mb_substr($path, 0, 1024);

        $hash = $this->generateHash([$referer]);

        $refererId = $this->getOrCreateRecord(Referer::class, $hash, [
            'raw' => $referer,
            'domain' => $domain,
            'path' => $path,
        ]);

        return [
            'id' => $refererId,
            'domain' => $domain,
        ];
    }

    private function processUtm(?array $utm): ?int
    {
        if (empty($utm) || ! is_array($utm)) {
            return null;
        }

        $utmData = [
            'utm_source' => $this->truncateString($utm['utm_source'] ?? null, 255),
            'utm_medium' => $this->truncateString($utm['utm_medium'] ?? null, 255),
            'utm_campaign' => $this->truncateString($utm['utm_campaign'] ?? null, 255),
            'utm_term' => $this->truncateString($utm['utm_term'] ?? null, 255),
            'utm_content' => $this->truncateString($utm['utm_content'] ?? null, 500),
        ];

        // Skip if all UTM values are empty
        if (array_filter($utmData) === []) {
            return null;
        }

        $hash = $this->generateHash(array_values($utmData));

        return $this->getOrCreateRecord(UtmParam::class, $hash, $utmData);
    }

    private function processClickIds(array $payload): ?int
    {
        $clickData = [
            'gclid' => $this->truncateString($payload['gclid'] ?? null, 255),
            'fbclid' => $this->truncateString($payload['fbclid'] ?? null, 255),
            'msclkid' => $this->truncateString($payload['msclkid'] ?? null, 255),
            'ttclid' => $this->truncateString($payload['ttclid'] ?? null, 255),
            'li_fat_id' => $this->truncateString($payload['li_fat_id'] ?? null, 255),
        ];

        // Skip if all click IDs are empty
        if (array_filter($clickData) === []) {
            return null;
        }

        $hash = $this->generateHash(array_values($clickData));

        return $this->getOrCreateRecord(ClickId::class, $hash, $clickData);
    }

    private function processScreen(?array $screen): ?int
    {
        if (empty($screen) || ! is_array($screen)) {
            return null;
        }

        $screenData = [
            'screen_width' => $this->validateInteger($screen['screen_width'] ?? null),
            'screen_height' => $this->validateInteger($screen['screen_height'] ?? null),
            'viewport_width' => $this->validateInteger($screen['viewport_width'] ?? null),
            'viewport_height' => $this->validateInteger($screen['viewport_height'] ?? null),
            'color_depth' => $this->validateInteger($screen['color_depth'] ?? null),
            'pixel_ratio' => $this->validateInteger($screen['pixel_ratio'] ?? null),
        ];

        // Skip if all screen values are empty
        if (array_filter($screenData) === []) {
            return null;
        }

        $hash = $this->generateHash(array_values($screenData));

        return $this->getOrCreateRecord(Screen::class, $hash, $screenData);
    }

    private function getOrCreateRecord(string $modelClass, string $hash, array $data): int
    {
        // Check cache first
        $cacheKey = $modelClass.':'.$hash;
        if (isset($this->hashCache[$cacheKey])) {
            return $this->hashCache[$cacheKey];
        }

        // Try to find existing record
        $record = $modelClass::where('hash', $hash)->first();

        if (! $record) {
            $record = $modelClass::create(array_merge(['hash' => $hash], $data));
        }

        // Cache the ID
        $this->hashCache[$cacheKey] = $record->id;

        return $record->id;
    }

    private function generateHash(array $values): string
    {
        return hash('xxh128',
            implode('|', array_map(fn ($v) => strval($v ?? ''), $values))
        );
    }

    private function truncateString(?string $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }

    private function validateInteger($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return ($int !== false && $int >= 0) ? $int : null;
    }

    private function cleanup(): void
    {
        if ($this->geoReader) {
            try {
                $this->geoReader->close();
            } catch (Exception $e) {
                Log::warning('Failed to close GeoIP reader', ['error' => $e->getMessage()]);
            }
            $this->geoReader = null;
        }

        // Clear cache periodically to prevent memory bloat
        if (count($this->hashCache) > 10000) {
            $this->hashCache = [];
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('ProcessVisitRaw job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'batch_size' => $this->batchSize,
        ]);

        $this->cleanup();
    }
}
