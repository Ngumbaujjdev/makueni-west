<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A supplier's bill (docs/specs/accounting-spec.md, A5), matched to the
 * order and what was received. Posting it owes the supplier (Cr 2100);
 * a payment voucher pays it.
 */
class SupplierInvoice extends Model implements \OwenIt\Auditing\Contracts\Auditable, HasMedia
{
    use InteractsWithMedia, \OwenIt\Auditing\Auditable;

    public const STATUSES = ['posted' => 'To pay', 'paid' => 'Paid', 'reversed' => 'Reversed'];

    protected $fillable = ['territory_id', 'number', 'supplier_id', 'purchase_order_id', 'supplier_ref', 'date', 'due_on', 'amount', 'status', 'journal_id', 'payment_voucher_id', 'posted_by'];

    protected $casts = ['date' => 'date', 'due_on' => 'date', 'amount' => 'decimal:2'];

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class)->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice')->singleFile()->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
