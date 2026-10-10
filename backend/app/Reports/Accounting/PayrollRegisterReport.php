<?php

namespace App\Reports\Accounting;

use App\Models\PayrollRun;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** A payroll month on one sheet: everyone's gross, other deductions and net, the totals and the approval signatures. */
final class PayrollRegisterReport extends PayslipsReport
{
    public function key(): string
    {
        return 'accounting.payroll';
    }

    public function title(): string
    {
        return 'Payroll register';
    }

    public function description(): string
    {
        return 'Everyone paid in a month on one sheet: position, paid to, gross, other deductions and net, with totals and signatures.';
    }

    public function icon(): string
    {
        return 'ri-money-dollar-box-line';
    }

    public function subject(): string
    {
        return 'payroll register';
    }

    public function build(ReportContext $context): ReportData
    {
        $run = $this->run($context);
        $rows = $run->payslips->map(fn ($p) => [$p->name, $p->position ?? '', $p->pay_to ?: $p->pay_method, (float) $p->gross, (float) $p->other, (float) $p->net])->all();

        return new ReportData(
            kicker: $context->kicker('payroll register'),
            title: 'Payroll register - '.$this->month($run),
            periodLabel: $this->month($run),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'People', 'value' => (string) $run->payslips->count(), 'tone' => 'primary'],
                ['label' => 'Gross', 'value' => $this->money($run->gross), 'tone' => 'primary'],
                ['label' => 'Other deductions', 'value' => $this->money($run->deductions), 'tone' => 'warning'],
                ['label' => 'Net pay', 'value' => $this->money($run->net), 'tone' => 'success'],
            ],
            meta: ['Month' => $this->month($run), 'Status' => PayrollRun::STATUSES[$run->status]],
            sections: [new ReportSection('Everyone paid', [ReportColumn::text('Name', true), ReportColumn::text('Position'), ReportColumn::text('Paid to'), ReportColumn::money('Gross'), ReportColumn::money('Other'), ReportColumn::money('Net', 'sum', true)], $rows, 'No PAYE, NSSF, SHIF or housing levy is deducted by the church; "Other" is SACCO or loan deductions asked for by the person.')],
            signatures: [
                ['label' => 'Prepared by', 'name' => $run->preparer?->full_name ?? ''],
                ['label' => 'Approved by', 'name' => $run->approver?->full_name ?? '', 'date' => $run->approved_at ? $this->day($run->approved_at->toDateString()) : null],
                ['label' => 'Paid by'],
            ],
        );
    }
}
