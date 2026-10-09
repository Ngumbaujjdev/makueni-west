<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Someone on a duty's team (P5 round 4): a person from the register, or a
 * typed name. The rota takes turns through a team in `position` order.
 */
class DutyTeamMember extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const MAX = 60;

    protected $fillable = ['territory_id', 'duty', 'person_id', 'name', 'position', 'added_by'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function getWhoAttribute(): string
    {
        return $this->person?->name ?? ($this->name ?: 'Someone');
    }

    /** Off the rota for now: the person left, moved, passed on or was archived (a typed name never is). */
    public function getAwayAttribute(): bool
    {
        $p = $this->person;

        return $p !== null && ($p->trashed() || $p->archived_at || $p->anonymised_at || ! in_array($p->status, ['member', 'visitor'], true));
    }
}
