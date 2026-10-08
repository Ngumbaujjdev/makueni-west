<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A room booked (P5): once, or every week until a date. Times are Nairobi
 * wall-clock times. A booking for an event (activity_id) moves with it.
 */
class RoomBooking extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['territory_id', 'room_id', 'starts_at', 'ends_at', 'purpose', 'booked_by', 'ministry_id', 'activity_id', 'repeat', 'repeat_until', 'status'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'repeat_until' => 'date'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
