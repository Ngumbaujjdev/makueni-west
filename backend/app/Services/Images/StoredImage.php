<?php

namespace App\Services\Images;

/** What the image engine saved: the WebP (and its thumbnail) on the private disk. */
final class StoredImage
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $thumbPath,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
    ) {}
}
