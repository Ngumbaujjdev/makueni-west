<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an imported bank or M-Pesa statement, matched to a book line or not. */
class BankStatementLine extends Model
{
    protected $fillable = ['reconciliation_id', 'line_no', 'date', 'description', 'reference', 'money_in', 'money_out', 'balance', 'matched_line_id', 'status'];

    protected $casts = [
        'date' => 'date',
        'money_in' => 'decimal:2',
        'money_out' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function matchedLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_line_id');
    }
}
