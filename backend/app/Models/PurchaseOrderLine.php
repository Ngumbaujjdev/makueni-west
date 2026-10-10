<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing ordered (A5): how many, at what price, what it is charged to, and how many came and were billed. */
class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'description', 'quantity', 'unit_price', 'amount', 'account_id', 'fund_id', 'budget_line_id', 'is_asset', 'received_qty', 'billed_qty'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'is_asset' => 'boolean', 'received_qty' => 'decimal:2', 'billed_qty' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class);
    }

    public function toReceive(): float
    {
        return round((float) $this->quantity - (float) $this->received_qty, 2);
    }

    public function toBill(): float
    {
        return round((float) $this->received_qty - (float) $this->billed_qty, 2);
    }
}
