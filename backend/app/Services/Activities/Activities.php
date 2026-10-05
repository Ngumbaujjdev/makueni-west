<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\BudgetEntry;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Notifications\PlaceNotification;
use App\Support\ActivityAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who sees which events and initiatives, who they reach, and what they add
 * up to (docs/specs/events-initiatives-spec.md). A place sees its own,
 * invitations from above (open to everyone below, to selected places, or -
 * for a church - from a church of its region), and with "below" the
 * published ones of the places under it.
 */
final class Activities
{
    /** The region a church (or subregion) belongs to. */
    public function regionOf(Territory $place): ?Territory
    {
        if ($place->territory_type?->value === 'region') {
            return $place;
        }
        foreach (PlaceAccess::ancestors($place) as $t) {
            if ($t->territory_type?->value === 'region') {
                return $t;
            }
        }

        return null;
    }

    /** Activities from above (or a church of the same region) open to this place. */
    public function invitations(Territory $place, string $kind): Builder
    {
        $ancestorIds = array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place));
        $selfAndUp = [(int) $place->id, ...$ancestorIds];
        $region = $place->territory_type?->value === 'church' ? $this->regionOf($place) : null;
        $siblingChurches = $region ? array_values(array_diff(
            array_map('intval', Territory::whereIn('id', PlaceAccess::descendantIds($region))->where('territory_type', 'church')->pluck('id')->all()),
            [(int) $place->id],
        )) : [];

        return Activity::query()
            ->where('kind', $kind)
            ->whereIn('status', ['published', 'completed', 'cancelled'])
            ->where(function ($q) use ($ancestorIds, $selfAndUp, $siblingChurches) {
                $q->where(fn ($q) => $q->whereIn('territory_id', $ancestorIds ?: [0])->where('open_to', 'below'))
                    ->orWhere(fn ($q) => $q->whereIn('territory_id', $ancestorIds ?: [0])->where('open_to', 'selected')
                        ->whereHas('invitees', fn ($q) => $q->whereIn('territories.id', $selfAndUp)))
                    ->orWhere(fn ($q) => $q->whereIn('territory_id', $siblingChurches ?: [0])->where('open_to', 'region'));
            });
    }

    /** Published (or done) activities of the places below. */
    public function below(Territory $place, string $kind): Builder
    {
        return Activity::query()->where('kind', $kind)
            ->whereIn('territory_id', PlaceAccess::descendantIds($place) ?: [0])
            ->where('status', '!=', 'draft');
    }

    /** How a place relates to an activity: own, invited, below - or null (not theirs to see). */
    public function relation(Activity $activity, Territory $place): ?string
    {
        if ((int) $activity->territory_id === (int) $place->id) {
            return 'own';
        }
        if ($this->invitations($place, $activity->kind)->whereKey($activity->id)->exists()) {
            return 'invited';
        }
        if ($place->territory_type?->value !== 'church' && $this->below($place, $activity->kind)->whereKey($activity->id)->exists()) {
            return 'below';
        }

        return null;
    }

    /** The places an activity reaches (ids) - for notifications. */
    public function reach(Activity $activity): array
    {
        $owner = $activity->territory;

        return array_values(array_unique(match ($activity->open_to) {
            'below' => PlaceAccess::descendantIds($owner),
            'selected' => $activity->invitees->flatMap(fn (Territory $t) => [(int) $t->id, ...PlaceAccess::descendantIds($t)])->all(),
            'region' => ($region = $this->regionOf($owner))
                ? array_values(array_diff(array_map('intval', Territory::whereIn('id', PlaceAccess::descendantIds($region))->where('territory_type', 'church')->pluck('id')->all()), [(int) $owner->id]))
                : [],
            default => [],
        }));
    }

    /** Leaders at these places who hold the given events permission at their level. */
    public function leadersWith(array $placeIds, string $suffix): Collection
    {
        return UserTerritoryAssignment::with(['user', 'territory', 'role.permissions'])
            ->whereIn('territory_id', $placeIds ?: [0])->where('is_active', true)->get()
            ->filter(fn ($a) => $a->user && $a->territory && $a->role?->permissions->contains(
                fn ($p) => $p->name === "{$a->territory->territory_type->value}.{$suffix}" && $p->territory_scope === $a->territory->territory_type->value,
            ))
            ->map(fn ($a) => $a->user)->unique('id')->values();
    }

    /** Tell the places it reaches (their leaders who can register). */
    public function notifyPublished(Activity $activity): int
    {
        $owner = $activity->territory;
        $by = $activity->register_by ? ' · Register by '.$activity->register_by->format('j M') : '';
        $users = $this->leadersWith($this->reach($activity), ActivityAccess::permission($activity->kind, 'register'));
        foreach ($users as $user) {
            $user->notify(new PlaceNotification(
                'invitation',
                $activity->title,
                "{$owner->name} invited you - {$activity->starts_at->format('D j M Y')}{$by}",
                $this->url($activity, $user),
                $owner,
            ));
        }

        return $users->count();
    }

    /** Tell the organiser's managers that a place registered (or changed its numbers). */
    public function notifyRegistered(ActivityRegistration $registration, bool $changed): void
    {
        $activity = $registration->activity;
        foreach ($this->leadersWith([(int) $activity->territory_id], ActivityAccess::permission($activity->kind, 'manage')) as $user) {
            $user->notify(new PlaceNotification(
                'registration',
                $activity->title,
                "{$registration->territory->name} ".($changed ? 'changed its numbers' : 'registered').": {$registration->expected()} coming",
                $this->url($activity, $user),
                $registration->territory,
            ));
        }
    }

    /** Tell the registered places it was cancelled. */
    public function notifyCancelled(Activity $activity): void
    {
        $placeIds = $activity->registrations()->where('status', 'registered')->pluck('territory_id')->all();
        foreach ($this->leadersWith($placeIds, ActivityAccess::permission($activity->kind, 'register')) as $user) {
            $user->notify(new PlaceNotification('invitation', "Cancelled: {$activity->title}", "{$activity->territory->name} cancelled it.", $this->url($activity, $user), $activity->territory));
        }
    }

    /** Where the event page is for a user (their level's events page). */
    public function url(Activity $activity, ?User $user = null): string
    {
        $level = $user ? (UserTerritoryAssignment::with('territory')->where('user_id', $user->id)->where('is_active', true)
            ->orderByRaw("assignment_type = 'primary' DESC")->first()?->territory?->territory_type?->value) : null;
        $level = in_array($level, PlaceAccess::LEVELS, true) ? $level : 'church';

        return $activity->kind === 'initiative'
            ? "/{$level}/initiatives/initiative?id={$activity->id}"
            : "/{$level}/events/event?id={$activity->id}";
    }

    /** Fee due for a registration's counts. */
    public static function feeDue(Activity $activity, array $counts): float
    {
        return round(array_sum(array_map(fn ($g) => (int) ($counts[$g] ?? 0), ActivityRegistration::GROUPS)) * (float) ($activity->fee_per_person ?? 0), 2);
    }

    /** Registrations of one activity, added up: places, expected and came by group, fees. */
    public function totals(Activity $activity): array
    {
        $regs = $activity->registrations->where('status', 'registered');
        $sum = fn (string $col) => (int) $regs->sum($col);

        return [
            'places' => $regs->count(),
            'expected' => array_sum(array_map(fn ($g) => $sum($g), ActivityRegistration::GROUPS)),
            'by_group' => collect(ActivityRegistration::GROUPS)->mapWithKeys(fn ($g) => [$g => $sum($g)])->all(),
            'came' => $regs->contains(fn ($r) => $r->came() !== null) ? $regs->sum(fn ($r) => (int) $r->came()) : null,
            'fee_due' => round((float) $regs->sum('fee_due'), 2),
            'fee_paid' => round((float) $regs->sum('fee_paid'), 2),
        ];
    }

    /** Money in and out recorded against the activity in its owner's budgets. */
    public function money(Activity $activity): array
    {
        $entries = BudgetEntry::with('lineItem')->where('activity_id', $activity->id)
            ->whereHas('budget', fn ($q) => $q->where('territory_id', $activity->territory_id))
            ->orderBy('entry_date')->get();
        $in = round((float) $entries->where('direction', 'in')->sum('amount'), 2);
        $out = round((float) $entries->where('direction', 'out')->sum('amount'), 2);

        return [
            'in' => $in,
            'out' => $out,
            'net' => round($in - $out, 2),
            'planned_income' => $activity->planned_income !== null ? (float) $activity->planned_income : null,
            'planned_spend' => $activity->planned_spend !== null ? (float) $activity->planned_spend : null,
            'entries' => $entries->map(fn (BudgetEntry $e) => [
                'id' => $e->id, 'direction' => $e->direction, 'amount' => (float) $e->amount, 'date' => $e->entry_date?->toDateString(),
                'description' => $e->description, 'budget_id' => $e->budget_id,
            ])->values()->all(),
        ];
    }

    /** The Events page's figures for a place and year. */
    public function overview(Territory $place, string $kind, int $year): array
    {
        $now = CarbonImmutable::now();
        $own = Activity::with('registrations')->where('kind', $kind)->where('territory_id', $place->id)->inYear($year, $kind)->get();
        $invited = $this->invitations($place, $kind)->inYear($year, $kind)->get();
        $registeredIds = ActivityRegistration::where('territory_id', $place->id)->where('status', 'registered')->pluck('activity_id')->all();
        $money = BudgetEntry::whereIn('activity_id', $own->pluck('id')->all() ?: [0])
            ->whereHas('budget', fn ($q) => $q->where('territory_id', $place->id));

        $byMonth = array_fill(0, 12, 0);
        foreach ($own as $a) {
            $byMonth[$a->starts_at->month - 1]++;
        }

        if ($kind === 'initiative') {
            return $this->initiativeFigures($place, $own, $invited, $registeredIds, $money, $year);
        }

        return [
            'year' => $year,
            'upcoming' => $own->where('status', 'published')->filter(fn ($a) => $a->starts_at->gte($now))->count()
                + $invited->where('status', 'published')->filter(fn ($a) => $a->starts_at->gte($now))->count(),
            'expected' => (int) $own->sum(fn ($a) => $a->registrations->where('status', 'registered')->sum(fn ($r) => $r->expected())),
            'came' => (int) $own->sum(fn ($a) => $a->registrations->sum(fn ($r) => (int) $r->came())),
            'raised' => round((float) (clone $money)->where('direction', 'in')->sum('amount'), 2),
            'by_month' => $byMonth,
            'counts' => [
                'own' => $own->count(),
                'invited' => $invited->count(),
                'invited_new' => $invited->filter(fn ($a) => $a->registrationOpen() && ! in_array($a->id, $registeredIds, true))->count(),
                'below' => $place->territory_type?->value === 'church' ? 0 : $this->below($place, $kind)->whereYear('starts_at', $year)->count(),
            ],
        ];
    }

    /** The Initiatives page's figures: active, places taking part, sessions held, attendance rate. */
    private function initiativeFigures(Territory $place, Collection $own, Collection $invited, array $registeredIds, Builder $money, int $year): array
    {
        $now = CarbonImmutable::now();
        $own->load('sessions');
        $active = fn ($a) => $a->status === 'published' && $a->ends_at->gte($now);
        $byMonth = array_fill(0, 12, 0);
        $attended = 0;
        $expectedTotal = 0;
        $heldTotal = 0;
        foreach ($own as $a) {
            // Who each session is for: its intended size, else the people the places below signed up.
            $perSession = (int) $a->capacity ?: (int) $a->registrations->where('status', 'registered')->sum(fn ($r) => $r->expected());
            foreach ($a->sessions->where('status', 'held') as $s) {
                if ((int) $s->held_on->year === $year) {
                    $byMonth[$s->held_on->month - 1]++;
                }
                $heldTotal++;
                $attended += (int) $s->attendance();
                $expectedTotal += $perSession;
            }
        }

        return [
            'year' => $year,
            'active' => $own->filter($active)->count() + $invited->filter($active)->count(),
            'taking_part' => $own->flatMap(fn ($a) => $a->registrations->where('status', 'registered')->pluck('territory_id'))->unique()->count(),
            'sessions_held' => $heldTotal,
            'attendance_rate' => $expectedTotal > 0 ? (int) round($attended / $expectedTotal * 100) : null,
            'average_attendance' => $heldTotal ? (int) round($attended / $heldTotal) : null,
            'raised' => round((float) (clone $money)->where('direction', 'in')->sum('amount'), 2),
            'by_month' => $byMonth,
            'counts' => [
                'own' => $own->count(),
                'invited' => $invited->count(),
                'invited_new' => $invited->filter(fn ($a) => $a->registrationOpen() && ! in_array($a->id, $registeredIds, true))->count(),
                'below' => $place->territory_type?->value === 'church' ? 0 : $this->below($place, 'initiative')->inYear($year, 'initiative')->count(),
            ],
        ];
    }
}
