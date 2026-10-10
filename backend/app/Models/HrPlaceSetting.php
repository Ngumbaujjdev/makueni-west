<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A place's own version of a position, grade or allowance set above it (docs/specs/hr-spec.md). */
class HrPlaceSetting extends Model
{
    protected $fillable = ['territory_id', 'kind', 'item_id', 'settings', 'created_by', 'updated_by'];

    protected $casts = ['settings' => 'array'];
}
