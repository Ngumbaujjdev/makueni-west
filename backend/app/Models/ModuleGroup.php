<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ModuleGroup extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'order',
        'territory_scope',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'icon', 'order', 'territory_scope', 'description', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // === RELATIONSHIPS ===

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    // === QUERY SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTerritoryScope($query, string $scope)
    {
        return $query->where('territory_scope', $scope);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('name');
    }

    // === MUTATORS ===

    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug when creating
        static::creating(function ($moduleGroup) {
            if (empty($moduleGroup->slug)) {
                $moduleGroup->slug = Str::slug($moduleGroup->name);
            }
        });

        // Update slug when name changes
        static::updating(function ($moduleGroup) {
            if ($moduleGroup->isDirty('name')) {
                $moduleGroup->slug = Str::slug($moduleGroup->name);
            }
        });
    }

    // === HELPER METHODS ===

    public function getActiveModulesAttribute()
    {
        return $this->modules()->active()->get();
    }

    public function getTotalModulesCountAttribute()
    {
        return $this->modules()->count();
    }

    public function getActiveModulesCountAttribute()
    {
        return $this->modules()->where('is_active', true)->count();
    }
}
