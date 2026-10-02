<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place's own value for one setting (docs/specs/settings-spec.md).
 * territory_id NULL is the system level. Read and written only through
 * App\Services\Settings\Settings, which decodes values, decrypts secrets
 * and writes the audit row - so this model is deliberately not Auditable.
 */
class Setting extends Model
{
    protected $fillable = ['territory_id', 'key', 'value', 'is_locked', 'updated_by'];

    protected $casts = [
        'is_locked' => 'boolean',
    ];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
