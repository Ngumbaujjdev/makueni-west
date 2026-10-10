<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A job a place sets up - where it can be used and its usual grade (docs/specs/hr-spec.md). */
class HrPosition extends Model
{
    protected $fillable = ['territory_id', 'name', 'description', 'levels', 'grade_id', 'is_active', 'display_order', 'created_by'];

    protected $casts = ['levels' => 'array', 'is_active' => 'boolean'];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    /** Can it be used at this level? (No levels: anywhere.) */
    public function fitsLevel(string $level): bool
    {
        return ! $this->levels || in_array($level, $this->levels, true);
    }
}
