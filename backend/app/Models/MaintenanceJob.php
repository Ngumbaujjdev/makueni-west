<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** A repair (P5): to equipment or a room - reported, in progress, done - and what it cost. */
class MaintenanceJob extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** key => [label, icon, colour, hint] */
    public const STATUSES = [
        'reported' => ['Reported', 'ri-flag-line', 'warning', 'Waiting for someone to take it'],
        'in_progress' => ['In progress', 'ri-tools-line', 'primary', 'Someone is on it'],
        'done' => ['Done', 'ri-checkbox-circle-line', 'success', 'Fixed'],
    ];

    protected $fillable = ['territory_id', 'equipment_id', 'room_id', 'title', 'detail', 'priority', 'status', 'reported_by', 'assigned_to', 'cost', 'done_on', 'budget_entry_id'];

    protected $casts = ['cost' => 'decimal:2', 'done_on' => 'date'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
