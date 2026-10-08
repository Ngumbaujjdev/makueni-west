<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One line in a chat: what someone wrote, or a system line ("Benson added Titus"). */
class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['chat_id', 'user_id', 'kind', 'body'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
