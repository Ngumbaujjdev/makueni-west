<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Money that actually came in (direction "in") or went out ("out") against
 * one line of a budget. Written through BudgetBook, which keeps the line's
 * and the budget's received/spent totals in step.
 *
 * Receipts (a photo or PDF) hang off it in the "receipts" media collection,
 * kept on the private local disk - they're only ever streamed through the
 * API, to people who may see the budget.
 */
class BudgetEntry extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    /** At most this many receipts on one entry. */
    public const MAX_RECEIPTS = 3;

    public const METHODS = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque'];

    protected $fillable = [
        'budget_id', 'budget_line_item_id', 'activity_id', 'direction', 'amount', 'entry_date',
        'description', 'counterparty', 'method', 'reference', 'recorded_by', 'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'budget_line_item_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipts')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
