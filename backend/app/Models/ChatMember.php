<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone in a chat - an admin of a group, or a member; left_at once they leave or are removed. */
class ChatMember extends Model
{
    protected $fillable = ['chat_id', 'user_id', 'is_admin', 'joined_at', 'left_at', 'last_read_message_id'];

    protected $casts = ['is_admin' => 'boolean', 'joined_at' => 'datetime', 'left_at' => 'datetime', 'last_read_message_id' => 'integer'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
