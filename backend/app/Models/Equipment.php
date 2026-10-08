<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/** Something the church owns (P5): where it is kept, its condition, who has borrowed it and its repairs. */
class Equipment extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

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

    protected $fillable = ['territory_id', 'name', 'category', 'room_id', 'quantity', 'condition', 'bought_on', 'value', 'serial', 'notes'];

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
}
