<?php

namespace App\Services\Calendar;

use App\Http\Controllers\Api\Settings\ServiceTimesController;
use App\Models\Activity;
use App\Models\ActivitySession;
use App\Models\Budget;
use App\Models\MonthlyReport;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Budget\BudgetRollup;
use App\Services\Activities\Activities;
use App\Services\People\Visitors;
use App\Services\Reports\MonthlyReports;
use App\Support\ActivityAccess;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use App\Support\ReportsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Church life on the calendar (docs/specs/calendar-spec.md, C3): events,
 * initiative sessions, our weekly services and our due dates, read from
 * where they live - never copied into calendar_events - in the same shape as
 * the calendar's own occurrences.
 */
final class LifeFeed
{
    public const SOURCES = ['calendar', 'events', 'sessions', 'services', 'due', 'bookings'];

    /** Dates and times are the diocese's own, not UTC's. */
    public const TZ = 'Africa/Nairobi';

    private const BUDGET_PATHS = ['church' => '/church/budget', 'region' => '/region/budgets', 'diocese' => '/diocese/budgets'];

    public function __construct(private Activities $activities, private BudgetRollup $rollup) {}

    /**
     * @param  array<int, string>  $layerOf  territory id => layer
     * @param  string[]  $layers  layers asked for
     * @param  string[]  $sources  sources asked for (calendar is the caller's)
     */
    public function occurrences(Territory $place, CarbonImmutable $from, CarbonImmutable $to, array $layerOf, array $layers, array $sources, ?User $user): array
    {
        $out = [];
        foreach (['event' => 'events', 'initiative' => 'sessions'] as $kind => $source) {
            if (in_array($source, $sources, true)) {
                $activities = $this->activitiesFor($place, $kind, $from, $to, $layers, $user);
                $out = [...$out, ...($kind === 'event'
                    ? $this->events($place, $activities, $layerOf)
                    : $this->sessions($place, $activities, $from, $to, $layerOf))];
            }
        }
        if (in_array('ours', $layers, true) && in_array('services', $sources, true)) {
            $out = [...$out, ...$this->services($place, $from, $to)];
        }
        if (in_array('ours', $layers, true) && in_array('due', $sources, true)) {
            $out = [...$out, ...$this->due($place, $from, $to, $user), ...$this->reportsDue($place, $from, $to, $user), ...$this->followupsDue($place, $from, $to, $user), ...$this->careDue($place, $from, $to, $user), ...$this->dutyDue($place, $from, $to, $user)];
        }
        if (in_array('ours', $layers, true) && in_array('bookings', $sources, true)) {
            $out = [...$out, ...$this->bookings($place, $from, $to, $user)];
        }

        return $out;
    }

    /** Our own (not cancelled), invitations from above and - with "below" - the places below, that touch the range. */
    private function activitiesFor(Territory $place, string $kind, CarbonImmutable $from, CarbonImmutable $to, array $layers, ?User $user): Collection
    {
        if (! ActivityAccess::can($user, $place, 'read', $kind)) {
            return new EloquentCollection;
        }
        $inRange = fn ($q) => $q->where('starts_at', '<=', $to->endOfDay()->setTimezone('UTC'))->where('ends_at', '>=', $from->setTimezone('UTC'));
        $sets = new EloquentCollection;
        if (in_array('ours', $layers, true)) {
            $sets = $sets->merge(Activity::where('kind', $kind)->where('territory_id', $place->id)->where('status', '!=', 'cancelled')->where($inRange)->get());
        }
        if (array_intersect($layers, ['cci', 'diocese', 'region'])) {
            $sets = $sets->merge($this->activities->invitations($place, $kind)->where('status', '!=', 'cancelled')->where($inRange)->get());
        }
        if (in_array('below', $layers, true) && $place->territory_type?->value !== 'church' && ActivityAccess::can($user, $place, 'below', $kind)) {
            $sets = $sets->merge($this->activities->below($place, $kind)->where('status', '!=', 'cancelled')->where($inRange)->get());
        }

        return $sets->unique('id')->values()->load('territory:id,name,territory_type');
    }

    private function events(Territory $place, Collection $events, array $layerOf): array
    {
        $level = PlaceAccess::level($place);

        return $events->map(fn (Activity $a) => $this->item(
            key: "event-{$a->id}",
            title: $a->title,
            kind: 'event',
            start: $this->local($a->starts_at),
            end: $this->local($a->ends_at),
            layer: $this->layerFor($a, $place, $layerOf),
            owner: $a->territory,
            source: 'events',
            url: "/{$level}/events/event?id={$a->id}",
            status: $a->status,
            location: $a->venue,
            description: $a->typeLabel(),
        ))->all();
    }

