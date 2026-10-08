<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Equipment lent out (P5) - to someone in the register or a name typed in -
 * until it comes back. Staff can ask first (round 2): requested, then out
 * when a manager agrees (or declined), then returned.
 */
class EquipmentLoan extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** key => [label, colour] */
    public const STATUSES = ['requested' => ['Asked', 'warning'], 'out' => ['Out', 'primary'], 'returned' => ['Back', 'success'], 'declined' => ['Declined', 'danger']];

    protected $fillable = ['equipment_id', 'to_name', 'to_person_id', 'quantity', 'status', 'out_on', 'due_on', 'returned_on', 'note', 'by', 'requested_by', 'decided_by', 'decided_at', 'decline_reason'];

    protected $casts = ['out_on' => 'date', 'due_on' => 'date', 'returned_on' => 'date', 'decided_at' => 'datetime', 'quantity' => 'integer'];

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'to_person_id');
    }
}
