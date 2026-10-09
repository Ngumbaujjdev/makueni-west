<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A month of a place's books, open or closed (docs/specs/accounting-spec.md); nothing posts into a closed one. */
class AccountingPeriod extends Model
{
    protected $fillable = ['territory_id', 'year', 'month', 'status', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected $casts = [
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];
}
