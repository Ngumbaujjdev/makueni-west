<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Something the church owns (P5) - an asset: its number, where it is kept,
 * its condition, what it cost and where it was bought, the Budgets entry
 * that paid for it, its photos and receipts (the "receipts" media
 * collection), who has borrowed it and its repairs.
 */
class Equipment extends Model implements Auditable, HasMedia
{
    use InteractsWithMedia, \OwenIt\Auditing\Auditable, SoftDeletes;

    /** At most this many receipts on one item. */
    public const MAX_RECEIPTS = 3;

    protected $table = 'equipment';

    /** key => [label, icon, colour] */
    public const CATEGORIES = [
        'sound' => ['Sound', 'ri-mic-line', 'primary'],
        'instruments' => ['Instruments', 'ri-music-2-line', 'purple'],
        'furniture' => ['Furniture', 'ri-table-line', 'warning'],
        'kitchen' => ['Kitchen', 'ri-restaurant-line', 'pink'],
        'cleaning' => ['Cleaning', 'ri-brush-line', 'success'],
        'it' => ['IT', 'ri-computer-line', 'primary'],
        'other' => ['Other', 'ri-archive-line', 'secondary'],
    ];

    /** key => [label, colour] */
    public const CONDITIONS = ['good' => ['Good', 'success'], 'fair' => ['Fair', 'primary'], 'poor' => ['Poor', 'warning'], 'broken' => ['Broken', 'danger']];

    protected $fillable = ['territory_id', 'asset_no', 'name', 'category', 'room_id', 'quantity', 'condition', 'bought_on', 'value', 'supplier', 'budget_entry_id', 'serial', 'notes'];

    protected $casts = ['quantity' => 'integer', 'bought_on' => 'date', 'value' => 'decimal:2'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EquipmentLoan::class)->orderByDesc('out_on')->orderByDesc('id');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class)->orderByDesc('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EquipmentPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function budgetEntry(): BelongsTo
    {
        return $this->belongsTo(BudgetEntry::class)->withTrashed();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipts')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    /** The next asset number in this church: A-0001, A-0002... (deleted ones keep theirs). */
    public static function nextAssetNo(int $territoryId): string
    {
        $last = static::withTrashed()->where('territory_id', $territoryId)->where('asset_no', 'like', 'A-%')->pluck('asset_no')
            ->map(fn ($n) => (int) substr($n, 2))->max() ?? 0;

        return sprintf('A-%04d', $last + 1);
    }

    protected static function booted(): void
    {
        static::creating(function (Equipment $e) {
            $e->asset_no ??= static::nextAssetNo((int) $e->territory_id);
        });
        // Its files go with it when it is removed for good.
        static::forceDeleting(function (Equipment $e) {
            foreach ($e->photos as $p) {
                app(\App\Services\Images\ImageEngine::class)->delete($p->path, $p->thumb_path);
            }
        });
    }
}
