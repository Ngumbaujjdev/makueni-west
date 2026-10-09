<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money given to someone ahead of spending it, accounted for later with receipts and change (A4). */
class StaffAdvance extends Model
{
    protected $fillable = ['territory_id', 'user_id', 'holder_name', 'requisition_id', 'payment_voucher_id', 'purpose', 'amount', 'issued_on', 'due_on', 'spent', 'returned', 'status'];

    protected $casts = ['amount' => 'decimal:2', 'spent' => 'decimal:2', 'returned' => 'decimal:2', 'issued_on' => 'date', 'due_on' => 'date'];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function retirements(): HasMany
    {
        return $this->hasMany(AdvanceRetirement::class, 'advance_id')->orderBy('id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /** Still to be accounted for. */
    public function outstanding(): float
    {
        return round((float) $this->amount - (float) $this->spent - (float) $this->returned, 2);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_on->lt(now()->startOfDay());
    }
}
