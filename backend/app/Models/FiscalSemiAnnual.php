<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalSemiAnnual extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'number',
        'name',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function budgetPeriods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeByYear($query, int $fiscalYearId)
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }

    public function scopeByNumber($query, int $number)
    {
        return $query->where('number', $number);
    }
}
