<?php

namespace App\Models;

use App\Approval\Contracts\Approvable;
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
class PaymentVoucher extends Model implements Approvable, HasMedia
{
    use InteractsWithMedia;

    public const STATUSES = ['prepared' => 'Waiting to be authorised', 'authorised' => 'Authorised - to pay', 'paid' => 'Paid', 'rejected' => 'Sent back', 'cancelled' => 'Cancelled'];

    public const MAX_ATTACHMENTS = 5;

    protected $fillable = [
        'territory_id', 'number', 'date', 'payee_name', 'payee_phone', 'pay_from_account_id', 'narration', 'purpose', 'requisition_id', 'supplier_invoice_id', 'remittance_id', 'payroll_payment_id', 'amount', 'status', 'method', 'reference',
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

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function approvalPlace(): Territory
    {
        return Territory::findOrFail($this->territory_id);
    }

    public function approvalContext(): array
    {
        return ['amount' => round((float) $this->amount, 2), 'document' => 'payment_voucher', 'purpose' => $this->purpose];
    }

    public function approvalSummary(): array
    {
        return ['type' => 'payment_voucher', 'label' => 'Payment voucher', 'number' => $this->number, 'title' => $this->narration, 'amount' => (float) $this->amount, 'page' => 'payments.php', 'param' => 'voucher'];
    }

    public function approvalDetails(): array
    {
        $this->loadMissing(['lines.account', 'payFrom', 'preparer']);

        return [
            'facts' => array_values(array_filter([
                ['Pay to', $this->payee_name.($this->payee_phone ? " ({$this->payee_phone})" : '')],
                ['What for', $this->narration],
                ['Pay from', $this->payFrom?->name],
                ['Prepared by', $this->preparer?->full_name],
                ['Date', $this->date->format('j M Y')],
            ])),
            'lines' => $this->lines->map(fn ($l) => [trim(($l->account?->name ?? '').($l->description ? " - {$l->description}" : '')), (float) $l->amount])->values()->all(),
            'media' => $this->getMedia('attachments')->all(),
        ];
    }

    /** The engine decided: approved -> authorised (by the last approver); a no -> sent back with the reason. */
    public function approvalOutcome(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment): void
    {
        if ($outcome === 'approved') {
            $this->update(['status' => 'authorised', 'authorised_by' => $by?->id, 'authorised_at' => now(), 'authorise_note' => $comment ?: null]);
        } elseif (in_array($outcome, ['rejected', 'returned'], true)) {
            $this->update(['status' => 'rejected', 'rejected_by' => $by?->id, 'rejected_at' => now(), 'reject_reason' => $comment ? mb_substr($comment, 0, 255) : null, 'authorised_by' => null, 'authorised_at' => null]);
        }
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
