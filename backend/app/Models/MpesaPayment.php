<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment into the diocese paybill (docs/specs/accounting-spec.md, A8), or
 * a church's own paybill (A10b, channel_id) -
 * one per M-Pesa code: who paid, the account number they typed, the place
 * and purpose it was for, and the journals it made.
 */
class MpesaPayment extends Model
{
    public const STATUSES = ['posted' => 'In the books', 'to_sort' => 'To sort', 'returned' => 'Returned to the payer'];

    protected $fillable = [
        'channel_id', 'remittance_id', 'trans_id', 'kind', 'shortcode', 'amount', 'phone', 'payer_name', 'bill_ref', 'paid_at', 'territory_id', 'owner_territory_id', 'purpose', 'account_id', 'fund_id',
        'status', 'note', 'diocese_journal_id', 'place_journal_id', 'sort_journal_id', 'return_voucher_id', 'sorted_by', 'sorted_at', 'mpesa_request_id', 'raw',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'sorted_at' => 'datetime', 'raw' => 'array'];

    protected $hidden = ['raw'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id');
    }

    public function sorter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sorted_by');
    }
}
