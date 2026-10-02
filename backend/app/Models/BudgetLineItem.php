<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetLineItem extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'budget_id',
        'budget_line_id',
        'budget_deduction_id',
        'budget_category_id',
        'budgeted_amount',
        'actual_amount',
        'notes',
        'is_unplanned',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_unplanned' => 'boolean',
        'budgeted_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Get the budget this line item belongs to
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the budget line (template)
     */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class)->with('budgetCategory');
    }

    /**
     * Get the budget category
     */
    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    /**
     * Get the user who created this line item
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this line item
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Filter by budget
     */
    public function scopeByBudget($query, int $budgetId)
    {
        return $query->where('budget_id', $budgetId);
    }

    /**
     * Filter by category
     */
    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('budget_category_id', $categoryId);
    }

    /**
     * Get only income line items
     */
    public function scopeIncome($query)
    {
        return $query->whereHas('budgetCategory', function ($q) {
            $q->where('slug', 'income');
        });
    }

    /**
     * Get only expense line items
     */
    public function scopeExpense($query)
    {
        return $query->whereHas('budgetCategory', function ($q) {
            $q->where('slug', 'expense');
        });
    }

    // ========================================================================
    // MODEL EVENTS
    // ========================================================================

    protected static function boot()
    {
        parent::boot();

        // Auto-update budget totals when line item is saved
        static::saved(function ($lineItem) {
            $lineItem->updateBudgetTotals();
        });

        // Auto-update budget totals when line item is deleted
        static::deleted(function ($lineItem) {
            $lineItem->updateBudgetTotals();
        });
    }

    /** Money recorded against this line. */
    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BudgetEntry::class);
    }

    /**
     * Update parent budget totals
     */
    public function updateBudgetTotals()
    {
        if ($this->budget) {
            app(\App\Services\Budgets\BudgetBook::class)->recalculate($this->budget);
        }
    }
}
