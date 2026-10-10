<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone a place pays (docs/specs/accounting-spec.md, A7): their position,
 * how they are paid, basic pay and allowances. ID number and KRA PIN are
 * encrypted at rest and only ever shown masked.
 */
class Employee extends Model implements \OwenIt\Auditing\Contracts\Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const METHODS = \App\Support\PayTo::METHODS;

    /** The personal numbers - kept out of the audit trail too. */
    public const PRIVATE = ['id_number', 'kra_pin'];

    protected $auditExclude = self::PRIVATE;

    protected $fillable = [
        'territory_id', 'user_id', 'name', 'phone', 'email', 'position', 'start_date', 'end_date', 'pay_method', 'pay_to', 'payee',
        'basic_pay', 'allowances', 'id_number', 'kra_pin', 'is_active', 'created_by',
    ];

    protected $hidden = self::PRIVATE;

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'basic_pay' => 'decimal:2', 'allowances' => 'array', 'payee' => 'array', 'is_active' => 'boolean',
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
}
