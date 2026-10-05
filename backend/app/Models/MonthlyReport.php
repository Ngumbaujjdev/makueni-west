<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A place's report for one month (docs/specs/monthly-reports-spec.md): the
 * figures (live while a draft, frozen when sent) and the pastor's words.
 */
class MonthlyReport extends Model implements Auditable, HasMedia
{
    use InteractsWithMedia, \OwenIt\Auditing\Auditable;

    public const STATUSES = ['draft', 'sent', 'seen'];

    /** What only the pastor knows - the six boxes. */
    public const WORDS = ['achievements', 'challenges', 'prayer_requests', 'support_needed', 'testimonies', 'next_month'];

    public const MAX_ATTACHMENTS = 5;

    protected $fillable = [
        'uid', 'territory_id', 'year', 'month', 'status', 'figures', ...self::WORDS, 'pastoral_visits', 'outreach',
        'sent_at', 'sent_by', 'seen_at', 'seen_by', 'created_by', 'updated_by',
    ];

    protected $casts = ['figures' => 'array', 'sent_at' => 'datetime', 'seen_at' => 'datetime', 'year' => 'integer', 'month' => 'integer', 'pastoral_visits' => 'integer'];

    /** The figures are a snapshot - keep them out of the audit log. */
    protected $auditExclude = ['figures'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uid ??= (string) Str::uuid());
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MonthlyReportComment::class)->orderBy('created_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function label(): string
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->format('F Y');
    }
}
