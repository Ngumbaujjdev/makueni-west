<?php

namespace App\Services\Facilities;

use App\Http\Controllers\Api\Settings\ServiceTimesController;
use App\Models\Activity;
use App\Models\DutyRota;
use App\Models\Equipment;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceJob;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\Territory;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A church's facilities (docs/specs/people-and-care-spec.md, P5): rooms and
 * who has them when - weekly bookings expanded and every date checked for a
 * clash - equipment and loans, repairs, and who is on duty at each service.
 * Times are Nairobi wall-clock times, stored as they read.
 */
final class Facilities
{
    public const TZ = 'Africa/Nairobi';

    /** A weekly booking runs at most this long. */
    public const MAX_WEEKS = 52;

    public function __construct(private Settings $settings) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** A stored wall-clock time as a Nairobi time. */
    public static function local($value): CarbonImmutable
    {
        return CarbonImmutable::parse(is_string($value) ? $value : $value->format('Y-m-d H:i:s'), self::TZ);
    }

    /** [open from, open to] as "HH:MM". */
    public function hours(Territory $church): array
    {
        return [
            (string) ($this->settings->get('facilities.open_from', $church) ?: '06:00'),
            (string) ($this->settings->get('facilities.open_to', $church) ?: '22:00'),
        ];
    }

    public function minNoticeHours(Territory $church): int
    {
        return (int) ($this->settings->get('facilities.min_notice_hours', $church) ?? 0);
    }

    public function loanDays(Territory $church): int
    {
        return (int) ($this->settings->get('facilities.loan_days', $church) ?: 14);
    }

    public function canManage(?User $user, Territory $church): bool
    {
        return PeopleAccess::canNamed($user, $church, 'facilities', 'manage');
    }

    public function canBook(?User $user, Territory $church): bool
    {
        return PeopleAccess::canNamed($user, $church, 'facilities', 'book') || $this->canManage($user, $church);
    }

    public function rooms(Territory $church, bool $all = false): Collection
    {
        return Room::where('territory_id', $church->id)->when(! $all, fn ($q) => $q->where('active', true))->orderBy('order')->orderBy('name')->get();
    }

