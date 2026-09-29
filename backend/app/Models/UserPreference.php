<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sparse (user_id, key, value) row per non-default Appearance
 * setting - see App\Support\Appearance for the option definitions this
 * stores values against, and docs/specs/appearance-settings-spec.md for
 * the full contract.
 */
class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
