<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A conversation: one-to-one ("direct") or a group (docs/specs/messages-spec.md, L6). */
class Chat extends Model
{
    public const TYPES = ['direct', 'group'];

    protected $fillable = ['type', 'name', 'photo_path', 'direct_key', 'created_by', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class);
    }

    /** Members still in the chat. */
    public function current(): HasMany
    {
        return $this->hasMany(ChatMember::class)->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /** "3:12" - the same key whichever of the two starts it. */
    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? url("/api/chat/groups/{$this->id}/photo").'?v='.pathinfo($this->photo_path, PATHINFO_FILENAME) : null;
    }
}
