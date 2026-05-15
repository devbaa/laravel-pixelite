<?php

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    const UPDATED_AT = null;

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
        'payload' => 'array',
        'timezone' => 'integer',
        'total_time' => 'integer',
        'created_at' => 'datetime',
    ];

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

    public function setIpAttribute(mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            $this->attributes['ip'] = null;

            return;
        }

        // remove null bytes and control characters
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value));

        // handle forwarded headers: take first IP only
        if (str_contains($value, ',')) {
            $value = trim(explode(',', $value)[0]);
        }

        // validate IPv4 / IPv6
        if (! filter_var($value, FILTER_VALIDATE_IP)) {
            $this->attributes['ip'] = null;

            return;
        }

        $this->attributes['ip'] = inet_pton($value);
    }

    public function getIpAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $len = strlen($value);

        // only valid binary lengths
        if ($len !== 4 && $len !== 16) {
            return null;
        }

        $ip = inet_ntop($value);

        return $ip === false ? null : $ip;
    }
}
