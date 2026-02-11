<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetPeriod extends Model
{
    protected $fillable = [
        'budget_type_id',
        'fiscal_year_id',
        'fiscal_month_id',
        'fiscal_quarter_id',
        'fiscal_semi_annual_id',
        'name',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function budgetType(): BelongsTo
    {
        return $this->belongsTo(BudgetType::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function fiscalMonth(): BelongsTo
    {
        return $this->belongsTo(FiscalMonth::class);
    }

    public function fiscalQuarter(): BelongsTo
    {
        return $this->belongsTo(FiscalQuarter::class);
    }

    public function fiscalSemiAnnual(): BelongsTo
    {
        return $this->belongsTo(FiscalSemiAnnual::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, int $budgetTypeId)
    {
        return $query->where('budget_type_id', $budgetTypeId);
    }

    public function scopeByYear($query, int $fiscalYearId)
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }

    public function scopeMonthly($query)
    {
        return $query->whereNotNull('fiscal_month_id');
    }

    public function scopeQuarterly($query)
    {
        return $query->whereNotNull('fiscal_quarter_id');
    }

    public function scopeSemiAnnual($query)
    {
        return $query->whereNotNull('fiscal_semi_annual_id');
    }

    public function scopeYearly($query)
    {
        return $query->whereNull('fiscal_month_id')
                     ->whereNull('fiscal_quarter_id')
                     ->whereNull('fiscal_semi_annual_id');
    }
}
