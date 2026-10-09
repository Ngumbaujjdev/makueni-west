<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class Submodule extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'module_id',
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
        'module_id',
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
            'submodule',
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

            // Submodule renamed
            if (isset($data['new_values']['title'])) {
                $data['event'] = 'submodule_renamed';
            }

            // Submodule activated/deactivated
            if (isset($data['new_values']['is_active'])) {
                $status = $data['new_values']['is_active'] ? 'activated' : 'deactivated';
                $data['event'] = "submodule_{$status}";
            }

            // Path changed
            if (isset($data['new_values']['path'])) {
                $data['event'] = 'submodule_path_changed';
            }

            // Module changed (moved to different module)
            if (isset($data['new_values']['module_id'])) {
                $data['event'] = 'submodule_moved';
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
     * Get all audit history for this submodule
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
                $query->where('event', 'like', 'submodule_%')
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
     * Get who last modified this submodule
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

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function subSubmodules(): HasMany
    {
        return $this->hasMany(SubSubmodule::class);
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

    public function scopeByModule($query, $moduleId)
    {
        return $query->where('module_id', $moduleId);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function getFullPathAttribute(): string
    {
        return "{$this->module->name} > {$this->title}";
    }

    public function getTotalPermissionsCountAttribute()
    {
        return $this->permissions()->count();
    }

    public function getActiveSubSubmodulesAttribute()
    {
        return $this->subSubmodules()->active()->get();
    }

    public function getTotalSubSubmodulesCountAttribute()
    {
        return $this->subSubmodules()->count();
    }
}
