<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Enums\TerritoryType;
use OwenIt\Auditing\Contracts\Auditable;

class Territory extends Model implements Auditable
{
    use HasFactory, SoftDeletes, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'code',
        'territory_type',
        'parent_territory_id',
        'level',
        'full_path',
        'is_active',
        'address',
        'phone',
        'email',
        'postal_code',
        'town',
        'county',
        'latitude',
        'longitude',
        'established_date',
        'description',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'territory_type' => TerritoryType::class,
        'is_active' => 'boolean',
        'established_date' => 'date',
        'metadata' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected $dates = [
        'established_date',
    ];

    /*
    |--------------------------------------------------------------------------
    | AUDIT CONFIGURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Attributes to include in audit
     */
    protected $auditInclude = [
        'name',
        'code',
        'territory_type',
        'parent_territory_id',
        'level',
        'is_active',
        'address',
        'phone',
        'email',
        'established_date',
        'description',
    ];

    /**
     * Attributes to exclude from audit
     */
    protected $auditExclude = [
        'full_path',
        'metadata',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
    ];

    /**
     * Generate tags for audit
     */
    public function generateTags(): array
    {
        return [
            'territory',
            'organizational_structure',
            $this->territory_type ? $this->territory_type->value : 'unknown',
        ];
    }

    /**
     * Transform audit data before storing
     */
    public function transformAudit(array $data): array
    {
        // Add metadata
        $data['app_version'] = config('app.version', '1.0.0');

        // Create custom event names based on what changed
        if ($data['event'] === 'updated') {

            // Territory renamed
            if (isset($data['new_values']['name'])) {
                $data['event'] = 'territory_renamed';
            }

            // Territory activated/deactivated
            if (isset($data['new_values']['is_active'])) {
                $status = $data['new_values']['is_active'] ? 'activated' : 'deactivated';
                $data['event'] = "territory_{$status}";
            }

            // Parent changed (territory moved)
            if (isset($data['new_values']['parent_territory_id'])) {
                $data['event'] = 'territory_moved';
            }

            // Territory type changed
            if (isset($data['new_values']['territory_type'])) {
                $data['event'] = 'territory_type_changed';
            }

            // Contact info changed
            if (isset($data['new_values']['phone']) ||
                isset($data['new_values']['email']) ||
                isset($data['new_values']['address'])) {
                $data['event'] = 'territory_contact_updated';
            }
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT TRAIL METHODS
    |--------------------------------------------------------------------------
    */

    /**
     * Get all audit history for this territory
     */
    public function getAuditHistory($limit = 20)
    {
        return $this->audits()
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'user' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'old_values' => $audit->old_values,
                    'new_values' => $audit->new_values,
                    'ip_address' => $audit->ip_address,
                    'user_agent' => $audit->user_agent,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }

    /**
     * Get status change history
     */
    public function getStatusChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'like', 'territory_%')
                      ->orWhereJsonContains('new_values->is_active', true)
                      ->orWhereJsonContains('new_values->is_active', false);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'old_status' => $audit->old_values['is_active'] ?? null,
                    'new_status' => $audit->new_values['is_active'] ?? null,
                    'changed_by' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'ip_address' => $audit->ip_address,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }

    /**
     * Get hierarchy change history (moves, parent changes)
     */
    public function getHierarchyChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'territory_moved')
                      ->orWhereJsonContains('new_values->parent_territory_id', true);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'old_parent_id' => $audit->old_values['parent_territory_id'] ?? null,
                    'new_parent_id' => $audit->new_values['parent_territory_id'] ?? null,
                    'changed_by' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'ip_address' => $audit->ip_address,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }

    /**
     * Get who last modified this territory
     */
    public function getLastModifiedBy(): ?string
    {
        $lastAudit = $this->audits()
            ->with('user:id,firstname,lastname')
            ->latest()
            ->first();

        if (!$lastAudit) {
            return null;
        }

        if (!$lastAudit->user) {
            return 'System';
        }

        return $lastAudit->user->full_name . ' on ' . $lastAudit->created_at->format('Y-m-d H:i:s');
    }

    /**
     * Boot the model to automatically set level and full_path
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($territory) {
            $territory->level = $territory->calculateLevel();
            $territory->full_path = $territory->generateFullPath();
        });

        static::updating(function ($territory) {
            if ($territory->isDirty('parent_territory_id')) {
                $territory->level = $territory->calculateLevel();
                $territory->full_path = $territory->generateFullPath();
            }
        });

        static::updated(function ($territory) {
            // Update children's paths if this territory's path changed
            if ($territory->wasChanged('full_path')) {
                $territory->updateChildrenPaths();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * Parent territory relationship
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'parent_territory_id');
    }

    /**
     * Children territories relationship
     */
    public function children(): HasMany
    {
        return $this->hasMany(Territory::class, 'parent_territory_id')
                   ->orderBy('name');
    }

    /**
     * Active children territories
     */
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    /**
     * User assignments to this territory
     */
    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserTerritoryAssignment::class);
    }

    /**
     * Active user assignments
     */
    public function activeUserAssignments(): HasMany
    {
        return $this->userAssignments()
                   ->where('is_active', true)
                   ->where(function($query) {
                       $query->whereNull('expires_at')
                             ->orWhere('expires_at', '>', now());
                   });
    }

    /**
     * Users assigned to this territory
     */
    public function assignedUsers(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            UserTerritoryAssignment::class,
            'territory_id',
            'id',
            'id',
            'user_id'
        )->where('user_territory_assignments.is_active', true);
    }

    /**
     * Who created this territory
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who last updated this territory
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the level based on parent hierarchy
     */
    public function calculateLevel(): int
    {
        if (!$this->parent_territory_id) {
            return 0; // Global level
        }

        $parent = Territory::find($this->parent_territory_id);
        return $parent ? $parent->level + 1 : 0;
    }

    /**
     * Generate the full hierarchical path
     */
    public function generateFullPath(): string
    {
        if (!$this->parent_territory_id) {
            return $this->name;
        }

        $parent = Territory::find($this->parent_territory_id);
        $parentPath = $parent ? $parent->full_path : '';

        return $parentPath ? "$parentPath > {$this->name}" : $this->name;
    }

    /**
     * Update children's full paths when this territory's path changes
     */
    protected function updateChildrenPaths(): void
    {
        $children = $this->children()->get();

        foreach ($children as $child) {
            $child->full_path = $child->generateFullPath();
            $child->saveQuietly(); // Save without triggering events to avoid recursion
            $child->updateChildrenPaths();
        }
    }

    /**
     * Get all children recursively
     */
    public function getAllChildren(): \Illuminate\Support\Collection
    {
        return $this->children()->with('children')->get()->flatMap(function ($child) {
            return collect([$child])->merge($child->getAllChildren());
        });
    }

    /**
     * Get all active children recursively
     */
    public function getAllActiveChildren(): \Illuminate\Support\Collection
    {
        return $this->activeChildren()->with('activeChildren')->get()->flatMap(function ($child) {
            return collect([$child])->merge($child->getAllActiveChildren());
        });
    }

    /**
     * Get siblings (territories at the same level with same parent)
     */
    public function getSiblings(): \Illuminate\Support\Collection
    {
        return Territory::where('parent_territory_id', $this->parent_territory_id)
                       ->where('id', '!=', $this->id)
                       ->where('is_active', true)
                       ->get();
    }

    /**
     * Get the full ancestry path as collection
     */
    public function getAncestry(): \Illuminate\Support\Collection
    {
        $ancestry = collect();
        $current = $this;

        while ($current) {
            $ancestry->prepend($current);
            $current = $current->parent;
        }

        return $ancestry;
    }

    /**
     * Check if this territory is an ancestor of another territory
     */
    public function isAncestorOf(Territory $territory): bool
    {
        return $territory->getAncestry()->contains('id', $this->id);
    }

    /**
     * Check if this territory is a descendant of another territory
     */
    public function isDescendantOf(Territory $territory): bool
    {
        return $this->getAncestry()->contains('id', $territory->id);
    }

    /**
     * Get territories that this territory can contain based on level
     */
    public function getValidChildTypes(): array
    {
        $currentLevel = $this->territory_type->getLevel();

        return collect(TerritoryType::cases())
            ->filter(fn($type) => $type->getLevel() === $currentLevel + 1)
            ->map(fn($type) => [
                'value' => $type->value,
                'label' => $type->getDisplayName(),
                'level' => $type->getLevel(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Generate next available code for child territory
     */
    public function generateChildCode(TerritoryType $childType): string
    {
        $prefix = $this->code;
        $childCount = $this->children()
                          ->where('territory_type', $childType)
                          ->count() + 1;

        $typeCode = match($childType) {
            TerritoryType::DIOCESE => 'D',
            TerritoryType::REGION => 'R',
            TerritoryType::SUBREGION => 'SR',
            TerritoryType::CHURCH => 'C',
            default => 'T',
        };

        return sprintf('%s-%s%03d', $prefix, $typeCode, $childCount);
    }

    /*
    |--------------------------------------------------------------------------
    | QUERY SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, TerritoryType $type)
    {
        return $query->where('territory_type', $type);
    }

    public function scopeByLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_territory_id');
    }

    public function scopeLeaves($query)
    {
        return $query->whereDoesntHave('children');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    /**
     * Get the display name with type
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->name} ({$this->territory_type->getDisplayName()})";
    }

    /**
     * Check if this territory can have children
     */
    public function getCanHaveChildrenAttribute(): bool
    {
        return $this->territory_type->canHaveChildren();
    }
}
