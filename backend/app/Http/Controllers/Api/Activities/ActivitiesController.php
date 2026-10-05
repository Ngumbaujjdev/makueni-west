<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\Territory;
use App\Models\User;
use App\Services\Activities\Activities;
use App\Services\Budgets\BudgetBook;
use App\Support\EventsAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Models\Audit;

/**
 * Events (and, from L2, initiatives) of a church, region or the diocese
 * (docs/specs/events-initiatives-spec.md): your own, invitations from above,
 * and the places below; registering counts; publishing, completing and
 * cancelling; who's coming, money and history.
 */
class ActivitiesController extends Controller
{
    public function __construct(private Activities $activities) {}

    /** GET /activities?kind=&year=&scope=own|invited|below */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(Activity::KINDS)],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'scope' => ['nullable', Rule::in(['own', 'invited', 'below'])],
        ]);
        $kind = $data['kind'] ?? 'event';
        $year = (int) ($data['year'] ?? now()->year);
        $scope = $data['scope'] ?? 'own';
        if ($scope === 'below' && ($place->territory_type->value === 'church' || ! EventsAccess::can($request->user(), $place, 'below'))) {
            return $this->forbidden("Your role can't see the places below.");
        }

        $query = match ($scope) {
            'invited' => $this->activities->invitations($place, $kind),
            'below' => $this->activities->below($place, $kind),
            default => Activity::where('kind', $kind)->where('territory_id', $place->id),
        };
        $items = $query->with(['territory', 'registrations.territory', 'invitees'])->whereYear('starts_at', $year)->orderBy('starts_at')->get();
        $mine = ActivityRegistration::where('territory_id', $place->id)->whereIn('activity_id', $items->pluck('id')->all() ?: [0])->get()->keyBy('activity_id');

        return $this->ok($items->map(fn (Activity $a) => $this->listItem($a, $scope, $mine->get($a->id)))->values());
    }

    /** GET /activities/overview?kind=&year= */
    public function overview(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $kind = in_array($request->query('kind'), Activity::KINDS, true) ? $request->query('kind') : 'event';
        $user = $request->user();
        $level = $place->territory_type->value;

        return $this->ok($this->activities->overview($place, $kind, (int) ($request->query('year') ?: now()->year)) + [
            'place' => ['id' => $place->id, 'name' => $place->name, 'type' => $level],
            'can' => [
                'manage' => EventsAccess::can($user, $place, 'manage'),
                'register' => EventsAccess::can($user, $place, 'register'),
                'below' => $level !== 'church' && EventsAccess::can($user, $place, 'below'),
            ],
            'types' => Activity::TYPES[$level] ?? Activity::TYPES['church'],
            'all_types' => Activity::TYPES['church'] + Activity::TYPES['diocese'],
            'audiences' => Activity::AUDIENCES,
            'open_to' => Activity::OPEN_TO[$level] ?? Activity::OPEN_TO['church'],
            'invitable' => $level === 'church' ? [] : Territory::whereIn('id', PlaceAccess::descendantIds($place))
                ->whereIn('territory_type', ['region', 'church'])->orderBy('territory_type', 'desc')->orderBy('name')
                ->get(['id', 'name', 'territory_type', 'parent_territory_id'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->territory_type->value])->values(),
        ]);
    }

    /** GET /activities/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($activity, $place, $relation, $request->user()));
    }

    /** POST /activities - a draft for the acting place. */
    public function store(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! PlaceAccess::isOwn($request->user(), $place) || ! EventsAccess::can($request->user(), $place, 'manage')) {
            return $this->forbidden("Your role can't add events here.");
        }
        [$fields, $invitees] = $this->validated($request, $place);
        $activity = Activity::create($fields + ['territory_id' => $place->id, 'status' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $activity->invitees()->sync($invitees);

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'own', $request->user()), 'Saved as a draft.', 201);
    }

    /** PUT /activities/{id} - own place, while a draft or published. */
    public function update(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $error] = $this->manageable($request, $id);
        if ($error) {
            return $error;
        }
        if (! in_array($activity->status, ['draft', 'published'], true)) {
            return $this->unprocessable('status', 'A completed or cancelled event can\'t be changed.');
        }
        [$fields, $invitees] = $this->validated($request, $place, $activity);
        $activity->fill($fields + ['updated_by' => $request->user()->id])->save();
        $activity->invitees()->sync($invitees);
        $this->refreshFees($activity);

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'own', $request->user()), 'Saved.');
    }

    /** POST /activities/{id}/publish - tells the places it reaches. */
    public function publish(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $error] = $this->manageable($request, $id);
        if ($error) {
            return $error;
        }
        if ($activity->status !== 'draft') {
            return $this->unprocessable('status', 'Only a draft can be published.');
        }
        $activity->forceFill(['status' => 'published', 'published_at' => now(), 'updated_by' => $request->user()->id])->save();
        $told = $this->activities->notifyPublished($activity->fresh(['territory', 'invitees']));

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'own', $request->user()) + ['notified' => $told],
            $activity->open_to === 'own' ? 'Published.' : "Published - {$told} leader(s) told.");
    }

    /** POST /activities/{id}/complete - {report_back} */
    public function complete(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $error] = $this->manageable($request, $id);
        if ($error) {
            return $error;
        }
        if ($activity->status !== 'published') {
            return $this->unprocessable('status', 'Only a published event can be marked done.');
        }
        $data = $request->validate(['report_back' => ['nullable', 'string', 'max:5000']]);
        $activity->forceFill(['status' => 'completed', 'report_back' => $data['report_back'] ?? null, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'own', $request->user()), 'Marked as done.');
    }

    /** POST /activities/{id}/cancel - tells the places that registered. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $error] = $this->manageable($request, $id);
        if ($error) {
            return $error;
        }
        if (! in_array($activity->status, ['draft', 'published'], true)) {
            return $this->unprocessable('status', 'It is already done or cancelled.');
        }
        $was = $activity->status;
        $activity->forceFill(['status' => 'cancelled', 'updated_by' => $request->user()->id])->save();
        if ($was === 'published') {
            $this->activities->notifyCancelled($activity->fresh('territory'));
        }

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'own', $request->user()), 'Cancelled.');
    }

    /** GET /activities/{id}/registrations - the organiser (or the places above) see who's coming. */
    public function registrations(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation === 'invited') {
            return $this->forbidden('Only the organiser sees every place that registered.');
        }
        $regs = $activity->registrations()->with('territory')->orderByDesc('updated_at')->get();

        return $this->ok([
            'totals' => $this->activities->totals($activity->load('registrations')),
            'items' => $regs->map(fn (ActivityRegistration $r) => $this->registrationItem($r, $activity, $place, $request->user()))->values(),
        ]);
    }

    /** POST /activities/{id}/register - the acting place says how many are coming. */
    public function register(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation !== 'invited' || ! PlaceAccess::isOwn($request->user(), $place) || ! EventsAccess::can($request->user(), $place, 'register')) {
            return $this->forbidden('Only a place it was opened to can register.');
        }
        if (! $activity->registrationOpen()) {
            return $this->unprocessable('activity', 'Registration for this event is closed.');
        }
        $counts = $this->counts($request);
        $existing = ActivityRegistration::where('activity_id', $activity->id)->where('territory_id', $place->id)->first();
        $registration = ActivityRegistration::updateOrCreate(
            ['activity_id' => $activity->id, 'territory_id' => $place->id],
            $counts + ['fee_due' => Activities::feeDue($activity, $counts), 'status' => 'registered', 'updated_by' => $request->user()->id]
                + ($existing ? [] : ['registered_by' => $request->user()->id]),
        );
        $this->activities->notifyRegistered($registration->fresh(['activity.territory', 'territory']), (bool) $existing);

        return $this->ok($this->detail($activity->fresh(['territory', 'invitees', 'registrations']), $place, 'invited', $request->user()),
            $existing ? 'Your numbers are updated.' : 'Registered.', $existing ? 200 : 201);
    }

    /**
     * PUT /registrations/{id} - the registering place changes its numbers
     * (while open) or says how many came and how it went (once it started);
     * the organiser records the fee paid.
     */
    public function updateRegistration(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $registration = ActivityRegistration::with(['activity.territory', 'territory'])->find($id);
        if (! $registration) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That registration no longer exists.'], 404);
        }
        $activity = $registration->activity;
        $user = $request->user();
        $isOrganiser = EventsAccess::canManage($user, $place, $activity) && PlaceAccess::isOwn($user, $place);
        $isRegistrant = (int) $registration->territory_id === (int) $place->id && PlaceAccess::isOwn($user, $place) && EventsAccess::can($user, $place, 'register');

        if ($isOrganiser && ! $isRegistrant) {
            $data = $request->validate(['fee_paid' => ['required', 'numeric', 'min:0', 'max:99999999']]);
            $registration->forceFill(['fee_paid' => $data['fee_paid'], 'updated_by' => $user->id])->save();

            return $this->ok($this->registrationItem($registration->fresh('territory'), $activity, $place, $user), 'Fee recorded.');
        }
        if (! $isRegistrant) {
            return $this->forbidden('Only the place that registered can change its numbers.');
        }
        if ($request->has('fee_paid')) {
            return $this->forbidden('The organiser records what was paid.');
        }

        $started = $activity->starts_at->isPast() || $activity->status === 'completed';
        $changes = [];
        if ($request->hasAny(ActivityRegistration::GROUPS) || $request->has('names')) {
            if (! $activity->registrationOpen()) {
                return $this->unprocessable('activity', 'Registration is closed - say how many came instead.');
            }
            $counts = $this->counts($request);
            $changes += $counts + ['fee_due' => Activities::feeDue($activity, $counts)];
        }
        if ($request->hasAny(['came_youth', 'came_adults', 'came_children', 'came_leaders', 'rating', 'comment'])) {
            if (! $started) {
                return $this->unprocessable('activity', 'You can say how many came once it has started.');
            }
            $changes += $request->validate([
                'came_youth' => ['nullable', 'integer', 'between:0,100000'], 'came_adults' => ['nullable', 'integer', 'between:0,100000'],
                'came_children' => ['nullable', 'integer', 'between:0,100000'], 'came_leaders' => ['nullable', 'integer', 'between:0,100000'],
                'rating' => ['nullable', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000'],
            ]);
        }
        $registration->forceFill($changes + ['updated_by' => $user->id])->save();
        if (isset($changes['fee_due'])) {
            $this->activities->notifyRegistered($registration->fresh(['activity.territory', 'territory']), true);
        }

        return $this->ok($this->registrationItem($registration->fresh('territory'), $activity, $place, $user), 'Saved.');
    }

    /** POST /registrations/{id}/withdraw */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $registration = ActivityRegistration::with('activity')->find($id);
        if (! $registration || (int) $registration->territory_id !== (int) $place->id || ! PlaceAccess::isOwn($request->user(), $place) || ! EventsAccess::can($request->user(), $place, 'register')) {
            return $this->forbidden('Only the place that registered can withdraw.');
        }
        if ($registration->activity->starts_at->isPast()) {
            return $this->unprocessable('activity', 'It has already started.');
        }
        $registration->forceFill(['status' => 'withdrawn', 'updated_by' => $request->user()->id])->save();

        return $this->ok(['id' => $registration->id, 'status' => 'withdrawn'], 'Withdrawn.');
    }

    /** GET /activities/{id}/money - the organiser's money in and out tagged with it. */
    public function money(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation === 'invited') {
            return $this->forbidden("Only the organiser sees the event's money.");
        }

        // Where "Record money" puts it: the organiser's budget in use on the event's day, else today.
        $budget = null;
        if ($relation === 'own') {
            $book = app(BudgetBook::class);
            $level = $place->territory_type->value;
            $budget = $book->budgetInUseOn($level, (int) $place->id, $activity->starts_at->toDateString())
                ?? $book->budgetInUseOn($level, (int) $place->id, now()->toDateString());
        }

        return $this->ok($this->activities->money($activity) + [
            'budget_in_use' => $budget ? ['id' => $budget->id, 'label' => $budget->period_label] : null,
        ]);
    }

    /** GET /activities/{id}/history - what happened, in plain sentences. */
    public function history(Request $request, int $id): JsonResponse
    {
        [$place, $activity, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation === 'invited') {
            return $this->forbidden('Only the organiser sees the history.');
        }
        $regIds = $activity->registrations()->pluck('id')->all();
        $audits = Audit::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auditable_type', 'activity')->where('auditable_id', $activity->id))
                ->orWhere(fn ($q) => $q->where('auditable_type', 'activity_registration')->whereIn('auditable_id', $regIds ?: [0])))
            ->latest('id')->limit(200)->get();
        $people = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $regs = ActivityRegistration::with('territory')->whereIn('id', $regIds ?: [0])->get()->keyBy('id');

        return $this->ok($audits->map(function (Audit $a) use ($people, $regs) {
            $who = ($p = $people->get($a->user_id)) ? trim("{$p->firstname} {$p->lastname}") : 'The system';
            $new = (array) $a->new_values;
            $sentence = $a->auditable_type === 'activity_registration'
                ? (($r = $regs->get($a->auditable_id)) ? match (true) {
                    $a->event === 'created' => "{$who} registered {$r->territory?->name}",
                    isset($new['fee_paid']) => "{$who} recorded {$r->territory?->name}'s fee paid: KES ".number_format((float) $new['fee_paid'], 2),
                    isset($new['status']) && $new['status'] === 'withdrawn' => "{$who} withdrew {$r->territory?->name}",
                    collect($new)->keys()->contains(fn ($k) => str_starts_with($k, 'came_')) => "{$who} said how many came from {$r->territory?->name}",
                    default => "{$who} changed {$r->territory?->name}'s numbers",
                } : "{$who} changed a registration")
                : match (true) {
                    $a->event === 'created' => "{$who} created it",
                    ($new['status'] ?? null) === 'published' => "{$who} published it",
                    ($new['status'] ?? null) === 'completed' => "{$who} marked it as done",
                    ($new['status'] ?? null) === 'cancelled' => "{$who} cancelled it",
                    default => "{$who} changed ".collect(array_keys($new))->reject(fn ($k) => in_array($k, ['updated_by', 'published_at'], true))
                        ->map(fn ($k) => str_replace('_', ' ', $k))->implode(', '),
                };

            return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
        })->values());
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: array, 1: int[]} the activity fields, and the invitee ids */
    private function validated(Request $request, Territory $place, ?Activity $activity = null): array
    {
        $level = $place->territory_type->value;
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(Activity::KINDS)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Activity::TYPES[$level] ?? []))],
            'audience' => ['sometimes', Rule::in(array_keys(Activity::AUDIENCES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'venue' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'between:1,1000000'],
            'coordinator' => ['nullable', 'string', 'max:120'],
            'speakers' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'open_to' => ['required', Rule::in(array_keys(Activity::OPEN_TO[$level] ?? []))],
            'invitees' => ['array', 'required_if:open_to,selected'],
            'invitees.*' => ['integer'],
            'registration' => ['sometimes', 'boolean'],
            'register_by' => ['nullable', 'date', 'before_or_equal:starts_at'],
            'fee_per_person' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'planned_income' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'planned_spend' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'ends_at.after_or_equal' => 'It can\'t end before it starts.',
            'register_by.before_or_equal' => 'Registration has to close by the day it starts.',
            'invitees.required_if' => 'Pick the places it\'s open to.',
            'type.in' => 'Pick a kind of event from the list.',
        ]);

        $below = PlaceAccess::descendantIds($place);
        $invitees = array_values(array_unique(array_map('intval', $data['open_to'] === 'selected' ? ($data['invitees'] ?? []) : [])));
        $outside = array_diff($invitees, $below);
        if ($outside) {
            throw ValidationException::withMessages(['invitees' => ['You can only open it to places below you.']]);
        }
        $reachesOthers = $data['open_to'] !== 'own';

        return [[
            'kind' => $activity?->kind ?? ($data['kind'] ?? 'event'),
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'audience' => $data['audience'] ?? 'everyone',
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'venue' => $data['venue'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'coordinator' => $data['coordinator'] ?? null,
            'speakers' => $data['speakers'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'open_to' => $data['open_to'],
            // Only an event that reaches other places takes registrations.
            'registration' => $reachesOthers && (bool) ($data['registration'] ?? false),
            'register_by' => $reachesOthers ? ($data['register_by'] ?? null) : null,
            'fee_per_person' => $reachesOthers ? ($data['fee_per_person'] ?? null) : null,
            'planned_income' => $data['planned_income'] ?? null,
            'planned_spend' => $data['planned_spend'] ?? null,
        ], $invitees];
    }

    private function counts(Request $request): array
    {
        $data = $request->validate([
            'youth' => ['nullable', 'integer', 'between:0,100000'], 'adults' => ['nullable', 'integer', 'between:0,100000'],
            'children' => ['nullable', 'integer', 'between:0,100000'], 'leaders' => ['nullable', 'integer', 'between:0,100000'],
            'names' => ['nullable', 'string', 'max:2000'],
        ]);
        $counts = array_combine(ActivityRegistration::GROUPS, array_map(fn ($g) => (int) ($data[$g] ?? 0), ActivityRegistration::GROUPS));
        if (array_sum($counts) === 0) {
            throw ValidationException::withMessages(['adults' => ['Say how many are coming.']]);
        }

        return $counts + ['names' => $data['names'] ?? null];
    }

    /** After the fee changes, every registration's fee due follows. */
    private function refreshFees(Activity $activity): void
    {
        foreach ($activity->registrations as $r) {
            $r->forceFill(['fee_due' => Activities::feeDue($activity, $r->only(ActivityRegistration::GROUPS))])->saveQuietly();
        }
    }

    private function listItem(Activity $a, string $scope, ?ActivityRegistration $mine): array
    {
        $totals = $this->activities->totals($a);

        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'title' => $a->title,
            'type' => $a->type,
            'type_label' => $a->typeLabel(),
            'audience' => $a->audience,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'venue' => $a->venue,
            'status' => $a->status,
            'open_to' => $a->open_to,
            'owner' => ['id' => $a->territory_id, 'name' => $a->territory?->name, 'type' => $a->level()],
            'relation' => $scope,
            'registration' => $a->registration,
            'registration_open' => $a->registrationOpen(),
            'register_by' => $a->register_by?->toDateString(),
            'fee_per_person' => $a->fee_per_person !== null ? (float) $a->fee_per_person : null,
            'totals' => $scope === 'invited' ? null : ['places' => $totals['places'], 'expected' => $totals['expected'], 'came' => $totals['came']],
            'mine' => $mine ? ['status' => $mine->status, 'expected' => $mine->expected()] : null,
        ];
    }

    private function detail(Activity $a, Territory $place, string $relation, User $user): array
    {
        $mine = ActivityRegistration::where('activity_id', $a->id)->where('territory_id', $place->id)->first();
        $manage = $relation === 'own' && EventsAccess::canManage($user, $place, $a) && PlaceAccess::isOwn($user, $place);
        $level = $a->level();

        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'title' => $a->title,
            'description' => $a->description,
            'type' => $a->type,
            'type_label' => $a->typeLabel(),
            'audience' => $a->audience,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'venue' => $a->venue,
            'capacity' => $a->capacity,
            'coordinator' => $a->coordinator,
            'speakers' => $a->speakers,
            'agenda' => $a->agenda,
            'open_to' => $a->open_to,
            'open_to_label' => Activity::OPEN_TO[$level][$a->open_to] ?? $a->open_to,
            'invitees' => $a->invitees->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->territory_type->value])->values(),
            'registration' => $a->registration,
            'registration_open' => $a->registrationOpen(),
            'register_by' => $a->register_by?->toDateString(),
            'fee_per_person' => $a->fee_per_person !== null ? (float) $a->fee_per_person : null,
            'planned_income' => $a->planned_income !== null ? (float) $a->planned_income : null,
            'planned_spend' => $a->planned_spend !== null ? (float) $a->planned_spend : null,
            'status' => $a->status,
            'report_back' => $a->report_back,
            'published_at' => $a->published_at?->toIso8601String(),
            'owner' => ['id' => $a->territory_id, 'name' => $a->territory?->name, 'type' => $level],
            'relation' => $relation,
            'totals' => $relation === 'invited' ? null : $this->activities->totals($a->loadMissing('registrations')),
            'mine' => $mine ? $this->registrationItem($mine->loadMissing('territory'), $a, $place, $user) : null,
            'can' => [
                'edit' => $manage && in_array($a->status, ['draft', 'published'], true),
                'publish' => $manage && $a->status === 'draft',
                'complete' => $manage && $a->status === 'published',
                'cancel' => $manage && in_array($a->status, ['draft', 'published'], true),
                'register' => $relation === 'invited' && $a->registrationOpen() && PlaceAccess::isOwn($user, $place) && EventsAccess::can($user, $place, 'register'),
                'say_came' => $relation === 'invited' && $mine !== null && $mine->status === 'registered' && ($a->starts_at->isPast() || $a->status === 'completed'),
                'record_money' => $manage && $a->status !== 'cancelled',
                'record_attendance' => $manage && $level === 'church' && $a->kind === 'event' && $a->starts_at->isPast() && $a->status !== 'cancelled',
            ],
        ];
    }

    private function registrationItem(ActivityRegistration $r, Activity $activity, Territory $place, User $user): array
    {
        $region = $this->activities->regionOf($r->territory);
        $subregion = collect(PlaceAccess::ancestors($r->territory))->first(fn ($t) => $t->territory_type?->value === 'subregion');

        return [
            'id' => $r->id,
            'place' => ['id' => $r->territory_id, 'name' => $r->territory?->name, 'type' => $r->territory?->territory_type?->value],
            'region' => $region?->name,
            'subregion' => $subregion?->name,
            'youth' => $r->youth, 'adults' => $r->adults, 'children' => $r->children, 'leaders' => $r->leaders,
            'expected' => $r->expected(),
            'names' => $r->names,
            'fee_due' => (float) $r->fee_due,
            'fee_paid' => (float) $r->fee_paid,
            'came_youth' => $r->came_youth, 'came_adults' => $r->came_adults, 'came_children' => $r->came_children, 'came_leaders' => $r->came_leaders,
            'came' => $r->came(),
            'rating' => $r->rating,
            'comment' => $r->comment,
            'status' => $r->status,
            'updated_at' => $r->updated_at?->toIso8601String(),
            'can_record_fee' => EventsAccess::canManage($user, $place, $activity) && PlaceAccess::isOwn($user, $place),
        ];
    }

    /** @return array{0: ?Territory, 1: ?Activity, 2: ?string, 3: ?JsonResponse} */
    private function visible(Request $request, int $id): array
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return [null, null, null, $place];
        }
        $activity = Activity::with(['territory', 'invitees', 'registrations'])->find($id);
        $relation = $activity ? $this->activities->relation($activity, $place) : null;
        if (! $activity || ! $relation || ($relation === 'own' && $activity->status === 'draft' && ! PlaceAccess::isOwn($request->user(), $place))) {
            return [$place, null, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That event isn\'t one you can see.'], 404)];
        }
        if ($relation === 'below' && ! EventsAccess::can($request->user(), $place, 'below')) {
            return [$place, null, null, $this->forbidden("Your role can't see the places below.")];
        }

        return [$place, $activity, $relation, null];
    }

    /** @return array{0: ?Territory, 1: ?Activity, 2: ?JsonResponse} */
    private function manageable(Request $request, int $id): array
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return [null, null, $place];
        }
        $activity = Activity::with(['territory', 'invitees', 'registrations'])->find($id);
        if (! $activity) {
            return [$place, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That event no longer exists.'], 404)];
        }
        if (! PlaceAccess::isOwn($request->user(), $place) || ! EventsAccess::canManage($request->user(), $place, $activity)) {
            return [$place, null, $this->forbidden('Only the place that organises it can change it.')];
        }

        return [$place, $activity, null];
    }

    private function place(Request $request): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden("This isn't your place.");
        }

        return EventsAccess::can($request->user(), $place, 'read') || PlaceAccess::isBelow($request->user(), $place)
            ? $place
            : $this->forbidden("Your role can't see events.");
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
