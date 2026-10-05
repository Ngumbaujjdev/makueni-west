<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * How many a place below is bringing to an event (counts, not people), its
 * fee, and afterwards how many came and how it went.
 */
class ActivityRegistration extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const GROUPS = ['youth', 'adults', 'children', 'leaders'];

    protected $fillable = [
        'activity_id', 'territory_id', 'youth', 'adults', 'children', 'leaders', 'names', 'fee_due', 'fee_paid',
        'came_youth', 'came_adults', 'came_children', 'came_leaders', 'completed', 'rating', 'comment', 'status', 'registered_by', 'updated_by',
    ];

    protected $casts = ['fee_due' => 'decimal:2', 'fee_paid' => 'decimal:2'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function expected(): int
    {
        return array_sum(array_map(fn ($g) => (int) $this->{$g}, self::GROUPS));
    }

    public function came(): ?int
    {
        $values = array_map(fn ($g) => $this->{"came_{$g}"}, self::GROUPS);

        // Nothing recorded yet - null, not 0.
        return array_filter($values, fn ($v) => $v !== null) === [] ? null : array_sum(array_map('intval', $values));
    }
}
