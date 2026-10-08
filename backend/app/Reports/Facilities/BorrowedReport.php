<?php

namespace App\Reports\Facilities;

use App\Enums\TerritoryType;
use App\Models\EquipmentLoan;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Facilities\Facilities;
use App\Support\PeopleAccess;

/**
 * What is borrowed (docs/specs/people-and-care-spec.md, P5 round 2): what is
 * out now (late first), the asks waiting for an answer, and what came back in
 * the last 90 days. Names, so only the church's own leaders can make it.
 */
final class BorrowedReport extends Report
{
    public function key(): string
    {
        return 'facilities.loans';
    }

    public function title(): string
    {
        return 'What is borrowed';
    }

    public function description(): string
    {
        return 'What is out on loan now and who has it (late ones first), the asks to borrow waiting for an answer, and what came back in the last 90 days.';
    }

    public function module(): string
    {
        return 'facilities';
    }

    public function icon(): string
    {
        return 'ri-hand-coin-line';
    }

    public function subject(): string
    {
        return 'borrowed things';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    public function inputs(): array
    {
        return [];
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        return PeopleAccess::canNamed($user, $territory, 'facilities', 'export')
            ? null
            : 'Only your own church\'s leaders with the export permission can export what is borrowed.';
    }

    public function build(ReportContext $context): ReportData
    {
        $today = app(Facilities::class)->today();
        $since = $today->subDays(90)->toDateString();
        $loans = EquipmentLoan::with(['equipment', 'person', 'asker'])->whereHas('equipment', fn ($q) => $q->where('territory_id', $context->territory->id))
            ->where(fn ($q) => $q->whereIn('status', ['out', 'requested'])->orWhere(fn ($w) => $w->where('status', 'returned')->where('returned_on', '>=', $since)))->get();
        $day = fn ($d) => $d?->format('j M Y') ?? '-';
        $late = fn (EquipmentLoan $l) => $l->due_on && $l->due_on->toDateString() < $today->toDateString();
        $out = $loans->where('status', 'out')->sortBy(fn ($l) => [$late($l) ? 0 : 1, $l->due_on?->toDateString()]);
        $asks = $loans->where('status', 'requested')->sortBy('out_on');
        $back = $loans->where('status', 'returned')->sortByDesc('returned_on');
        $name = fn (EquipmentLoan $l) => $l->person?->name ?? $l->to_name;

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'What is borrowed',
            periodLabel: 'As at '.$today->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Out now', 'value' => (string) $out->count(), 'tone' => 'primary'],
                ['label' => 'Not back on time', 'value' => (string) $out->filter($late)->count(), 'tone' => 'danger'],
                ['label' => 'Asks waiting', 'value' => (string) $asks->count(), 'tone' => 'warning'],
                ['label' => 'Back (90 days)', 'value' => (string) $back->count(), 'tone' => 'success'],
            ],
            meta: ['As at' => $today->format('j M Y'), 'Prepared by' => $context->preparedBy(), 'Private' => 'For our church only - do not share outside the church'],
            sections: [
                new ReportSection('Out now', [
                    ReportColumn::text('Item', true), ReportColumn::text('Asset no'), ReportColumn::number('How many', 'sum'), ReportColumn::text('Who has it'),
                    ReportColumn::text('Since'), ReportColumn::text('Due back'), ReportColumn::text('Late?'), ReportColumn::text('For'),
                ], $out->map(fn ($l) => [$l->equipment?->name ?? '-', $l->equipment?->asset_no ?? '-', (int) $l->quantity, $name($l), $day($l->out_on), $day($l->due_on), $late($l) ? 'Late' : 'On time', $l->note ?: '-'])->values()->all(), $out->isEmpty() ? 'Nothing is out.' : null),
                new ReportSection('Asks waiting for an answer', [
                    ReportColumn::text('Item', true), ReportColumn::number('How many'), ReportColumn::text('Asked by'), ReportColumn::text('From'), ReportColumn::text('Until'), ReportColumn::text('For'),
                ], $asks->map(fn ($l) => [$l->equipment?->name ?? '-', (int) $l->quantity, $name($l), $day($l->out_on), $day($l->due_on), $l->note ?: '-'])->values()->all(), $asks->isEmpty() ? 'No asks waiting.' : null),
                new ReportSection('Came back (last 90 days)', [
                    ReportColumn::text('Item', true), ReportColumn::number('How many'), ReportColumn::text('Who had it'), ReportColumn::text('Out'), ReportColumn::text('Due'), ReportColumn::text('Back'),
                ], $back->map(fn ($l) => [$l->equipment?->name ?? '-', (int) $l->quantity, $name($l), $day($l->out_on), $day($l->due_on), $day($l->returned_on)])->values()->all(), $back->isEmpty() ? 'Nothing came back in the last 90 days.' : null),
            ],
            insights: [],
        );
    }
}
