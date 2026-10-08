<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A template of a place, to use again (docs/specs/messages-spec.md, L5c).
 * The diocese or a region can share one with every place below
 * (shared_below); a place's own copy remembers what it was copied from.
 */
class MessageTemplate extends Model
{
    protected $fillable = ['territory_id', 'name', 'channel', 'subject', 'body', 'shared_below', 'copied_from_id', 'created_by'];

    protected $casts = ['shared_below' => 'boolean'];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /**
     * What a place sees: its own, and what the places above it share.
     *
     * @param  int[]  $aboveIds
     */
    public function scopeVisibleTo(Builder $query, int $placeId, array $aboveIds): Builder
    {
        return $query->where(fn ($q) => $q->where('territory_id', $placeId)
            ->orWhere(fn ($q) => $q->whereIn('territory_id', $aboveIds ?: [0])->where('shared_below', true)));
    }
}
