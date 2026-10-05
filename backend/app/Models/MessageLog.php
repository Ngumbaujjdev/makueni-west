<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One email or SMS sent, failed or only logged (Settings > System health,
 * and each place's Communication > Messages, S6c).
 */
class MessageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'channel', 'kind', 'via', 'to', 'from', 'reply_to', 'subject', 'body', 'body_type', 'body_cleared_at',
        'status', 'provider_ref', 'error', 'territory_id', 'sent_by', 'meta',
    ];

    protected $casts = ['meta' => 'array', 'body_cleared_at' => 'datetime'];

    /** Message text is kept this long for the preview, then cleared (the row stays). */
    public const KEEP_BODY_DAYS = 90;

    /** The most a stored copy of a message may be. */
    public const MAX_BODY = 200_000;

    /** Replace each secret (a temporary password, a sign-in code) with •••• before it's stored. */
    public static function mask(?string $text, array $secrets): ?string
    {
        if ($text === null) {
            return null;
        }
        foreach (array_filter($secrets, fn ($s) => is_string($s) && strlen($s) >= 4) as $secret) {
            $text = str_replace($secret, '••••', $text);
        }

        return mb_substr($text, 0, self::MAX_BODY);
    }

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
