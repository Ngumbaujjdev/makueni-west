<?php

namespace App\Models;

use App\Enums\TerritoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Territory
{
    protected $table = 'territories';

    protected static function booted()
    {
        static::addGlobalScope('region', function (Builder $builder) {
            $builder->where('territory_type', TerritoryType::REGION);
        });

        static::creating(function ($model) {
            $model->territory_type = TerritoryType::REGION;
            $model->level = 2;
        });
    }

    // Region-specific relationships
    public function diocese(): BelongsTo
    {
        return $this->belongsTo(Diocese::class, 'parent_territory_id');
    }

    public function subregions(): HasMany
    {
        return $this->hasMany(Subregion::class, 'parent_territory_id');
    }

    public function churches(): HasMany
    {
        return $this->hasMany(Church::class, 'parent_territory_id');
    }

    // Check if region has subregions
    public function hasSubregions(): bool
    {
        return $this->subregions()->exists();
    }

    // Get all churches (direct + through subregions)
    public function allChurches()
    {
        $directChurches = $this->churches;
        $subregionChurches = $this->subregions->flatMap(function ($subregion) {
            return $subregion->churches;
        });

        return $directChurches->merge($subregionChurches);
    }

    // Region-specific methods
    public function getTotalChurches(): int
    {
        return $this->allChurches()->count();
    }

    public function getDirectChurches()
    {
        return $this->churches()->get();
    }
}
