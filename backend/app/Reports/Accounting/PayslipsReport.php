<?php

namespace App\Reports\Accounting;

use App\Models\PayrollRun;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use Carbon\CarbonImmutable;

/** One payslip per person for a payroll month: basic, allowances, gross, other deductions (SACCO, loans), net, paid to. */
class PayslipsReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.payslips';
    }

    public function title(): string
    {
        return 'Payslips';
    }

    public function description(): string
    {
        return 'A payslip for each person paid in the month: basic, allowances, gross, other deductions, net pay and where it was paid.';
    }

    public function icon(): string
    {
        return 'ri-file-user-line';
    }

    public function subject(): string
    {
        return 'payslips';
    }

    public function lockedOnly(): bool
    {
        return true;
    }

    public function inputs(): array
    {
        return ['record'];
    }

    public function checkParams(ReportContext $c): ?string
    {
        return $this->run($c) ? null : 'That payroll month isn\'t in these books.';
    }

    protected function run(ReportContext $c): ?PayrollRun
    {
        return PayrollRun::with(['payslips', 'preparer', 'approver'])->where('territory_id', $c->territory->id)->find((int) $c->param('record_id'));
    }

    protected function month(PayrollRun $r): string
    {
        return CarbonImmutable::parse("{$r->month}-01")->format('F Y');
    }

    public function build(ReportContext $context): ReportData
    {
        $run = $this->run($context);
        $sections = [];
        foreach ($run->payslips as $p) {
            $rows = [['Basic pay', (float) $p->basic]];
            foreach ((array) $p->allowances as $a) {
                $rows[] = [(string) ($a['name'] ?? $a['label'] ?? 'Allowance'), (float) ($a['amount'] ?? 0)];
            }
            $rows[] = ['Gross pay', (float) $p->gross];
            if ((float) $p->other > 0) {
                $rows[] = ['Less: '.($p->other_note ?: 'other deductions'), -1 * (float) $p->other];
            }
            $rows[] = ['NET PAY', (float) $p->net];
            $sections[] = new ReportSection(trim("{$p->name} · ".($p->position ?? '').' · paid to '.($p->pay_to ?: $p->pay_method), ' ·'), [ReportColumn::text('Pay', true), ReportColumn::money('KES', null)], $rows);
        }

        return new ReportData(
            kicker: $context->kicker('payslips'),
            title: 'Payslips - '.$this->month($run),
            periodLabel: $this->month($run),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'People', 'value' => (string) $run->payslips->count(), 'tone' => 'primary'],
                ['label' => 'Gross', 'value' => $this->money($run->gross), 'tone' => 'primary'],
                ['label' => 'Net pay', 'value' => $this->money($run->net), 'tone' => 'success'],
            ],
            meta: ['Month' => $this->month($run), 'Status' => PayrollRun::STATUSES[$run->status], 'Prepared by' => $run->preparer?->full_name ?? '-', 'Approved by' => $run->approver?->full_name ?? '-'],
            sections: $sections,
        );
    }
}
