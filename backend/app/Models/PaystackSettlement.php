<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Paystack payout into a place's bank (A10a), recorded once as a transfer out of clearing. */
class PaystackSettlement extends Model
{
    protected $fillable = ['settlement_id', 'territory_id', 'amount', 'settled_on', 'journal_id', 'raw'];

    protected $casts = ['amount' => 'decimal:2', 'settled_on' => 'date', 'raw' => 'array'];
}
