<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** A member moving in from, or out to, another church (docs/specs/people-and-care-spec.md, P1). */
class PersonTransfer extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['person_id', 'territory_id', 'direction', 'other_church_id', 'other_church_name', 'on', 'reason', 'notified', 'created_by'];

    protected $casts = ['on' => 'date', 'notified' => 'boolean'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Who recorded it. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function otherChurch(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'other_church_id');
    }

    /** The other church's name - one in the system, or one typed in. */
    public function getOtherNameAttribute(): string
    {
        return $this->otherChurch?->name ?? ($this->other_church_name ?: 'Another church');
    }
}
