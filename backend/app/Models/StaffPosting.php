<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where someone worked, in what job, from when to when, and why it changed (docs/specs/hr-spec.md). */
class StaffPosting extends Model
{
    public const REASONS = ['hired' => 'Hired', 'transferred' => 'Transferred', 'changed' => 'Changed job', 'left' => 'Left'];

    protected $fillable = ['employee_id', 'territory_id', 'position', 'position_id', 'from_date', 'to_date', 'reason', 'note', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
