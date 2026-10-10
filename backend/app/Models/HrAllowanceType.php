<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A kind of allowance a place sets up - house, transport... - with its usual amount (docs/specs/hr-spec.md). */
class HrAllowanceType extends Model
{
    protected $fillable = ['territory_id', 'name', 'default_amount', 'description', 'is_active', 'display_order', 'created_by'];

    protected $casts = ['default_amount' => 'decimal:2', 'is_active' => 'boolean'];
}
