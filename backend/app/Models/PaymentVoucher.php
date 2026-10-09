<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A payment voucher (docs/specs/accounting-spec.md): prepared, authorised by
 * someone else, then paid - only paying posts it to the books. Supporting
 * papers (invoice, quote) and the payee's receipt hang off it.
 */
class PaymentVoucher extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUSES = ['prepared' => 'Waiting to be authorised', 'authorised' => 'Authorised - to pay', 'paid' => 'Paid', 'rejected' => 'Sent back', 'cancelled' => 'Cancelled'];

    public const MAX_ATTACHMENTS = 5;

    protected $fillable = [
        'territory_id', 'number', 'date', 'payee_name', 'payee_phone', 'pay_from_account_id', 'narration', 'purpose', 'amount', 'status', 'method', 'reference',
        'prepared_by', 'prepared_at', 'authorised_by', 'authorised_at', 'authorise_note', 'rejected_by', 'rejected_at', 'reject_reason',
        'paid_by', 'paid_at', 'paid_on', 'journal_id', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'date' => 'date',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
        'prepared_at' => 'datetime',
        'authorised_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentVoucherLine::class)->orderBy('id');
    }

    public function payFrom(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'pay_from_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
