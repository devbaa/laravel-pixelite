<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Services;

use Boralp\Pixelite\Models\Visit;
use Boralp\Pixelite\Models\VisitRaw;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrivacyService
{
    /**
     * Determine whether tracking is allowed for this request.
     *
     * Checks (in order):
     *  1. Opt-out cookie (CCPA "Do Not Sell / Share")
     *  2. Consent cookie (GDPR / KVKK opt-in gate)
     */
    public function isTrackingAllowed(Request $request): bool
    {
        // Opt-out cookie always wins when the feature is enabled
        if (config('pixelite.rights.opt_out_enabled', false)) {
            $optOutCookie = config('pixelite.rights.opt_out_cookie', 'pixelite_optout');
            if ($request->cookie($optOutCookie)) {
                return false;
            }
        }

        // Consent gate (GDPR / KVKK opt-in model)
        if (config('pixelite.consent.required', false)) {
            $cookieName = config('pixelite.consent.cookie_name', 'pixelite_consent');
            $consent = $request->cookie($cookieName);

            if ($consent === null) {
                return config('pixelite.consent.default', 'granted') === 'granted';
            }

            return in_array($consent, ['1', 'true', 'granted', 'yes'], true);
        }

        return true;
    }

    /**
     * Delete all visit data for an authenticated user (GDPR Art.17).
     */
    public function deleteByUserId(int $userId): array
    {
        $raw = VisitRaw::where('user_id', $userId)->delete();
        $visits = Visit::where('user_id', $userId)->delete();
        $total = $raw + $visits;

        $this->logDsr('deletion', (string) $userId, 'user_id', $total);

        return ['raw_deleted' => $raw, 'visits_deleted' => $visits, 'total' => $total];
    }

    /**
     * Delete all visit data for a team (multi-tenant erasure).
     */
    public function deleteByTeamId(int $teamId): array
    {
        $raw = VisitRaw::where('team_id', $teamId)->delete();
        $visits = Visit::where('team_id', $teamId)->delete();
        $total = $raw + $visits;

        $this->logDsr('deletion', (string) $teamId, 'team_id', $total);

        return ['raw_deleted' => $raw, 'visits_deleted' => $visits, 'total' => $total];
    }

    /**
     * Delete all visit data matching a custom identifier (e.g. shop_id).
     */
    public function deleteByCustomId(string $customId): array
    {
        $raw = VisitRaw::where('custom_id', $customId)->delete();
        $visits = Visit::where('custom_id', $customId)->delete();
        $total = $raw + $visits;

        $label = config('pixelite.tracking.custom_id.label', 'custom_id');
        $this->logDsr('deletion', $customId, $label, $total);

        return ['raw_deleted' => $raw, 'visits_deleted' => $visits, 'total' => $total];
    }

    /**
     * Export all visit data for a team.
     */
    public function exportByTeamId(int $teamId): array
    {
        $visits = Visit::where('team_id', $teamId)
            ->with(['geo', 'userAgent', 'referer', 'utm', 'click', 'screen'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($v) => $this->formatVisitForExport($v))
            ->toArray();

        $this->logDsr('export', (string) $teamId, 'team_id', count($visits));

        return $visits;
    }

    /**
     * Delete all visit data for an anonymous session.
     */
    public function deleteBySessionId(string $sessionId): array
    {
        $raw = VisitRaw::where('session_id', $sessionId)->delete();
        $visits = Visit::where('session_id', $sessionId)->delete();
        $total = $raw + $visits;

        $this->logDsr('deletion', $sessionId, 'session_id', $total);

        return ['raw_deleted' => $raw, 'visits_deleted' => $visits, 'total' => $total];
    }

    /**
     * Export all visit data for a user as an array (GDPR Art.20).
     */
    public function exportByUserId(int $userId): array
    {
        $visits = Visit::where('user_id', $userId)
            ->with(['geo', 'userAgent', 'referer', 'utm', 'click', 'screen'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($v) => $this->formatVisitForExport($v))
            ->toArray();

        $this->logDsr('export', (string) $userId, 'user_id', count($visits));

        return $visits;
    }

    /**
     * Delete records that exceed the configured retention thresholds.
     */
    public function purgeOldData(): array
    {
        if (! config('pixelite.retention.enabled', false)) {
            return ['raw_deleted' => 0, 'visits_deleted' => 0];
        }

        $rawHours = (int) config('pixelite.retention.raw_hours', 24);
        $visitsDays = (int) config('pixelite.retention.visits_days', 365);

        $rawDeleted = 0;
        $visitsDeleted = 0;

        if ($rawHours > 0) {
            $rawDeleted = VisitRaw::where('created_at', '<', now()->subHours($rawHours))->delete();
        }

        if ($visitsDays > 0) {
            $visitsDeleted = Visit::where('created_at', '<', now()->subDays($visitsDays))->delete();
        }

        return ['raw_deleted' => $rawDeleted, 'visits_deleted' => $visitsDeleted];
    }

    private function formatVisitForExport(Visit $visit): array
    {
        return [
            'id'                 => $visit->id,
            'session_id'         => $visit->session_id,
            'team_id'            => $visit->team_id,
            'custom_id'          => $visit->custom_id,
            'route'              => $visit->route_name,
            'visited_at'         => $visit->created_at?->toISOString(),
            'country'            => $visit->country_code,
            'device'             => $visit->device_category,
            'os'                 => $visit->os_name,
            'browser'            => $visit->userAgent?->browser_name,
            'referer'            => $visit->referer?->raw,
            'utm_source'         => $visit->utm?->utm_source,
            'utm_medium'         => $visit->utm?->utm_medium,
            'utm_campaign'       => $visit->utm?->utm_campaign,
            'total_time_seconds' => $visit->total_time,
            'locale'             => $visit->locale,
            'geo'                => $visit->geo ? [
                'country' => $visit->geo->country_code,
                'region'  => $visit->geo->region,
                'city'    => $visit->geo->city,
            ] : null,
        ];
    }

    private function logDsr(string $type, string $identifier, string $identifierType, int $affected): void
    {
        try {
            DB::table('pixelite_dsr')->insert([
                'type'             => $type,
                'identifier'       => $identifier,
                'identifier_type'  => $identifierType,
                'records_affected' => $affected,
                'status'           => 'completed',
                'requested_at'     => now(),
                'completed_at'     => now(),
            ]);
        } catch (\Exception) {
            // DSR logging is best-effort; never fail the primary operation
        }
    }
}
