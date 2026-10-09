<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a payment voucher pays for: the account it is charged to, the fund and an amount. */
class PaymentVoucherLine extends Model
{
    protected $fillable = ['payment_voucher_id', 'account_id', 'fund_id', 'budget_line_id', 'description', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

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
