<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OwenIt\Auditing\Contracts\Auditable;

class ChurchAttendanceRecord extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'territory_type',
        'territory_id',
        'service_date',
        'fiscal_year_id',
        'fiscal_month_id',
        'service_type',
        'event_name',
        'adults_count',
        'youth_count',
        'children_male_count',
        'children_female_count',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'adults_count' => 'integer',
        'youth_count' => 'integer',
        'children_male_count' => 'integer',
        'children_female_count' => 'integer',
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

    public function scopeByServiceType($query, string $type)
    {
        return $query->where('service_type', $type);
    }

    public function scopeSundayServices($query)
    {
        return $query->where('service_type', 'sunday_service');
    }

    public function scopeForPeriod($query, int $fiscalYearId, int $fiscalMonthId)
    {
        return $query->where('fiscal_year_id', $fiscalYearId)
            ->where('fiscal_month_id', $fiscalMonthId);
    }

    // ========================================================================
    // COMPUTED ATTRIBUTES
    // ========================================================================

    protected function childrenCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->children_male_count + $this->children_female_count,
        );
    }

    protected function totalCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->adults_count + $this->youth_count
                + $this->children_male_count + $this->children_female_count,
        );
    }
}
