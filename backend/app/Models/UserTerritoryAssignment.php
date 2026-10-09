<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\AssignmentType;
use OwenIt\Auditing\Contracts\Auditable;

class UserTerritoryAssignment extends Model implements Auditable
{
    use HasFactory, SoftDeletes, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id', 'territory_id', 'role_id', 'assignment_type',
        'can_see_children', 'can_see_siblings', 'can_manage_users', 'can_manage_finances',
        'assignment_reason', 'effective_from', 'expires_at', 'is_active',
        'assigned_by', 'assigned_at', 'approved_by', 'approved_at',
        'removed_at', 'removed_by_user_id', 'is_primary',
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'can_see_children' => 'boolean',
        'can_see_siblings' => 'boolean',
        'can_manage_users' => 'boolean',
        'can_manage_finances' => 'boolean',
        'effective_from' => 'date',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'assigned_at' => 'datetime',
        'approved_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($assignment) {
            if (!$assignment->assigned_at) {
                $assignment->assigned_at = now();
            }
            if (!$assignment->effective_from) {
                $assignment->effective_from = now();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT CONFIGURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Attributes to include in audit
     */
    protected $auditInclude = [
        'user_id',
        'territory_id',
        'role_id',
        'assignment_type',
        'is_primary',
        'is_active',
        'can_see_children',
        'can_see_siblings',
        'can_manage_users',
        'can_manage_finances',
        'assigned_by',
        'removed_by_user_id',
        'assigned_at',
        'removed_at',
        'expires_at',
    ];

    /**
     * Attributes to exclude from audit
     */
    protected $auditExclude = [
        'created_at',
        'updated_at',
        'deleted_at',
        'assignment_reason',
        'effective_from',
        'approved_by',
        'approved_at',
    ];

    /**
     * Generate custom audit tags
     */
    public function generateTags(): array
    {
        return [
            'assignment',
            'territorial_assignment',
            "user_{$this->user_id}",
            "territory_{$this->territory_id}",
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
        if ($data['event'] === 'created') {
            $data['event'] = 'assignment_created';
        }

        if ($data['event'] === 'updated') {

            // Territory change event
            if (isset($data['new_values']['territory_id'])) {
                $data['event'] = 'territory_changed';
            }

            // Role change event
            if (isset($data['new_values']['role_id'])) {
                $data['event'] = 'role_changed';
            }

            // Primary assignment change
            if (isset($data['new_values']['is_primary'])) {
                $data['event'] = $data['new_values']['is_primary']
                    ? 'set_as_primary_assignment'
                    : 'removed_as_primary_assignment';
            }

            // Assignment type change
            if (isset($data['new_values']['assignment_type'])) {
                $data['event'] = 'assignment_type_changed';
            }

            // Assignment removed/deactivated
            if (isset($data['new_values']['is_active']) && $data['new_values']['is_active'] === false) {
                $data['event'] = 'assignment_removed';
            }

            // Assignment reactivated
            if (isset($data['new_values']['is_active']) && $data['new_values']['is_active'] === true) {
                $data['event'] = 'assignment_reactivated';
            }

            // Permission changes
            if (isset($data['new_values']['can_manage_users']) ||
                isset($data['new_values']['can_manage_finances']) ||
                isset($data['new_values']['can_see_children']) ||
                isset($data['new_values']['can_see_siblings'])) {
                $data['event'] = 'permissions_updated';
            }
        }

        if ($data['event'] === 'deleted') {
            $data['event'] = 'assignment_deleted';
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT TRAIL METHODS
    |--------------------------------------------------------------------------
    */

    /**
     * Get all audit history for this assignment
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
     * Get territory change history for this assignment
     */
    public function getTerritoryChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'territory_changed')
                      ->orWhereJsonContains('new_values->territory_id', true);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'old_territory_id' => $audit->old_values['territory_id'] ?? null,
                    'new_territory_id' => $audit->new_values['territory_id'] ?? null,
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
     * Get role change history for this assignment
     */
    public function getRoleChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'role_changed')
                      ->orWhereJsonContains('new_values->role_id', true);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'old_role_id' => $audit->old_values['role_id'] ?? null,
                    'new_role_id' => $audit->new_values['role_id'] ?? null,
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
     * Get assignment status change history (active/inactive)
     */
    public function getStatusChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'assignment_removed')
                      ->orWhere('event', 'assignment_reactivated')
                      ->orWhereJsonContains('new_values->is_active', true);
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
                    'removed_at' => $audit->new_values['removed_at'] ?? null,
                    'removed_by' => $audit->new_values['removed_by_user_id'] ?? null,
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
     * Get permission change history
     */
    public function getPermissionChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'permissions_updated')
                      ->orWhereJsonContains('new_values->can_manage_users', true)
                      ->orWhereJsonContains('new_values->can_manage_finances', true)
                      ->orWhereJsonContains('new_values->can_see_children', true)
                      ->orWhereJsonContains('new_values->can_see_siblings', true);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'changes' => [
                        'old' => $audit->old_values,
                        'new' => $audit->new_values,
                    ],
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
     * Get who last modified this assignment
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function removedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function isEffective(): bool
    {
        return $this->is_active
            && $this->effective_from <= now()
            && ($this->expires_at === null || $this->expires_at > now());
    }

