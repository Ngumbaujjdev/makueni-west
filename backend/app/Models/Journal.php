<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A posted document in a place's books - a receipt, payment, transfer,
 * journal voucher or the reversal of one (docs/specs/accounting-spec.md).
 * Written only through App\Services\Accounting\Ledger, which checks that it
 * balances. Never changed or deleted once posted: a mistake is reversed.
 */
class Journal extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const TYPES = ['receipt' => 'Receipt', 'payment' => 'Payment', 'transfer' => 'Transfer', 'journal' => 'Journal', 'reversal' => 'Reversal', 'petty_cash' => 'Petty cash voucher', 'bill' => 'Supplier bill', 'payroll' => 'Payroll', 'closing' => 'Year-end close'];

    public const PREFIXES = ['receipt' => 'RCT', 'payment' => 'PAY', 'transfer' => 'TRF', 'journal' => 'JV', 'reversal' => 'REV', 'voucher' => 'PV', 'petty_cash' => 'PCV', 'requisition' => 'REQ', 'bill' => 'BILL', 'order' => 'LPO', 'delivery' => 'GRN', 'remittance' => 'REM', 'payroll' => 'PRL', 'closing' => 'YEC'];

    public const METHODS = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque', 'card' => 'Card (online)', 'airtel' => 'Airtel Money'];

    public const MAX_ATTACHMENTS = 5;

    protected $fillable = [
        'territory_id', 'number', 'doc_type', 'date', 'narration', 'party_name', 'party_phone', 'method', 'reference',
        'amount', 'source_type', 'source_id', 'status', 'reverses_id', 'reversed_by_id', 'reverse_reason', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
