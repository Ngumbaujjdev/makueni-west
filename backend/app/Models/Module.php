<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class Module extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'module_group_id',
        'name',
        'icon',
        'number',
        'is_active',
        'description'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'module_group_id' => 'integer',
        'number' => 'integer'
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
        'module_group_id',
        'name',
        'icon',
        'number',
        'is_active',
        'description',
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
            'module',
            'navigation',
            $this->name ?? 'unnamed',
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

            // Module renamed
            if (isset($data['new_values']['name'])) {
                $data['event'] = 'module_renamed';
            }

            // Module activated/deactivated
            if (isset($data['new_values']['is_active'])) {
                $status = $data['new_values']['is_active'] ? 'activated' : 'deactivated';
                $data['event'] = "module_{$status}";
            }

            // Module number changed
            if (isset($data['new_values']['number'])) {
                $data['event'] = 'module_reordered';
            }

            // Module group changed
            if (isset($data['new_values']['module_group_id'])) {
                $data['event'] = 'module_group_changed';
            }

            // Icon changed
            if (isset($data['new_values']['icon'])) {
                $data['event'] = 'module_icon_changed';
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
     * Get all audit history for this module
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
                $query->where('event', 'like', 'module_%')
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
     * Get who last modified this module
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

    public function moduleGroup(): BelongsTo
    {
        return $this->belongsTo(ModuleGroup::class);
    }

    public function submodules(): HasMany
    {
        return $this->hasMany(Submodule::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
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

    public function scopeOrderByNumber($query)
    {
        return $query->orderBy('number');
    }

    public function scopeInGroup($query, $moduleGroupId)
    {
        return $query->where('module_group_id', $moduleGroupId);
    }

    public function scopeWithActiveGroup($query)
    {
        return $query->whereHas('moduleGroup', function($q) {
            $q->where('is_active', true);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function getActiveSubmodulesAttribute()
    {
        return $this->submodules()->active()->get();
    }

    public function getTotalPermissionsCountAttribute()
    {
        return $this->permissions()->count();
    }

    public function getTotalSubmodulesCountAttribute()
    {
        return $this->submodules()->count();
    }

    public function getGroupNameAttribute()
    {
        return $this->moduleGroup ? $this->moduleGroup->name : null;
    }

    public function isInGroup(): bool
    {
        return !is_null($this->module_group_id);
    }
}
