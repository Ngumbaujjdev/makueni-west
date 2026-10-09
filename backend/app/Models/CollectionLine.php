<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One kind of giving in a collection - Offering, Tithe, Building... - in cash and by M-Pesa. */
class CollectionLine extends Model
{
    protected $fillable = ['collection_id', 'label', 'account_id', 'fund_id', 'cash_amount', 'mpesa_amount'];

    protected $casts = ['cash_amount' => 'decimal:2', 'mpesa_amount' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(AccountingFund::class);
    }
}
