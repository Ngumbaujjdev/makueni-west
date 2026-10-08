<?php

namespace App\Reports\People;

use App\Enums\TerritoryType;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\People\Visitors;
use App\Support\PeopleAccess;

/**
 * The church's visitors (docs/specs/people-and-care-spec.md, P2) - the
 * Visitors list as it opens: everyone still visiting, and those who became
 * members in the last 90 days. Names and phones, so only the church's own
 * leaders with the export permission can make it. Every export is kept in
 * the report runs.
 */
final class VisitorsListReport extends Report
{
    public function key(): string
    {
        return 'visitors.list';
    }

    public function title(): string
    {
        return 'Visitors';
    }

    public function description(): string
    {
        return 'Our visitors: name, phone, area, first and last visit, visits, where they are in their follow-up and who follows them up. Private to our church.';
    }

    public function module(): string
    {
        return 'visitors';
    }

    public function icon(): string
    {
        return 'ri-user-heart-line';
    }

    public function subject(): string
    {
        return 'visitors list';
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
        return PeopleAccess::canNamed($user, $territory, 'visitors', 'export')
            ? null
            : 'Only your own church\'s leaders with the export permission can export the visitors.';
    }

    public function build(ReportContext $context): ReportData
    {
        $visitors = app(Visitors::class);
        $today = $visitors->today();
        $people = $visitors->query($context->territory, [], $context->user)->with('assignee')
            ->orderByDesc('first_visit_on')->orderBy('first_name')->get();
        $day = fn ($d) => $d?->format('j M Y') ?? '-';
        $rows = $people->map(fn (Person $p) => [
            $p->name, $p->phone ?: '-', $p->area ?: '-', $day($p->first_visit_on), (int) $p->visit_count, $day($p->last_visit_on),
            Person::STAGES[$p->stage] ?? ($p->stage ?: '-'),
            $p->assignee ? trim("{$p->assignee->firstname} {$p->assignee->lastname}") : 'Nobody yet',
        ])->values()->all();
        $monthStart = $today->startOfMonth()->toDateString();

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Visitors',
            periodLabel: $today->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Still visiting', 'value' => (string) $people->where('status', 'visitor')->count(), 'tone' => 'primary'],
                ['label' => 'First came this month', 'value' => (string) $people->filter(fn (Person $p) => $p->first_visit_on && $p->first_visit_on->toDateString() >= $monthStart)->count(), 'tone' => 'success'],
                ['label' => 'Became members (90 days)', 'value' => (string) $people->where('stage', 'member')->count(), 'tone' => 'purple'],
            ],
            meta: ['As at' => $today->format('j M Y'), 'Prepared by' => $context->preparedBy(), 'Private' => 'For our church only - do not share outside the church'],
            sections: [new ReportSection('Visitors', [
                ReportColumn::text('Name', true), ReportColumn::text('Phone'), ReportColumn::text('Area'), ReportColumn::text('First visit'),
                ReportColumn::number('Visits'), ReportColumn::text('Last visit'), ReportColumn::text('Stage'), ReportColumn::text('Follows up'),
            ], $rows, $rows ? null : 'No visitors yet.')],
            insights: [],
        );
    }
}
