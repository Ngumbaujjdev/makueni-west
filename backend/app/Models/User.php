<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Permission\Traits\HasRoles;
use Carbon\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

class User extends Authenticatable implements Auditable
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'firstname',
        'lastname',
        'username',
        'email',
        'phone',
        'position',
        'employee_code',
        'password',
        'pin',
        'pin_changed_at',
        'failed_pin_attempts',
        'pin_locked_until',
        'status',
        'login_attempts',
        'last_login_at',
        'password_changed_at',
        'must_change_password',
        'password_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_changed_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password_expires_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT CONFIGURATION
    |--------------------------------------------------------------------------
    */

    /**
     * Attributes to include in audit
     * These are the important fields we want to track changes for
     */
    protected $auditInclude = [
        'firstname',
        'lastname',
        'username',
        'email',
        'phone',
        'position',
        'employee_code',
        'status',
        'login_attempts',
        'last_login_at',
        'password_changed_at',
        'must_change_password',
        'password_expires_at',
        'failed_pin_attempts',
        'pin_locked_until',
        'pin_changed_at',
    ];

    /**
     * Attributes to exclude from audit
     * CRITICAL: Never audit sensitive data like passwords, pins, or tokens
     */
    protected $auditExclude = [
        'password',           // Never audit the actual password hash
        'pin',               // Never audit the actual PIN hash
        'remember_token',    // Never audit tokens
        'created_at',
        'updated_at',
    ];

    /**
     * Generate custom audit tags for better categorization
     */
    public function generateTags(): array
    {
        return [
            'user',
            'authentication',
            $this->employee_code ?? 'no-code',
        ];
    }

    /**
     * Transform audit data before storing
     * This is where we can customize what gets saved and add custom event names
     */
    public function transformAudit(array $data): array
    {
        // Add metadata
        $data['app_version'] = config('app.version', '1.0.0');

        // Create custom event names based on what changed
        if ($data['event'] === 'updated') {

            // Password change event
            if (isset($data['new_values']['password_changed_at'])) {
                $data['event'] = 'password_changed';
            }

            // PIN change event
            if (isset($data['new_values']['pin_changed_at'])) {
                $data['event'] = 'pin_changed';
            }

            // Status change event (account activated, suspended, etc.)
            if (isset($data['new_values']['status'])) {
                $oldStatus = $data['old_values']['status'] ?? 'unknown';
                $newStatus = $data['new_values']['status'];
                $data['event'] = "status_changed_{$oldStatus}_to_{$newStatus}";
            }

            // Account locked event
            if (isset($data['new_values']['login_attempts']) && $data['new_values']['login_attempts'] >= 3) {
                $data['event'] = 'account_locked';
            }

            // Profile update event
            if (isset($data['new_values']['firstname']) ||
                isset($data['new_values']['lastname']) ||
                isset($data['new_values']['email']) ||
                isset($data['new_values']['phone']) ||
                isset($data['new_values']['position'])) {
                $data['event'] = 'profile_updated';
            }
        }

        // CRITICAL SECURITY: Remove password and pin from audit data if they somehow got included
        if (isset($data['old_values']['password'])) {
            unset($data['old_values']['password']);
        }
        if (isset($data['new_values']['password'])) {
            unset($data['new_values']['password']);
        }
        if (isset($data['old_values']['pin'])) {
            unset($data['old_values']['pin']);
        }
        if (isset($data['new_values']['pin'])) {
            unset($data['new_values']['pin']);
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | AUDIT TRAIL METHODS
    |--------------------------------------------------------------------------
    */

    /**
     * Get all audit history for this user
     */
 /**
 * Get all audit history for this user
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
                'changed_by' => $audit->user ? [
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
     * Get login history (successful and failed logins)
     */
  /**
 * Get login history (successful and failed logins)
 */
/**
 * Get login history (successful and failed logins)
 */
public function getLoginAuditHistory($limit = 20)
{
    return $this->audits()
        ->where(function ($query) {
            $query->where('event', 'user_login')  // ← Manual login success
                  ->orWhere('event', 'login_failed')  // ← Manual login failure
                  ->orWhere('event', 'account_locked')
                  ->orWhere('event', 'like', '%login%');
        })
        ->with('user:id,firstname,lastname,username')
        ->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'login_success' => $audit->new_values['login_success'] ?? null,
                'login_attempts' => $audit->new_values['login_attempts'] ?? $audit->old_values['login_attempts'] ?? null,
                'last_login_at' => $audit->new_values['last_login_at'] ?? null,
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}
    /**
     * Get password change history
     */
   /**
 * Get password change history
 */
public function getPasswordChangeHistory($limit = 10)
{
    return $this->audits()
        ->where(function ($query) {
            $query->where('event', 'password_changed')
                  ->orWhere('event', 'like', '%password%');
        })
        ->with('user:id,firstname,lastname,username')
        ->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'System',
                'password_changed_at' => $audit->new_values['password_changed_at'] ?? null,
                'must_change_password' => $audit->new_values['must_change_password'] ?? null,
                'password_expires_at' => $audit->new_values['password_expires_at'] ?? null,
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

    /**
     * Get profile change history
     */
   /**
 * Get profile change history
 */
public function getProfileChangeHistory($limit = 20)
{
    return $this->audits()
        ->where(function ($query) {
            $query->where('event', 'profile_updated')
                  ->orWhere('event', 'updated');
        })
        ->whereRaw("(
            JSON_EXTRACT(new_values, '$.firstname') IS NOT NULL OR
            JSON_EXTRACT(new_values, '$.lastname') IS NOT NULL OR
            JSON_EXTRACT(new_values, '$.email') IS NOT NULL OR
            JSON_EXTRACT(new_values, '$.phone') IS NOT NULL OR
            JSON_EXTRACT(new_values, '$.position') IS NOT NULL OR
            JSON_EXTRACT(new_values, '$.username') IS NOT NULL
        )")
        ->with('user:id,firstname,lastname,username')
        ->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'Self',
                'changes' => [
                    'old' => $audit->old_values,
                    'new' => $audit->new_values,
                ],
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

    /**
     * Get status change history (active, inactive, suspended)
     */
   /**
 * Get status change history (active, inactive, suspended)
 */
public function getStatusChangeHistory($limit = 20)
{
    return $this->audits()
        ->where(function ($query) {
            $query->where('event', 'like', 'status_changed%')
                  ->orWhere('event', 'account_locked');
        })
        ->whereRaw("JSON_EXTRACT(new_values, '$.status') IS NOT NULL")
        ->with('user:id,firstname,lastname,username')
        ->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'old_status' => $audit->old_values['status'] ?? null,
                'new_status' => $audit->new_values['status'] ?? null,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'System',
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

    /**
     * Get who last modified this user
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

    public function territoryAssignments(): HasMany
    {
        return $this->hasMany(UserTerritoryAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->territoryAssignments()->effective();
    }

    public function allAssignments(): HasMany
    {
        return $this->hasMany(UserTerritoryAssignment::class);
    }

    public function superAdminConfig(): HasOne
    {
        return $this->hasOne(SuperAdminConfig::class);
    }

    public function impersonationSessionsAsImpersonator(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'impersonator_id');
    }

    public function impersonationSessionsAsImpersonated(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'impersonated_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATION METHODS
    |--------------------------------------------------------------------------
    */

    public static function findByIdentifier(string $identifier): ?self
    {
        return static::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->orWhere('username', $identifier)
            ->orWhere('employee_code', $identifier)
            ->first();
    }

    public static function getIdentifierType(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        } elseif (preg_match('/^[0-9]{10,15}$/', $identifier)) {
            return 'phone';
        } elseif (preg_match('/^[A-Z0-9]{6}$/', $identifier)) {
            return 'employee_code';
        } else {
            return 'username';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PIN AUTHENTICATION
    |--------------------------------------------------------------------------
    */

    public function setPin(string $pin): void
    {
        $this->update([
            'pin' => Hash::make($pin),
            'pin_changed_at' => now(),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
    }

    public function verifyPin(string $pin): bool
    {
        if ($this->isPinLocked()) {
            return false;
        }

        if (Hash::check($pin, $this->pin)) {
            $this->update(['failed_pin_attempts' => 0]);
            return true;
        }

        $this->increment('failed_pin_attempts');

        if ($this->failed_pin_attempts >= 3) {
            $this->update(['pin_locked_until' => now()->addMinutes(15)]);
        }

        return false;
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until && $this->pin_locked_until > now();
    }

    public function getRemainingPinLockMinutes(): int
    {
        if (!$this->isPinLocked()) {
            return 0;
        }

        return now()->diffInMinutes($this->pin_locked_until);
    }

    public function clearPinLock(): void
    {
        $this->update([
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PASSWORD MANAGEMENT
    |--------------------------------------------------------------------------
    */

    public function isPasswordExpired(): bool
    {
        return $this->password_expires_at && $this->password_expires_at <= now();
    }

    public function isPasswordExpiringSoon(): bool
    {
        return $this->password_expires_at &&
               $this->password_expires_at->diffInDays(now()) <= 7;
    }

    public function getDaysUntilPasswordExpiry(): int
    {
        if (!$this->password_expires_at) {
            return 0;
        }

        return max(0, now()->diffInDays($this->password_expires_at, false));
    }

    /*
    |--------------------------------------------------------------------------
    | ACCOUNT STATUS
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ACCESS
    |--------------------------------------------------------------------------
    */

    public function hasGlobalAccess(): bool
    {
        return $this->superAdminConfig && $this->superAdminConfig->hasGlobalAccess();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasGlobalAccess();
    }

    /*
    |--------------------------------------------------------------------------
    | TERRITORY HELPERS
    |--------------------------------------------------------------------------
    */

    public function getPrimaryAssignment(): ?UserTerritoryAssignment
    {
        return $this->activeAssignments()
                   ->where('assignment_type', \App\Enums\AssignmentType::PRIMARY)
                   ->first();
    }

    public function getAccessibleTerritories(): \Illuminate\Support\Collection
    {
        $territories = collect();

        foreach ($this->activeAssignments as $assignment) {
            $territories = $territories->merge($assignment->getAccessibleTerritories());
        }

        return $territories->unique('id');
    }

    public function canAccessTerritory(Territory $territory): bool
    {
        if ($this->hasGlobalAccess()) {
            return true;
        }

        return $this->getAccessibleTerritories()->contains('id', $territory->id);
    }

    /*
    |--------------------------------------------------------------------------
    | ROLE HELPERS
    |--------------------------------------------------------------------------
    */

    public function getAssignedRoles(): \Illuminate\Support\Collection
    {
        return $this->activeAssignments->map->role->unique('id');
    }

    public function hasAssignedRole(string $roleName): bool
    {
        return $this->getAssignedRoles()->contains('name', $roleName);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getFullNameAttribute(): string
    {
        return trim($this->firstname . ' ' . $this->lastname);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?: $this->username ?: $this->email;
    }
}
