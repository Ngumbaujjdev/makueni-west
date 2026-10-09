<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fund money belongs to - General, or a restricted one like the Building fund (docs/specs/accounting-spec.md). */
class AccountingFund extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_restricted', 'equity_account_id', 'is_active', 'display_order'];

    protected $casts = [
        'is_restricted' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function equityAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'equity_account_id');
    }
}
