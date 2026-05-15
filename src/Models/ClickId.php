<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ClickId extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'gclid',
        'fbclid',
        'msclkid',
        'ttclid',
        'li_fat_id',
        'hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function visits(): HasMany
    {
        // FK column is 'click_id' on the visits table
        return $this->hasMany(Visit::class, 'click_id');
    }
}
