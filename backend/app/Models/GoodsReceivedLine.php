<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many of one ordered line came in a delivery (A5), and the equipment it became at a church. */
class GoodsReceivedLine extends Model
{
    protected $fillable = ['goods_received_id', 'purchase_order_line_id', 'quantity', 'equipment_id'];

    protected $casts = ['quantity' => 'decimal:2'];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
