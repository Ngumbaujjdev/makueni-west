<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who approves what (docs/specs/accounting-spec.md, A4): a workflow for a kind
 * of document ('*' = any money document), at one level or all, chosen by
 * conditions such as the amount, with its stages in order.
 */
class ApprovalWorkflow extends Model
{
    use SoftDeletes;

    protected $fillable = ['key', 'name', 'subject_type', 'level', 'applies_when', 'match_priority', 'version', 'is_active', 'created_by', 'updated_by'];

    protected $casts = ['applies_when' => 'array', 'is_active' => 'boolean', 'match_priority' => 'integer', 'version' => 'integer'];

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class, 'workflow_id')->orderBy('sequence');
    }
}
