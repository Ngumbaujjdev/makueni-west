<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What someone decided, and why. */
class ApprovalDecision extends Model
{
    protected $fillable = ['assignment_id', 'actor_id', 'decision', 'comment'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
