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
use App\Services\People\People;
use App\Support\PeopleAccess;

/**
 * The church's member directory (docs/specs/people-and-care-spec.md, P1) -
 * names and phones, so only the church's own leaders with the export
 * permission can make it, and only for their own church. Every export is
 * kept in the report runs.
 */
final class MembersDirectoryReport extends Report
{
    public function key(): string
    {
        return 'members.directory';
    }

    public function title(): string
    {
        return 'Member directory';
    }

    public function description(): string
    {
        return 'Everyone in our register: name, phone, age, status, joined and baptised. Private to our church.';
    }

    public function module(): string
    {
        return 'members';
    }

    public function icon(): string
    {
        return 'ri-contacts-book-2-line';
    }

    public function subject(): string
    {
        return 'member directory';
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
        return PeopleAccess::canNamed($user, $territory, 'members', 'export')
            ? null
            : 'Only your own church\'s leaders with the export permission can export the member directory.';
    }

    public function build(ReportContext $context): ReportData
    {
        $people = app(People::class);
        $today = $people->today();
        $members = Person::where('territory_id', $context->territory->id)->listed()->where('status', '!=', 'visitor')
            ->orderBy('first_name')->orderBy('last_name')->get();
        $statuses = Person::STATUSES;
        $rows = $members->map(fn (Person $p) => [
            $p->name, $p->phone ?: '-', $p->gender ? ucfirst($p->gender) : '-', $p->ageOn($today) ?? '-',
            $statuses[$p->status] ?? $p->status, $p->joined_on?->format('j M Y') ?? '-', $p->baptised_on ? 'Yes' : 'No',
        ])->values()->all();
        $active = $members->where('status', 'member')->count();

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Member directory',
            periodLabel: $today->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Members', 'value' => (string) $active, 'tone' => 'primary'],
                ['label' => 'Baptised', 'value' => (string) $members->where('status', 'member')->whereNotNull('baptised_on')->count(), 'tone' => 'success'],
                ['label' => 'Others listed', 'value' => (string) ($members->count() - $active), 'tone' => 'purple'],
            ],
            meta: ['As at' => $today->format('j M Y'), 'Prepared by' => $context->preparedBy(), 'Private' => 'For our church only - do not share outside the church'],
            sections: [new ReportSection('Members', [
                ReportColumn::text('Name', true), ReportColumn::text('Phone'), ReportColumn::text('Gender'), ReportColumn::text('Age'),
                ReportColumn::text('Status'), ReportColumn::text('Joined'), ReportColumn::text('Baptised'),
            ], $rows, $rows ? null : 'No one in the register yet.')],
            insights: [],
        );
    }
}
