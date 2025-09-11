<?php

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screen extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'screen_width',
        'screen_height',
        'viewport_width',
        'viewport_height',
        'color_depth',
        'pixel_ratio',
        'hash',
    ];

    protected $casts = [
        'screen_width' => 'integer',
        'screen_height' => 'integer',
        'viewport_width' => 'integer',
        'viewport_height' => 'integer',
        'color_depth' => 'integer',
        'pixel_ratio' => 'integer',
        'created_at' => 'datetime',
    ];

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
