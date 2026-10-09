<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank or M-Pesa account matched to its statement (docs/specs/accounting-spec.md, A2):
 * statement balance + deposits in transit - unpresented payments = the cashbook.
 * Prepared, then signed off by someone else.
 */
class BankReconciliation extends Model
{
    public const STATUSES = ['draft' => 'In progress', 'submitted' => 'Waiting for sign-off', 'approved' => 'Signed off', 'returned' => 'Sent back'];

    protected $fillable = [
        'territory_id', 'account_id', 'statement_date', 'statement_balance', 'book_balance', 'in_transit', 'unpresented', 'difference',
        'status', 'notes', 'created_by', 'prepared_by', 'prepared_at', 'approved_by', 'approved_at', 'return_reason',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'in_transit' => 'decimal:2',
        'unpresented' => 'decimal:2',
        'difference' => 'decimal:2',
        'prepared_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'reconciliation_id')->orderBy('date')->orderBy('line_no');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }
}
