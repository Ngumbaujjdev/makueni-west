<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Sunday (or any service's) collection (docs/specs/accounting-spec.md, A3):
 * counted by one person, confirmed by another - which posts it as one
 * official receipt, a line per kind and fund - then banked.
 */
class Collection extends Model
{
    public const STATUSES = ['counted' => 'Waiting to be confirmed', 'returned' => 'Sent back', 'posted' => 'Confirmed and receipted', 'reversed' => 'Reversed'];

    protected $fillable = [
        'territory_id', 'date', 'title', 'attendance_record_id', 'gathering_type_id', 'cash_account_id', 'mpesa_account_id', 'denominations',
        'cash_total', 'mpesa_total', 'total', 'witnesses', 'notes', 'status', 'counted_by', 'confirmed_by', 'confirmed_at', 'return_reason',
        'journal_id', 'banking_journal_id',
    ];

    protected $casts = [
        'date' => 'date',
        'denominations' => 'array',
        'witnesses' => 'array',
        'cash_total' => 'decimal:2',
        'mpesa_total' => 'decimal:2',
        'total' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CollectionLine::class)->orderBy('id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'cash_account_id');
    }

    public function mpesaAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'mpesa_account_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function bankingJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'banking_journal_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(ChurchAttendanceRecord::class, 'attendance_record_id');
    }

    public function gatheringType(): BelongsTo
    {
        return $this->belongsTo(GatheringType::class);
    }
}
