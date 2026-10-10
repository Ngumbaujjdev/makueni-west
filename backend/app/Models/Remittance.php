<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money between levels (docs/specs/accounting-spec.md, A6): a share sent up
 * to the place a deduction is owed to, or support sent down. Paid by a
 * voucher in the sender's books; confirmed into the receiver's.
 */
class Remittance extends Model implements \OwenIt\Auditing\Contracts\Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const KINDS = ['share' => 'Share sent up', 'support' => 'Support sent down', 'settlement' => 'Paybill money settled'];

    public const STATUSES = ['waiting' => 'Waiting to be paid', 'sent' => 'Sent - in transit', 'queried' => 'Queried', 'confirmed' => 'Confirmed received', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'number', 'from_territory_id', 'to_territory_id', 'kind', 'budget_deduction_id', 'purpose', 'amount', 'status',
        'payment_voucher_id', 'sent_journal_id', 'sent_on', 'method', 'reference',
        'into_account_id', 'received_on', 'received_journal_id', 'confirmed_by', 'confirmed_at',
        'query_reason', 'queried_by', 'queried_at', 'answer', 'created_by',
    ];

    protected $casts = ['amount' => 'decimal:2', 'sent_on' => 'date', 'received_on' => 'date', 'confirmed_at' => 'datetime', 'queried_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceLine::class)->orderBy('month');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'from_territory_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'to_territory_id');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(BudgetDeduction::class, 'budget_deduction_id')->withTrashed();
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Has the money left the sender (paid) - counted as sent? */
    public function isOut(): bool
    {
        return in_array($this->status, ['sent', 'queried', 'confirmed'], true);
    }
}
