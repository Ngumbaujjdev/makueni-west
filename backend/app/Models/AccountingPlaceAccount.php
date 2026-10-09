<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place's own settings for one of its money accounts (docs/specs/accounting-spec.md, A2):
 * the petty cash float and who keeps it, and how its bank statement CSV reads.
 * Separate from the account because standard accounts (Petty cash) are shared by every place.
 */
class AccountingPlaceAccount extends Model
{
    protected $fillable = ['territory_id', 'account_id', 'imprest_float', 'custodian_id', 'statement_mapping'];

    protected $casts = [
        'imprest_float' => 'decimal:2',
        'statement_mapping' => 'array',
    ];

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }
}
