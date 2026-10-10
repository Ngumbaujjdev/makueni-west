<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A month's paybill settlement to a place (A8): what the diocese held for it,
 * the diocese share netted off, and the net paid by a remittance.
 */
class PaybillSettlement extends Model
{
    protected $fillable = ['territory_id', 'month', 'held', 'share', 'net', 'status', 'remittance_id', 'share_remittance_id', 'diocese_journal_id', 'place_journal_id', 'prepared_by'];

    protected $casts = ['held' => 'decimal:2', 'share' => 'decimal:2', 'net' => 'decimal:2'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }
}
