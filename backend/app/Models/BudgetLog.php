<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetLog extends Model
{
    // NOTE: NOT Auditable - this IS the audit trail
    // NOTE: NO soft deletes - permanent record

    protected $fillable = [
        'budget_id',
        'action',
        'description',
        'old_values',
        'new_values',
        'affected_model',
        'affected_model_id',
        'amount_change',
        'performed_by',
        'ip_address',
        'user_agent',
        'notes',
        'is_reversal',
        'reversal_of_log_id',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'amount_change' => 'decimal:2',
        'is_reversal' => 'boolean',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Get the budget this log belongs to
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the user who performed this action
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Get the original log entry this is a reversal of
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(BudgetLog::class, 'reversal_of_log_id');
    }

    /**
     * Get all reversals of this log entry
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(BudgetLog::class, 'reversal_of_log_id');
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
     * Filter by action type
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Get only reversal entries
     */
    public function scopeReversals($query)
    {
        return $query->where('is_reversal', true);
    }

    /**
     * Get only non-reversal entries
     */
    public function scopeNonReversals($query)
    {
        return $query->where('is_reversal', false);
    }

    /**
     * Get recent logs
     */
    public function scopeRecent($query, int $limit = 20)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    // ========================================================================
    // METHODS
    // ========================================================================

    /**
     * Create a reversal log entry
     */
    public function createReversal(string $reason, int $userId): BudgetLog
    {
        return static::create([
            'budget_id' => $this->budget_id,
            'action' => $this->action === 'deduction_applied' ? 'deduction_reversed' : 'updated',
            'description' => "Reversal of: {$this->description}",
            'old_values' => $this->new_values,
            'new_values' => $this->old_values,
            'affected_model' => $this->affected_model,
            'affected_model_id' => $this->affected_model_id,
            'amount_change' => -$this->amount_change,
            'performed_by' => $userId,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => $reason,
            'is_reversal' => true,
            'reversal_of_log_id' => $this->id,
        ]);
    }

    // ========================================================================
    // BOOT
    // ========================================================================

    protected static function boot()
    {
        parent::boot();

        // Prevent updates and deletes - logs are immutable
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }
}
