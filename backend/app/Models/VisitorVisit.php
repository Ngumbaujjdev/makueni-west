<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A visitor at one of our gatherings (docs/specs/people-and-care-spec.md,
 * P2): who, when, and at which weekly service or gathering - nothing more.
 */
class VisitorVisit extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['person_id', 'territory_id', 'gathering_type_id', 'gathering_category_id', 'service_name', 'on', 'first_time', 'created_by'];

    protected $casts = ['on' => 'date', 'first_time' => 'boolean'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function gatheringType(): BelongsTo
    {
        return $this->belongsTo(GatheringType::class);
    }

    /** Where they came: the weekly service ("Sunday morning") or the gathering. */
    public function getGatheringLabelAttribute(): ?string
    {
        return $this->service_name ?: $this->gatheringType?->name;
    }
}
