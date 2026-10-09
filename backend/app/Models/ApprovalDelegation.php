<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "While I'm away, X approves for me" - between two dates, for every kind of document or one. */
class ApprovalDelegation extends Model
{
    protected $fillable = ['delegator_id', 'delegate_id', 'subject_type', 'starts_at', 'ends_at', 'reason', 'is_active', 'created_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
