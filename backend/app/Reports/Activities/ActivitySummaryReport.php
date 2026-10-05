<?php

namespace App\Reports\Activities;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\ActivitySession;
use App\Models\Territory;
use App\Models\User;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Activities\Activities;
use App\Services\Activities\Sessions;
use App\Support\ActivityAccess;
use App\Support\PlaceAccess;

/** One event or initiative: who's taking part, the sessions (initiatives), fees, money and how it went. */
final class ActivitySummaryReport extends ActivityReport
{
    public function key(): string
    {
        return 'activity.summary';
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function description(): string
    {
        return 'One event or initiative: the places taking part, how many came, sessions and attendance, fees, income and expenses.';
    }

    public function subject(): string
    {
        return 'summary';
    }

    public function inputs(): array
    {
        return ['activity'];
    }

    /** Either kind: reading events or initiatives here is enough to list it; build() checks the activity's own kind. */
    public function authorize(User $user, Territory $territory): ?string
    {
        if (! ActivityAccess::canReadAny($user, $territory)) {
            return 'Your role cannot see events or initiatives here.';
        }
        if (! PlaceAccess::isOwn($user, $territory) && ! PlaceAccess::isBelow($user, $territory)) {
            return 'You can export your own, or those of places below you.';
        }

        return null;
    }

    public function build(ReportContext $context): ReportData
    {
        $activity = Activity::with(['territory', 'registrations.territory'])->findOrFail((int) $context->param('activity_id'));
        $service = app(Activities::class);
        $totals = $service->totals($activity);
        $money = $service->money($activity);
        $regs = $activity->registrations->where('status', 'registered')->sortBy(fn ($r) => $service->regionOf($r->territory)?->name.' '.$r->territory?->name);

        $rows = $regs->map(fn (ActivityRegistration $r) => [
            $r->territory?->name, $service->regionOf($r->territory)?->name ?? '-',
            $r->youth, $r->adults, $r->children, $r->leaders, $r->expected(),
            ($activity->kind === 'initiative' ? $r->completed : $r->came()) ?? '-', (float) $r->fee_due, (float) $r->fee_paid,
        ])->values()->all();

        $initiative = $activity->kind === 'initiative';
        $sections = [];
        if ($initiative) {
            $sessions = $activity->sessions()->get();
            $sessionRows = $sessions->map(fn (ActivitySession $s) => [
                $s->number, $s->held_on->format('D j M Y'), $s->topic ?: '-', ucfirst($s->status),
                $s->youth ?? '-', $s->adults ?? '-', $s->children ?? '-', $s->leaders ?? '-', $s->attendance() ?? '-',
            ])->values()->all();
            $sections[] = new ReportSection('Sessions', [
                ReportColumn::number('No.'), ReportColumn::text('Date'), ReportColumn::text('Topic', true), ReportColumn::text('Status'),
                ReportColumn::number('Youth', 'sum'), ReportColumn::number('Adults', 'sum'), ReportColumn::number('Children', 'sum'),
                ReportColumn::number('Leaders', 'sum'), ReportColumn::number('Attendance', 'sum', true),
            ], $sessionRows, $sessionRows ? null : 'No sessions.');
        }
        $sections[] = new ReportSection($initiative ? 'Who is taking part' : 'Who is coming', [
            ReportColumn::text('Place', true), ReportColumn::text('Region'), ReportColumn::number('Youth', 'sum'), ReportColumn::number('Adults', 'sum'),
            ReportColumn::number('Children', 'sum'), ReportColumn::number('Leaders', 'sum'), ReportColumn::number('Expected', 'sum', true),
            ReportColumn::number($initiative ? 'Finished' : 'Came', 'sum'), ReportColumn::money('Fee due'), ReportColumn::money('Fee paid'),
        ], $rows, $rows ? null : 'No place has registered.');
        if ($money['entries']) {
            $sections[] = new ReportSection('Money', [ReportColumn::text('Date'), ReportColumn::text('What for', true), ReportColumn::money('Income'), ReportColumn::money('Expenses')],
                array_map(fn ($e) => [$e['date'], $e['description'], $e['direction'] === 'in' ? $e['amount'] : 0, $e['direction'] === 'out' ? $e['amount'] : 0], $money['entries']));
        }

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $activity->title,
            periodLabel: self::when($activity->starts_at, $activity->ends_at),
            scopeLabel: $context->scopeLabel(),
            tiles: $initiative ? [
                ['label' => 'Places taking part', 'value' => (string) $totals['places'], 'tone' => 'primary'],
                ['label' => 'Sessions held', 'value' => ($sum = Sessions::summary($activity))['held'].' of '.$sum['total'], 'tone' => 'purple'],
                ['label' => 'Average attendance', 'value' => $sum['average'] === null ? '-' : number_format($sum['average']), 'tone' => 'success'],
                ['label' => 'Income', 'value' => self::money($money['in']), 'tone' => 'secondary'],
            ] : [
                ['label' => 'Places', 'value' => (string) $totals['places'], 'tone' => 'primary'],
                ['label' => 'Expected', 'value' => number_format($totals['expected']), 'tone' => 'purple'],
                ['label' => 'Came', 'value' => $totals['came'] === null ? '-' : number_format($totals['came']), 'tone' => 'success'],
                ['label' => 'Income', 'value' => self::money($money['in']), 'tone' => 'secondary'],
            ],
            meta: [
                'Organised by' => $activity->territory?->name,
                'Kind' => $activity->typeLabel(),
                'Where' => $activity->venue ?: '-',
                ...($initiative ? ['Meets' => Activity::FREQUENCIES[$activity->frequency] ?? '-'] : []),
                'Status' => ucfirst($activity->status),
                'Prepared by' => $context->preparedBy(),
                'Prepared' => now()->format('j M Y, H:i'),
            ],
            sections: $sections,
            insights: [],
        );
    }
}