    private function sessions(Territory $place, Collection $initiatives, CarbonImmutable $from, CarbonImmutable $to, array $layerOf): array
    {
        if ($initiatives->isEmpty()) {
            return [];
        }
        $level = PlaceAccess::level($place);
        $byId = $initiatives->keyBy('id');
        $sessions = ActivitySession::whereIn('activity_id', $byId->keys()->all())->where('status', '!=', 'cancelled')
            ->whereBetween('held_on', [$from->toDateString(), $to->toDateString()])->orderBy('held_on')->get();

        return $sessions->map(function (ActivitySession $s) use ($byId, $place, $layerOf, $level) {
            $a = $byId->get($s->activity_id);
            $day = $s->held_on->toDateString();
            $startTime = $a->meeting_time ? substr($a->meeting_time, 0, 5) : null;
            $endTime = $startTime ? $this->local($a->ends_at)->format('H:i') : null;
            if ($startTime && (! $endTime || $endTime <= $startTime)) {
                $endTime = CarbonImmutable::parse("{$day} {$startTime}")->addHours(2)->format('H:i');
            }

            return $this->item(
                key: "session-{$s->id}",
                title: "{$a->title} · Session {$s->number}",
                kind: 'session',
                start: $startTime ? "{$day}T{$startTime}" : $day,
                end: $startTime ? "{$day}T{$endTime}" : CarbonImmutable::parse($day)->addDay()->toDateString(),
                layer: $this->layerFor($a, $place, $layerOf),
                owner: $a->territory,
                source: 'sessions',
                url: "/{$level}/initiatives/initiative?id={$a->id}",
                status: $s->status,
                location: $a->venue,
                description: $s->topic,
                allDay: ! $startTime,
            );
        })->all();
    }

    /** Each weekly service from our service times. */
    private function services(Territory $place, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $times = ServiceTimesController::normalize($place->metadata['service_times'] ?? []);
        $level = PlaceAccess::level($place);
        $out = [];
        foreach ($times as $i => $t) {
            $day = $from;
            while ($day->dayOfWeek !== (int) $t['day']) {
                $day = $day->addDay();
            }
            for (; $day->lte($to); $day = $day->addWeek()) {
                $date = $day->toDateString();
                $end = $t['end'] ?? CarbonImmutable::parse("{$date} {$t['start']}")->addHours(2)->format('H:i');
                $out[] = $this->item(
                    key: "service-{$i}-{$date}",
                    title: $t['name'] ?: 'Service',
                    kind: 'service',
                    start: "{$date}T{$t['start']}",
                    end: "{$date}T{$end}",
                    layer: 'ours',
                    owner: $place,
                    source: 'services',
                    url: "/{$level}/settings/?section=servicetimes",
                    status: null,
                    location: null,
                    description: $t['language'] ?? null,
                );
            }
        }

        return $out;
    }

    /** Our due dates: the diocese share still to send, and next month's budget when there isn't one. */
    private function due(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        $level = PlaceAccess::level($place);
        if (! isset(self::BUDGET_PATHS[$level]) || ! PlaceAccess::can($user, $place, ['read' => 'budgets.budgets.read'], 'read')) {
            return [];
        }
        $base = self::BUDGET_PATHS[$level];
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $out = [];

        // The share, from the one source - never worked out again here.
        for ($year = $from->year; $year <= $to->year; $year++) {
            foreach ($this->rollup->contributionsOf($level, (int) $place->id, $year) as $row) {
                if (! in_array($row['status'], ['pending', 'late'], true) || $row['owed'] <= 0 || $row['end'] < $from->toDateString() || $row['end'] > $to->toDateString()) {
                    continue;
                }
                $late = $row['status'] === 'late';
                $out[] = $this->item(
                    key: "share-{$row['budget_id']}-{$row['deduction_id']}",
                    title: "{$row['name']} for {$row['label']}",
                    kind: 'due',
                    start: $row['end'],
                    end: CarbonImmutable::parse($row['end'])->addDay()->toDateString(),
                    layer: 'ours',
                    owner: $place,
                    source: 'due',
                    url: "{$base}/contributions.php",
                    status: $late ? 'late' : 'due',
                    location: null,
                    description: 'KES '.number_format($row['owed'], 2).' still to send'.($row['to'] ? " to {$row['to']}" : '').($late ? ' · late' : ''),
                    allDay: true,
                    tone: $late ? 'danger' : null,
                );
            }
        }

        // Next month's budget, on the 25th, from this month on.
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
            $on = $month->day(25);
            if ($on->lt($from) || $on->gt($to) || $month->lt($today->startOfMonth())) {
                continue;
            }
            $next = $month->addMonthNoOverflow();
            $has = Budget::where('territory_type', $level)->where('territory_id', $place->id)
                ->whereDate('start_date', '<=', $next->toDateString())->whereDate('end_date', '>=', $next->toDateString())->exists();
            if (! $has) {
                $label = Budget::periodLabelFor($next->year, $next->month);
                $out[] = $this->item(
                    key: "budget-{$next->format('Y-m')}",
                    title: "Prepare {$label}'s budget",
                    kind: 'due',
                    start: $on->toDateString(),
                    end: $on->addDay()->toDateString(),
                    layer: 'ours',
                    owner: $place,
                    source: 'due',
                    url: "{$base}/form.php",
                    status: 'due',
                    location: null,
                    description: "No budget covers {$label} yet.",
                    allDay: true,
                );
            }
        }

