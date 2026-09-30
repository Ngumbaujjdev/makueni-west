<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetLine extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'budget_category_id',
        'name',
        'slug',
        'territory_scope',
        'territory_type',
        'territory_id',
        'description',
        'is_system_default',
        'is_active',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_system_default' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * Get the budget category this line belongs to
     */
    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    /**
     * Get the user who created this line
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this line
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get all budget line items using this line
     */
    public function budgetLineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    /**
     * Scope to get only active budget lines
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Shared diocese lines (no owning church).
     */
    public function scopeShared($query)
    {
        return $query->whereNull('territory_id');
    }

    /**
     * The lines a place can use: the diocese's shared lines that apply to its
     * level (church, region or diocese), plus that place's own lines - never
     * another place's.
     */
    public function scopeForPlace($query, string $type, int $id)
    {
        return $query->where(function ($q) use ($type, $id) {
            $q->where(fn ($shared) => $shared->whereNull('territory_id')->whereIn('territory_scope', [$type, 'all']))
                ->orWhere(fn ($own) => $own->where('territory_type', $type)->where('territory_id', $id));
        });
    }

    public function scopeForChurch($query, int $churchId)
    {
        return $query->forPlace('church', $churchId);
    }

    public function isOwnedBy(int $churchId): bool
    {
        return $this->territory_type === 'church' && (int) $this->territory_id === $churchId;
    }

    /**
     * Scope to filter by territory scope
     */
    public function scopeByTerritoryScope($query, $scope)
    {
        return $query->where(function ($q) use ($scope) {
            $q->where('territory_scope', $scope)
                ->orWhere('territory_scope', 'all');
        });
    }

    /**
     * Scope to get system default lines
     */
    public function scopeSystemDefaults($query)
    {
        return $query->where('is_system_default', true);
    }

    /**
     * Scope to get user-created lines
     */
    public function scopeUserCreated($query)
    {
        return $query->where('is_system_default', false);
    }
}
