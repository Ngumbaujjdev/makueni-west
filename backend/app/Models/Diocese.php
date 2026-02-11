<?php

namespace App\Models;

use App\Enums\TerritoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Diocese extends Territory
{
    protected $table = 'territories';

    protected static function booted()
    {
        static::addGlobalScope('diocese', function (Builder $builder) {
            $builder->where('territory_type', TerritoryType::DIOCESE);
        });

        static::creating(function ($model) {
            $model->territory_type = TerritoryType::DIOCESE;
            $model->level = 1;
        });
    }

    // Diocese-specific relationships
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_territory_id');
    }

    public function churches(): HasManyThrough
    {
        return $this->hasManyThrough(
            Church::class,
            Region::class,
            'parent_territory_id', // Foreign key on regions table
            'parent_territory_id', // Foreign key on churches table (can be region or subregion)
            'id', // Local key on dioceses table
            'id' // Local key on regions table
        );
    }

    // Get all churches in the diocese (including through subregions)
    public function allChurches()
    {
        return Church::whereHas('parent', function ($query) {
            $query->where('parent_territory_id', $this->id)
                  ->orWhereHas('parent', function ($q) {
                      $q->where('parent_territory_id', $this->id);
                  });
        })->get();
    }

    // Diocese-specific methods
    public function getTotalChurches(): int
    {
        return $this->allChurches()->count();
    }

    public function getTotalRegions(): int
    {
        return $this->regions()->count();
    }

    public function getActiveChurches()
    {
        return $this->allChurches()->where('is_active', true);
    }
}
