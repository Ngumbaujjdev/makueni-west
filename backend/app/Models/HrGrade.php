<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A pay grade a place sets up - its range and usual basic pay (docs/specs/hr-spec.md). */
class HrGrade extends Model
{
    protected $fillable = ['territory_id', 'code', 'name', 'min_pay', 'max_pay', 'default_pay', 'description', 'is_active', 'display_order', 'created_by'];

    protected $casts = ['min_pay' => 'decimal:2', 'max_pay' => 'decimal:2', 'default_pay' => 'decimal:2', 'is_active' => 'boolean'];
}
