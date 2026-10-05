<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Territory;
use App\Models\User;
use App\Support\CalendarAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a place sees on its calendar (docs/specs/calendar-spec.md): its own
 * events, the shared events of every place above it (the CCI calendar at
 * the top), and - when asked - the shared events of the places below.
 * Repeating events are expanded into occurrences for the range asked for.
 */
final class Calendar
{
    public const LAYERS = ['cci', 'diocese', 'region', 'ours', 'below'];

    /** Territory ids per layer for a place: ['cci' => [1], 'diocese' => [2], 'region' => [...], 'ours' => [13], 'below' => [...]] */
    public function layersFor(Territory $place): array
    {
        $layers = array_fill_keys(self::LAYERS, []);
        $layers['ours'] = [(int) $place->id];
        for ($t = $place->parent_territory_id ? Territory::find($place->parent_territory_id) : null, $depth = 0; $t && $depth < 6; $depth++) {
            $layer = match ($t->territory_type?->value) {
                'global' => 'cci',
                'diocese' => 'diocese',
                default => 'region', // region or subregion
            };
            $layers[$layer][] = (int) $t->id;
            $t = $t->parent_territory_id ? Territory::find($t->parent_territory_id) : null;
        }
        if ($place->territory_type?->value !== 'church') {
            for ($frontier = [(int) $place->id], $depth = 0; $frontier && $depth < 5; $depth++) {
                $frontier = Territory::whereIn('parent_territory_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $layers['below'] = [...$layers['below'], ...$frontier];
            }
        }

        return $layers;
    }

    /**
     * The occurrences a place sees between two dates.
     *
     * @param  string[]  $layers  which layers to include
     * @param  string[]  $kinds  only these calendar kinds (empty = all); a kind narrows the feed to calendar events
     * @param  string[]  $sources  calendar events and/or Church life items (LifeFeed::SOURCES, C3)
     */
    public function occurrences(Territory $place, CarbonImmutable $from, CarbonImmutable $to, array $layers, array $kinds = [], ?User $user = null, array $sources = ['calendar']): array
    {
        $map = $this->layersFor($place);
        $layerOf = [];
        foreach ($map as $layer => $ids) {
            foreach ($ids as $id) {
                $layerOf[$id] = $layer;
            }
        }
        $own = in_array('ours', $layers, true) ? $map['ours'] : [];
        $shared = collect($layers)->reject(fn ($l) => $l === 'ours')->flatMap(fn ($l) => $map[$l] ?? [])->unique()->values()->all();
        if ($kinds) {
            // A calendar kind (Conference, Meeting...) is about calendar events only.
            $sources = array_values(array_intersect($sources, ['calendar']));
        }
        $out = array_diff($sources, ['calendar'])
            ? app(LifeFeed::class)->occurrences($place, $from, $to, $layerOf, $layers, $sources, $user)
            : [];
        if ((! $own && ! $shared) || ! in_array('calendar', $sources, true)) {
            return $this->sorted($out);
        }

        $events = CalendarEvent::with('territory:id,name,territory_type')
            ->where(function ($q) use ($own, $shared) {
                if ($own) {
                    $q->orWhereIn('territory_id', $own);
                }
                if ($shared) {
                    $q->orWhere(fn ($q) => $q->whereIn('territory_id', $shared)->where('shared_below', true));
                }
            })
            ->where('starts_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('repeats', 'none')->where('ends_on', '>=', $from->toDateString()))
                ->orWhere(fn ($q) => $q->where('repeats', '!=', 'none')->where(fn ($q) => $q->whereNull('repeat_until')->orWhere('repeat_until', '>=', $from->toDateString()))))
            ->when($kinds, fn ($q) => $q->whereIn('kind', $kinds))
            ->orderBy('starts_on')
            ->get();

        foreach ($events as $event) {
            $canEdit = CalendarAccess::canEdit($user, $place, $event);
            foreach (self::expand($event, $from, $to) as [$start, $end]) {
                $out[] = $this->occurrence($event, $start, $end, $layerOf[(int) $event->territory_id] ?? 'below', $canEdit);
            }
        }

        return $this->sorted($out);
    }

    private function sorted(array $out): array
    {
        usort($out, fn ($a, $b) => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);

        return $out;
    }

    /** [[start, end], ...] - each occurrence's first and last day (inclusive) that touches the range. */
    public static function expand(CalendarEvent $e, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = CarbonImmutable::parse($e->starts_on->toDateString());
        $length = (int) $base->diffInDays(CarbonImmutable::parse($e->ends_on->toDateString()));
        $overlaps = fn (CarbonImmutable $s, CarbonImmutable $end) => $s->lte($to) && $end->gte($from);

        if ($e->repeats === 'none') {
            $end = $base->addDays($length);

            return $overlaps($base, $end) ? [[$base, $end]] : [];
        }

        $until = $e->repeat_until ? CarbonImmutable::parse($e->repeat_until->toDateString()) : $to;
        $until = $until->lt($to) ? $until : $to;
        $step = fn (int $n) => match ($e->repeats) {
            'weekly' => $base->addWeeks($n),
            'monthly' => $base->addMonthsNoOverflow($n),
            default => $base->addYearsNoOverflow($n),
        };
        // Jump close to the range rather than walking from an old start date.
        $gap = $base->lt($from) ? match ($e->repeats) {
            'weekly' => intdiv((int) $base->diffInDays($from), 7),
            'monthly' => (int) $base->diffInMonths($from),
            default => (int) $base->diffInYears($from),
        } : 0;

        $out = [];
        for ($n = max(0, $gap - 1), $i = 0; $i < 600; $n++, $i++) {
            $start = $step($n);
            if ($start->gt($until)) {
                break;
            }
            $end = $start->addDays($length);
            if ($overlaps($start, $end)) {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /** The calendar's KPI figures for a place (this month vs last, this week, next CCI event, our upcoming, per month). */
    public function overview(Territory $place, ?User $user = null): array
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfYear()->min($today->subMonthNoOverflow()->startOfMonth());
        // What happens on the calendar: its own events, plus events and initiative sessions (not the weekly services or due dates).
        $all = $this->occurrences($place, $from, $today->endOfYear()->max($today->addDays(60)), ['cci', 'diocese', 'region', 'ours'], [], $user, ['calendar', 'events', 'sessions']);
        $in = fn (CarbonImmutable $a, CarbonImmutable $b) => collect($all)->filter(fn ($o) => substr($o['start'], 0, 10) >= $a->toDateString() && substr($o['start'], 0, 10) <= $b->toDateString());

        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $start = $today->setDate($today->year, $m, 1);
            $byMonth[] = $in($start, $start->endOfMonth())->count();
        }
        $nextCci = collect($all)->first(fn ($o) => $o['layer'] === 'cci' && substr($o['start'], 0, 10) >= $today->toDateString());

        return [
            'this_month' => $in($today->startOfMonth(), $today->endOfMonth())->count(),
            'last_month' => $in($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth())->count(),
            'this_week' => $in($today->startOfWeek(), $today->endOfWeek())->count(),
            'ours_upcoming' => $in($today, $today->addDays(30))->where('layer', 'ours')->count(),
            'next_cci' => $nextCci ? ['title' => $nextCci['title'], 'start' => $nextCci['start'], 'kind' => $nextCci['kind']] : null,
            'by_month' => $byMonth,
            'year' => $today->year,
        ];
    }

    private function occurrence(CalendarEvent $e, CarbonImmutable $start, CarbonImmutable $end, string $layer, bool $canEdit): array
    {
        $time = fn (?string $t) => $t ? substr($t, 0, 5) : null;

        return [
            'key' => "{$e->id}-{$start->toDateString()}",
            'event_id' => $e->id,
            'title' => $e->title,
            'kind' => $e->kind,
            'start' => $e->all_day ? $start->toDateString() : $start->toDateString().'T'.$time($e->start_time),
            // FullCalendar's end is exclusive for all-day events.
            'end' => $e->all_day ? $end->addDay()->toDateString() : $end->toDateString().'T'.($time($e->end_time) ?? $time($e->start_time)),
            'all_day' => $e->all_day,
            'layer' => $layer,
            'owner' => ['id' => $e->territory_id, 'name' => $e->territory?->name, 'type' => $e->territory?->territory_type?->value],
            'location' => $e->location,
            'description' => $e->description,
            'repeats' => $e->repeats,
            'shared_below' => $e->shared_below,
            'can_edit' => $canEdit,
            // The event itself (not this occurrence) - what the edit form starts from.
            'source' => 'calendar',
            'url' => null,
            'status' => null,
            'tone' => null,
            'base' => $canEdit ? [
                'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on->toDateString(),
                'start_time' => $time($e->start_time), 'end_time' => $time($e->end_time), 'repeat_until' => $e->repeat_until?->toDateString(),
            ] : null,
        ];
    }

    /** @return Collection<int, CalendarEvent> the CCI calendar's events starting in a year (not expanded) */
    public function cciYear(int $year): Collection
    {
        $national = CalendarEvent::national();

        return $national
            ? CalendarEvent::where('territory_id', $national->id)->whereYear('starts_on', $year)->orderBy('starts_on')->get()
            : collect();
    }
}
