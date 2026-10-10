<?php

namespace App\Models;

use App\Approval\Contracts\Approvable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A month's payroll at a place (docs/specs/accounting-spec.md, A7): drafted,
 * approved through the engine (which posts it), then paid - net pay to the
 * staff and the deductions to each authority.
 */
class PayrollRun extends Model implements \OwenIt\Auditing\Contracts\Auditable, Approvable
{
    use \OwenIt\Auditing\Auditable;

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'returned' => 'Sent back', 'posted' => 'Approved - to pay', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $fillable = ['territory_id', 'month', 'status', 'gross', 'deductions', 'net', 'employer', 'journal_id', 'prepared_by', 'approved_by', 'approved_at', 'decision_note'];

    protected $casts = ['gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer' => 'decimal:2', 'approved_at' => 'datetime'];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class)->orderBy('name');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class)->orderBy('id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** "October 2026" */
    public function label(): string
    {
        return date('F Y', strtotime("{$this->month}-01"));
    }

    public function approvalPlace(): Territory
    {
        return Territory::findOrFail($this->territory_id);
    }

    public function approvalContext(): array
    {
        return ['amount' => round((float) $this->gross, 2), 'kind' => 'payroll', 'document' => 'payroll_run'];
    }

    public function approvalSummary(): array
    {
        return ['type' => 'payroll_run', 'label' => 'Payroll', 'number' => $this->label(), 'title' => 'Payroll for '.$this->label(), 'amount' => (float) $this->gross, 'page' => 'payroll.php', 'param' => 'run'];
    }

    public function approvalDetails(): array
    {
        $this->loadMissing(['payslips', 'preparer']);

        return [
            'facts' => [
                ['Month', $this->label()],
                ['People', (string) $this->payslips->count()],
                ['Gross pay', 'KES '.number_format((float) $this->gross, 2)],
                ['Deductions', 'KES '.number_format((float) $this->deductions, 2)],
                ['Net pay', 'KES '.number_format((float) $this->net, 2)],
                ['Employer NSSF and Housing Levy', 'KES '.number_format((float) $this->employer, 2)],
                ['Prepared by', $this->preparer?->full_name],
            ],
            // Gross per person, so the lines add up to the amount approved; net beside it.
            'lines' => $this->payslips->map(fn ($p) => [$p->name.($p->position ? " - {$p->position}" : '').' (net '.number_format((float) $p->net, 2).')', (float) $p->gross])->values()->all(),
            'media' => [],
        ];
    }

    public function approvalOutcome(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment): void
    {
        app(\App\Services\Accounting\Payroll::class)->outcome($this, $outcome, $by, $comment);
    }
}
