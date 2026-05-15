<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Services;

use Boralp\Pixelite\Models\ClickId;
use Boralp\Pixelite\Models\Geo;
use Boralp\Pixelite\Models\Referer;
use Boralp\Pixelite\Models\Screen;
use Boralp\Pixelite\Models\UserAgent;
use Boralp\Pixelite\Models\Utm;
use Boralp\Pixelite\Models\Visit;
use Boralp\Pixelite\Models\VisitRaw;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * Processes raw visit records into normalised Visit rows using batch DB operations.
 *
 * Query budget per batch (regardless of batch size):
 *   - 1 SELECT FOR UPDATE + DELETE  (claim)
 *   - 2 queries × 6 related tables  (SELECT existing + INSERT missing)
 *   - 1 bulk INSERT                  (visits)
 *   Total ≈ 15 queries for any batch size
 */
final class VisitProcessor
{
    private ?Reader $geoReader = null;

    public function __construct() {}

    /**
     * Claim and process one batch. Returns the number of records processed.
     */
    public function run(int $batchSize): int
    {
        $rawVisits = $this->claimBatch($batchSize);

        if ($rawVisits->isEmpty()) {
            return 0;
        }

        $collect     = config('pixelite.collect', []);
        $behavioral  = (bool) config('pixelite.profiling.behavioral', true);
        $crossSession = (bool) config('pixelite.profiling.cross_session', true);

        // ── Pass 1: compute all hashes / resolve external data ─────────────────
        $geoInputs    = [];
        $uaInputs     = [];
        $refInputs    = [];
        $utmInputs    = [];
        $clickInputs  = [];
        $screenInputs = [];

        foreach ($rawVisits as $raw) {
            $payload   = $this->decodeJson($raw->payload);
            $payloadJs = $this->decodeJson($raw->payload_js);

            if ($collect['geo'] ?? true) {
                $geoInputs[$raw->id] = $this->buildGeoInput($raw->ip);
            }
            if ($collect['user_agent'] ?? true) {
                $uaInputs[$raw->id] = $this->buildUaInput($raw->user_agent);
            }
            if ($collect['referer'] ?? true) {
                // Key is 'referrer' (double-r) — set by TrackVisit middleware
                $refInputs[$raw->id] = $this->buildRefInput($payload['referrer'] ?? null);
            }
            if ($collect['utm'] ?? true) {
                $utmInputs[$raw->id] = $this->buildUtmInput($payload['utm'] ?? null);
            }
            if ($collect['click_ids'] ?? true) {
                $clickInputs[$raw->id] = $this->buildClickInput($payload);
            }
            if (($collect['screen'] ?? true) && $behavioral) {
                $screenInputs[$raw->id] = $this->buildScreenInput($payloadJs['screen'] ?? null);
            }
        }

        // ── Pass 2: batch upsert each related table ────────────────────────────
        $geoIds    = $this->batchUpsert(Geo::class,       $geoInputs);
        $uaIds     = $this->batchUpsert(UserAgent::class, $uaInputs);
        $refIds    = $this->batchUpsert(Referer::class,   $refInputs);
        $utmIds    = $this->batchUpsert(Utm::class,       $utmInputs);
        $clickIds  = $this->batchUpsert(ClickId::class,   $clickInputs);
        $screenIds = $this->batchUpsert(Screen::class,    $screenInputs);

        // ── Pass 3: build normalised visit rows ────────────────────────────────
        $visitRows = [];

        foreach ($rawVisits as $raw) {
            $payload   = $this->decodeJson($raw->payload);
            $payloadJs = $this->decodeJson($raw->payload_js);

            $visitRows[] = [
                'user_id'        => $crossSession ? $raw->user_id : null,
                'team_id'        => $raw->team_id,
                'session_id'     => $raw->session_id,
                'custom_id'      => $raw->custom_id,
                'route_name'     => $raw->route_name,
                'route_params'   => $raw->route_params
                    ? (is_string($raw->route_params) ? $raw->route_params : json_encode($raw->route_params))
                    : null,
                'ip'             => $raw->ip,  // already binary — bypasses Visit mutator via bulk insert
                'geo_id'         => $geoIds[$raw->id] ?? null,
                'user_agent_id'  => $uaIds[$raw->id] ?? null,
                'referer_id'     => $refIds[$raw->id] ?? null,
                'referer_domain' => $refInputs[$raw->id]['domain'] ?? null,
                'device_category' => $uaInputs[$raw->id]['device_category'] ?? null,
                'os_name'        => $uaInputs[$raw->id]['os_name'] ?? null,
                'country_code'   => $geoInputs[$raw->id]['country_code'] ?? null,
                'utm_id'         => $utmIds[$raw->id] ?? null,
                'click_id'       => $clickIds[$raw->id] ?? null,
                'screen_id'      => $screenIds[$raw->id] ?? null,
                'timezone'       => ($collect['timezone'] ?? true) && $behavioral
                    ? ($payloadJs['timezone_offset'] ?? null) : null,
                'locale'         => ($collect['locale'] ?? true)
                    ? ($payload['locale'] ?? null) : null,
                'payload'        => is_string($raw->payload) ? $raw->payload : json_encode($payload),
                'payload_js'     => is_string($raw->payload_js) ? $raw->payload_js : json_encode($payloadJs),
                'total_time'     => ($collect['total_time'] ?? true) && $behavioral
                    ? $raw->total_time : null,
                'created_at'     => $raw->created_at,
            ];
        }

        // ── Pass 4: bulk insert visits (bypasses all Eloquent mutators) ────────
        // Chunk to stay well under PDO parameter limits (~65k)
        foreach (array_chunk($visitRows, 200) as $chunk) {
            Visit::insert($chunk);
        }

        return count($visitRows);
    }

