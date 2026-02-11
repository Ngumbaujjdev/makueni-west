<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class BudgetType extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'slug',
        'duration_months',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'duration_months' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user who created this budget type
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all budgets of this type
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * Scope to get only active budget types
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
