<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Paystack payout into a place's bank (A10a), recorded once - a
 * successful one as a transfer out of clearing. A10c keeps every status
 * (a failed one is flagged, never posted) and which gifts it paid, matched
 * by day: the place's part through settlement_id, the diocese's main
 * account's part through main_settlement_id.
 */
class PaystackSettlement extends Model
{
    public const STATUSES = ['pending' => 'On the way', 'processing' => 'On the way', 'success' => 'Paid to the bank', 'processed' => 'Paid to the bank', 'failed' => 'Failed'];

    public const MATCHES = ['adds_up' => 'Adds up', 'closest' => 'Closest match', 'none' => 'Gifts not found'];

    protected $fillable = ['settlement_id', 'territory_id', 'status', 'amount', 'gross', 'fees', 'settled_on', 'covers_from', 'covers_to', 'matched', 'main', 'journal_id', 'raw'];

    protected $casts = ['amount' => 'decimal:2', 'gross' => 'decimal:2', 'fees' => 'decimal:2', 'settled_on' => 'date', 'covers_from' => 'date', 'covers_to' => 'date', 'main' => 'boolean', 'raw' => 'array'];

    protected $hidden = ['raw'];

    public function isPaid(): bool
    {
        return in_array($this->status, ['success', 'processed'], true);
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class, $this->main ? 'main_settlement_id' : 'settlement_id');
    }
}
