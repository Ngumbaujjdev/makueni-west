<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/** A room at a church (P5) - booked by the hour, and where equipment is kept. */
class Room extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    /** The colours a room may take - the palette's six that look different. */
    public const COLOURS = ['primary', 'success', 'purple', 'pink', 'warning', 'danger'];

    protected $fillable = ['territory_id', 'name', 'capacity', 'bookable', 'colour', 'notes', 'active', 'order'];

    protected $casts = ['capacity' => 'integer', 'bookable' => 'boolean', 'active' => 'boolean', 'order' => 'integer'];

    public function bookings(): HasMany
    {
        return $this->hasMany(RoomBooking::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function getColourNameAttribute(): string
    {
        return $this->colour ?: self::COLOURS[$this->id % count(self::COLOURS)];
    }
}
