<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A place's financial year and whether it is closed, with its closing journal (docs/specs/accounting-spec.md, A9). */
class AccountingYear extends Model
{
    protected $fillable = ['territory_id', 'year', 'status', 'closing_journal_id', 'surplus', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected $attributes = ['status' => 'open'];

    protected $casts = [
        'year' => 'integer',
        'surplus' => 'decimal:2',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];
}
