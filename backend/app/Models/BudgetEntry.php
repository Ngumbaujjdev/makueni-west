<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money that actually came in (direction "in") or went out ("out") against
 * one line of a budget. Written through BudgetBook, which keeps the line's
 * and the budget's received/spent totals in step.
 */
class BudgetEntry extends Model
{
    use SoftDeletes;

    public const METHODS = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque'];

    protected $fillable = [
        'budget_id', 'budget_line_item_id', 'direction', 'amount', 'entry_date',
        'description', 'counterparty', 'method', 'reference', 'recorded_by', 'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'budget_line_item_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
