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
