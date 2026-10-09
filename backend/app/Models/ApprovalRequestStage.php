<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request's own copy of one stage, frozen when it was submitted. */
class ApprovalRequestStage extends Model
{
    protected $fillable = ['request_id', 'stage_id', 'sequence', 'name', 'type', 'quorum', 'on_reject', 'rule_snapshot', 'status', 'blocked_reason', 'activated_at', 'completed_at'];

    protected $casts = ['rule_snapshot' => 'array', 'sequence' => 'integer', 'quorum' => 'integer', 'activated_at' => 'datetime', 'completed_at' => 'datetime'];

    public function assignments(): HasMany
    {
        return $this->hasMany(ApprovalAssignment::class, 'request_stage_id');
    }
}
