<?php

namespace App\Models;

use App\Approval\Contracts\Approvable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Asking for money (docs/specs/accounting-spec.md, A4): to pay something, to
 * buy something, or an advance. Approved through the engine; once approved
 * the treasurer pays it with a payment voucher that is already authorised.
 */
class Requisition extends Model implements Approvable, HasMedia
{
    use InteractsWithMedia;

    public const KINDS = ['payment' => 'Pay for something', 'purchase' => 'Buy something', 'advance' => 'Cash advance'];

    public const STATUSES = ['submitted' => 'Waiting for approval', 'approved' => 'Approved - to pay', 'returned' => 'Sent back for changes', 'rejected' => 'Rejected', 'ordered' => 'Ordered - waiting for the goods and the bill', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'territory_id', 'number', 'requested_by', 'kind', 'purpose', 'amount', 'needed_by', 'account_id', 'budget_line_id', 'fund_id',
        'payee_name', 'payee_phone', 'payee', 'status', 'decision_note', 'decided_by', 'decided_at', 'payment_voucher_id',
    ];

    protected $casts = ['amount' => 'decimal:2', 'needed_by' => 'date', 'decided_at' => 'datetime', 'payee' => 'array'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(AccountingFund::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    /** Quotations for a purchase (A5). */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderBy('amount')->orderBy('id');
    }

    /** Its local purchase order (A5) - one per requisition. */
    public function order(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function approvalPlace(): Territory
    {
        return Territory::findOrFail($this->territory_id);
    }

    public function approvalContext(): array
    {
        return ['amount' => round((float) $this->amount, 2), 'kind' => $this->kind, 'document' => 'requisition'];
    }

    public function approvalSummary(): array
    {
        return ['type' => 'requisition', 'label' => 'Requisition', 'number' => $this->number, 'title' => $this->purpose, 'amount' => (float) $this->amount, 'page' => 'requisitions.php', 'param' => 'requisition'];
    }

    public function approvalDetails(): array
    {
        $this->loadMissing(['account', 'budgetLine', 'fund', 'requester', 'quotations.supplier']);
        $chosen = $this->quotations->firstWhere('chosen', true);

        return [
            'facts' => array_values(array_filter([
                ['What for', $this->purpose],
                ['Kind', self::KINDS[$this->kind]],
                ['Asked by', $this->requester?->full_name],
                $this->payee_name ? ['Pay to', $this->payee_name.($this->payee_phone ? " ({$this->payee_phone})" : '')] : null,
                $this->payee ? ['Pay by', \App\Support\PayTo::describe($this->payee)] : null,
                $this->needed_by ? ['Needed by', $this->needed_by->format('j M Y')] : null,
                $this->account ? ['Charged to', "{$this->account->code} {$this->account->name}"] : null,
                $this->budgetLine ? ['Budget line', $this->budgetLine->name] : null,
                $this->fund ? ['Fund', $this->fund->name] : null,
                $this->quotations->isNotEmpty() ? ['Quotations', $this->quotations->map(fn ($q) => $q->supplier?->name.' KES '.number_format((float) $q->amount).($q->chosen ? ' (chosen)' : ''))->implode(' · ')] : null,
                $chosen?->chosen_reason ? ['Why that supplier', $chosen->chosen_reason] : null,
            ])),
            'lines' => [[$this->purpose, (float) $this->amount]],
            'media' => array_merge($this->getMedia('attachments')->all(), $this->quotations->flatMap(fn ($q) => $q->getMedia('quote'))->all()),
        ];
    }

    public function approvalOutcome(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment): void
    {
        app(\App\Services\Accounting\Requisitions::class)->outcome($this, $outcome, $by, $comment);
    }
}
