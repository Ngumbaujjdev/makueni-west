<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetDeductionItem extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'budget_id',
        'budget_deduction_id',
        'deduction_amount',
        'notes',
        'is_applied',
        'applied_at',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'deduction_amount' => 'decimal:2',
        'is_applied' => 'boolean',
        'applied_at' => 'datetime',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
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

    /**
     * Get the user who reversed this deduction
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
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

    /**
     * Get only reversed deductions
     */
    public function scopeReversed($query)
    {
        return $query->where('is_reversed', true);
    }

    /**
     * Get active deductions (applied and not reversed)
     */
    public function scopeActive($query)
    {
        return $query->where('is_applied', true)
                     ->where('is_reversed', false);
    }

    // ========================================================================
    // METHODS
    // ========================================================================

    /**
     * Apply the deduction
     */
    public function apply(): void
    {
        $this->update([
            'is_applied' => true,
            'applied_at' => now(),
        ]);

        // Create log entry
        $this->budget->log(
            'deduction_applied',
            "Applied deduction: {$this->budgetDeduction->name}",
            [
                'affected_model' => 'BudgetDeductionItem',
                'affected_model_id' => $this->id,
                'amount_change' => -$this->deduction_amount,
                'new_values' => [
                    'deduction_name' => $this->budgetDeduction->name,
                    'amount' => $this->deduction_amount,
                ],
            ]
        );
    }

    /**
     * Reverse the deduction
     */
    public function reverse(string $reason, int $userId): void
    {
        $this->update([
            'is_reversed' => true,
            'reversed_at' => now(),
            'reversed_by' => $userId,
            'reversal_reason' => $reason,
        ]);

        // Create log entry
        $this->budget->log(
            'deduction_reversed',
            "Reversed deduction: {$this->budgetDeduction->name}",
            [
                'affected_model' => 'BudgetDeductionItem',
                'affected_model_id' => $this->id,
                'amount_change' => $this->deduction_amount,
                'notes' => $reason,
                'old_values' => [
                    'deduction_name' => $this->budgetDeduction->name,
                    'amount' => $this->deduction_amount,
                ],
            ]
        );
    }

    // ========================================================================
    // MODEL EVENTS
    // ========================================================================

    protected static function boot()
    {
        parent::boot();

        // Auto-update budget totals when deduction item is saved
        static::saved(function ($deductionItem) {
            $deductionItem->budget->recalculateDeductions();
        });

        // Auto-update budget totals when deduction item is deleted
        static::deleted(function ($deductionItem) {
            $deductionItem->budget->recalculateDeductions();
        });
    }
}
