<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo in a place's gallery (Settings > Profile). Served publicly - it is meant for the church's own page. */
class PlacePhoto extends Model
{
    /** How many photos a place may keep. */
    public const MAX = 30;

    protected $fillable = ['territory_id', 'path', 'thumb_path', 'caption', 'width', 'height', 'bytes', 'position', 'created_by'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'position' => 'integer'];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    /** The public links - the file name changes with each upload, so no cache-busting is needed. */
    public function present(): array
    {
        $base = url("/api/places/{$this->territory_id}/photos/{$this->id}");

        return [
            'id' => $this->id,
            'url' => $base,
            'thumb_url' => $this->thumb_path ? "{$base}/thumb" : $base,
            'caption' => $this->caption,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
        ];
    }
}
