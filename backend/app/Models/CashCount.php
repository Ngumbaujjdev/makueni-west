<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash counted against the book (docs/specs/accounting-spec.md, A2): the
 * notes and coins, what the book said, and the difference - which needs a
 * second person before its adjustment is posted.
 */
class CashCount extends Model
{
    /** Notes and coins in circulation in Kenya, largest first. */
    public const DENOMINATIONS = ['1000', '500', '200', '100', '50', '40', '20', '10', '5', '1'];

    public const STATUSES = ['balanced' => 'Balanced', 'waiting' => 'Difference - waiting for approval', 'approved' => 'Difference approved', 'rejected' => 'Sent back - count again'];

    protected $fillable = [
        'territory_id', 'account_id', 'counted_on', 'denominations', 'counted_total', 'book_balance', 'difference', 'reason', 'is_surprise',
        'status', 'counted_by', 'approved_by', 'approved_at', 'reject_reason', 'journal_id',
    ];

    protected $casts = [
        'counted_on' => 'date',
        'denominations' => 'array',
        'counted_total' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'is_surprise' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
