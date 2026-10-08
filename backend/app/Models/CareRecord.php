<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Pastoral care given at a church (docs/specs/people-and-care-spec.md, P3):
 * for someone in the register or a name typed in. The note is encrypted and
 * never written into the audits; counselling is always confidential - only
 * its author and the confidential-care holders (the Senior Pastor) read it.
 */
class CareRecord extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    public const TYPES = [
        'home_visit' => ['Home visit', 'ri-home-heart-line', 'success'],
        'hospital' => ['Hospital', 'ri-hospital-line', 'danger'],
        'counselling' => ['Counselling', 'ri-chat-heart-line', 'purple'],
        'prayer' => ['Prayer', 'ri-hand-heart-line', 'pink'],
        'phone_call' => ['Phone call', 'ri-phone-line', 'primary'],
        'bereavement' => ['Bereavement', 'ri-heart-2-line', 'secondary'],
        'concern' => ['Concern', 'ri-error-warning-line', 'warning'],
    ];

    /** Care that counts as a visit in the monthly report's "Pastoral visits". */
    public const VISITS = ['home_visit', 'hospital', 'counselling', 'bereavement'];

    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed', 'answered' => 'Answered'];

    protected $fillable = [
        'territory_id', 'person_id', 'person_name', 'type', 'priority', 'status', 'on', 'hospital', 'discharged_on',
        'note', 'confidential', 'next_on', 'testimony', 'share_testimony', 'closed_on', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'on' => 'date', 'discharged_on' => 'date', 'next_on' => 'date', 'closed_on' => 'date',
        'note' => 'encrypted', 'confidential' => 'boolean', 'share_testimony' => 'boolean',
    ];

    protected $auditExclude = ['updated_by'];

    public function transformAudit(array $data): array
    {
        return Person::maskSecrets($data, ['note']);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function carers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'care_record_users');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CareContact::class)->orderByDesc('on')->orderByDesc('id');
    }

    /** Whose care it is: the register's name, or the one typed in. */
    public function getWhoAttribute(): string
    {
        return $this->person?->name ?? ($this->person_name ?: 'Someone');
    }
}
