<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's pay in a run (A7): gross, each deduction, net, and the employer's share. */
class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'name', 'position', 'pay_method', 'pay_to', 'statutory', 'basic', 'allowances', 'gross',
        'nssf', 'shif', 'ahl', 'taxable', 'paye', 'other', 'other_note', 'total_deductions', 'net', 'employer_nssf', 'employer_ahl', 'manual', 'reference',
    ];

    protected $casts = [
        'statutory' => 'boolean', 'allowances' => 'array', 'manual' => 'boolean',
        'basic' => 'decimal:2', 'gross' => 'decimal:2', 'nssf' => 'decimal:2', 'shif' => 'decimal:2', 'ahl' => 'decimal:2', 'taxable' => 'decimal:2', 'paye' => 'decimal:2',
        'other' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_nssf' => 'decimal:2', 'employer_ahl' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
