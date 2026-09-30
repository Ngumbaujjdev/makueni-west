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
        'budget_type_id',
        'budget_period_id',
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
        'status_id',
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
        'submitted_at',
        'approved_at',
        'approved_by',
        'approval_notes',
        'rejection_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
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
     * Get the budget type
     */
    public function budgetType(): BelongsTo
    {
        return $this->belongsTo(BudgetType::class);
    }

    /**
     * Get the budget period
     */
    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class);
    }

    /**
     * Get the status (renamed to avoid conflict with status enum column)
     */
    public function statusRelation(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

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

    /**
     * Get the user who approved this budget
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all logs for this budget
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
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
    // SCOPES
    // ========================================================================

    /**
     * Filter by territory type
     */
    public function scopeByTerritoryType($query, string $type)
    {
        return $query->where('territory_type', $type);
    }

    /**
     * Filter by territory ID
     */
    public function scopeByTerritoryId($query, int $id)
    {
        return $query->where('territory_id', $id);
    }

    /**
     * Filter by fiscal year
     */
    public function scopeByFiscalYear($query, int $year)
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * Filter by status (enum)
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Filter by status ID
     */
    public function scopeByStatusId($query, int $statusId)
    {
        return $query->where('status_id', $statusId);
    }

    /**
     * Get only active budgets
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get only draft budgets
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    // ========================================================================
    // COMPUTED ATTRIBUTES
    // ========================================================================

    /**
     * Total budgeted amount (income + expense)
     */
    protected function totalBudgeted(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_income_budgeted + $this->total_expense_budgeted,
        );
    }

    /**
     * Total actual amount (income + expense)
     */
    protected function totalActual(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_income_actual + $this->total_expense_actual,
        );
    }

    /**
     * Budget balance (income - expense)
     */
    protected function budgetedBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_income_budgeted - $this->total_expense_budgeted,
        );
    }

    /**
     * Actual balance (income - expense)
     */
    protected function actualBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total_income_actual - $this->total_expense_actual,
        );
    }

    /**
     * Variance percentage
     */
    protected function variancePercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->total_budgeted == 0) {
                    return 0;
                }
                $variance = $this->total_actual - $this->total_budgeted;

                return round(($variance / $this->total_budgeted) * 100, 2);
            }
        );
    }

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

    /**
     * Net balance (income - expense - deductions)
     */
    protected function netBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->net_income_budgeted - $this->total_expense_budgeted,
        );
    }

    // ========================================================================
    // LOGGING METHODS
    // ========================================================================

    /**
     * Create a log entry for this budget
     */
    public function log(string $action, string $description, array $data = []): BudgetLog
    {
        return BudgetLog::create([
            'budget_id' => $this->id,
            'action' => $action,
            'description' => $description,
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'affected_model' => $data['affected_model'] ?? null,
            'affected_model_id' => $data['affected_model_id'] ?? null,
            'amount_change' => $data['amount_change'] ?? null,
            'performed_by' => auth()->id() ?? $data['performed_by'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => $data['notes'] ?? null,
            'is_reversal' => $data['is_reversal'] ?? false,
            'reversal_of_log_id' => $data['reversal_of_log_id'] ?? null,
        ]);
    }

    /**
     * Recalculate deduction totals
     */
    public function recalculateDeductions(): void
    {
        $totalDeductions = $this->budgetDeductionItems()
            ->where('is_applied', true)
            ->where('is_reversed', false)
            ->sum('deduction_amount');

        $this->update([
            'total_deductions' => $totalDeductions,
            'net_income_budgeted' => $this->total_income_budgeted - $totalDeductions,
            'net_income_actual' => $this->total_income_actual - $totalDeductions,
        ]);
    }

    /**
     * Apply a deduction to this budget
     */
    public function applyDeduction(int $deductionId, float $amount, ?string $notes = null): BudgetDeductionItem
    {
        $deduction = BudgetDeduction::findOrFail($deductionId);

        // Check if deduction already exists for this budget
        $existingItem = $this->budgetDeductionItems()
            ->where('budget_deduction_id', $deductionId)
            ->first();

        if ($existingItem) {
            throw new \Exception("Deduction '{$deduction->name}' has already been applied to this budget");
        }

        // Create deduction item
        $deductionItem = BudgetDeductionItem::create([
            'budget_id' => $this->id,
            'budget_deduction_id' => $deductionId,
            'deduction_amount' => $amount,
            'notes' => $notes,
            'is_applied' => false,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // Apply it
        $deductionItem->apply();

        return $deductionItem;
    }

    /**
     * Reverse a deduction
     */
    public function reverseDeduction(int $deductionItemId, string $reason): void
    {
        $deductionItem = $this->budgetDeductionItems()->findOrFail($deductionItemId);

        if ($deductionItem->is_reversed) {
            throw new \Exception('This deduction has already been reversed');
        }

        if (! $deductionItem->is_applied) {
            throw new \Exception("Cannot reverse a deduction that hasn't been applied");
        }

        $deductionItem->reverse($reason, auth()->id());
    }
}
