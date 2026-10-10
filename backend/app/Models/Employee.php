<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Someone a place employs and pays (docs/specs/hr-spec.md; payroll in
 * accounting-spec A7): their job and grade, how they are paid, basic pay and
 * allowances, the member record or login they are, where they have been
 * posted, and their papers. ID number and KRA PIN are encrypted at rest and
 * only ever shown masked.
 */
class Employee extends Model implements \OwenIt\Auditing\Contracts\Auditable, HasMedia
{
    use InteractsWithMedia;
    use \OwenIt\Auditing\Auditable;

    public const TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'casual' => 'Casual'];

    public const MAX_DOCUMENTS = 5;

    public const METHODS = \App\Support\PayTo::METHODS;

    /** The personal numbers - kept out of the audit trail too. */
    public const PRIVATE = ['id_number', 'kra_pin'];

    protected $auditExclude = self::PRIVATE;

    protected $fillable = [
        'territory_id', 'user_id', 'person_id', 'name', 'phone', 'email', 'position', 'position_id', 'grade_id', 'employment_type', 'start_date', 'end_date', 'contract_end',
        'pay_method', 'pay_to', 'payee', 'basic_pay', 'allowances', 'id_number', 'kra_pin', 'is_active', 'created_by',
    ];

    protected $hidden = self::PRIVATE;

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'contract_end' => 'date', 'basic_pay' => 'decimal:2', 'allowances' => 'array', 'payee' => 'array', 'is_active' => 'boolean',
        'id_number' => 'encrypted', 'kra_pin' => 'encrypted',
    ];

    /** "•••• 5678" - the last four only, never the whole number. */
    public static function mask(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : '•••• '.mb_substr($value, -4);
    }

    /** Paid in this month (started by its end, not left before it)? */
    public function paidIn(string $month): bool
    {
        $start = "{$month}-01";
        $end = date('Y-m-t', strtotime($start));

        return $this->is_active && (! $this->start_date || $this->start_date->toDateString() <= $end) && (! $this->end_date || $this->end_date->toDateString() >= $start);
    }

    /** The place that pays this month: the one they were last posted to during it (docs/specs/hr-spec.md). */
    public function payingPlaceIn(string $month): ?int
    {
        $start = "{$month}-01";
        $end = date('Y-m-t', strtotime($start));
        $p = $this->postings()->where('from_date', '<=', $end)->where(fn ($q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $start))
            ->orderByDesc('from_date')->orderByDesc('id')->first();

        return $p ? (int) $p->territory_id : ($this->postings()->exists() ? null : (int) $this->territory_id);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(StaffPosting::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function positionRow(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
