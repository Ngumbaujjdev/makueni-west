<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One document going through approval, with its own frozen copy of the workflow's stages. */
class ApprovalRequest extends Model
{
    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Sent back for changes', 'cancelled' => 'Cancelled'];

    protected $fillable = ['workflow_id', 'workflow_version', 'workflow_name', 'subject_type', 'subject_id', 'territory_id', 'requested_by', 'context', 'status', 'current_stage_sequence', 'completed_at'];

    protected $casts = ['context' => 'array', 'requested_by' => 'integer', 'current_stage_sequence' => 'integer', 'completed_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalRequestStage::class, 'request_id')->orderBy('sequence');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ApprovalAssignment::class, 'request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class, 'request_id')->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
