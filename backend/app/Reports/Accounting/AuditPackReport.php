<?php

namespace App\Reports\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Models\User;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use Carbon\CarbonImmutable;

/**
 * The audit pack (docs/specs/accounting-spec.md, A9): everything an auditor
 * asks for in one document, behind a cover - income and expenditure,
 * financial position, receipts and payments, changes in funds, the trial
 * balance before the year-end close, then how the year was kept: each
 * month's close and each money account's last reconciliation. Built in the
 * background like every report.
 */
final class AuditPackReport extends StatementReport
{
    public function key(): string
    {
        return 'accounting.statement.audit-pack';
    }

    public function title(): string
    {
        return 'Audit pack';
    }

    public function description(): string
    {
        return 'The year\'s statements, the trial balance, the month-end closes and the reconciliations in one document for the auditor.';
    }

    public function icon(): string
    {
        return 'ri-folder-shield-2-line';
    }

    public function subject(): string
    {
        return 'audit pack';
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->yearDates($context);
        $place = $context->territory;
        $consolidated = $this->consolidated($context);
        $st = $this->statements();
        $ie = $st->incomeExpenditure($place, $from, $to, $consolidated);
        $position = $st->position($place, $to, $consolidated);
        $rp = $st->receiptsPayments($place, $from, $to, $consolidated);
        $funds = $st->changesInFunds($place, $from, $to, $consolidated);
        $tb = $st->trialBalance($place, $to, $consolidated, true);
        $part = fn (string $title, array $sections) => array_map(fn (ReportSection $s, int $i) => $i === 0 ? new ReportSection("{$title} - {$s->heading}", $s->columns, $s->rows, $s->note, $s->totalsLabel) : $s, $sections, array_keys($sections));
        $year = (int) substr($to, 0, 4);
        $closed = AccountingYear::where('territory_id', $place->id)->where('year', $year)->where('status', 'closed')->first();

        return new ReportData(
            kicker: $context->kicker('audit pack'),
            title: 'Audit pack',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $this->scope($context, $ie),
            tiles: [
                ['label' => 'Income', 'value' => $this->money($ie['totals']['income']), 'tone' => 'success'],
                ['label' => 'Expenditure', 'value' => $this->money($ie['totals']['expense']), 'tone' => 'danger'],
                ['label' => 'Net assets', 'value' => $this->money($position['totals']['net_assets']), 'tone' => 'primary'],
                ['label' => 'Books balance', 'value' => $tb['balanced'] && $position['totals']['balanced'] && $rp['totals']['balanced'] ? 'Yes' : 'No', 'tone' => $tb['balanced'] ? 'success' : 'danger'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Year-end close' => $closed ? 'Closed '.$closed->closed_at?->format('j M Y') : 'Not closed yet', 'Prepared by' => $context->preparedBy()],
            sections: [
                ...$part('Income and expenditure', $this->ieSections($ie)),
                ...$part('Financial position', $this->positionSections($position)),
                ...$part('Receipts and payments', $this->receiptsPaymentsSections($rp)),
                ...$this->fundsSections($funds),
                ...$part('Trial balance before the close', $this->trialBalanceSections($tb)),
                ...($consolidated ? [] : [$this->closes($place->id, $from, $to), $this->reconciliations($place->id, $to)]),
            ],
            cover: [
                'title' => 'Audit pack',
                'subtitle' => $this->scope($context, $ie),
                'lines' => ['Period' => $this->rangeLabel($from, $to), 'Year-end close' => $closed ? 'Closed' : 'Not closed yet', 'Prepared by' => $context->preparedBy(), 'Printed' => now()->format('j M Y')],
                'logo_path' => $this->placeLogo($place),
            ],
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Treasurer'], ['label' => 'Chairperson'], ['label' => 'Auditor']],
        );
    }

    /** Each month of the period: closed or not, by whom. */
    private function closes(int $placeId, string $from, string $to): ReportSection
    {
        $periods = AccountingPeriod::where('territory_id', $placeId)->get()->keyBy(fn ($p) => sprintf('%04d-%02d', $p->year, $p->month));
        $names = User::whereIn('id', $periods->pluck('closed_by')->filter())->get()->mapWithKeys(fn ($u) => [$u->id => $u->full_name]);
        $rows = [];
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->lte(CarbonImmutable::parse($to)); $m = $m->addMonth()) {
            $p = $periods->get($m->format('Y-m'));
            $rows[] = [$m->format('F Y'), $p?->status === 'closed' ? 'Closed' : 'Open', $p?->closed_at?->format('j M Y'), $p?->closed_by ? ($names[$p->closed_by] ?? null) : null, $p?->reopen_reason];
        }

        return new ReportSection('Month-end closes', [ReportColumn::text('Month', true), ReportColumn::text('Status'), ReportColumn::text('Closed on'), ReportColumn::text('By'), ReportColumn::text('Reopened because')], $rows);
    }

    /** Each money account's last signed-off reconciliation up to the end of the period. */
    private function reconciliations(int $placeId, string $to): ReportSection
    {
        $accounts = AccountingAccount::usableBy($placeId)->whereNotNull('cash_kind')->where('is_header', false)
            ->whereIn('id', JournalLine::where('territory_id', $placeId)->where('date', '<=', $to)->select('account_id'))->orderBy('code')->get();
        $rows = [];
        foreach ($accounts as $a) {
            $r = BankReconciliation::where('territory_id', $placeId)->where('account_id', $a->id)->where('status', 'approved')->where('statement_date', '<=', $to)->latest('statement_date')->first();
            $rows[] = [trim("{$a->code} {$a->name}"), $r?->statement_date?->format('j M Y') ?? 'Never', $r ? (float) $r->statement_balance : null, $r ? (float) $r->book_balance : null, $r ? (float) $r->difference : null];
        }

        return new ReportSection('Reconciliations', [ReportColumn::text('Account', true), ReportColumn::text('Last signed off'), ReportColumn::money('Statement', null), ReportColumn::money('Books', null), ReportColumn::money('Difference', null)], $rows,
            'The last reconciliation signed off for each money account, up to the end of the period.');
    }
}
