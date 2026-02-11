<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetLineItem extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'budget_id',
        'budget_line_id',
        'budget_category_id',
        'budgeted_amount',
        'actual_amount',
        'notes',
        'is_locked',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'budgeted_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'is_locked' => 'boolean',
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

    /**
     * Get locked line items
     */
    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    /**
     * Get unlocked line items
     */
    public function scopeUnlocked($query)
    {
        return $query->where('is_locked', false);
    }

    // ========================================================================
    // COMPUTED ATTRIBUTES
    // ========================================================================

    /**
     * Calculate variance (actual - budgeted)
     */
    protected function variance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->actual_amount - $this->budgeted_amount,
        );
    }

    /**
     * Calculate variance percentage
     */
    protected function variancePercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->budgeted_amount == 0) {
                    return 0;
                }
                return round(($this->variance / $this->budgeted_amount) * 100, 2);
            }
        );
    }

    /**
     * Check if over budget
     */
    protected function isOverBudget(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->actual_amount > $this->budgeted_amount,
        );
    }

    /**
     * Check if under budget
     */
    protected function isUnderBudget(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->actual_amount < $this->budgeted_amount,
        );
    }

    /**
     * Check if on budget
     */
    protected function isOnBudget(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->actual_amount == $this->budgeted_amount,
        );
    }

    /**
     * Check if editable
     */
    protected function isEditable(): Attribute
    {
        return Attribute::make(
            get: fn () => !$this->is_locked && $this->budget && $this->budget->is_editable,
        );
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

    /**
     * Update parent budget totals
     */
    public function updateBudgetTotals()
    {
        if (!$this->budget) {
            return;
        }

        $budget = $this->budget;

        // Calculate income totals
        $incomeBudgeted = $budget->budgetLineItems()
            ->income()
            ->sum('budgeted_amount');
        $incomeActual = $budget->budgetLineItems()
            ->income()
            ->sum('actual_amount');

        // Calculate expense totals
        $expenseBudgeted = $budget->budgetLineItems()
            ->expense()
            ->sum('budgeted_amount');
        $expenseActual = $budget->budgetLineItems()
            ->expense()
            ->sum('actual_amount');

        // Update budget
        $budget->update([
            'total_income_budgeted' => $incomeBudgeted,
            'total_income_actual' => $incomeActual,
            'total_expense_budgeted' => $expenseBudgeted,
            'total_expense_actual' => $expenseActual,
        ]);
    }
}
