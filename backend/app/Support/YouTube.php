<?php

namespace App\Support;

/** YouTube links a place can give for its services online: a channel (@handle, /channel, /c, /user), a video, a live stream or a playlist. */
final class YouTube
{
    private const PATTERN = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:@[\w.-]+|channel/[\w-]+|c/[\w.-]+|user/[\w.-]+|watch\?(?:.*&)?v=[\w-]{11}|live/[\w-]{11}|shorts/[\w-]{11}|embed/[\w-]{11}|playlist\?(?:.*&)?list=[\w-]+)|youtu\.be/[\w-]{11})(?:[/?&#].*)?$~i';

    public static function valid(?string $url): bool
    {
        return $url !== null && preg_match(self::PATTERN, $url) === 1;
    }

    /** "youtube.com/@x" -> "https://youtube.com/@x". */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return preg_match('~^https?://~i', $url) ? $url : "https://{$url}";
    }

    /** The video id for a video or live link (for an embedded player); null for a channel or playlist. */
    public static function videoId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        foreach (['~youtu\.be/([\w-]{11})~i', '~[?&]v=([\w-]{11})~i', '~/(?:live|shorts|embed)/([\w-]{11})~i'] as $re) {
            if (preg_match($re, $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
