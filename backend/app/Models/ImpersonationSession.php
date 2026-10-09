<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Carbon\Carbon;

class ImpersonationSession extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'impersonator_id',
        'impersonated_user_id',
        'territory_context_id',
        'reason',
        'ip_address',
        'user_agent',
        'started_at',
        'expires_at',
        'ended_at',
        'ended_by',
        'end_reason',
        'is_active',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($session) {
            if (!$session->expires_at) {
                $session->expires_at = now()->addHour(); // Default 1 hour
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['impersonator_id', 'impersonated_user_id', 'reason', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // === RELATIONSHIPS ===

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function territoryContext(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_context_id');
    }

    public function endedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    // === HELPER METHODS ===

    public function isExpired(): bool
    {
        return $this->expires_at <= now();
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active && !$this->isExpired() && !$this->ended_at;
    }

    public function getRemainingMinutes(): int
    {
        if ($this->isExpired()) return 0;
        return now()->diffInMinutes($this->expires_at);
    }

    public function endSession(User $endedBy, string $reason = null): bool
    {
        return $this->update([
            'ended_at' => now(),
            'ended_by' => $endedBy->id,
            'end_reason' => $reason,
            'is_active' => false,
        ]);
    }

    public function extendSession(int $minutes, User $extendedBy): bool
    {
        $newExpiry = $this->expires_at->addMinutes($minutes);

        activity()
            ->causedBy($extendedBy)
            ->performedOn($this)
            ->withProperties([
                'old_expiry' => $this->expires_at,
                'new_expiry' => $newExpiry,
                'extended_minutes' => $minutes,
            ])
            ->log('Impersonation session extended');

        return $this->update(['expires_at' => $newExpiry]);
    }

    // === QUERY SCOPES ===

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->whereNull('ended_at')
                    ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeByImpersonator($query, $userId)
    {
        return $query->where('impersonator_id', $userId);
    }

    public function scopeByImpersonated($query, $userId)
    {
        return $query->where('impersonated_user_id', $userId);
    }
}
