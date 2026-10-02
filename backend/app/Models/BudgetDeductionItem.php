<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A deduction as worked out on one budget: a snapshot of its rule (rate,
 * base) and amount, kept by Services\Budgets\Deductions on every save.
 */
class BudgetDeductionItem extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'budget_id',
        'budget_deduction_id',
        'deduction_amount',
        'rate_type',
        'rate_value',
        'base_amount',
        'notes',
        'is_applied',
        'applied_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'deduction_amount' => 'decimal:2',
        'is_applied' => 'boolean',
        'applied_at' => 'datetime',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Get the budget this deduction item belongs to
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the deduction template
     */
    public function budgetDeduction(): BelongsTo
    {
        return $this->belongsTo(BudgetDeduction::class);
    }

    /**
     * Get the user who created this item
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this item
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
     * Get only applied deductions
     */
    public function scopeApplied($query)
    {
        return $query->where('is_applied', true);
    }

    /**
     * Get only pending deductions
     */
    public function scopePending($query)
    {
        return $query->where('is_applied', false);
    }
}
