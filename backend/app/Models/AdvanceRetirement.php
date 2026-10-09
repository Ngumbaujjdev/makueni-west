<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accounting for (part of) an advance: what was spent, with receipts, and the change returned. */
class AdvanceRetirement extends Model
{
    protected $fillable = ['advance_id', 'date', 'spent', 'returned', 'journal_id', 'recorded_by'];

    protected $casts = ['date' => 'date', 'spent' => 'decimal:2', 'returned' => 'decimal:2'];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
