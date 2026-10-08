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
 * a member, or (from P2) a visitor. Only the church's own leaders ever see
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

    /** The Demographics age bands (docs/specs/demographics-module-spec.md). */
    public const AGE_BANDS = ['children' => [0, 12, 'Children'], 'youth' => [13, 35, 'Youth'], 'adults' => [36, 59, 'Adults'], 'seniors' => [60, 200, 'Seniors']];

    /** Encrypted at rest; shown in history only as "changed". */
    public const SECRET = ['address', 'national_id', 'notes', 'next_of_kin_phone'];

    protected $fillable = [
        'territory_id', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'national_id',
        'marital_status', 'occupation', 'photo_path', 'status', 'joined_on', 'how_joined', 'previous_church', 'saved_on', 'baptised_on',
        'next_of_kin_name', 'next_of_kin_phone', 'notes', 'archived_at', 'anonymised_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joined_on' => 'date',
        'saved_on' => 'date',
        'baptised_on' => 'date',
        'archived_at' => 'datetime',
        'anonymised_at' => 'datetime',
        'address' => 'encrypted',
        'national_id' => 'encrypted',
        'notes' => 'encrypted',
        'next_of_kin_phone' => 'encrypted',
    ];

    protected $auditExclude = ['photo_path', 'updated_by'];

    /** The audit trail never holds the encrypted values - only that they changed. */
    public function transformAudit(array $data): array
    {
        foreach (self::SECRET as $field) {
            foreach (['old_values', 'new_values'] as $side) {
                if (array_key_exists($field, $data[$side] ?? [])) {
                    $data[$side][$field] = $data[$side][$field] === null || $data[$side][$field] === '' ? null : '(private)';
                }
            }
        }

        return $data;
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(PersonTransfer::class)->orderByDesc('on')->orderByDesc('id');
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
