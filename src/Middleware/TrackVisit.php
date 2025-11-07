<?php

namespace Boralp\Pixelite\Middleware;

use Boralp\Pixelite\Models\VisitRaw;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackVisit
{
    /**
     * Handle an incoming request and track visit data
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip tracking for non-trackable requests
        if ($this->shouldSkipTracking($request)) {
            return $next($request);
        }

        try {
            $this->trackVisit($request);
        } catch (Exception $e) {
            // Don't break the request flow if tracking fails
            Log::error('Visit tracking failed', [
                'error' => $e->getMessage(),
                'url' => $request->fullUrl(),
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }

    /**
     * Determine if tracking should be skipped
     */
    private function shouldSkipTracking(Request $request): bool
    {
        return $request->expectsJson()
               || $request->ajax()
               || $request->is('api/*')
               || $request->is('admin/*')
               || $request->is('_debugbar/*')
               || $request->isMethod('POST') // Only track GET requests
               || ! $request->route();
    }

    /**
     * Track the visit by creating VisitRaw record
     */
    private function trackVisit(Request $request): void
    {
        $payload = $this->buildPayload($request);
        $ip = $this->processIpAddress($request->ip());

        if (! $ip) {
            Log::warning('Invalid IP address for tracking', ['ip' => $request->ip()]);

            return;
        }

        // Create raw visit record
        $raw = VisitRaw::create([
            'user_id' => auth()->id(),
            'session_id' => $request->session()->getId(),
            'route_name' => $request->route()->getName(),
            'route_params' => $this->sanitizeRouteParams($request->route()->parameters()),
            'ip' => $ip,
            'user_agent' => $this->sanitizeUserAgent($request->userAgent()),
            'payload' => $payload,
            'total_time' => null,
        ]);

        // Store visit ID in session for lifecycle tracking
        $request->session()->put('pixelite_trace_id', $raw->id);

        Log::debug('Visit tracked', ['visit_id' => $raw->id, 'route' => $request->route()->getName()]);
    }

    /**
     * Build payload from request data
     */
    private function buildPayload(Request $request): array
    {
        $payload = [];

        // UTM Parameters
        $utmParams = $this->extractUtmParams($request);
        if (! empty(array_filter($utmParams))) {
            $payload['utm'] = $utmParams;
        }

        // Click IDs
        $clickIds = $this->extractClickIds($request);
        if (! empty(array_filter($clickIds))) {
            $payload = array_merge($payload, $clickIds);
        }

        // Additional tracking data
        $payload['referrer'] = $this->sanitizeReferrer($request->header('referer'));
        $payload['locale'] = $request->getPreferredLanguage();

        return array_filter($payload); // Remove null/empty values
    }

    /**
     * Extract UTM parameters
     */
    private function extractUtmParams(Request $request): array
    {
        return [
            'utm_source' => $this->sanitizeString($request->get('utm_source'), 255),
            'utm_medium' => $this->sanitizeString($request->get('utm_medium'), 255),
            'utm_campaign' => $this->sanitizeString($request->get('utm_campaign'), 255),
            'utm_term' => $this->sanitizeString($request->get('utm_term'), 255),
            'utm_content' => $this->sanitizeString($request->get('utm_content'), 500),
        ];
    }

    /**
     * Extract click IDs
     */
    private function extractClickIds(Request $request): array
    {
        return [
            'gclid' => $this->sanitizeString($request->get('gclid'), 255),
            'fbclid' => $this->sanitizeString($request->get('fbclid'), 255),
            'msclkid' => $this->sanitizeString($request->get('msclkid'), 255),
            'ttclid' => $this->sanitizeString($request->get('ttclid'), 255),
            'li_fat_id' => $this->sanitizeString($request->get('li_fat_id'), 255),
        ];
    }

    /**
     * Process and validate IP address
     */
    private function processIpAddress(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $binaryIp = inet_pton($ip);
        if ($binaryIp === false) {
            return null;
        }

        // Ensure IPv4 addresses are stored as IPv4-mapped IPv6 (16 bytes)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $binaryIp = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff".$binaryIp;
        }

        return $binaryIp;
    }

    /**
     * Sanitize user agent string
     */
    private function sanitizeUserAgent(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        return mb_substr(trim($userAgent), 0, 1024);
    }

    /**
     * Sanitize referrer URL
     */
    private function sanitizeReferrer(?string $referrer): ?string
    {
        if (! $referrer || trim($referrer) === '') {
            return null;
        }

        $referrer = trim($referrer);

        // Basic URL validation
        if (! filter_var($referrer, FILTER_VALIDATE_URL)) {
            return null;
        }

        return mb_substr($referrer, 0, 1024);
    }

    /**
     * Sanitize route parameters
     */
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

    /**
     * Sanitize string input
     */
    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if (! $value || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
