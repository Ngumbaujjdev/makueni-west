<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A visitor at one of our gatherings (docs/specs/people-and-care-spec.md,
 * P2). The prayer request is encrypted, and never written into the audits.
 */
class VisitorVisit extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['person_id', 'territory_id', 'gathering_type_id', 'on', 'first_time', 'wants_visit', 'prayer_request', 'created_by'];

    protected $casts = ['on' => 'date', 'first_time' => 'boolean', 'wants_visit' => 'boolean', 'prayer_request' => 'encrypted'];

    public function transformAudit(array $data): array
    {
        return Person::maskSecrets($data, ['prayer_request']);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function gatheringType(): BelongsTo
    {
        return $this->belongsTo(GatheringType::class);
    }
}
