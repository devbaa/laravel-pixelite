<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Middleware;

use Boralp\Pixelite\Models\VisitRaw;
use Boralp\Pixelite\Services\IpAnonymizer;
use Boralp\Pixelite\Services\PrivacyService;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackVisit
{
    public function __construct(
        private readonly PrivacyService $privacy,
        private readonly IpAnonymizer $ipAnonymizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldSkipTracking($request) && $this->privacy->isTrackingAllowed($request)) {
            try {
                $this->trackVisit($request);
            } catch (Exception $e) {
                Log::error('Visit tracking failed', [
                    'error'      => $e->getMessage(),
                    'url'        => $request->fullUrl(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
        }

        return $next($request);
    }

    private function shouldSkipTracking(Request $request): bool
    {
        return $request->expectsJson()
               || $request->ajax()
               || $request->is('api/*')
               || $request->is('admin/*')
               || $request->is('_debugbar/*')
               || $request->isMethod('POST')
               || ! $request->route();
    }

    private function trackVisit(Request $request): void
    {
        $collect = config('pixelite.collect', []);

        $ip = $this->resolveIp($request->ip());

        // Abort only when the original IP was malformed (not when anonymised to null)
        if ($ip === false) {
            Log::warning('Invalid IP address — visit not tracked', ['ip' => $request->ip()]);

            return;
        }

        $crossSession = config('pixelite.profiling.cross_session', true);

        $raw = VisitRaw::create([
            'user_id'     => $crossSession ? auth()->id() : null,
            'team_id'     => $this->resolveTrackedId('tracking.team_id', $request),
            'session_id'  => $request->session()->getId(),
            'custom_id'   => $this->resolveCustomId($request),
            'route_name'  => $request->route()->getName(),
            'route_params' => $this->sanitizeRouteParams($request->route()->parameters()),
            'ip'          => $ip ?: null, // null is acceptable (full anonymisation)
            'user_agent'  => ($collect['user_agent'] ?? true) ? $this->sanitizeUserAgent($request->userAgent()) : null,
            'payload'     => $this->buildPayload($request, $collect),
            'total_time'  => null,
        ]);

        $request->session()->put('pixelite_trace_id', $raw->id);
    }

    /**
     * Return the (anonymised) binary IP, null for full-anonymisation, or false for invalid.
     *
     * @return string|null|false
     */
    private function resolveIp(?string $rawIp): string|null|false
    {
        if (! $rawIp || ! filter_var($rawIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        $level = config('pixelite.ip.anonymization', 'none');
        $ip = $this->ipAnonymizer->anonymize($rawIp, $level);

        // full anonymisation — valid visit, just no IP stored
        if ($ip === null && $level === 'full') {
            return null;
        }

        if (! $ip) {
            return false;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        // Store IPv4 as IPv4-mapped IPv6 (16 bytes)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $binary = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff".$binary;
        }

        return $binary;
    }

    /**
     * Resolve team_id (integer) using the configured dot-notation resolver.
     * Returns null when tracking is disabled or the resolved value is empty.
     */
    private function resolveTrackedId(string $configKey, Request $request): ?int
    {
        $cfg = config($configKey, []);
        if (empty($cfg['enabled'])) {
            return null;
        }

        $value = $this->resolveByDotNotation($cfg['resolver'] ?? '', $request);

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * Resolve custom_id (string) using the configured dot-notation resolver.
     * Returns null when tracking is disabled or the resolved value is empty.
     */
    private function resolveCustomId(Request $request): ?string
    {
        $cfg = config('pixelite.tracking.custom_id', []);
        if (empty($cfg['enabled'])) {
            return null;
        }

        $value = $this->resolveByDotNotation($cfg['resolver'] ?? '', $request);

        return $value !== null && $value !== ''
            ? $this->sanitizeString((string) $value, 255)
            : null;
    }

    /**
     * Resolve a value using "source.key" dot-notation.
     *
     * Supported sources:
     *   user.{attr}     → auth()->user()?->{attr}
     *   session.{key}   → session value
     *   request.{key}   → query / post input
     *   header.{name}   → HTTP request header
     */
    private function resolveByDotNotation(string $resolver, Request $request): mixed
    {
        [$source, $key] = array_pad(explode('.', $resolver, 2), 2, null);

        if (! $key) {
            return null;
        }

        return match ($source) {
            'user'    => auth()->user()?->{$key},
            'session' => $request->session()->get($key),
            'request' => $request->input($key),
            'header'  => $request->header($key),
            default   => null,
        };
    }

    private function buildPayload(Request $request, array $collect): array
    {
        $payload = [];

        if ($collect['utm'] ?? true) {
            $utm = $this->extractUtmParams($request);
            if (! empty(array_filter($utm))) {
                $payload['utm'] = $utm;
            }
        }

        if ($collect['click_ids'] ?? true) {
            $clickIds = $this->extractClickIds($request);
            if (! empty(array_filter($clickIds))) {
                $payload = array_merge($payload, $clickIds);
            }
        }

        if ($collect['referer'] ?? true) {
            $referrer = $this->sanitizeReferrer($request->header('referer'));
            if ($referrer !== null) {
                $payload['referrer'] = $referrer;
            }
        }

        if ($collect['locale'] ?? true) {
            $payload['locale'] = $request->getPreferredLanguage();
        }

        return array_filter($payload);
    }

    private function extractUtmParams(Request $request): array
    {
        return [
            'utm_source'   => $this->sanitizeString($request->get('utm_source'), 255),
            'utm_medium'   => $this->sanitizeString($request->get('utm_medium'), 255),
            'utm_campaign' => $this->sanitizeString($request->get('utm_campaign'), 255),
            'utm_term'     => $this->sanitizeString($request->get('utm_term'), 255),
            'utm_content'  => $this->sanitizeString($request->get('utm_content'), 500),
        ];
    }

    private function extractClickIds(Request $request): array
    {
        return [
            'gclid'    => $this->sanitizeString($request->get('gclid'), 255),
            'fbclid'   => $this->sanitizeString($request->get('fbclid'), 255),
            'msclkid'  => $this->sanitizeString($request->get('msclkid'), 255),
            'ttclid'   => $this->sanitizeString($request->get('ttclid'), 255),
            'li_fat_id' => $this->sanitizeString($request->get('li_fat_id'), 255),
        ];
    }

    private function sanitizeUserAgent(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        return mb_substr(trim($ua), 0, 1024);
    }

    private function sanitizeReferrer(?string $referrer): ?string
    {
        if (! $referrer || trim($referrer) === '') {
            return null;
        }

        $referrer = trim($referrer);

        if (! filter_var($referrer, FILTER_VALIDATE_URL)) {
            return null;
        }

        return mb_substr($referrer, 0, 1024);
    }

    private function sanitizeRouteParams(?array $params): ?array
    {
        if (! $params || empty($params)) {
            return null;
        }

        $sanitized = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $sanitized[$key] = substr((string) $value, 0, 255);
            }
        }

        return ! empty($sanitized) ? $sanitized : null;
    }

    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if (! $value || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
