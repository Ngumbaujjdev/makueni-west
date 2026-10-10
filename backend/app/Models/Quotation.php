<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** A supplier's price for a purchase requisition (A5), with the quote itself as a file. */
class Quotation extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['requisition_id', 'supplier_id', 'amount', 'notes', 'chosen', 'chosen_reason', 'added_by'];

    protected $casts = ['amount' => 'decimal:2', 'chosen' => 'boolean'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('quote')->singleFile()->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
