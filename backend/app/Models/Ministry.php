<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A church's ministry (docs/specs/people-and-care-spec.md, P4): youth,
 * women, men, children, music, prayer, or one of its own - who leads it,
 * who serves in it, when it meets, and the gathering type its attendance is
 * recorded under.
 */
class Ministry extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    /** kind => [label, icon, colour] */
    public const KINDS = [
        'youth' => ['Youth', 'ri-user-star-line', 'primary'],
        'women' => ['Women', 'ri-women-line', 'pink'],
        'men' => ['Men', 'ri-men-line', 'success'],
        'children' => ['Children', 'ri-bear-smile-line', 'warning'],
        'music' => ['Music', 'ri-music-2-line', 'purple'],
        'prayer' => ['Prayer', 'ri-hand-heart-line', 'primary'],
        'other' => ['Other', 'ri-team-line', 'success'],
    ];

    /** Every church starts with these (kind => [name, the gathering type names it links to]). */
    public const STANDARD = [
        'youth' => ['Youth', '/youth/i'],
        'women' => ['Women', '/\bwomen|\bmothers/i'],
        'men' => ['Men', '/\bmen\b|\bmen\'s|\bfathers/i'],
        'children' => ['Children & Sunday school', '/children|sunday school/i'],
        'music' => ['Music & choir', '/choir|music|praise/i'],
        'prayer' => ['Prayer', '/prayer|kesha/i'],
    ];

    /** The colours a ministry may take - the palette's six that look different (info and teal are the same teal, secondary the same gold). */
    public const COLOURS = ['primary', 'success', 'purple', 'pink', 'warning', 'danger'];

    public const ICONS = [
        'ri-user-star-line', 'ri-women-line', 'ri-men-line', 'ri-bear-smile-line', 'ri-music-2-line', 'ri-hand-heart-line', 'ri-team-line',
        'ri-mic-line', 'ri-book-open-line', 'ri-seedling-line', 'ri-community-line', 'ri-service-line', 'ri-football-line', 'ri-hearts-line',
        'ri-parent-line', 'ri-sun-line', 'ri-star-smile-line', 'ri-shield-user-line',
    ];

    public const ROLES = ['leader' => 'Leader', 'assistant' => 'Assistant', 'secretary' => 'Secretary'];

    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = [
        'territory_id', 'name', 'kind', 'standard', 'icon', 'colour', 'meets_day', 'meets_time', 'gathering_type_id', 'active', 'order', 'created_by', 'updated_by',
    ];

    protected $casts = ['active' => 'boolean', 'meets_day' => 'integer', 'order' => 'integer'];

    protected $auditExclude = ['updated_by'];

    public function church(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function gatheringType(): BelongsTo
    {
        return $this->belongsTo(GatheringType::class);
    }

    public function leaders(): HasMany
    {
        return $this->hasMany(MinistryLeader::class)->orderByRaw("field(role, 'leader', 'assistant', 'secretary')")->orderBy('id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(MinistryMember::class);
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'ministry_members')->withPivot('joined_on');
    }

    public function getIconNameAttribute(): string
    {
        return $this->icon ?: self::KINDS[$this->kind][1] ?? 'ri-team-line';
    }

    public function getColourNameAttribute(): string
    {
        return $this->colour ?: self::KINDS[$this->kind][2] ?? 'primary';
    }

    /** "Tuesdays 5:00 pm", or null when it has no set day. */
    public function getMeetsAttribute(): ?string
    {
        if ($this->meets_day === null) {
            return null;
        }
        $time = $this->meets_time ? ' '.strtolower(date('g:i a', strtotime($this->meets_time))) : '';

        return self::DAYS[$this->meets_day].'s'.$time;
    }
}
