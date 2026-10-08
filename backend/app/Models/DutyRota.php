<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** Someone on duty at a service (P5): cleaning, security, ushering, welcome, sound... */
class DutyRota extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'duty_rota';

    /** key => [label, icon, colour] */
    public const DUTIES = [
        'ushering' => ['Ushering', 'ri-door-open-line', 'primary'],
        'welcome' => ['Welcome', 'ri-hand-heart-line', 'pink'],
        'sound' => ['Sound', 'ri-mic-line', 'purple'],
        'security' => ['Security', 'ri-shield-check-line', 'warning'],
        'cleaning' => ['Cleaning', 'ri-brush-line', 'success'],
    ];

    protected $fillable = ['territory_id', 'on', 'service', 'duty', 'person_id', 'name'];

    protected $casts = ['on' => 'date'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getWhoAttribute(): string
    {
        return $this->person?->name ?? ($this->name ?: 'Someone');
    }
}
