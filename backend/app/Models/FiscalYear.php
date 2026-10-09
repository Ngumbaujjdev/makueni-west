<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class FiscalYear extends Model
{
    protected $fillable = [
        'year',
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

    public function quarters(): HasMany
    {
        return $this->hasMany(FiscalQuarter::class);
    }

    public function semiAnnuals(): HasMany
    {
        return $this->hasMany(FiscalSemiAnnual::class);
    }

    public function budgetPeriods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    // =========================================================================
    // BOOT - Auto-generate quarters and semi-annuals on create
    // =========================================================================

    protected static function boot()
    {
        parent::boot();

        static::created(function (FiscalYear $fiscalYear) {
            $fiscalYear->generateQuarters();
            $fiscalYear->generateSemiAnnuals();
        });
    }

    // =========================================================================
    // AUTO-GENERATION METHODS
    // =========================================================================

    /**
     * Generate 4 quarters for this fiscal year
     */
    public function generateQuarters(): void
    {
        $year = $this->year;

        $quarters = [
            ['number' => 1, 'name' => "Q1 {$year}", 'start' => "{$year}-01-01", 'end' => "{$year}-03-31"],
            ['number' => 2, 'name' => "Q2 {$year}", 'start' => "{$year}-04-01", 'end' => "{$year}-06-30"],
            ['number' => 3, 'name' => "Q3 {$year}", 'start' => "{$year}-07-01", 'end' => "{$year}-09-30"],
            ['number' => 4, 'name' => "Q4 {$year}", 'start' => "{$year}-10-01", 'end' => "{$year}-12-31"],
        ];

        foreach ($quarters as $q) {
            FiscalQuarter::firstOrCreate(
                ['fiscal_year_id' => $this->id, 'number' => $q['number']],
                ['name' => $q['name'], 'start_date' => $q['start'], 'end_date' => $q['end']]
            );
        }
    }

    /**
     * Generate 2 semi-annuals for this fiscal year
     */
    public function generateSemiAnnuals(): void
    {
        $year = $this->year;

        $halves = [
            ['number' => 1, 'name' => "H1 {$year}", 'start' => "{$year}-01-01", 'end' => "{$year}-06-30"],
            ['number' => 2, 'name' => "H2 {$year}", 'start' => "{$year}-07-01", 'end' => "{$year}-12-31"],
        ];

        foreach ($halves as $h) {
            FiscalSemiAnnual::firstOrCreate(
                ['fiscal_year_id' => $this->id, 'number' => $h['number']],
                ['name' => $h['name'], 'start_date' => $h['start'], 'end_date' => $h['end']]
            );
        }
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeYear($query, int $year)
    {
        return $query->where('year', $year);
    }
}
