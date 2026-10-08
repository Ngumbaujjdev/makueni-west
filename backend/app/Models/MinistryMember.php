<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** Someone in the register serving in a ministry - each one audited, so the ministry's history says who was added and removed. */
class MinistryMember extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['ministry_id', 'person_id', 'joined_on', 'added_by'];

    protected $casts = ['joined_on' => 'date'];

    protected $auditExclude = ['added_by'];

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
