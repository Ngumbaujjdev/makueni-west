<?php

namespace App\Services\Images;

use App\Support\Images;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every photo people upload goes through here (the way v1-events-backend's
 * ImageService does it): read it, stand it the right way up, scale it down,
 * and keep it as WebP - small, sharp, and nothing of the original file (its
 * GPS and camera details) kept. Optionally a thumbnail for grids.
 * Files live on the private disk; public routes serve them.
 */
class ImageEngine
{
    public const THUMB = 480;

    /**
     * @param  string  $dir  e.g. "places/12/gallery"
     * @param  int  $max  the longest side, in pixels
     * @param  string  $field  the form field, for the error message
     */
    public function store(UploadedFile $file, string $dir, int $max = 2560, int $quality = 82, bool $thumb = false, string $field = 'photo'): StoredImage
    {
        return $this->withMemory(function () use ($file, $dir, $max, $quality, $thumb, $field) {
            $image = Images::fromUpload($file->getRealPath());
            if (! $image) {
                throw ValidationException::withMessages([$field => "That file couldn't be read as a photo."]);
            }
            $image = Images::fitWithin($image, $max);
            $width = imagesx($image);
            $height = imagesy($image);

            $name = (string) Str::uuid();
            $thumbPath = null;
            if ($thumb) {
                $small = imagecreatetruecolor($width, $height);
                imagealphablending($small, false);
                imagesavealpha($small, true);
                imagecopy($small, $image, 0, 0, 0, 0, $width, $height);
                $thumbPath = "{$dir}/thumbs/{$name}.webp";
                Storage::disk('local')->put($thumbPath, Images::webp(Images::fitWithin($small, self::THUMB), 78));
            }
            $bytes = Images::webp($image, $quality);
            $path = "{$dir}/{$name}.webp";
            Storage::disk('local')->put($path, $bytes);

            return new StoredImage($path, $thumbPath, $width, $height, strlen($bytes));
        });
    }

    /** Remove an image and its thumbnail. */
    public function delete(?string $path, ?string $thumbPath = null): void
    {
        foreach (array_filter([$path, $thumbPath]) as $p) {
            Storage::disk('local')->delete($p);
        }
    }

    /** Big phone photos need room to decode - more memory for this one job, then back. */
    private function withMemory(callable $fn): mixed
    {
        $before = ini_get('memory_limit');
        $bytes = fn (string $v) => $v === '-1' ? PHP_INT_MAX : (int) $v * match (strtolower(substr($v, -1))) {
            'g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10, default => 1
        };
        $raised = $before !== false && $bytes($before) < 512 * (1 << 20) && ini_set('memory_limit', '512M') !== false;
        try {
            return $fn();
        } finally {
            if ($raised) {
                ini_set('memory_limit', $before);
            }
        }
    }
}