    public function roomRow(Room $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'capacity' => $r->capacity, 'bookable' => (bool) $r->bookable, 'colour' => $r->colour_name, 'notes' => $r->notes, 'active' => (bool) $r->active];
    }

    // ------------------------------------------------------------------ bookings

    /**
     * Every booked time in [from, to) - weekly bookings expanded, one entry a
     * week until repeat_until. [['booking' => RoomBooking, 'start' => ..., 'end' => ...]]
     */
    public function occurrences(Territory $church, CarbonImmutable $from, CarbonImmutable $to, ?int $roomId = null, ?int $except = null): Collection
    {
        $bookings = RoomBooking::with(['room', 'booker', 'ministry', 'activity'])
            ->where('territory_id', $church->id)->where('status', 'booked')
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('starts_at', '<', $to->format('Y-m-d H:i:s'))
            ->where(fn ($w) => $w->where(fn ($o) => $o->where('repeat', 'none')->where('ends_at', '>', $from->format('Y-m-d H:i:s')))
                ->orWhere(fn ($o) => $o->where('repeat', 'weekly')->where(fn ($u) => $u->whereNull('repeat_until')->orWhere('repeat_until', '>=', $from->toDateString()))))
            ->get();
        $out = collect();
        foreach ($bookings as $b) {
            $start = self::local($b->starts_at);
            $length = $start->diffInMinutes(self::local($b->ends_at));
            if ($b->repeat !== 'weekly') {
                $out->push(['booking' => $b, 'start' => $start, 'end' => $start->addMinutes($length)]);

                continue;
            }
            $until = $b->repeat_until ? CarbonImmutable::parse($b->repeat_until->toDateString(), self::TZ)->endOfDay() : $start->addWeeks(self::MAX_WEEKS);
            $k = max(0, (int) floor($start->diffInDays($from, false) / 7) - 1);
            for ($s = $start->addWeeks($k); $s->lt($to) && $s->lte($until); $s = $s->addWeek()) {
                if ($s->addMinutes($length)->gt($from)) {
                    $out->push(['booking' => $b, 'start' => $s, 'end' => $s->addMinutes($length)]);
                }
            }
        }

        return $out->sortBy(fn ($o) => $o['start']->format('Y-m-d H:i').'-'.$o['booking']->room_id)->values();
    }

    /** The times a new or changed booking takes: one, or one a week until the date. */
    public function slots(CarbonImmutable $start, CarbonImmutable $end, string $repeat, ?string $until): array
    {
        if ($repeat !== 'weekly') {
            return [[$start, $end]];
        }
        $last = CarbonImmutable::parse($until, self::TZ)->endOfDay();
        $slots = [];
        for ($s = $start, $e = $end; $s->lte($last) && count($slots) < self::MAX_WEEKS; $s = $s->addWeek(), $e = $e->addWeek()) {
            $slots[] = [$s, $e];
        }

        return $slots;
    }

    /** The first booking in that room that overlaps any of the slots - or null. */
    public function clash(Territory $church, int $roomId, array $slots, ?int $except = null): ?array
    {
        if (! $slots) {
            return null;
        }
        $taken = $this->occurrences($church, $slots[0][0]->startOfDay(), end($slots)[1]->endOfDay(), $roomId, $except);
        foreach ($slots as [$s, $e]) {
            $hit = $taken->first(fn ($o) => $o['start']->lt($e) && $o['end']->gt($s));
            if ($hit) {
                return $this->occurrenceRow($hit);
            }
        }

        return null;
    }

    /** One booked time as the calendar and lists show it. */
    public function occurrenceRow(array $o, ?User $user = null, ?Territory $church = null): array
    {
        $b = $o['booking'];

        return [
            'id' => $b->id,
            'key' => "b{$b->id}-{$o['start']->format('Ymd')}",
            'room' => $b->room ? ['id' => $b->room->id, 'name' => $b->room->name, 'colour' => $b->room->colour_name] : null,
            'date' => $o['start']->toDateString(),
            'starts_at' => $o['start']->format('Y-m-d\TH:i'),
            'ends_at' => $o['end']->format('Y-m-d\TH:i'),
            'start_time' => $o['start']->format('H:i'),
            'end_time' => $o['end']->format('H:i'),
            'purpose' => $b->purpose,
            'booked_by' => $b->booked_by,
            'booker' => $b->booker ? trim("{$b->booker->firstname} {$b->booker->lastname}") : null,
            'ministry' => $b->ministry ? ['id' => $b->ministry->id, 'name' => $b->ministry->name, 'icon' => $b->ministry->icon_name, 'colour' => $b->ministry->colour_name] : null,
            'activity' => $b->activity ? ['id' => $b->activity->id, 'title' => $b->activity->title] : null,
            'repeat' => $b->repeat,
            'repeat_until' => $b->repeat_until?->toDateString(),
            'first_date' => self::local($b->starts_at)->toDateString(),
            'can_change' => $user && $church ? ((int) $b->booked_by === (int) $user->id || $this->canManage($user, $church)) && ! $b->activity_id : false,
        ];
    }

    /** "Fellowship hall is already booked then - Youth Service, Sat 10 Oct 15:00-17:00 (booked by ...)." */
    public static function clashMessage(array $clash): string
    {
        $when = CarbonImmutable::parse($clash['starts_at'], self::TZ);

        return "{$clash['room']['name']} is already booked then - {$clash['purpose']}, {$when->format('D j M')} {$clash['start_time']}-{$clash['end_time']}".($clash['booker'] ? " (booked by {$clash['booker']})" : '').'.';
    }

    /** An event's times (stored in UTC) as Nairobi wall-clock times. */
    public static function activitySlot($startsAt, $endsAt): array
    {
        $at = fn ($v) => ($v instanceof \DateTimeInterface ? CarbonImmutable::instance($v) : CarbonImmutable::parse($v, 'UTC'))->setTimezone(self::TZ);

        return [$at($startsAt), $at($endsAt)];
    }

    /**
     * The room for an event: booked with it, moved when it moves, cancelled
     * when it is cancelled or the room is taken off. Returns the clash (and
     * changes nothing) when the room is taken.
     */
    public function roomForActivity(Territory $church, Activity $a, ?int $roomId, ?User $by): ?array
    {
        $b = RoomBooking::where('activity_id', $a->id)->where('status', 'booked')->first();
        if (! $roomId || $a->status === 'cancelled') {
            $b?->update(['status' => 'cancelled']);

            return null;
        }
        [$start, $end] = self::activitySlot($a->starts_at, $a->ends_at);
        if ($clash = $this->clash($church, $roomId, [[$start, $end]], $b?->id)) {
            return $clash;
        }
        $fields = ['room_id' => $roomId, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'), 'purpose' => $a->title, 'repeat' => 'none', 'repeat_until' => null];
        $b ? $b->update($fields) : RoomBooking::create($fields + ['territory_id' => $church->id, 'activity_id' => $a->id, 'booked_by' => $by?->id, 'status' => 'booked']);

        return null;
    }

    // ------------------------------------------------------------------ equipment and repairs

    /** Loaned out now, by equipment id (sum of quantities not back yet). */
    public function onLoan(array $ids): Collection
    {
        return EquipmentLoan::whereIn('equipment_id', $ids ?: [0])->where('status', 'out')->selectRaw('equipment_id, sum(quantity) as n')->groupBy('equipment_id')->pluck('n', 'equipment_id');
    }

    public function equipmentRow(Equipment $e, int $out = 0, int $openRepairs = 0): array
    {
        [$cat, $icon, $colour] = Equipment::CATEGORIES[$e->category] ?? Equipment::CATEGORIES['other'];

        return [
            'id' => $e->id, 'name' => $e->name, 'category' => $e->category, 'category_label' => $cat, 'icon' => $icon, 'colour' => $colour,
            'room' => $e->room ? ['id' => $e->room->id, 'name' => $e->room->name] : null,
            'quantity' => (int) $e->quantity, 'on_loan' => $out, 'available' => max(0, (int) $e->quantity - $out),
            'condition' => $e->condition, 'condition_label' => Equipment::CONDITIONS[$e->condition][0] ?? 'Good',
            'bought_on' => $e->bought_on?->toDateString(), 'value' => $e->value !== null ? (float) $e->value : null,
            'total' => $e->value !== null ? round((float) $e->value * (int) $e->quantity, 2) : null,
            'asset_no' => $e->asset_no, 'supplier' => $e->supplier, 'in_budgets' => (bool) $e->budget_entry_id,
            'photo' => $e->relationLoaded('photos') && $e->photos->isNotEmpty() ? $e->photos->first()->present() : null,
            'serial' => $e->serial, 'notes' => $e->notes, 'open_repairs' => $openRepairs,
        ];
    }

    public function loanRow(EquipmentLoan $l): array
    {
        $today = $this->today()->toDateString();

        return [
            'id' => $l->id, 'equipment_id' => $l->equipment_id, 'equipment' => $l->equipment?->name,
            'to_name' => $l->person?->name ?? $l->to_name, 'to_person_id' => $l->to_person_id, 'quantity' => (int) $l->quantity,
            'out_on' => $l->out_on->toDateString(), 'due_on' => $l->due_on?->toDateString(), 'returned_on' => $l->returned_on?->toDateString(),
            'overdue' => $l->status === 'out' && $l->due_on && $l->due_on->toDateString() < $today, 'note' => $l->note,
            'status' => $l->status, 'status_label' => EquipmentLoan::STATUSES[$l->status][0] ?? $l->status,
            'asked_by' => $l->requested_by, 'decline_reason' => $l->decline_reason,
        ];
    }

    public function repairRow(MaintenanceJob $j): array
    {
        return [
            'id' => $j->id, 'title' => $j->title, 'detail' => $j->detail, 'priority' => $j->priority, 'status' => $j->status,
            'equipment' => $j->equipment ? ['id' => $j->equipment->id, 'name' => $j->equipment->name] : null,
            'room' => $j->room ? ['id' => $j->room->id, 'name' => $j->room->name] : null,
            'reported_by' => $j->reporter ? trim("{$j->reporter->firstname} {$j->reporter->lastname}") : null,
            'assigned_to' => $j->assigned_to, 'assignee' => $j->assignee ? trim("{$j->assignee->firstname} {$j->assignee->lastname}") : null,
            'cost' => $j->cost !== null ? (float) $j->cost : null, 'done_on' => $j->done_on?->toDateString(),
            'budget_entry_id' => $j->budget_entry_id, 'reported_on' => $j->created_at?->setTimezone(self::TZ)->toDateString(),
            'days_open' => $j->status === 'done' ? null : (int) $j->created_at?->setTimezone(self::TZ)->startOfDay()->diffInDays($this->today()),
        ];
    }

    // ------------------------------------------------------------------ the duty rota

    /** The church's services (Settings > Service times), or a Sunday service when none are set. */
    public function services(Territory $church): array
    {
        $times = ServiceTimesController::normalize(($church->metadata ?? [])['service_times'] ?? null);

        return $times ?: [['name' => 'Sunday service', 'day' => 0, 'start' => '09:00']];
    }

    /** The services between two dates - [date, service name, start] - and who is on duty at each. */
    public function rota(Territory $church, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $services = $this->services($church);
        $rows = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            foreach ($services as $s) {
                if ((int) $s['day'] === $d->dayOfWeek) {
                    $rows[] = ['date' => $d->toDateString(), 'service' => $s['name'], 'start' => $s['start']];
                }
            }
        }
        $entries = DutyRota::with('person')->where('territory_id', $church->id)->whereBetween('on', [$from->toDateString(), $to->toDateString()])->orderBy('id')->get();
        $cells = [];
        foreach ($entries as $e) {
            $cells["{$e->on->toDateString()}|{$e->service}|{$e->duty}"][] = [
                'id' => $e->id, 'person_id' => $e->person_id, 'name' => $e->who,
                'initials' => mb_strtoupper(collect(preg_split('/\s+/', trim($e->who)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')),
            ];
        }

        return ['rows' => $rows, 'cells' => (object) $cells];
    }

    /** The next service day (today counts) and who is on duty then, by duty. */
    public function nextDuty(Territory $church): array
    {
        $today = $this->today();
        $services = $this->services($church);
        $next = null;
        for ($d = $today; $d->lte($today->addDays(7)) && ! $next; $d = $d->addDay()) {
            foreach ($services as $s) {
                if ((int) $s['day'] === $d->dayOfWeek) {
                    $next = $d;
                    break;
                }
            }
        }
        $next ??= $today->next(CarbonImmutable::SUNDAY);
        $rota = $this->rota($church, $next, $next);

        return ['date' => $next->toDateString()] + $rota;
    }

    // ------------------------------------------------------------------ the page

    /** The Facilities page: the cards, today and this week, this Sunday's duty, what needs attention. */
    public function overview(Territory $church, ?User $user): array
    {
        $today = $this->today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weeks = collect(range(5, 0))->map(fn ($i) => $monday->subWeeks($i));
        $all = $this->occurrences($church, $weeks->first(), $monday->addWeek());
        $perWeek = $weeks->map(fn ($w) => $all->filter(fn ($o) => $o['start']->gte($w) && $o['start']->lt($w->addWeek()))->count())->all();
        $agenda = $this->occurrences($church, $today, $today->addDays(7));
        $equipment = Equipment::where('territory_id', $church->id)->get(['id', 'quantity', 'condition', 'name', 'category']);
        $loans = EquipmentLoan::with('equipment', 'person')->whereIn('equipment_id', $equipment->pluck('id')->all() ?: [0])->whereIn('status', ['out', 'requested'])->get();
        $asks = $loans->where('status', 'requested');
        $loans = $loans->where('status', 'out');
        $repairs = MaintenanceJob::with(['equipment', 'room', 'reporter', 'assignee'])->where('territory_id', $church->id)->where('status', '!=', 'done')->orderByRaw("priority = 'urgent' desc")->orderBy('created_at')->get();
        $overdue = $loans->filter(fn ($l) => $l->due_on && $l->due_on->toDateString() < $today->toDateString());

        return [
            'bookings_this_week' => end($perWeek),
            'bookings_last_week' => $perWeek[count($perWeek) - 2],
            'bookings_series' => $perWeek,
            'weeks' => $weeks->map(fn ($w) => $w->format('j M'))->all(),
            'rooms' => $this->rooms($church)->map(fn ($r) => $this->roomRow($r) + ['today' => $agenda->filter(fn ($o) => $o['booking']->room_id === $r->id && $o['start']->isSameDay($today))->count()])->values()->all(),
            'repairs_open' => $repairs->count(),
            'repairs_urgent' => $repairs->where('priority', 'urgent')->count(),
            'repairs_done_month' => MaintenanceJob::where('territory_id', $church->id)->where('status', 'done')->where('done_on', '>=', $today->startOfMonth()->toDateString())->count(),
            'equipment_items' => (int) $equipment->sum('quantity'),
            'equipment_kinds' => $equipment->count(),
            'equipment_poor' => $equipment->whereIn('condition', ['poor', 'broken'])->count(),
            'equipment_broken' => $equipment->where('condition', 'broken')->count(),
            'loans_out' => $loans->count(),
            'loans_overdue' => $overdue->count(),
            'agenda' => $agenda->map(fn ($o) => $this->occurrenceRow($o, $user, $church))->values()->all(),
            'duty' => $this->nextDuty($church),
            'duties' => collect(DutyRota::DUTIES)->map(fn ($d, $k) => ['key' => $k, 'label' => $d[0], 'icon' => $d[1], 'color' => $d[2]])->values()->all(),
            'attention' => [
                'repairs' => $repairs->where('priority', 'urgent')->take(5)->map(fn ($j) => $this->repairRow($j))->values()->all(),
                'broken' => $equipment->where('condition', 'broken')->take(5)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'category' => $e->category])->values()->all(),
                'overdue' => $overdue->take(5)->map(fn ($l) => $this->loanRow($l))->values()->all(),
                'asks' => $asks->sortBy('out_on')->take(5)->map(fn ($l) => $this->loanRow($l))->values()->all(),
            ],
            'asks' => $asks->count(),
        ];
    }

    // ------------------------------------------------------------------ what we own

    /**
     * What the church owns, as assets: the totals, the worth by kind, by room
     * and by the year it was bought, how much is recorded in Budgets, and the
     * items still missing a price, a receipt or a photo.
     */
    public function assets(Territory $church): array
    {
        $items = Equipment::with(['room', 'photos', 'budgetEntry'])->withCount(['media as receipts_count' => fn ($q) => $q->where('collection_name', 'receipts')])
            ->where('territory_id', $church->id)->orderBy('name')->get();
        $total = fn ($e) => $e->value !== null ? (float) $e->value * (int) $e->quantity : 0.0;
        $worth = $items->sum($total);
        $group = function (Collection $by, callable $label) use ($total) {
            return $by->map(fn ($list, $k) => ['key' => (string) $k, 'label' => $label($k, $list), 'items' => (int) $list->sum('quantity'), 'kinds' => $list->count(), 'worth' => round($list->sum($total), 2)])
                ->sortByDesc('worth')->values()->all();
        };
        $needs = $items->map(function ($e) {
            $missing = array_values(array_filter([
                $e->value === null ? 'price' : null,
                ! $e->receipts_count ? 'receipt' : null,
                $e->photos->isEmpty() ? 'photo' : null,
            ]));

            return $missing ? ['id' => $e->id, 'name' => $e->name, 'asset_no' => $e->asset_no, 'category' => $e->category, 'missing' => $missing] : null;
        })->filter()->values();
        $years = $items->filter(fn ($e) => $e->bought_on && $e->value !== null)->groupBy(fn ($e) => $e->bought_on->year)->sortKeys();
        $repairs = MaintenanceJob::where('territory_id', $church->id)->whereNotNull('cost')->get(['cost', 'done_on', 'created_at']);

        return [
            'worth' => round($worth, 2),
            'items' => (int) $items->sum('quantity'),
            'kinds' => $items->count(),
            'priced' => $items->whereNotNull('value')->count(),
            'in_budgets' => $items->whereNotNull('budget_entry_id')->count(),
            'in_budgets_worth' => round($items->whereNotNull('budget_entry_id')->sum($total), 2),
            'with_receipt' => $items->filter(fn ($e) => $e->receipts_count > 0)->count(),
            'with_photo' => $items->filter(fn ($e) => $e->photos->isNotEmpty())->count(),
            'repairs_spent' => round((float) $repairs->sum('cost'), 2),
            'by_kind' => $group($items->groupBy('category'), fn ($k) => Equipment::CATEGORIES[$k][0] ?? 'Other'),
            'by_room' => $group($items->groupBy(fn ($e) => $e->room?->name ?? 'No room'), fn ($k) => $k),
            'by_year' => $years->map(fn ($list, $y) => ['year' => (int) $y, 'spent' => round($list->sum($total), 2), 'kinds' => $list->count()])->values()->all(),
            'top' => $items->sortByDesc($total)->take(6)->map(fn ($e) => $this->equipmentRow($e))->values()->all(),
            'needs' => $needs->all(),
            'categories' => collect(Equipment::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'icon' => $c[1], 'color' => $c[2]])->values()->all(),
        ];
    }
}