    public function shutdown(): void
    {
        try {
            $this->geoReader?->close();
        } catch (Exception) {
            // best-effort
        }
        $this->geoReader = null;
    }

    // ── Claim ──────────────────────────────────────────────────────────────────

    /**
     * Atomically claim a batch of raw visit records.
     *
     * SELECT FOR UPDATE + DELETE in a single transaction prevents two concurrent
     * workers from processing the same rows.  The lock is held only for the
     * duration of a fast DELETE, not during GeoIP I/O.
     */
    private function claimBatch(int $batchSize): Collection
    {
        return DB::transaction(function () use ($batchSize): Collection {
            $records = VisitRaw::lockForUpdate()
                ->select([
                    'id', 'user_id', 'team_id', 'session_id', 'custom_id',
                    'route_name', 'route_params', 'ip',
                    'user_agent', 'payload', 'payload_js', 'total_time', 'created_at',
                ])
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($records->isNotEmpty()) {
                VisitRaw::whereIn('id', $records->pluck('id'))->delete();
            }

            return $records;
        });
    }

    // ── Input builders ─────────────────────────────────────────────────────────

    private function buildGeoInput(?string $binaryIp): ?array
    {
        if ($binaryIp === null || $binaryIp === '') {
            return null;
        }

        $ipString = $this->binaryToString($binaryIp);

        if ($ipString === null) {
            return null;
        }

        $reader = $this->getGeoReader();

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ipString);

            if ($record === null) {
                return null;
            }

            $data = [
                'ip'           => $binaryIp,
                'country_code' => $record['country']['iso_code'] ?? null,
                'region'       => $record['subdivisions'][0]['names']['en'] ?? null,
                'city'         => $record['city']['names']['en'] ?? null,
                'postal_code'  => $record['postal']['code'] ?? null,
                'latitude'     => isset($record['location']['latitude'])
                    ? round((float) $record['location']['latitude'], 8) : null,
                'longitude'    => isset($record['location']['longitude'])
                    ? round((float) $record['location']['longitude'], 8) : null,
            ];

            $data['hash'] = $this->hash([
                $binaryIp,
                $data['country_code'],
                $data['region'],
                $data['city'],
                $data['postal_code'],
                $data['latitude'],
                $data['longitude'],
            ]);

