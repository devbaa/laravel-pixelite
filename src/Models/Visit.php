<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Visit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'team_id',
        'session_id',
        'custom_id',
        'route_name',
        'route_params',
        'ip',
        'country_code',
        'device_category',
        'os_name',
        'referer_domain',
        'geo_id',
        'user_agent_id',
        'referer_id',
        'utm_id',
        'click_id',
        'screen_id',
        'timezone',
        'locale',
        'payload',
        'payload_js',
        'total_time',
    ];

    protected $casts = [
        'route_params' => 'array',
        'payload'      => 'array',
        'timezone'     => 'integer',
        'total_time'   => 'integer',
        'created_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function geo(): BelongsTo
    {
        return $this->belongsTo(Geo::class);
    }

    public function userAgent(): BelongsTo
    {
        return $this->belongsTo(UserAgent::class);
    }

    public function referer(): BelongsTo
    {
        return $this->belongsTo(Referer::class);
    }

    public function utm(): BelongsTo
    {
        return $this->belongsTo(Utm::class, 'utm_id');
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(ClickId::class, 'click_id');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'screen_id');
    }

    // ── IP accessor ──────────────────────────────────────────────────────────
    //
    // The ip column stores raw binary (4 or 16 bytes written directly via
    // Visit::insert() in VisitProcessor — bulk inserts bypass Eloquent mutators).
    // This accessor converts binary back to a human-readable string for display.
    //
    // There is intentionally NO setIpAttribute: all Visit rows are created via
    // bulk insert which bypasses mutators.  String IP input is not supported on
    // this model.

    public function getIpAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $len = strlen((string) $value);

        if ($len !== 4 && $len !== 16) {
            return null;
        }

        $result = inet_ntop((string) $value);

        return $result !== false ? $result : null;
    }
}
