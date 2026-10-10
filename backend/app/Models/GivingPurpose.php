<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A giving option (docs/specs/accounting-spec.md, A11): its key is what gifts,
 * claims and payments store; its suffix is the Pay Bill account-number ending
 * (SHR027T); its words are other endings givers type. No owner = standard,
 * kept by whichever place collects it.
 */
class GivingPurpose extends Model
{
    protected $fillable = ['territory_id', 'reach', 'key', 'label', 'suffix', 'words', 'account_id', 'fund_id', 'icon', 'colour',
        'display_order', 'is_active', 'is_default', 'created_by', 'updated_by'];

    protected $casts = ['words' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(AccountingFund::class, 'fund_id');
    }

    public function isStandard(): bool
    {
        return $this->territory_id === null;
    }
}