    public function getRemainingDays(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return now()->diffInDays($this->expires_at, false);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at <= now();
    }

    public function getAccessibleTerritories(): \Illuminate\Support\Collection
    {
        $territories = collect([$this->territory]);

        if ($this->can_see_children) {
            $territories = $territories->merge($this->territory->getAllChildren());
        }

        if ($this->can_see_siblings) {
            $territories = $territories->merge($this->territory->getSiblings());
        }

        return $territories->unique('id');
    }

    public function hasPermission(string $permission): bool
    {
        return match($permission) {
            'manage_users' => $this->can_manage_users,
            'manage_finances' => $this->can_manage_finances,
            'see_children' => $this->can_see_children,
            'see_siblings' => $this->can_see_siblings,
            default => false,
        };
    }

    public function getAssignmentDetails(): array
    {
        return [
            'user' => $this->user->name,
            'territory' => $this->territory->full_name,
            'role' => $this->role->name,
            'type' => $this->assignment_type->getDisplayName(),
            'effective' => $this->isEffective(),
            'expires_in' => $this->getRemainingDays(),
            'permissions' => [
                'manage_users' => $this->can_manage_users,
                'manage_finances' => $this->can_manage_finances,
                'see_children' => $this->can_see_children,
                'see_siblings' => $this->can_see_siblings,
            ]
        ];
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

    public function scopeEffective($query)
    {
        return $query->where('is_active', true)
                    ->where('effective_from', '<=', now())
                    ->where(function($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByTerritory($query, $territoryId)
    {
        return $query->where('territory_id', $territoryId);
    }

    public function scopeByRole($query, $roleId)
    {
        return $query->where('role_id', $roleId);
    }

    public function scopePrimaryAssignments($query)
    {
        return $query->where('assignment_type', AssignmentType::PRIMARY);
    }

    public function scopeSecondaryAssignments($query)
    {
        return $query->where('assignment_type', AssignmentType::SECONDARY);
    }

    public function scopeTemporaryAssignments($query)
    {
        return $query->where('assignment_type', AssignmentType::TEMPORARY);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getAssignmentTypeDetailsAttribute(): array
    {
        return [
            'value' => $this->assignment_type->value,
            'display_name' => $this->assignment_type->getDisplayName(),
            'description' => $this->assignment_type->getDescription(),
        ];
    }

    public function getIsTemporaryAttribute(): bool
    {
        return $this->assignment_type === AssignmentType::TEMPORARY;
    }

    public function getIsPrimaryAttribute(): bool
    {
        return $this->assignment_type === AssignmentType::PRIMARY;
    }
}
