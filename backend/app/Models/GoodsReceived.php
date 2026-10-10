<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** A delivery against an order (A5) - the goods received note, with the delivery note or a photo. */
class GoodsReceived extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'goods_received';

    protected $fillable = ['territory_id', 'number', 'purchase_order_id', 'date', 'notes', 'status', 'received_by', 'undone_by', 'undone_at'];

    protected $casts = ['date' => 'date', 'undone_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceivedLine::class)->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('delivery')->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
