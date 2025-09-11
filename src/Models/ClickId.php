<?php

namespace Boralp\Pixelite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClickId extends Model
{
    const UPDATED_AT = null;

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
        return $this->hasMany(Visit::class, 'click_id_id');
    }
}
