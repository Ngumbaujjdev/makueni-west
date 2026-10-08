<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Someone in a church's private register (docs/specs/people-and-care-spec.md):
 * a member, or a visitor (P2). Only the church's own leaders ever see
 * them - the region and diocese get counts. Churches keep only a name,
 * phone, area and gender, and for members Sunday school or main church -
 * nothing more (slimmed down 2026-10-09).
 */
class Person extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $table = 'people';

    public const STATUSES = ['visitor' => 'Visitor', 'member' => 'Member', 'inactive' => 'Inactive', 'transferred_out' => 'Transferred out', 'deceased' => 'Deceased'];

    public const HOW_JOINED = ['conversion' => 'Conversion', 'transfer' => 'Transfer', 'baptism' => 'Baptism', 'birth' => 'Born into the church', 'other' => 'Other'];

    /** Which part of the church a member belongs to - instead of an age. */
    public const CONGREGATIONS = ['sunday_school' => 'Sunday school', 'main_church' => 'Main church'];

    /** A visitor's follow-up stage (P2); "member" once they joined. */
    public const STAGES = ['new' => 'New', 'contacted' => 'Contacted', 'returning' => 'Returning', 'regular' => 'Regular', 'member' => 'Became a member'];

    /** Visits that make a visitor "regular". */
    public const REGULAR_AFTER = 4;

    protected $fillable = [
        'territory_id', 'first_name', 'last_name', 'gender', 'phone', 'area', 'congregation', 'status', 'joined_on', 'how_joined', 'previous_church',
        'saved_on', 'baptised_on', 'archived_at', 'anonymised_at', 'created_by', 'updated_by',
        'first_visit_on', 'last_visit_on', 'visit_count', 'consent_contact', 'stage', 'assigned_to', 'became_member_on',
    ];

    protected $casts = [
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
    ];

    protected $auditExclude = ['updated_by'];

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
     * "Leaving the register"): name, phone, area, previous church and
     * follow-up notes. The row, its dates and its counts stay. Can't be
     * undone.
     */
    public function anonymise(?int $by = null): void
    {
        $this->forceFill([
            'first_name' => 'Removed', 'last_name' => 'person', 'phone' => null, 'area' => null, 'previous_church' => null,
            'consent_contact' => false, 'assigned_to' => null, 'anonymised_at' => now(), 'updated_by' => $by,
        ])->save();
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
}
