<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class GatheringCategory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'is_weekly',
        'display_order',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_weekly' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gatheringTypes(): HasMany
    {
        return $this->hasMany(GatheringType::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
