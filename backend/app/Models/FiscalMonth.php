<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalMonth extends Model
{
    protected $fillable = [
        'number',
        'name',
        'short_name',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function budgetPeriods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeByNumber($query, int $number)
    {
        return $query->where('number', $number);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get month start date for a given year
     */
    public function getStartDateForYear(int $year): string
    {
        return sprintf('%d-%02d-01', $year, $this->number);
    }

    /**
     * Get month end date for a given year
     */
    public function getEndDateForYear(int $year): string
    {
        $date = \Carbon\Carbon::create($year, $this->number, 1);
        return $date->endOfMonth()->format('Y-m-d');
    }
}
