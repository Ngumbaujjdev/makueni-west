<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Someone in a church's private register (docs/specs/people-and-care-spec.md):
 * a member, or a visitor (P2). Only the church's own leaders ever see
 * them - the region and diocese get counts. ID, address, notes and next of
 * kin's phone are encrypted, and never written into the audit trail.
 */
class Person extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $table = 'people';

    public const STATUSES = ['visitor' => 'Visitor', 'member' => 'Member', 'inactive' => 'Inactive', 'transferred_out' => 'Transferred out', 'deceased' => 'Deceased'];

    public const HOW_JOINED = ['conversion' => 'Conversion', 'transfer' => 'Transfer', 'baptism' => 'Baptism', 'birth' => 'Born into the church', 'other' => 'Other'];

    public const MARITAL = ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'divorced' => 'Divorced', 'other' => 'Other'];

    /** A visitor's follow-up stage (P2); "member" once they joined. */
    public const STAGES = ['new' => 'New', 'contacted' => 'Contacted', 'returning' => 'Returning', 'regular' => 'Regular', 'member' => 'Became a member'];

    /** Visits that make a visitor "regular". */
    public const REGULAR_AFTER = 4;

    /** The Demographics age bands (docs/specs/demographics-module-spec.md). */
    public const AGE_BANDS = ['children' => [0, 12, 'Children'], 'youth' => [13, 35, 'Youth'], 'adults' => [36, 59, 'Adults'], 'seniors' => [60, 200, 'Seniors']];

    /** Encrypted at rest; shown in history only as "changed". */
    public const SECRET = ['address', 'national_id', 'notes', 'next_of_kin_phone'];

    protected $fillable = [
        'territory_id', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'national_id',
        'marital_status', 'occupation', 'photo_path', 'status', 'joined_on', 'how_joined', 'previous_church', 'saved_on', 'baptised_on',
        'next_of_kin_name', 'next_of_kin_phone', 'notes', 'archived_at', 'anonymised_at', 'created_by', 'updated_by',
        'first_visit_on', 'last_visit_on', 'visit_count', 'how_heard', 'consent_contact', 'wants_visit', 'stage', 'assigned_to', 'became_member_on',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joined_on' => 'date',
        'saved_on' => 'date',
        'baptised_on' => 'date',
        'archived_at' => 'datetime',
        'anonymised_at' => 'datetime',
        'first_visit_on' => 'date',
        'last_visit_on' => 'date',
        'became_member_on' => 'date',
        'visit_count' => 'integer',
        'consent_contact' => 'boolean',
        'wants_visit' => 'boolean',
        'address' => 'encrypted',
        'national_id' => 'encrypted',
        'notes' => 'encrypted',
        'next_of_kin_phone' => 'encrypted',
    ];

    protected $auditExclude = ['photo_path', 'updated_by'];

    /** The audit trail never holds the encrypted values - only that they changed. */
    public function transformAudit(array $data): array
    {
        return self::maskSecrets($data, self::SECRET);
    }

    /** An audit's private fields as "(private)" - for the people models' transformAudit. */
    public static function maskSecrets(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            foreach (['old_values', 'new_values'] as $side) {
                if (array_key_exists($field, $data[$side] ?? [])) {
                    $data[$side][$field] = $data[$side][$field] === null || $data[$side][$field] === '' ? null : '(private)';
                }
            }
        }

        return $data;
    }

    /**
     * Remove their personal details (docs/specs/people-and-care-spec.md,
     * "Leaving the register"): names, contacts, ID, notes, photo, next of
     * kin, prayer requests and follow-up notes. The row, its dates and its
     * counts stay. Can't be undone.
     */
    public function anonymise(?int $by = null): void
    {
        if ($this->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($this->photo_path);
        }
        $this->forceFill([
            'first_name' => 'Removed', 'last_name' => 'person', 'other_names' => null, 'date_of_birth' => null, 'phone' => null, 'email' => null,
            'address' => null, 'national_id' => null, 'occupation' => null, 'photo_path' => null, 'previous_church' => null,
            'next_of_kin_name' => null, 'next_of_kin_phone' => null, 'notes' => null, 'consent_contact' => false, 'assigned_to' => null,
            'anonymised_at' => now(), 'updated_by' => $by,
        ])->save();
        $this->visits()->whereNotNull('prayer_request')->get()->each(fn (VisitorVisit $v) => $v->forceFill(['prayer_request' => null])->save());
        $this->followups()->whereNotNull('note')->get()->each(fn (VisitorFollowup $f) => $f->forceFill(['note' => null])->save());
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(PersonTransfer::class)->orderByDesc('on')->orderByDesc('id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(VisitorVisit::class)->orderByDesc('on')->orderByDesc('id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(VisitorFollowup::class)->orderByDesc('done_on')->orderByDesc('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** In the register's lists: not archived, not removed. */
    public function scopeListed(Builder $q): Builder
    {
        return $q->whereNull('archived_at')->whereNull('anonymised_at');
    }

    public function scopeMembers(Builder $q): Builder
    {
        return $q->where('status', 'member');
    }

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getInitialsAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    public function ageOn(?CarbonImmutable $day = null): ?int
    {
        return $this->date_of_birth ? (int) $this->date_of_birth->diffInYears($day ?? CarbonImmutable::now(), true) : null;
    }

    public static function bandFor(?int $age): ?string
    {
        if ($age === null) {
            return null;
        }
        foreach (self::AGE_BANDS as $key => [$from, $to]) {
            if ($age >= $from && $age <= $to) {
                return $key;
            }
        }

        return null;
    }
}
