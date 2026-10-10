<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "I paid by Pay Bill - here's my M-Pesa code" (A10f), checked with Safaricom before it is recorded. */
class PaymentClaim extends Model
{
    public const STATUSES = ['checking' => 'Checking with Safaricom', 'waiting' => 'Waiting for the treasurer', 'confirmed' => 'Confirmed', 'failed' => 'Not found'];

    protected $fillable = ['trans_id', 'territory_id', 'purpose', 'giver_name', 'giver_phone', 'amount', 'status', 'result', 'conversation_id', 'originator_id', 'mpesa_payment_id', 'claimed_by', 'ip', 'raw'];

    protected $casts = ['amount' => 'decimal:2', 'raw' => 'array'];

    protected $hidden = ['raw', 'ip'];
}
