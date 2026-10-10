<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What was paid out of a run (A7): the staff's net pay, with its voucher. */
class PayrollPayment extends Model
{
    /** kind => [label, who it is paid to] */
    public const KINDS = ['net' => ['Net pay', 'The staff']];

    protected $fillable = ['payroll_run_id', 'kind', 'amount', 'payment_voucher_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }
}
