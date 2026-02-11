<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

class Role extends SpatieRole implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'guard_name',
        'territory_level',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
        'guard_name',
        'territory_level',
        'description',
        'is_active',
    ];

    /**
     * Attributes to exclude from audit
     */
    protected $auditExclude = [
        'created_at',
        'updated_at',
    ];

    /**
     * Generate tags for audit
     */
    public function generateTags(): array
    {
        return [
            'role',
            'permissions',
            $this->territory_level ?? 'no-level',
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

            // Role renamed event
            if (isset($data['new_values']['name'])) {
                $data['event'] = 'role_renamed';
            }

            // Role activation/deactivation event
            if (isset($data['new_values']['is_active'])) {
                $status = $data['new_values']['is_active'] ? 'activated' : 'deactivated';
                $data['event'] = "role_{$status}";
            }

            // Territory level changed event
            if (isset($data['new_values']['territory_level'])) {
                $data['event'] = 'territory_level_changed';
            }

            // Description updated
            if (isset($data['new_values']['description'])) {
                $data['event'] = 'role_description_updated';
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
     * Get all audit history for this role
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
     * Get permission change history
     */
    public function getPermissionChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'like', '%permission%')
                      ->orWhere('event', 'updated');
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'changed_by' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->full_name,
                    ] : 'System',
                    'changes' => [
                        'old' => $audit->old_values,
                        'new' => $audit->new_values,
                    ],
                    'ip_address' => $audit->ip_address,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                ];
            });
    }

    /**
     * Get status change history (active/inactive)
     */
    public function getStatusChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'like', 'role_%')
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
     * Get who last modified this role
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

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    public function userTerritoryAssignments(): HasMany
    {
        return $this->hasMany(UserTerritoryAssignment::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_has_permissions');
    }

    /*
    |--------------------------------------------------------------------------
    | PERMISSION MANAGEMENT
    |--------------------------------------------------------------------------
    */

    /**
     * Update permissions for this role by syncing permission IDs
     * Uses Spatie's givePermissionTo method with permission names (like seeders)
     * 
     * @param array $permissionIds Array of permission IDs to assign to this role
     * @return void
     */
    public function updateModulePermissions(array $permissionIds): void
    {
        // Clear existing permissions first (like the seeders do)
        $this->permissions()->detach();
        
        // Get permission names from IDs
        $permissionNames = Permission::whereIn('id', $permissionIds)
            ->pluck('name')
            ->toArray();
        
        // Use Spatie's givePermissionTo with permission names
        if (!empty($permissionNames)) {
            $this->givePermissionTo($permissionNames);
        }
    }

    /**
     * Get permissions grouped by module and submodule structure
     */
    public function getGroupedPermissions(): array
    {
        return $this->permissions()
            ->with(['module', 'submodule', 'subSubmodule'])
            ->get()
            ->groupBy('module.name')
            ->map(function ($modulePermissions) {
                return $modulePermissions->groupBy('submodule.title')
                    ->map(function ($submodulePermissions) {
                        // If sub-submodules exist, group by them too
                        if ($submodulePermissions->first()->sub_submodule_id) {
                            return $submodulePermissions->groupBy('subSubmodule.title')
                                ->map(function ($subSubmodulePermissions) {
                                    return $subSubmodulePermissions->pluck('action')->toArray();
                                });
                        } else {
                            return $submodulePermissions->pluck('action')->toArray();
                        }
                    });
            })
            ->toArray();
    }

    /**
     * Get available permissions for this role's territory level
     */
    public function getAvailablePermissions()
    {
        if (!$this->territory_level) {
            return Permission::with(['module', 'submodule', 'subSubmodule'])->get();
        }

        return Permission::with(['module', 'submodule', 'subSubmodule'])
            ->forTerritoryLevel($this->territory_level)
            ->get();
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

    public function scopeForTerritoryLevel($query, $level)
    {
        return $query->where('territory_level', $level);
    }

    public function scopeAccessibleByLevel($query, $level)
    {
        // Higher levels can see lower level roles
        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];
        $levelIndex = array_search($level, $levelHierarchy);

        if ($levelIndex !== false) {
            $accessibleLevels = array_slice($levelHierarchy, 0, $levelIndex + 1);
            return $query->whereIn('territory_level', $accessibleLevels);
        }

        return $query->where('territory_level', $level);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function getTerritoryLevelNameAttribute(): string
    {
        return ucfirst($this->territory_level ?? 'general');
    }

    public function getFullDescriptionAttribute(): string
    {
        return "{$this->name} ({$this->territory_level_name} Level)";
    }

    public function canManageLevel($level): bool
    {
        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];
        $currentIndex = array_search($this->territory_level, $levelHierarchy);
        $targetIndex = array_search($level, $levelHierarchy);

        return $currentIndex !== false && $targetIndex !== false && $currentIndex >= $targetIndex;
    }
}
