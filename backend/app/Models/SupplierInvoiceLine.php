<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a supplier's bill (A5), matched to an ordered line. */
class SupplierInvoiceLine extends Model
{
    protected $fillable = ['supplier_invoice_id', 'purchase_order_line_id', 'description', 'quantity', 'unit_price', 'amount'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
