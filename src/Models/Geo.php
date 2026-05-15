<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Geo extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'ip',
        'country_code',
        'region',
        'city',
        'postal_code',
        'latitude',
        'longitude',
        'hash',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'created_at' => 'datetime',
    ];

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
