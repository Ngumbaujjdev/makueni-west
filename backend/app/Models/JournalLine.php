<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One debit or credit of a journal (docs/specs/accounting-spec.md). */
class JournalLine extends Model
{
    protected $fillable = [
        'journal_id', 'territory_id', 'date', 'line_no', 'account_id', 'fund_id', 'budget_line_id', 'debit', 'credit', 'memo', 'for_territory_id',
    ];

    protected $casts = [
        'date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(AccountingFund::class);
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }
}
