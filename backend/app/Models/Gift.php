<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gift made on the public giving page (docs/specs/accounting-spec.md,
 * A10): by M-Pesa through the diocese paybill, or on Paystack's page.
 * Completed once - verified with the provider - and posted to the books.
 * A10c: which payout paid it, and a refund or dispute on it.
 */
class Gift extends Model
{
    public const STATUSES = ['pending' => 'Waiting for payment', 'paid' => 'Paid', 'failed' => 'Not paid', 'abandoned' => 'Abandoned', 'refunded' => 'Refunded'];

    protected $fillable = [
        'reference', 'territory_id', 'owner_territory_id', 'purpose', 'amount', 'giver_name', 'giver_phone', 'giver_email', 'method', 'provider_ref', 'status', 'channel',
        'fee', 'split', 'net', 'journal_id', 'diocese_journal_id', 'remittance_id', 'mpesa_request_id', 'mpesa_payment_id', 'paid_at', 'result', 'raw', 'ip',
        'settlement_id', 'main_settlement_id', 'refunded_amount', 'refunded_at', 'disputed_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'fee' => 'decimal:2', 'split' => 'decimal:2', 'net' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'paid_at' => 'datetime', 'refunded_at' => 'datetime', 'disputed_at' => 'datetime', 'raw' => 'array'];

    protected $hidden = ['raw', 'ip'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }
}
