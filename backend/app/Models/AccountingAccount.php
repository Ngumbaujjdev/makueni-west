<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account of the chart (docs/specs/accounting-spec.md). territory_id
 * null is the diocese's standard account, shared by every place; set, it is
 * a place's own sub-account (its bank, its M-Pesa) under a standard header.
 * An account with a cash_kind holds money and has a cashbook.
 */
class AccountingAccount extends Model
{
    public const TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'fund' => 'Funds', 'income' => 'Income', 'expense' => 'Expenses'];

    public const CASH_KINDS = ['cash' => 'Cash', 'petty_cash' => 'Petty cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money'];

    protected $fillable = [
        'territory_id', 'parent_id', 'code', 'name', 'description', 'type', 'system_key', 'cash_kind',
        'bank_name', 'branch', 'account_number', 'mpesa_number', 'is_header', 'is_active', 'display_order', 'created_by',
    ];

    protected $casts = [
        'is_header' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** The standard accounts plus this place's own. */
    public function scopeUsableBy(Builder $q, int $territoryId): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('territory_id')->orWhere('territory_id', $territoryId));
    }

    /** Debits make it bigger (assets, expenses); credits for the rest. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    public function isCash(): bool
    {
        return $this->cash_kind !== null;
    }

    /** The account number with all but its last four hidden. */
    public function maskedNumber(): ?string
    {
        $n = $this->account_number ?: $this->mpesa_number;
        if (! $n) {
            return null;
        }
        $n = preg_replace('/\s+/', '', $n);

        return strlen($n) <= 4 ? $n : str_repeat('•', min(6, strlen($n) - 4)).substr($n, -4);
    }
}
