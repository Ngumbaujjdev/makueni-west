<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** Who leads a ministry: a leader with a login (who can then look after it), or someone in the register. */
class MinistryLeader extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['ministry_id', 'user_id', 'person_id', 'role'];

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getNameAttribute(): string
    {
        return $this->user ? trim("{$this->user->firstname} {$this->user->lastname}") : ($this->person?->name ?? 'Someone');
    }
}
