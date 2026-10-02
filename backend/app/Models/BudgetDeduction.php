<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetDeduction extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'deduction_type',
        'deduction_value',
        'applies_to',
        'territory_scope',
        'territory_type',
        'territory_id',
        'applies_to_level',
        'budget_line_id',
        'basis',
        'basis_line_ids',
        'is_mandatory',
        'is_active',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'deduction_value' => 'decimal:2',
        'basis_line_ids' => 'array',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /** The money-out line it is paid through. */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    /**
     * Get all deduction items using this deduction
     */
    public function budgetDeductionItems(): HasMany
    {
        return $this->hasMany(BudgetDeductionItem::class);
    }

    /**
     * Get the user who created this deduction
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this deduction
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Get only active deductions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get only mandatory deductions
     */
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Filter by territory scope
     */
    public function scopeByTerritoryScope($query, string $scope)
    {
        return $query->where(function ($q) use ($scope) {
            $q->where('territory_scope', $scope)
                ->orWhere('territory_scope', 'all');
        });
    }

    /**
     * Filter by applies_to
     */
    public function scopeByAppliesTo($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('applies_to', $type)
                ->orWhere('applies_to', 'both');
        });
    }

    // ========================================================================
    // METHODS
    // ========================================================================

    /**
     * Calculate deduction amount for a given base amount
     */
    public function calculateDeduction(float $baseAmount): float
    {
        if ($this->deduction_type === 'percentage') {
            return ($baseAmount * $this->deduction_value) / 100;
        }

        // Fixed amount
        return $this->deduction_value;
    }
}
