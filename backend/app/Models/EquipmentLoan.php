<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** Equipment lent out (P5) - to someone in the register or a name typed in - until it comes back. */
class EquipmentLoan extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['equipment_id', 'to_name', 'to_person_id', 'quantity', 'out_on', 'due_on', 'returned_on', 'note', 'by'];

    protected $casts = ['out_on' => 'date', 'due_on' => 'date', 'returned_on' => 'date', 'quantity' => 'integer'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'to_person_id');
    }
}
