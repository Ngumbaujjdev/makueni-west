<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class Permission extends SpatiePermission implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'guard_name',
        'module_id',
        'submodule_id',
        'sub_submodule_id',
        'action',
        'territory_scope'
    ];

    protected $casts = [
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
        'module_id',
        'submodule_id',
        'sub_submodule_id',
        'action',
        'territory_scope',
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
            'permission',
            'access_control',
            $this->territory_scope ?? 'no-scope',
            $this->action ?? 'no-action',
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

            // Permission renamed
            if (isset($data['new_values']['name'])) {
                $data['event'] = 'permission_renamed';
            }

            // Action changed
            if (isset($data['new_values']['action'])) {
                $data['event'] = 'permission_action_changed';
            }

            // Territory scope changed
            if (isset($data['new_values']['territory_scope'])) {
                $data['event'] = 'permission_scope_changed';
            }

            // Module/Submodule changed
            if (isset($data['new_values']['module_id']) ||
                isset($data['new_values']['submodule_id']) ||
                isset($data['new_values']['sub_submodule_id'])) {
                $data['event'] = 'permission_structure_changed';
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
     * Get all audit history for this permission
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
     * Get scope change history
     */
    public function getScopeChangeHistory($limit = 20)
    {
        return $this->audits()
            ->where(function ($query) {
                $query->where('event', 'permission_scope_changed')
                      ->orWhereJsonContains('new_values->territory_scope', true);
            })
            ->with('user:id,firstname,lastname,username')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'event' => $audit->event,
                    'old_scope' => $audit->old_values['territory_scope'] ?? null,
                    'new_scope' => $audit->new_values['territory_scope'] ?? null,
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
     * Get who last modified this permission
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

    public function submodule(): BelongsTo
    {
        return $this->belongsTo(Submodule::class);
    }

    public function subSubmodule(): BelongsTo
    {
        return $this->belongsTo(SubSubmodule::class);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER METHODS
    |--------------------------------------------------------------------------
    */

    public function getFormattedNameAttribute(): string
    {
        if ($this->sub_submodule_id && $this->subSubmodule) {
            return "{$this->module->name} > {$this->submodule->title} > {$this->subSubmodule->title} > {$this->action}";
        } elseif ($this->submodule) {
            return "{$this->module->name} > {$this->submodule->title} > {$this->action}";
        } elseif ($this->module) {
            return "{$this->module->name} > {$this->action}";
        }

        return $this->name;
    }

    public function getFullScopeDescriptionAttribute(): string
    {
        $scope = ucfirst($this->territory_scope ?? 'all');
        return "{$this->formatted_name} ({$scope} Level)";
    }

    /*
    |--------------------------------------------------------------------------
    | QUERY SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeByModule($query, $moduleId)
    {
        return $query->where('module_id', $moduleId);
    }

    public function scopeBySubmodule($query, $submoduleId)
    {
        return $query->where('submodule_id', $submoduleId);
    }

    public function scopeBySubSubmodule($query, $subSubmoduleId)
    {
        return $query->where('sub_submodule_id', $subSubmoduleId);
    }

    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByTerritoryScope($query, $scope)
    {
        return $query->where('territory_scope', $scope);
    }

    public function scopeForTerritoryLevel($query, $level)
    {
        return $query->whereIn('territory_scope', $this->getAllowedScopes($level));
    }

    /*
    |--------------------------------------------------------------------------
    | TERRITORY ACCESS LOGIC
    |--------------------------------------------------------------------------
    */

    /**
     * Get allowed permission scopes for a given territorial level
     */
    private function getAllowedScopes($level): array
    {
        $scopeHierarchy = [
            'church' => ['church'],
            'subregion' => ['church', 'subregion'],
            'region' => ['church', 'subregion', 'region'],
            'diocese' => ['church', 'subregion', 'region', 'diocese']
        ];

        return $scopeHierarchy[$level] ?? ['church'];
    }

    /**
     * Check if this permission is applicable for given territory level
     */
    public function isApplicableForLevel($level): bool
    {
        $allowedScopes = $this->getAllowedScopes($level);
        return in_array($this->territory_scope, $allowedScopes);
    }
}
