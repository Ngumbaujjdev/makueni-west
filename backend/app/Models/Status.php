<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Status extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    use SoftDeletes;

    protected $fillable = [
        'status_category_id',
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'display_order',
        'is_initial',
        'is_final',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_final' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Get the status category this status belongs to
     */
    public function statusCategory(): BelongsTo
    {
        return $this->belongsTo(StatusCategory::class);
    }

    /**
     * Get the user who created this status
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all budgets using this status
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'status_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Scope to get only active statuses
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by category ID
     */
    public function scopeByCategoryId($query, int $categoryId)
    {
        return $query->where('status_category_id', $categoryId);
    }

    /**
     * Scope to filter by category slug
     */
    public function scopeByCategorySlug($query, string $categorySlug)
    {
        return $query->whereHas('statusCategory', function ($q) use ($categorySlug) {
            $q->where('slug', $categorySlug);
        });
    }

    /**
     * Scope to get initial statuses
     */
    public function scopeInitial($query)
    {
        return $query->where('is_initial', true);
    }

    /**
     * Scope to get final statuses
     */
    public function scopeFinal($query)
    {
        return $query->where('is_final', true);
    }

    /**
     * Scope to order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
