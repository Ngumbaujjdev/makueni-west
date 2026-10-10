<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An "Ask to pay" - an M-Pesa prompt (STK) sent to a phone (A8). */
class MpesaRequest extends Model
{
    protected $fillable = ['channel_id', 'territory_id', 'account_ref', 'amount', 'phone', 'shortcode', 'requested_by', 'merchant_request_id', 'checkout_request_id', 'status', 'result', 'mpesa_payment_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
