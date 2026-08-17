<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OwenIt\Auditing\Contracts\Auditable;

class ChurchDemographic extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'territory_type',
        'territory_id',
        'fiscal_year_id',
        'fiscal_month_id',
        'total_members',
        'male_count',
        'female_count',
        'youth_count',
        'womens_fellowship_count',
        'mens_fellowship_count',
        'sunday_school_male_count',
        'sunday_school_female_count',
        'seniors_count',
        'new_members_count',
        'transferred_out_count',
        'baptisms_count',
        'communion_participants_count',
        'conversions_count',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_members' => 'integer',
        'male_count' => 'integer',
        'female_count' => 'integer',
        'youth_count' => 'integer',
        'womens_fellowship_count' => 'integer',
        'mens_fellowship_count' => 'integer',
        'sunday_school_male_count' => 'integer',
        'sunday_school_female_count' => 'integer',
        'seniors_count' => 'integer',
        'new_members_count' => 'integer',
        'transferred_out_count' => 'integer',
        'baptisms_count' => 'integer',
        'communion_participants_count' => 'integer',
        'conversions_count' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function territory(): MorphTo
    {
        return $this->morphTo();
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function fiscalMonth(): BelongsTo
    {
        return $this->belongsTo(FiscalMonth::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeByTerritoryId($query, int $id)
    {
        return $query->where('territory_id', $id);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForPeriod($query, int $fiscalYearId, int $fiscalMonthId)
    {
        return $query->where('fiscal_year_id', $fiscalYearId)
            ->where('fiscal_month_id', $fiscalMonthId);
    }

    // ========================================================================
    // COMPUTED ATTRIBUTES
    // ========================================================================

    protected function sundaySchoolCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->sunday_school_male_count + $this->sunday_school_female_count,
        );
    }

    protected function isEditable(): Attribute
    {
        return Attribute::make(
            get: fn () => in_array($this->status, ['draft', 'changes_requested']),
        );
    }

    protected function canBeSubmitted(): Attribute
    {
        return Attribute::make(
            get: fn () => in_array($this->status, ['draft', 'changes_requested']),
        );
    }
}
