<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One follow-up with a visitor - a call, an SMS, a visit or a word at church
 * (docs/specs/people-and-care-spec.md, P2). The note is encrypted.
 */
class VisitorFollowup extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const TYPES = ['call' => 'Phone call', 'sms' => 'SMS', 'visit' => 'Home visit', 'met' => 'Met at church'];

    public const OUTCOMES = ['reached' => 'Reached them', 'will_come' => 'Will come again', 'no_answer' => 'No answer', 'not_interested' => 'Not interested', 'sent' => 'Message sent', 'other' => 'Other'];

    protected $fillable = ['person_id', 'territory_id', 'type', 'outcome', 'note', 'done_by', 'done_on', 'next_on'];

    protected $casts = ['done_on' => 'date', 'next_on' => 'date', 'note' => 'encrypted'];

    public function transformAudit(array $data): array
    {
        return Person::maskSecrets($data, ['note']);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function doer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
