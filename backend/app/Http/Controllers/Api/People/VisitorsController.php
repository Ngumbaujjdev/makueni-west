<?php

namespace App\Http\Controllers\Api\People;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use App\Notifications\PlaceNotification;
use App\Services\Messaging\PlaceMessenger;
use App\Services\People\People;
use App\Services\People\Visitors;
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use App\Support\Phone;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Visitors and their follow-up (docs/specs/people-and-care-spec.md, P2).
 * Like the member register, every named route answers only for the church
 * the user acts for; the region and diocese get /visitors/totals - counts.
 */
class VisitorsController extends Controller
{
    public function __construct(private Visitors $visitors, private People $people) {}

    /** GET /visitors/options - how-heard choices, gatherings, leaders to assign, the welcome SMS. */
    public function options(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->visitors->options($church) + ['can' => $this->can($request->user(), $church)]);
    }

    /** GET /visitors/overview - the cards, the stage counts and "my follow-ups". */
    public function overview(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->visitors->overview($church, $request->user()) + ['can' => $this->can($request->user(), $church)]);
    }

    /** GET /visitors?stage[]=&area=&assigned=&month=&q=&due=&archived= */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $f = $request->validate([
            'stage' => ['nullable', 'array'], 'stage.*' => [Rule::in(array_keys(Person::STAGES))],
            'area' => ['nullable', 'string', 'max:80'],
            'assigned' => ['nullable', 'string', 'max:20'],
            'month' => ['nullable', 'date_format:Y-m'],
            'q' => ['nullable', 'string', 'max:80'],
            'due' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);
        $people = $this->visitors->query($church, $f, $request->user())->orderByDesc('last_visit_on')->orderBy('first_name')->limit(3000)->get();
        $rows = collect($this->visitors->rows($church, $people));
        if (! empty($f['due'])) {
            $rows = $rows->filter(fn ($r) => $r['due_on'] && $r['due_on'] <= $this->visitors->today()->toDateString())->values();
        }

        return $this->ok(['items' => $rows->values(), 'total' => $rows->count()]);
    }

    /** GET /visitors/check?phone= - someone we already know, before adding them again. */
    public function check(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $key = Phone::key((string) $request->query('phone'));
        if (! $key || strlen($key) < 9) {
            return $this->ok(null);
        }
        $p = $this->known($church, $key);

        return $this->ok($p ? ['id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'stage' => $p->stage, 'visits' => (int) $p->visit_count, 'last_visit_on' => $p->last_visit_on?->toDateString()] : null);
    }

    /**
     * POST /visitors/batch - Sunday's visitors in one go. A phone we know adds
     * a visit to that person instead of a new one; a member's phone is noted,
     * not counted as a visitor. Each row is a name, a phone and an area -
     * nothing more. With welcome_sms, first-timers with a phone get the
     * welcome message (a phone given is a yes to being contacted).
     */
    public function batch(Request $request, PlaceMessenger $messenger): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $today = $this->visitors->today()->toDateString();
        $d = $request->validate([
            'on' => ['required', 'date', "before_or_equal:{$today}"],
            'gathering_type_id' => ['nullable', 'integer', Rule::exists('gathering_types', 'id')->where('territory_id', $church->id)],
            'gathering' => ['nullable', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'integer', Rule::in($this->visitors->leaders($church)->pluck('id')->all())],
            'welcome_sms' => ['nullable', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:60'],
            'rows.*.name' => ['required', 'string', 'max:160'],
            'rows.*.phone' => ['nullable', 'string', 'max:30'],
            'rows.*.area' => ['nullable', 'string', 'max:80'],
        ], [
            'on.before_or_equal' => "The date can't be in the future.",
            'rows.required' => 'Add at least one visitor.',
            'rows.*.name.required' => 'Each visitor needs a name.',
            'assigned_to.in' => 'Pick a leader who can follow visitors up.',
        ]);
        $where = $this->visitors->resolveGathering($church, $d['gathering'] ?? null, $d['gathering_type_id'] ?? null);
        // Every phone first, so one bad number stops the batch before anything is saved.
        $phones = [];
        foreach ($d['rows'] as $i => $row) {
            if (! empty($row['phone'])) {
                $phones[$i] = Phone::kenyaMobile($row['phone']) ?: throw ValidationException::withMessages(["rows.{$i}.phone" => "That phone number doesn't look right - use a Kenyan mobile, e.g. 0712 345 678."]);
            }
        }

        $user = $request->user();
        $results = [];
        $welcome = [];
        DB::transaction(function () use ($d, $church, $user, $phones, $where, &$results, &$welcome) {
            foreach ($d['rows'] as $i => $row) {
                $phone = $phones[$i] ?? null;
                $known = $phone ? $this->known($church, Phone::key($phone)) : null;
                if ($known && $known->status !== 'visitor') {
                    $results[] = ['row' => $i, 'id' => $known->id, 'name' => $known->name, 'result' => 'member', 'visits' => (int) $known->visit_count];

                    continue;
                }
                $visit = $where + ['territory_id' => $church->id, 'on' => $d['on'], 'created_by' => $user->id];
                $area = ($a = trim((string) ($row['area'] ?? ''))) === '' ? null : mb_convert_case($a, MB_CASE_TITLE);
                if ($known) {
                    if (! VisitorVisit::where('person_id', $known->id)->whereDate('on', $d['on'])->exists()) {
                        VisitorVisit::create($visit + ['person_id' => $known->id, 'first_time' => false]);
                        $known->visit_count++;
                    }
                    $last = $known->last_visit_on?->toDateString();
                    $known->fill([
                        'last_visit_on' => ! $last || $d['on'] > $last ? $d['on'] : $last,
                        'area' => $known->area ?: $area,
                        'updated_by' => $user->id,
                    ]);
                    $this->visitors->advance($known);
                    $known->save();
                    $results[] = ['row' => $i, 'id' => $known->id, 'name' => $known->name, 'result' => 'returning', 'visits' => (int) $known->visit_count];

                    continue;
                }
                [$first, $last] = $this->splitName($row['name']);
                $person = Person::create([
                    'territory_id' => $church->id, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'area' => $area,
                    'status' => 'visitor', 'stage' => 'new', 'first_visit_on' => $d['on'], 'last_visit_on' => $d['on'], 'visit_count' => 1,
                    'consent_contact' => (bool) $phone,
                    'assigned_to' => $d['assigned_to'] ?? null, 'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                VisitorVisit::create($visit + ['person_id' => $person->id, 'first_time' => true]);
                $results[] = ['row' => $i, 'id' => $person->id, 'name' => $person->name, 'result' => 'new', 'visits' => 1];
                if ($person->consent_contact && $person->phone && ! Phone::isDemo($person->phone)) {
                    $welcome[] = $person;
                }
            }
        });

        $sent = 0;
        $template = trim((string) app(Settings::class)->get('visitors.welcome_template', $church));
        if ($request->boolean('welcome_sms') && $template !== '') {
            foreach ($welcome as $person) {
                $text = strtr($template, ['{first_name}' => $person->first_name, '{church}' => $church->name]);
                if ($messenger->sms($church, $person->phone, $text, 'visitor', [], $user)['ok']) {
                    VisitorFollowup::create(['person_id' => $person->id, 'territory_id' => $church->id, 'type' => 'sms', 'outcome' => 'sent', 'note' => 'The welcome SMS', 'done_by' => $user->id, 'done_on' => $this->visitors->today()->toDateString()]);
                    $sent++;
                }
            }
        }
        if (! empty($d['assigned_to']) && (int) $d['assigned_to'] !== (int) $user->id && ($new = collect($results)->where('result', 'new')->count())) {
            User::find($d['assigned_to'])?->notify(new PlaceNotification('followup', "{$new} new ".($new === 1 ? 'visitor' : 'visitors').' to follow up', "{$user->firstname} recorded visitors from ".date('j M', strtotime($d['on'])).' and asked you to follow them up.', '/church/visitors/?assigned=me', $church, 'ri-user-follow-line'));
        }
        $c = collect($results)->countBy('result');

        return $this->ok([
            'created' => (int) ($c['new'] ?? 0), 'returning' => (int) ($c['returning'] ?? 0), 'members' => (int) ($c['member'] ?? 0),
            'sms_sent' => $sent, 'items' => $results,
        ], 'Saved '.count($results).' '.(count($results) === 1 ? 'visitor' : 'visitors').'.', 201);
    }

    /** GET /visitors/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($request, $church, $person));
    }

    /** PUT /visitors/{id} - their details. */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($person->anonymised_at) {
            return $this->unprocessable('first_name', "This person's details were removed, so they can't be changed.");
        }
        $d = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'area' => ['sometimes', 'nullable', 'string', 'max:80'],
            'consent_contact' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('area', $d)) {
            $d['area'] = ($a = trim((string) $d['area'])) === '' ? null : mb_convert_case($a, MB_CASE_TITLE);
        }
        if (! empty($d['phone'])) {
            $d['phone'] = Phone::kenyaMobile($d['phone']) ?: throw ValidationException::withMessages(['phone' => "That phone number doesn't look right - use a Kenyan mobile, e.g. 0712 345 678."]);
            $other = $this->known($church, Phone::key($d['phone']));
            if ($other && $other->id !== $person->id) {
                return $this->unprocessable('phone', "{$other->name} already has this phone number.");
            }
        }
        if (array_key_exists('last_name', $d)) {
            $d['last_name'] = (string) $d['last_name'];
        }
        $person->fill($d + ['updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $church, $person->fresh()), 'Saved.');
    }

    /** POST /visitors/{id}/stage {stage} - moved on the board. "Became a member" has its own step. */
    public function stage(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['stage' => ['required', Rule::in(['new', 'contacted', 'returning', 'regular'])]], ['stage.in' => 'Use "Became a member" to make them a member.']);
        if ($person->status !== 'visitor') {
            return $this->unprocessable('stage', "{$person->name} is already a member.");
        }
        $person->forceFill(['stage' => $d['stage'], 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $church, $person->fresh()), 'Moved to '.strtolower(Person::STAGES[$d['stage']]).'.');
    }

    /** POST /visitors/{id}/assign {user_id|null} - who follows them up; they are told. */
    public function assign(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['user_id' => ['nullable', 'integer', Rule::in($this->visitors->leaders($church)->pluck('id')->all())]], ['user_id.in' => 'Pick a leader who can follow visitors up.']);
        $to = $d['user_id'] ?? null;
        $person->forceFill(['assigned_to' => $to, 'updated_by' => $request->user()->id])->save();
        if ($to && (int) $to !== (int) $request->user()->id) {
            User::find($to)?->notify(new PlaceNotification('followup', "Please follow up {$person->name}", "{$request->user()->firstname} asked you to follow up {$person->name}, a visitor at {$church->name}.", "/church/visitors/visitor.php?id={$person->id}", $church, 'ri-user-follow-line'));
        }
        $name = $to ? User::find($to) : null;

        return $this->ok($this->detail($request, $church, $person->fresh()), $name ? "{$name->firstname} follows them up now." : 'Nobody is assigned now.');
    }

    /** POST /visitors/{id}/visits {on, gathering?} - they came again. */
    public function visit(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate([
            'on' => ['required', 'date', 'before_or_equal:'.$this->visitors->today()->toDateString()],
            'gathering_type_id' => ['nullable', 'integer', Rule::exists('gathering_types', 'id')->where('territory_id', $church->id)],
            'gathering' => ['nullable', 'string', 'max:120'],
        ]);
        $where = $this->visitors->resolveGathering($church, $d['gathering'] ?? null, $d['gathering_type_id'] ?? null);
        if (VisitorVisit::where('person_id', $person->id)->whereDate('on', $d['on'])->exists()) {
            return $this->unprocessable('on', 'Their visit that day is already recorded.');
        }
        VisitorVisit::create($where + ['on' => $d['on'], 'person_id' => $person->id, 'territory_id' => $church->id, 'first_time' => false, 'created_by' => $request->user()->id]);
        $last = $person->last_visit_on?->toDateString();
        $person->fill([
            'visit_count' => $person->visit_count + 1,
            'first_visit_on' => $person->first_visit_on ?? $d['on'],
            'last_visit_on' => ! $last || $d['on'] > $last ? $d['on'] : $last,
            'updated_by' => $request->user()->id,
        ]);
        $this->visitors->advance($person);
        $person->save();

        return $this->ok($this->detail($request, $church, $person->fresh()), 'Visit recorded.');
    }

    /** POST /visitors/{id}/followups {type, outcome, note?, done_on, next_on?} */
    public function followup(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $today = $this->visitors->today()->toDateString();
        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(VisitorFollowup::TYPES))],
            'outcome' => ['required', Rule::in(array_keys(VisitorFollowup::OUTCOMES))],
            'note' => ['nullable', 'string', 'max:2000'],
            'done_on' => ['required', 'date', "before_or_equal:{$today}"],
            'next_on' => ['nullable', 'date', 'after_or_equal:done_on'],
        ], ['type.required' => 'How did you follow up?', 'outcome.required' => 'How did it go?', 'done_on.before_or_equal' => "The date can't be in the future.", 'next_on.after_or_equal' => 'The next step comes after this one.']);
        VisitorFollowup::create($d + ['person_id' => $person->id, 'territory_id' => $church->id, 'done_by' => $request->user()->id]);
        if ($person->stage === 'new' && in_array($d['outcome'], ['reached', 'will_come'], true)) {
            $person->forceFill(['stage' => 'contacted', 'updated_by' => $request->user()->id])->save();
        }

        return $this->ok($this->detail($request, $church, $person->fresh()), 'Follow-up logged.', 201);
    }

    /** POST /visitors/{id}/sms {text} - only when they said yes to being contacted. */
    public function sms(Request $request, int $id, PlaceMessenger $messenger): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['text' => ['required', 'string', 'max:640']], ['text.required' => 'Write the message.']);
        if (! $person->consent_contact) {
            return $this->unprocessable('text', "{$person->first_name} asked not to be texted.");
        }
        if (Phone::isDemo($person->phone)) {
            return $this->unprocessable('text', 'This is a demo number, so it is never texted.');
        }
        if (! $person->phone) {
            return $this->unprocessable('text', "There's no phone number for {$person->first_name}.");
        }
        $result = $messenger->sms($church, $person->phone, $d['text'], 'visitor', [], $request->user());
        if (! $result['ok']) {
            return $this->unprocessable('text', 'The SMS was not sent: '.($result['error'] ?: 'try again in a moment.'));
        }
        VisitorFollowup::create(['person_id' => $person->id, 'territory_id' => $church->id, 'type' => 'sms', 'outcome' => 'sent', 'note' => $d['text'], 'done_by' => $request->user()->id, 'done_on' => $this->visitors->today()->toDateString()]);

        return $this->ok($this->detail($request, $church, $person->fresh()), $result['status'] === 'logged' ? 'Logged (SMS sending is off on this server).' : 'SMS sent.');
    }

    /** POST /visitors/{id}/become-member {joined_on?, how_joined?} - the same person, now in the register. */
    public function becomeMember(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if (! PeopleAccess::canNamed($request->user(), $church, 'members', 'manage')) {
            return $this->forbidden("Your role can't add members to the register.");
        }
        if ($person->status !== 'visitor') {
            return $this->unprocessable('status', "{$person->name} is already in the register.");
        }
        $d = $request->validate([
            'joined_on' => ['nullable', 'date', 'before_or_equal:'.$this->visitors->today()->toDateString()],
            'how_joined' => ['nullable', Rule::in(array_keys(Person::HOW_JOINED))],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'congregation' => ['nullable', Rule::in(array_keys(Person::CONGREGATIONS))],
        ]);
        $person->forceFill([
            'gender' => $d['gender'] ?? $person->gender, 'congregation' => $d['congregation'] ?? $person->congregation ?? 'main_church',
            'status' => 'member', 'stage' => 'member', 'became_member_on' => $this->visitors->today()->toDateString(),
            'joined_on' => $d['joined_on'] ?? $this->visitors->today()->toDateString(), 'how_joined' => $d['how_joined'] ?? 'conversion',
            'updated_by' => $request->user()->id,
        ])->save();

        return $this->ok($this->detail($request, $church, $person->fresh()), "{$person->name} is a member now.");
    }

    /**
     * POST /visitors/bulk {ids[], action: assign|stage|archive, user_id?, stage?}
     * - many visitors at once from the list. Only this church's; each change
     * is audited, and someone given visitors to follow up is told once.
     */
    public function bulk(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['assign', 'stage', 'archive'])],
            'user_id' => ['nullable', 'integer', Rule::in($this->visitors->leaders($church)->pluck('id')->all())],
            'stage' => ['required_if:action,stage', 'nullable', Rule::in(['new', 'contacted', 'returning', 'regular'])],
        ], ['user_id.in' => 'Pick a leader who can follow visitors up.', 'stage.in' => 'Use "Became a member" to make them members.']);
        $people = Person::where('territory_id', $church->id)->whereNotNull('stage')->whereIn('id', $d['ids'])->whereNull('anonymised_at')->get();
        $done = 0;
        foreach ($people as $person) {
            $change = match ($d['action']) {
                'assign' => $person->status === 'visitor' ? ['assigned_to' => $d['user_id'] ?? null] : null,
                'stage' => $person->status === 'visitor' ? ['stage' => $d['stage']] : null,
                'archive' => $person->archived_at ? null : ['archived_at' => now()],
            };
            if ($change) {
                $person->forceFill($change + ['updated_by' => $request->user()->id])->save();
                $done++;
            }
        }
        $to = $d['action'] === 'assign' && ! empty($d['user_id']) ? User::find($d['user_id']) : null;
        if ($to && $done && (int) $to->id !== (int) $request->user()->id) {
            $to->notify(new PlaceNotification('followup', "{$done} ".($done === 1 ? 'visitor' : 'visitors').' to follow up', "{$request->user()->firstname} asked you to follow up {$done} ".($done === 1 ? 'visitor' : 'visitors')." at {$church->name}.", '/church/visitors/?assigned=me', $church, 'ri-user-follow-line'));
        }
        $what = ['assign' => $to ? "given to {$to->firstname}" : 'now with nobody', 'stage' => 'moved to '.strtolower(Person::STAGES[$d['stage'] ?? 'new'] ?? ''), 'archive' => 'archived'][$d['action']];

        return $this->ok(['done' => $done, 'skipped' => count(array_unique($d['ids'])) - $done], "{$done} ".($done === 1 ? 'visitor' : 'visitors')." {$what}.");
    }

    /** POST /visitors/{id}/archive and /restore */
    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, true);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, false);
    }

    /** POST /visitors/{id}/anonymise {confirm: "REMOVE"} */
    public function anonymise(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($request->input('confirm') !== 'REMOVE') {
            return $this->unprocessable('confirm', 'Type REMOVE to confirm. This cannot be undone.');
        }
        $person->anonymise($request->user()->id);

        return $this->ok($this->detail($request, $church, $person->fresh()), 'Their personal details are removed.');
    }

    /** GET /visitors/{id}/history */
    public function history(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->people->history($person));
    }

    /** GET /visitors/insights?year= */
    public function insights(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        return $this->ok($this->visitors->insights($church, (int) ($d['year'] ?? $this->visitors->today()->year)));
    }

    /** GET /visitors/totals - the region's and diocese's view: counts per church, never a name. */
    public function totals(Request $request): JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! PeopleAccess::canTotals($request->user(), $place, 'visitors')) {
            return $this->forbidden("Your role can't see the churches' visitor totals.");
        }

        return $this->ok($this->visitors->totals($place) + ['place' => ['id' => $place->id, 'name' => $place->name, 'type' => PlaceAccess::level($place)]]);
    }

    // ------------------------------------------------------------------ helpers

    /** The visitor page: the person, their visits and follow-ups (decrypted - only ever sent to their own church). */
    private function detail(Request $request, Territory $church, Person $p): array
    {
        $p->loadMissing('assignee');
        $followups = VisitorFollowup::with('doer')->where('person_id', $p->id)->orderByDesc('done_on')->orderByDesc('id')->get();
        $visits = VisitorVisit::with('gatheringType')->where('person_id', $p->id)->orderByDesc('on')->orderByDesc('id')->get();
        $row = $this->visitors->row($p, $followups->sortBy([['done_on', 'asc'], ['id', 'asc']])->last(), $this->visitors->followupDays($church));
        $can = $this->can($request->user(), $church);

        return $row + [
            'first_name' => $p->first_name, 'last_name' => $p->last_name, 'congregation' => $p->congregation,
            'anonymised' => (bool) $p->anonymised_at, 'created_at' => $p->created_at?->toIso8601String(),
            'visit_list' => $visits->map(fn (VisitorVisit $v) => [
                'id' => $v->id, 'on' => $v->on->toDateString(), 'first_time' => $v->first_time, 'gathering' => $v->gathering_label,
            ])->values(),
            'followups' => $followups->map(fn (VisitorFollowup $f) => [
                'id' => $f->id, 'type' => $f->type, 'outcome' => $f->outcome, 'note' => $f->note, 'done_on' => $f->done_on->toDateString(),
                'next_on' => $f->next_on?->toDateString(), 'by' => $f->doer ? trim("{$f->doer->firstname} {$f->doer->lastname}") : null,
            ])->values(),
            'can' => $can + ['sms' => $can['manage'] && $p->consent_contact && (bool) $p->phone && ! Phone::isDemo($p->phone) && ! $p->anonymised_at],
            'demo' => Phone::isDemo($p->phone),
        ];
    }

    private function can(?User $user, Territory $church): array
    {
        return [
            'manage' => PeopleAccess::canNamed($user, $church, 'visitors', 'manage'),
            'members' => PeopleAccess::canNamed($user, $church, 'members', 'read'),
            'make_member' => PeopleAccess::canNamed($user, $church, 'visitors', 'manage') && PeopleAccess::canNamed($user, $church, 'members', 'manage'),
        ];
    }

    /** Someone in this church with this phone (a visitor or a member). */
    private function known(Territory $church, ?string $key): ?Person
    {
        if (! $key) {
            return null;
        }

        return Person::where('territory_id', $church->id)->whereNull('anonymised_at')->where('phone', 'like', '%'.$key)
            ->orderByRaw("status = 'visitor' DESC")->orderBy('id')->first();
    }

    /** "Mary Wanjiru Mutua" -> ["Mary", "Wanjiru Mutua"]; one name stays a first name. */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [mb_substr($parts[0], 0, 80), mb_substr($parts[1] ?? '', 0, 80)];
    }

    private function setArchived(Request $request, int $id, bool $archive): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $person->forceFill(['archived_at' => $archive ? now() : null, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $church, $person->fresh()), $archive ? 'Archived - hidden from the board.' : 'Back on the board.');
    }

    /** The church the user acts for, when their role may read (or manage) its visitors. */
    private function church(Request $request, string $ability = 'read'): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return $this->forbidden('Visitors are kept by each church - only its own leaders see them.');
        }
        if (! PeopleAccess::canNamed($request->user(), $place, 'visitors', $ability)) {
            return $this->forbidden($ability === 'manage' ? "Your role can't change the visitors' records." : "Your role can't see the visitors.");
        }

        return $place;
    }

    /** [church, person, error] - someone who came to this church as a visitor. */
    private function person(Request $request, int $id, string $ability = 'read'): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $person = Person::where('territory_id', $church->id)->whereNotNull('stage')->find($id);

        return $person ? [$church, $person, null] : [$church, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That visitor isn\'t in your records.'], 404)];
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
