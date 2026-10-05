<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One meeting of an initiative: its date, topic, and once held how many came
 * (counts, not people) - docs/specs/events-initiatives-spec.md, L2.
 */
class ActivitySession extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const STATUSES = ['planned', 'held', 'cancelled'];

    protected $fillable = ['activity_id', 'number', 'held_on', 'topic', 'status', 'youth', 'adults', 'children', 'leaders', 'notes', 'updated_by'];

    protected $casts = ['held_on' => 'date'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** People at the session, or null while nothing is recorded. */
    public function attendance(): ?int
    {
        $values = array_map(fn ($g) => $this->{$g}, ActivityRegistration::GROUPS);

        return array_filter($values, fn ($v) => $v !== null) === [] ? null : array_sum(array_map('intval', $values));
    }

    /** Untouched: still planned and nothing recorded - safe to replace when the schedule changes. */
    public function untouched(): bool
    {
        return $this->status === 'planned' && $this->attendance() === null;
    }
}
