<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One stage of a workflow: who approves (its steps), how many must, what happens on a no, how long they have. */
class ApprovalStage extends Model
{
    protected $fillable = ['workflow_id', 'sequence', 'name', 'type', 'quorum', 'on_reject', 'on_empty', 'sla_hours', 'escalate_after_hours', 'escalate_to'];

    protected $casts = ['escalate_to' => 'array', 'sequence' => 'integer', 'quorum' => 'integer', 'sla_hours' => 'integer', 'escalate_after_hours' => 'integer'];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'stage_id')->orderBy('id');
    }
}
