<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\TerritoryType;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SuperAdminConfig extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'primary_territory_id',
        'global_access',
        'default_territory_type',
        'preferences',
        'restricted_territories',
        'restricted_modules',
    ];

    protected $casts = [
        'global_access' => 'boolean',
        'default_territory_type' => TerritoryType::class,
        'preferences' => 'array',
        'restricted_territories' => 'array',
        'restricted_modules' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'primary_territory_id', 'global_access', 'default_territory_type'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // === RELATIONSHIPS ===

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryTerritory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'primary_territory_id');
    }

    // === HELPER METHODS ===

    public function hasGlobalAccess(): bool
    {
        return $this->global_access;
    }

    public function canAccessTerritory(int $territoryId): bool
    {
        if (!$this->global_access) {
            return false;
        }

        $restrictedTerritories = $this->restricted_territories ?? [];
        return !in_array($territoryId, $restrictedTerritories);
    }

    public function canAccessModule(int $moduleId): bool
    {
        $restrictedModules = $this->restricted_modules ?? [];
        return !in_array($moduleId, $restrictedModules);
    }

    public function getPreference(string $key, $default = null)
    {
        $preferences = $this->preferences ?? [];
        return $preferences[$key] ?? $default;
    }

    public function setPreference(string $key, $value): void
    {
        $preferences = $this->preferences ?? [];
        $preferences[$key] = $value;
        $this->update(['preferences' => $preferences]);
    }
}
