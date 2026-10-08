<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** One contact on an open care case - a visit, a call, a prayer (P3). The note is encrypted. */
class CareContact extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const TYPES = ['visit' => ['Visit', 'ri-home-heart-line'], 'call' => ['Call', 'ri-phone-line'], 'prayer' => ['Prayed together', 'ri-hand-heart-line'], 'other' => ['Other', 'ri-more-line']];

    protected $fillable = ['care_record_id', 'territory_id', 'on', 'type', 'note', 'done_by', 'next_on'];

    protected $casts = ['on' => 'date', 'next_on' => 'date', 'note' => 'encrypted'];

    public function transformAudit(array $data): array
    {
        return Person::maskSecrets($data, ['note']);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CareRecord::class, 'care_record_id');
    }

    public function doer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
