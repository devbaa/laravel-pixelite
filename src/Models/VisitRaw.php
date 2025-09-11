<?php

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;

class VisitRaw extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'session_id',
        'route_name',
        'route_params',
        'ip',
        'user_agent',
        'payload',
        'payload_js',
        'total_time',
    ];

    protected $casts = [
        'route_params' => 'array',
        'payload' => 'array',
        'created_at' => 'datetime',
        'total_time' => 'integer',
    ];
}
