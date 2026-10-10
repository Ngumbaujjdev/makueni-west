<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A local purchase order (docs/specs/accounting-spec.md, A5): raised from an
 * approved purchase requisition to the chosen supplier. Goods are received
 * against it and the supplier's bill is matched to it.
 */
class PurchaseOrder extends Model implements \OwenIt\Auditing\Contracts\Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const STATUSES = ['issued' => 'Ordered', 'part_received' => 'Part received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

    protected $fillable = ['territory_id', 'number', 'requisition_id', 'supplier_id', 'date', 'deliver_by', 'notes', 'amount', 'status', 'issued_by', 'ended_by', 'ended_at', 'end_reason'];

    protected $casts = ['date' => 'date', 'deliver_by' => 'date', 'amount' => 'decimal:2', 'ended_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(GoodsReceived::class)->orderBy('id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Can more goods still come? */
    public function isOpen(): bool
    {
        return in_array($this->status, ['issued', 'part_received'], true);
    }
}
