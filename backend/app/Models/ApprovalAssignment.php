<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One person asked to decide at one stage. */
class ApprovalAssignment extends Model
{
    protected $fillable = ['request_id', 'request_stage_id', 'approver_id', 'resolver_type', 'delegated_from', 'escalated_from', 'status', 'due_at', 'reminded_at', 'escalated_at', 'superseded_at'];

    protected $casts = ['approver_id' => 'integer', 'due_at' => 'datetime', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime', 'superseded_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStage::class, 'request_stage_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'assignment_id');
    }
}
