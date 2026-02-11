<?php

namespace App\Models;

use App\Enums\TerritoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subregion extends Territory
{
    protected $table = 'territories';

    protected static function booted()
    {
        static::addGlobalScope('subregion', function (Builder $builder) {
            $builder->where('territory_type', TerritoryType::SUBREGION);
        });

        static::creating(function ($model) {
            $model->territory_type = TerritoryType::SUBREGION;
            $model->level = 3;
        });
    }

    // Subregion-specific relationships
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_territory_id');
    }

    public function churches(): HasMany
    {
        return $this->hasMany(Church::class, 'parent_territory_id');
    }

    public function diocese(): BelongsTo
    {
        return $this->belongsTo(Diocese::class, 'parent_territory_id')
            ->through('region');
    }

    // Subregion-specific methods
    public function getTotalChurches(): int
    {
        return $this->churches()->count();
    }

    public function getActiveChurches()
    {
        return $this->churches()->where('is_active', true)->get();
    }
}
