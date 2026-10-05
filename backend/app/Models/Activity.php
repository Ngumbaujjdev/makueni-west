<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An event or an initiative of a church, region or the diocese
 * (docs/specs/events-initiatives-spec.md). Places below it's open to
 * register how many are coming.
 */
class Activity extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    public const KINDS = ['event', 'initiative'];

    /** Event types by the owner's level - key => label. */
    public const TYPES = [
        'church' => [
            'sunday_service' => 'Sunday service', 'midweek' => 'Midweek service', 'prayer_meeting' => 'Prayer meeting',
            'youth_kesha' => 'Youth kesha', 'revival' => 'Revival', 'wedding' => 'Wedding', 'funeral' => 'Funeral',
            'fundraising' => 'Fundraising', 'special_service' => 'Special service', 'other' => 'Other',
        ],
        'region' => self::UPPER_TYPES,
        'diocese' => self::UPPER_TYPES,
    ];

    private const UPPER_TYPES = [
        'conference' => 'Conference', 'leadership_meeting' => 'Leadership meeting', 'revival' => 'Revival',
        'youth_convention' => 'Youth convention', 'womens' => "Women's gathering", 'mens' => "Men's gathering",
        'prayer_conference' => 'Prayer conference', 'worship_night' => 'Worship night', 'outreach' => 'Outreach',
        'training' => 'Training', 'fundraising' => 'Fundraising', 'celebration' => 'Celebration', 'other' => 'Other',
    ];

    public const AUDIENCES = [
        'everyone' => 'Everyone', 'youth' => 'Youth', 'women' => 'Women', 'men' => 'Men',
        'children' => 'Children', 'leaders' => 'Leaders', 'pastors' => 'Pastors',
    ];

    /** Who it's open to, by the owner's level. */
    public const OPEN_TO = [
        'church' => ['own' => 'Our church only', 'region' => 'The other churches of our region'],
        'region' => ['below' => 'All our churches', 'selected' => 'Selected churches'],
        'diocese' => ['below' => 'Every region and church', 'selected' => 'Selected regions and churches'],
    ];

    public const STATUSES = ['draft', 'published', 'completed', 'cancelled'];

    protected $fillable = [
        'uid', 'kind', 'territory_id', 'title', 'description', 'type', 'audience', 'starts_at', 'ends_at', 'venue', 'capacity',
        'coordinator', 'speakers', 'agenda', 'open_to', 'registration', 'register_by', 'fee_per_person', 'planned_income',
        'planned_spend', 'status', 'report_back', 'published_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'register_by' => 'date',
        'published_at' => 'datetime',
        'registration' => 'boolean',
        'fee_per_person' => 'decimal:2',
        'planned_income' => 'decimal:2',
        'planned_spend' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uid ??= (string) Str::uuid());
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(Territory::class, 'activity_invitees');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function level(): string
    {
        return $this->territory?->territory_type?->value ?? 'church';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->level()][$this->type] ?? Str::headline($this->type);
    }

    /** Can places still register? */
    public function registrationOpen(): bool
    {
        return $this->status === 'published' && $this->registration
            && ($this->register_by === null || $this->register_by->endOfDay()->isFuture())
            && $this->starts_at->isFuture();
    }
}
