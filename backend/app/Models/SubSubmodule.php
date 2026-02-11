<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SubSubmodule extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'submodule_id',
        'title',
        'path',
        'is_active',
        'description'
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
        'submodule_id',
        'title',
        'path',
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
            'sub_submodule',
            'navigation',
            $this->title ?? 'unnamed',
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

            // Sub-submodule renamed
            if (isset($data['new_values']['title'])) {
                $data['event'] = 'sub_submodule_renamed';
            }

            // Sub-submodule activated/deactivated
            if (isset($data['new_values']['is_active'])) {
                $status = $data['new_values']['is_active'] ? 'activated' : 'deactivated';
                $data['event'] = "sub_submodule_{$status}";
            }

            // Path changed
            if (isset($data['new_values']['path'])) {
                $data['event'] = 'sub_submodule_path_changed';
            }

            // Submodule changed (moved to different submodule)
            if (isset($data['new_values']['submodule_id'])) {
                $data['event'] = 'sub_submodule_moved';
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
     * Get all audit history for this sub-submodule
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
                $query->where('event', 'like', 'sub_submodule_%')
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
     * Get who last modified this sub-submodule
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

    public function submodule(): BelongsTo
    {
        return $this->belongsTo(Submodule::class);
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

    public function scopeBySubmodule($query, $submoduleId)
    {
        return $query->where('submodule_id', $submoduleId);
    }

    public function scopeByModule($query, $moduleId)
    {
        return $query->whereHas('submodule', function ($q) use ($moduleId) {
            $q->where('module_id', $moduleId);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function getFullPathAttribute(): string
    {
        return "{$this->submodule->module->name} > {$this->submodule->title} > {$this->title}";
    }

    public function getModuleAttribute()
    {
        return $this->submodule->module;
    }

    public function getTotalPermissionsCountAttribute()
    {
        return $this->permissions()->count();
    }

    public function getHierarchyLevelAttribute(): string
    {
        return 'sub_submodule';
    }
}
