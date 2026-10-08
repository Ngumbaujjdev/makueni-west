<?php

namespace App\Support;

/** Small GD helpers for the images people upload (place logos, profile photos). */
class Images
{
    /**
     * Decode an uploaded file and stand a phone photo the right way up
     * (cameras store sideways pixels plus an EXIF "turn me" note). Null when
     * the file isn't an image GD can read.
     */
    public static function fromUpload(string $path): ?\GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image) {
            return null;
        }

        return self::orient($image, $path);
    }

    /** Turn the image by its EXIF orientation (JPEG only; anything else is left as it is). */
    public static function orient(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        try {
            $exif = @exif_read_data($path);
        } catch (\Throwable) {
            return $image;
        }
        $angle = match ((int) (($exif ?: [])['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

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
