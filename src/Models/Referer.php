<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referer extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'raw',
        'domain',
        'path',
        'hash',
    ];

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