        return $out;
    }

    /**
     * Our monthly report's due day (L4), until it is sent. Only last month's
     * and later - older months are on the Monthly reports page, not flagged
     * on every calendar view.
     */
    private function reportsDue(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        $level = PlaceAccess::level($place);
        if (! in_array($level, ReportsAccess::REPORTING_LEVELS, true) || ! ReportsAccess::can($user, $place, 'read')) {
            return [];
        }
        $reports = app(MonthlyReports::class);
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $out = [];
        for ($m = $from->startOfMonth()->subMonthsNoOverflow(2); $m->lte($to); $m = $m->addMonthNoOverflow()) {
            $due = $reports->dueOn($place, $m->year, $m->month);
            if ($due->lt($from) || $due->gt($to) || $due->lt($today->subDays(40))) {
                continue;
            }
            $report = MonthlyReport::where('territory_id', $place->id)->where('year', $m->year)->where('month', $m->month)->first();
            if ($report && in_array($report->status, ['sent', 'seen'], true)) {
                continue;
            }
            $late = $today->gt($due);
            $out[] = $this->item(
                key: "report-{$m->format('Y-m')}",
                title: "{$m->format('F')}'s report due",
                kind: 'due',
                start: $due->toDateString(),
                end: $due->addDay()->toDateString(),
                layer: 'ours',
                owner: $place,
                source: 'due',
                url: "/{$level}/monthly-reports/report?year={$m->year}&month={$m->month}",
                status: $late ? 'late' : 'due',
                location: null,
                description: $report ? 'A draft is started'.($late ? ' · late' : '') : 'Not started yet'.($late ? ' · late' : ''),
                allDay: true,
                tone: $late ? 'danger' : null,
            );
        }

        return $out;
    }

    /**
     * Visitor follow-ups due (P2 of docs/specs/people-and-care-spec.md): one
     * line a day - "3 visitor follow-ups due" - never a name on the calendar.
     * Only for leaders who can follow visitors up.
     */
    private function followupsDue(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        if (PlaceAccess::level($place) !== 'church' || ! PeopleAccess::canNamed($user, $place, 'visitors', 'manage')) {
            return [];
        }
        $visitors = app(Visitors::class);
        $open = $visitors->base($place)->where('status', 'visitor')->whereNull('archived_at')->get();
        $latest = $visitors->latestFollowups($open);
        $days = $visitors->followupDays($place);
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $byDay = $open->map(fn (Person $p) => $visitors->dueOn($p, $latest->get($p->id), $days))->filter()
            // Late ones gather on today, so they don't hide in the past.
            ->map(fn (CarbonImmutable $d) => $d->lt($today) ? $today->toDateString() : $d->toDateString())
            ->filter(fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString())
            ->countBy()->sortKeys();

        return $byDay->map(fn (int $n, string $day) => $this->item(
            key: "followups-{$day}",
            title: $n.' visitor '.($n === 1 ? 'follow-up' : 'follow-ups').' due',
            kind: 'due',
            start: $day,
            end: CarbonImmutable::parse($day)->addDay()->toDateString(),
            layer: 'ours',
            owner: $place,
            source: 'due',
            url: '/church/visitors/?due=1',
            status: 'due',
            location: null,
            description: 'Visitors waiting for a call, an SMS or a visit',
            allDay: true,
            tone: $day === $today->toDateString() ? 'danger' : null,
        ))->values()->all();
    }

    /**
     * Pastoral care's next steps (P3): one line a day - "2 pastoral visits
     * due" - never a name. Late ones gather on today. Leaders with pastoral
     * care only.
     */
    private function careDue(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        if (PlaceAccess::level($place) !== 'church' || ! PeopleAccess::canNamed($user, $place, 'pastoral')) {
            return [];
        }
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $byDay = \App\Models\CareRecord::where('territory_id', $place->id)->where('status', 'open')->whereNotNull('next_on')->pluck('next_on')
            ->map(fn ($d) => $d->toDateString() < $today->toDateString() ? $today->toDateString() : $d->toDateString())
            ->filter(fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString())
            ->countBy()->sortKeys();

        return $byDay->map(fn (int $n, string $day) => $this->item(
            key: "care-{$day}",
            title: $n.' pastoral '.($n === 1 ? 'visit' : 'visits').' due',
            kind: 'due',
            start: $day,
            end: CarbonImmutable::parse($day)->addDay()->toDateString(),
            layer: 'ours',
            owner: $place,
            source: 'due',
            url: '/church/pastoral-care/',
            status: 'due',
            location: null,
            description: 'Next steps in pastoral care',
            allDay: true,
            tone: $day === $today->toDateString() ? 'danger' : null,
        ))->values()->all();
    }

    /** Our rooms' bookings (P5) - weekly ones expanded - for those who see the facilities. */
    private function bookings(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        if (PlaceAccess::level($place) !== 'church' || ! PeopleAccess::canNamed($user, $place, 'facilities')) {
            return [];
        }
        $facilities = app(\App\Services\Facilities\Facilities::class);

        return $facilities->occurrences($place, $from->setTimezone(self::TZ), $to->setTimezone(self::TZ))->map(fn ($o) => $this->item(
            key: "booking-{$o['booking']->id}-{$o['start']->format('Ymd')}",
            title: ($o['booking']->room?->name ?? 'Room').': '.$o['booking']->purpose,
            kind: 'booking',
            start: $o['start'],
            end: $o['end'],
            layer: 'ours',
            owner: $place,
            source: 'bookings',
            url: '/church/facilities/bookings?date='.$o['start']->toDateString(),
            status: 'booked',
            location: $o['booking']->room?->name,
            description: $o['booking']->booker ? 'Booked by '.trim("{$o['booking']->booker->firstname} {$o['booking']->booker->lastname}") : null,
        ))->values()->all();
    }

    /** Who is on duty (P5) - one line a service day, "Duty: 6 people", never a name. */
    private function dutyDue(Territory $place, CarbonImmutable $from, CarbonImmutable $to, ?User $user): array
    {
        if (PlaceAccess::level($place) !== 'church' || ! PeopleAccess::canNamed($user, $place, 'facilities')) {
            return [];
        }
        $byDay = \App\Models\DutyRota::where('territory_id', $place->id)->whereBetween('on', [$from->toDateString(), $to->toDateString()])->pluck('on')
            ->map(fn ($d) => $d->toDateString())->countBy()->sortKeys();

        return $byDay->map(fn (int $n, string $day) => $this->item(
            key: "duty-{$day}",
            title: 'Duty: '.$n.' '.($n === 1 ? 'person' : 'people'),
            kind: 'due',
            start: $day,
            end: CarbonImmutable::parse($day)->addDay()->toDateString(),
            layer: 'ours',
            owner: $place,
            source: 'due',
            url: '/church/facilities/rota',
            status: 'due',
            location: null,
            description: 'Who is on duty at the services',
            allDay: true,
        ))->values()->all();
    }

    private function layerFor(Activity $a, Territory $place, array $layerOf): string
    {
        return (int) $a->territory_id === (int) $place->id ? 'ours' : ($layerOf[(int) $a->territory_id] ?? 'below');
    }

    private function local(\DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::TZ);
    }

    private function item(
        string $key, string $title, string $kind, CarbonImmutable|string $start, CarbonImmutable|string $end, string $layer, ?Territory $owner,
        string $source, ?string $url, ?string $status, ?string $location, ?string $description, bool $allDay = false, ?string $tone = null,
    ): array {
        $fmt = fn ($v) => $v instanceof CarbonImmutable ? $v->format('Y-m-d\TH:i') : $v;

        return [
            'key' => $key,
            'event_id' => null,
            'title' => $title,
            'kind' => $kind,
            'start' => $fmt($start),
            'end' => $fmt($end),
            'all_day' => $allDay,
            'layer' => $layer,
            'owner' => ['id' => $owner?->id, 'name' => $owner?->name, 'type' => $owner?->territory_type?->value],
            'location' => $location,
            'description' => $description,
            'repeats' => 'none',
            'shared_below' => true,
            'can_edit' => false,
            'base' => null,
            'source' => $source,
            'url' => $url,
            'status' => $status,
            'tone' => $tone,
        ];
    }
}
