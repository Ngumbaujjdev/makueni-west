<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** A photo of a piece of equipment (P5 round 2) - an upright WebP on the private disk, shown through a signed link. */
class EquipmentPhoto extends Model
{
    /** At most this many photos of one item. */
    public const MAX = 4;

    protected $fillable = ['equipment_id', 'path', 'thumb_path', 'width', 'height', 'bytes', 'position', 'created_by'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'position' => 'integer'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /** {id, url, thumb_url} - signed for a day, so an <img> can show it without the sign-in token. */
    public function present(): array
    {
        $until = now()->addDay()->startOfHour();

        return [
            'id' => $this->id,
            'url' => URL::temporarySignedRoute('equipment.photo', $until, ['photo' => $this->id]),
            'thumb_url' => URL::temporarySignedRoute('equipment.photo', $until, ['photo' => $this->id, 'size' => 'thumb']),
        ];
    }
}
