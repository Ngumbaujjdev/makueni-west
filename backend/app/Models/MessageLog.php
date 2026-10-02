<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One email or SMS sent, failed or only logged (Settings > System health). */
class MessageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['channel', 'to', 'subject', 'status', 'provider_ref', 'error', 'territory_id', 'sent_by'];

    /** The latest of a channel, for Health. */
    public static function latestFor(string $channel): ?self
    {
        try {
            return self::where('channel', $channel)->latest('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
