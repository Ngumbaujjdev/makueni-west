<?php

namespace App\Models;

use App\Enums\TerritoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Church extends Territory
{
    protected $table = 'territories';

    protected static function booted()
    {
        static::addGlobalScope('church', function (Builder $builder) {
            $builder->where('territory_type', TerritoryType::CHURCH);
        });

        static::creating(function ($model) {
            $model->territory_type = TerritoryType::CHURCH;
            $model->level = 4;
        });
    }

    // Church-specific relationships
    public function parentTerritory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'parent_territory_id');
    }

    // Can belong to either Region or Subregion
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_territory_id')
            ->where('territory_type', TerritoryType::REGION);
    }

    public function subregion(): BelongsTo
    {
        return $this->belongsTo(Subregion::class, 'parent_territory_id')
            ->where('territory_type', TerritoryType::SUBREGION);
    }

    // Get the diocese this church belongs to
    public function diocese()
    {
        $parent = $this->parentTerritory;

        while ($parent && $parent->territory_type !== TerritoryType::DIOCESE) {
            $parent = $parent->parent;
        }

        return $parent;
    }

    // Church-specific methods
    public function isUnderSubregion(): bool
    {
        return $this->parentTerritory?->territory_type === TerritoryType::SUBREGION;
    }

    public function isUnderRegion(): bool
    {
        return $this->parentTerritory?->territory_type === TerritoryType::REGION;
    }

    public function getMembershipEstimate(): int
    {
        return $this->metadata['membership_estimate'] ?? 0;
    }

    public function getServiceTimes(): array
    {
        return $this->metadata['service_times'] ?? [];
    }

    public function getFacilities(): array
    {
        return $this->metadata['facilities'] ?? [];
    }

    public function getLeadershipStructure(): array
    {
        return $this->metadata['leadership_structure'] ?? [];
    }

    /**
     * Demographics data-entry mode: 'weekly_and_monthly' (default) or
     * 'monthly_only' - for churches with unreliable internet where weekly
     * attendance entry isn't realistic. Self-service, set by the Pastor.
     * See docs/specs/demographics-module-spec.md.
     */
    public function getAttendanceMode(): string
    {
        return $this->metadata['attendance_mode'] ?? 'weekly_and_monthly';
    }
}
