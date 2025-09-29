<?php

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'session_id',
        'route_name',
        'route_params',
        'ip',
        'country_code',
        'device_type',
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
        'ip' => 'binary',
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
        return $this->belongsTo(UtmParam::class, 'utm_id');
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(ClickId::class, 'click_id');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'screen_id');
    }

    public function setIpAttribute(?string $value): void
    {
        $this->attributes['ip'] = $value ? inet_pton($value) : null;
    }

    public function getIpAttribute(?string $value): ?string
    {
        return $value ? inet_ntop($value) : null;
    }
}
