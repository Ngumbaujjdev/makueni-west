<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Budget extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'territory_type',
        'territory_id',
        'name',
        'slug',
        'description',
        'fiscal_year',
        'period_month',
        'start_date',
        'end_date',
        'status',
        'started_at',
        'started_by',
        'closed_at',
        'closed_by',
        'total_income_budgeted',
        'total_expense_budgeted',
        'total_income_actual',
        'total_expense_actual',
        'total_deductions',
        'net_income_budgeted',
        'net_income_actual',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_income_budgeted' => 'decimal:2',
        'total_expense_budgeted' => 'decimal:2',
        'total_income_actual' => 'decimal:2',
        'total_expense_actual' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_income_budgeted' => 'decimal:2',
        'net_income_actual' => 'decimal:2',
        'fiscal_year' => 'integer',
        'period_month' => 'integer',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** What a budget can be: still being prepared, in use, or done. No approval step. */
    public const STATUSES = ['draft', 'active', 'closed'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'active' => 'In use', 'closed' => 'Closed'];

    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** "January 2026", or "2026" for a whole-year budget. */
    public static function periodLabelFor(int $year, ?int $month): string
    {
        return $month ? self::MONTHS[$month].' '.$year : 'Whole of '.$year;
    }

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Get the territory (polymorphic - diocese, region, subregion, church)
     */
    public function territory(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all line items for this budget
     */
    public function budgetLineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    /**
     * Get the user who created this budget
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this budget
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** Money recorded against this budget's lines. */
    public function entries(): HasMany
    {
        return $this->hasMany(BudgetEntry::class);
    }

    public function budgetLogs(): HasMany
    {
        return $this->hasMany(BudgetLog::class);
    }

    /**
     * Get all deduction items for this budget
     */
    public function budgetDeductionItems(): HasMany
    {
        return $this->hasMany(BudgetDeductionItem::class);
    }

    // ========================================================================
    // COMPUTED ATTRIBUTES
    // ========================================================================

    /** Draft and in-use budgets can be changed; a closed one can't until it's reopened. */
    protected function isEditable(): Attribute
    {
        return Attribute::make(
            get: fn () => in_array($this->status, ['draft', 'active'], true),
        );
    }

    /** "January 2026" / "Whole of 2026". */
    protected function periodLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::periodLabelFor((int) $this->fiscal_year, $this->period_month),
        );
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status),
        );
    }
}
