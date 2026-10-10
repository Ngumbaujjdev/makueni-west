<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's pay in a run (A7): basic and allowances, any other deduction (a SACCO, a loan), net. */
class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'name', 'position', 'pay_method', 'pay_to', 'payee', 'basic', 'allowances', 'gross',
        'other', 'other_note', 'total_deductions', 'net', 'reference',
    ];

    protected $casts = [
        'payee' => 'array',
        'allowances' => 'array', 'basic' => 'decimal:2', 'gross' => 'decimal:2', 'other' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
