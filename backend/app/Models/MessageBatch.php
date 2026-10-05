<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** A message a place sent (or scheduled) to people at it and below it - docs/specs/messages-spec.md. */
class MessageBatch extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const CHANNELS = ['app' => 'In the app only', 'sms' => 'SMS', 'email' => 'Email', 'both' => 'SMS and email'];

    public const STATUSES = ['scheduled', 'sending', 'sent', 'cancelled'];

    protected $fillable = [
        'uid', 'territory_id', 'channel', 'subject', 'body', 'audience', 'summary', 'recipient_count', 'sent_count', 'failed_count',
        'status', 'scheduled_at', 'sent_at', 'created_by',
    ];

    protected $casts = ['audience' => 'array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime'];

    protected $auditExclude = ['sent_count', 'failed_count'];

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uid ??= (string) Str::uuid());
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(MessageReply::class)->orderBy('created_at');
    }

    public function sendsSms(): bool
    {
        return in_array($this->channel, ['sms', 'both'], true);
    }

    public function sendsEmail(): bool
    {
        return in_array($this->channel, ['email', 'both'], true);
    }
}
