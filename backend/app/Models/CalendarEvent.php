<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One calendar event, owned by one territory (docs/specs/calendar-spec.md).
 * Events owned by the national territory are the CCI calendar.
 */
class CalendarEvent extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    /** What an event is - key => label (the import accepts either). */
    public const KINDS = [
        'conference' => 'Conference',
        'fasting_prayer' => 'Fasting & prayer',
        'service' => 'Service',
        'meeting' => 'Meeting',
        'deadline' => 'Deadline',
        'holiday' => 'Holiday',
        'celebration' => 'Celebration',
        'other' => 'Other',
    ];

    public const REPEATS = ['none', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'uid', 'territory_id', 'title', 'description', 'kind', 'starts_on', 'ends_on', 'all_day', 'start_time', 'end_time',
        'location', 'repeats', 'repeat_until', 'shared_below', 'source', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'repeat_until' => 'date:Y-m-d',
        'all_day' => 'boolean',
        'shared_below' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->uid ??= (string) Str::uuid());
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /** The national territory - the owner of the CCI calendar. */
    public static function national(): ?Territory
    {
        return Territory::where('territory_type', 'global')->orderBy('id')->first();
    }

    public function isCci(): bool
    {
        return $this->territory?->territory_type?->value === 'global';
    }
}
