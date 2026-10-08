<?php

namespace App\Support;

/** Small GD helpers for the images people upload (place logos, profile photos). */
class Images
{
    /** Scale down (never up) so the longest side is at most $max pixels. */
    public static function fitWithin(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $max) {
            return $image;
        }
        $ratio = $max / max($w, $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $out = self::blank($nw, $nh);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($image);

        return $out;
    }

    /** The centre square of the image, scaled to $size x $size - a face photo's usual framing. */
    public static function squareCrop(\GdImage $image, int $size): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $size = min($size, $side);
        $out = self::blank($size, $size);
        imagecopyresampled($out, $image, 0, 0, (int) floor(($w - $side) / 2), (int) floor(($h - $side) / 2), $size, $size, $side, $side);
        imagedestroy($image);

        return $out;
    }

    /** The image as webp bytes, keeping transparency. */
    public static function webp(\GdImage $image, int $quality = 85): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private static function blank(int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

        return $out;
    }
}