            return $data;

        } catch (Exception $e) {
            Log::warning('Pixelite GeoIP lookup failed', [
                'ip'    => $ipString,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildUaInput(?string $userAgent): ?array
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $ua = mb_substr($userAgent, 0, 1000);

        return [
            'raw'             => $ua,
            'device_category' => $this->detectDevice($ua),
            'os_name'         => $this->detectOs($ua),
            'browser_name'    => $this->detectBrowser($ua),
            'hash'            => $this->hash([$ua]),
        ];
    }

    private function buildRefInput(?string $referer): ?array
    {
        if ($referer === null || $referer === '') {
            return null;
        }

        $referer = mb_substr($referer, 0, 1024);
        $parts   = parse_url($referer);

        if (! $parts || ! isset($parts['host'])) {
            return null;
        }

        return [
            'raw'    => $referer,
            'domain' => mb_substr($parts['host'], 0, 255),
            'path'   => mb_substr($parts['path'] ?? '/', 0, 1024),
            'hash'   => $this->hash([$referer]),
        ];
    }

    private function buildUtmInput(?array $utm): ?array
    {
        if (empty($utm) || ! is_array($utm)) {
            return null;
        }

        $data = [
            'utm_source'   => $this->trunc($utm['utm_source']   ?? null, 255),
            'utm_medium'   => $this->trunc($utm['utm_medium']   ?? null, 255),
            'utm_campaign' => $this->trunc($utm['utm_campaign'] ?? null, 255),
            'utm_term'     => $this->trunc($utm['utm_term']     ?? null, 255),
            'utm_content'  => $this->trunc($utm['utm_content']  ?? null, 500),
        ];

        if (array_filter($data) === []) {
            return null;
        }

        $data['hash'] = $this->hash(array_values($data));

        return $data;
    }

    private function buildClickInput(array $payload): ?array
    {
        $data = [
            'gclid'    => $this->trunc($payload['gclid']    ?? null, 255),
            'fbclid'   => $this->trunc($payload['fbclid']   ?? null, 255),
            'msclkid'  => $this->trunc($payload['msclkid']  ?? null, 255),
            'ttclid'   => $this->trunc($payload['ttclid']   ?? null, 255),
            'li_fat_id' => $this->trunc($payload['li_fat_id'] ?? null, 255),
        ];

        if (array_filter($data) === []) {
            return null;
        }

        $data['hash'] = $this->hash(array_values($data));

        return $data;
    }

    private function buildScreenInput(?array $screen): ?array
    {
        if (empty($screen) || ! is_array($screen)) {
            return null;
        }

        $data = [
            'screen_width'   => $this->parseInt($screen['screen_width']   ?? null),
            'screen_height'  => $this->parseInt($screen['screen_height']  ?? null),
            'viewport_width' => $this->parseInt($screen['viewport_width'] ?? null),
            'viewport_height' => $this->parseInt($screen['viewport_height'] ?? null),
            'color_depth'    => $this->parseInt($screen['color_depth']    ?? null),
            'pixel_ratio'    => $this->parseInt($screen['pixel_ratio']    ?? null),
        ];

        if (array_filter($data) === []) {
            return null;
        }

        $data['hash'] = $this->hash(array_values($data));

        return $data;
    }

    // ── Batch upsert ──────────────────────────────────────────────────────────

    /**
     * For a given related model, insert any records whose hash doesn't yet exist,
     * then return a [rawVisitId → dbId] map.
     *
     * Uses exactly 2 queries when all hashes are already known,
     * or 3 queries (SELECT + INSERT IGNORE + SELECT) on first-seen data.
     *
     * @param  class-string  $modelClass
     * @param  array<int, array<string,mixed>|null>  $inputs  rawId → row data (with 'hash') or null
     * @return array<int, int>  rawId → DB id
     */
    private function batchUpsert(string $modelClass, array $inputs): array
    {
        // Group by hash to deduplicate across the batch
        $byHash    = [];  // hash → row data
        $idToHash  = [];  // rawId → hash

        foreach ($inputs as $rawId => $data) {
            if ($data === null) {
                continue;
            }
            $byHash[$data['hash']]  = $data;
            $idToHash[$rawId]       = $data['hash'];
        }

        if (empty($byHash)) {
            return [];
        }

        $hashes   = array_keys($byHash);
        $existing = $modelClass::whereIn('hash', $hashes)->pluck('id', 'hash');

        // Insert rows whose hash isn't in the DB yet
        $missing = array_filter(
            $byHash,
            static fn (string $h) => ! $existing->has($h),
            ARRAY_FILTER_USE_KEY
        );

        if (! empty($missing)) {
            $now  = now();
            $rows = array_map(
                static fn (array $row): array => array_merge($row, ['created_at' => $now]),
                array_values($missing)
            );
            // insertOrIgnore silently handles concurrent duplicate-key violations
            $modelClass::insertOrIgnore($rows);
            $existing = $modelClass::whereIn('hash', $hashes)->pluck('id', 'hash');
        }

        // Build rawId → dbId result
        $result = [];

        foreach ($idToHash as $rawId => $hash) {
            if ($existing->has($hash)) {
                $result[$rawId] = $existing[$hash];
            }
        }

        return $result;
    }

    // ── GeoIP ─────────────────────────────────────────────────────────────────

    private function getGeoReader(): ?Reader
    {
        if ($this->geoReader !== null) {
            return $this->geoReader;
        }

        $path = (string) config('pixelite.geo_db_path', '');

        if (! file_exists($path)) {
            return null;
        }

        try {
            $this->geoReader = new Reader($path);
        } catch (Exception $e) {
            Log::error('Pixelite: failed to open GeoIP database', ['error' => $e->getMessage()]);
        }

        return $this->geoReader;
    }

    private function binaryToString(?string $binary): ?string
    {
        if ($binary === null || strlen($binary) !== 16) {
            return null;
        }

        $prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

        if (strncmp($binary, $prefix, 12) === 0) {
            // IPv4-mapped IPv6 → extract the last 4 bytes as IPv4
            $ip = inet_ntop(substr($binary, 12));
        } else {
            $ip = inet_ntop($binary);
        }

        return $ip !== false ? $ip : null;
    }

    // ── User-agent detection ───────────────────────────────────────────────────

    private function detectDevice(string $ua): string
    {
        $botPatterns = [
            '/bot/i', '/crawl/i', '/slurp/i', '/spider/i', '/mediapartners/i',
            '/facebookexternalhit/i', '/WhatsApp/i', '/Googlebot/i', '/Bingbot/i',
            '/YandexBot/i', '/Applebot/i', '/LinkedInBot/i', '/Twitterbot/i',
        ];

        foreach ($botPatterns as $p) {
            if (preg_match($p, $ua)) {
                return 'bot';
            }
        }

        $tabletPatterns = [
            '/iPad/i', '/Tablet/i', '/Nexus (7|9|10)/i', '/KFAPWI|KFTT|KFJWI|KFOT/i',
            '/PlayBook/i', '/Galaxy Tab/i', '/SM-T/i', '/Xoom/i',
        ];

        foreach ($tabletPatterns as $p) {
            if (preg_match($p, $ua)) {
                return 'tablet';
            }
        }

        if (preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua)) {
            return 'tablet';
        }

        $mobilePatterns = [
            '/Mobile/i', '/iPhone/i', '/iPod/i', '/BlackBerry/i', '/Windows Phone/i',
            '/Opera Mini/i', '/Opera Mobi/i', '/IEMobile/i', '/webOS/i',
        ];

        foreach ($mobilePatterns as $p) {
            if (preg_match($p, $ua)) {
                return 'mobile';
            }
        }

        $tvPatterns = ['/Smart-TV/i', '/SmartTV/i', '/GoogleTV/i', '/Apple TV/i',
            '/PlayStation/i', '/Xbox/i', '/Nintendo/i'];

        foreach ($tvPatterns as $p) {
            if (preg_match($p, $ua)) {
                return 'tv';
            }
        }

        return 'desktop';
    }

    private function detectOs(string $ua): string
    {
        $map = [
            '/iPhone OS/i'           => 'iOS',
            '/OS.*like Mac OS X/i'   => 'iOS',
            '/iPad/i'                => 'iOS',
            '/Android/i'             => 'Android',
            '/Windows NT/i'          => 'Windows',
            '/Mac OS X/i'            => 'macOS',
            '/Macintosh/i'           => 'macOS',
            '/Linux/i'               => 'Linux',
            '/Windows Phone/i'       => 'Windows Phone',
            '/BlackBerry/i'          => 'BlackBerry',
            '/webOS/i'               => 'webOS',
            '/Tizen/i'               => 'Tizen',
            '/PlayStation/i'         => 'PlayStation',
            '/Xbox/i'                => 'Xbox',
            '/Nintendo/i'            => 'Nintendo',
        ];

        foreach ($map as $pattern => $name) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    private function detectBrowser(string $ua): string
    {
        $map = [
            '/Edg\//i'            => 'Edge',
            '/Edge\//i'           => 'Edge Legacy',
            '/OPR\//i'            => 'Opera',
            '/Brave\//i'          => 'Brave',
            '/Vivaldi/i'          => 'Vivaldi',
            '/YaBrowser/i'        => 'Yandex Browser',
            '/SamsungBrowser/i'   => 'Samsung Browser',
            '/CriOS/i'            => 'Chrome',
            '/Chrome/i'           => 'Chrome',
            '/FxiOS/i'            => 'Firefox',
            '/Firefox/i'          => 'Firefox',
            '/Safari/i'           => 'Safari',
            '/MSIE/i'             => 'Internet Explorer',
            '/Trident/i'          => 'Internet Explorer',
            '/UCBrowser/i'        => 'UC Browser',
            '/DuckDuckGo/i'       => 'DuckDuckGo Browser',
            '/Googlebot/i'        => 'Googlebot',
            '/bot/i'              => 'Bot',
            '/crawl/i'            => 'Crawler',
        ];

        foreach ($map as $pattern => $name) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hash(array $values): string
    {
        return hash('xxh128', implode('|', array_map(
            static fn (mixed $v): string => (string) ($v ?? ''),
            $values
        )));
    }

    private function trunc(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return ($int !== false && $int >= 0) ? (int) $int : null;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
