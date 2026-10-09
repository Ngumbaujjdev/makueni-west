<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\DutyRota;
use App\Models\DutyTeamMember;
use App\Models\Person;
use App\Models\Territory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Teams page (docs/specs/people-and-care-spec.md, P5 round 4): who serves
 * on each duty - people from the register or typed names - in the order the
 * rota takes turns. Someone who leaves the church stays on the list, marked
 * away, and the rota skips them. Each church's own; another is refused.
 */
class TeamsController extends FacilitiesBase
{
    /** GET /facilities/teams - each duty in use, its team and how each has served lately; the counts for the cards. */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $duties = $this->facilities->dutyList($church);
        $teams = $this->facilities->teams($church);
        $served = $this->served($church);

        $rows = collect($duties)->map(fn ($d) => [
            'key' => $d['key'], 'label' => $d['label'], 'icon' => $d['icon'], 'color' => $d['color'], 'needed' => $d['needed'],
            'members' => ($teams[$d['key']] ?? collect())->map(fn (DutyTeamMember $m) => $this->row($m, $served))->values(),
        ]);

        $live = $rows->flatMap(fn ($d) => collect($d['members'])->reject(fn ($m) => $m['away']));
        $next = $this->facilities->nextDuty($church);
        $cells = (array) $next['cells'];
        $empty = 0;
        foreach ($next['rows'] as $r) {
            foreach ($duties as $d) {
                $empty += max(0, $d['needed'] - count($cells["{$r['date']}|{$r['service']}|{$d['key']}"] ?? []));
            }
        }
        $onATeam = $teams->flatten()->pluck('person_id')->filter()->unique()->all();

        return $this->ok([
            'duties' => $rows->values(),
            'counts' => [
                'teams' => $rows->filter(fn ($d) => count($d['members']) > 0)->count(),
                'duties' => $rows->count(),
                'people' => $live->map(fn ($m) => $m['person_id'] ? "p{$m['person_id']}" : 'n'.mb_strtolower($m['name']))->unique()->count(),
                'away' => $rows->flatMap(fn ($d) => collect($d['members'])->where('away', true))->count(),
                'next_date' => $next['date'],
                'next_services' => count($next['rows']),
                'next_empty' => $empty,
                'not_on_a_team' => Person::where('territory_id', $church->id)->where('status', 'member')->whereNull('archived_at')->whereNull('anonymised_at')->whereNotIn('id', $onATeam ?: [0])->count(),
            ],
            'can' => $this->can($request, $church),
        ]);
    }

    /** GET /facilities/teams/person/{person} - the teams someone is on (their member page), and the duties they could join. */
    public function person(Request $request, int $person): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $p = Person::where('territory_id', $church->id)->find($person);
        if (! $p) {
            return $this->notFound('That person is not in our register.');
        }
        $served = $this->served($church);
        $mine = DutyTeamMember::where('territory_id', $church->id)->where('person_id', $p->id)->get()->keyBy('duty');

        return $this->ok([
            'duties' => collect($this->facilities->dutyList($church))->map(fn ($d) => [
                'key' => $d['key'], 'label' => $d['label'], 'icon' => $d['icon'], 'color' => $d['color'],
                'member' => isset($mine[$d['key']]) ? $this->row($mine[$d['key']]->setRelation('person', $p), $served) : null,
            ])->values(),
            'can' => $this->can($request, $church),
        ]);
    }

    /** POST /facilities/teams/{duty}/members {person_ids?: [], names?: []} - joins them to the end of the team. */
    public function store(Request $request, string $duty): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        if (! $this->dutyHere($church, $duty)) {
            return $this->notFound('That duty is not in use here.');
        }
        $d = $request->validate([
            'person_ids' => ['array', 'max:200'],
            'person_ids.*' => ['integer', Rule::exists('people', 'id')->where('territory_id', $church->id)->whereNull('anonymised_at')->whereNull('deleted_at')],
            'names' => ['array', 'max:20'],
            'names.*' => ['string', 'max:120'],
        ], ['person_ids.*.exists' => 'Someone picked is not in our register.']);
        $ids = array_values(array_unique(array_map('intval', $d['person_ids'] ?? [])));
        $names = collect($d['names'] ?? [])->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))->filter()->unique(fn ($n) => mb_strtolower($n))->values();
        if (! $ids && $names->isEmpty()) {
            return $this->unprocessable('person_ids', 'Pick at least one person.');
        }

        $team = DutyTeamMember::where('territory_id', $church->id)->where('duty', $duty)->get();
        $haveIds = $team->pluck('person_id')->filter()->all();
        $haveNames = $team->whereNull('person_id')->map(fn ($m) => mb_strtolower($m->name))->all();
        $new = collect($ids)->diff($haveIds)->map(fn ($id) => ['person_id' => $id, 'name' => null])
            ->merge($names->reject(fn ($n) => in_array(mb_strtolower($n), $haveNames, true))->map(fn ($n) => ['person_id' => null, 'name' => $n]))->values();
        $already = count($ids) + $names->count() - $new->count();
        if ($team->count() + $new->count() > DutyTeamMember::MAX) {
            return $this->unprocessable('person_ids', 'A team can have up to '.DutyTeamMember::MAX.' people.');
        }

        $at = (int) $team->max('position') + 1;
        DB::transaction(function () use ($new, $church, $duty, $request, &$at) {
            foreach ($new as $row) {
                DutyTeamMember::create($row + ['territory_id' => $church->id, 'duty' => $duty, 'position' => $at++, 'added_by' => $request->user()->id]);
            }
        });
        $label = $this->facilities->dutyLabel($church, $duty);
        $added = $new->count();

        return $this->ok(['added' => $added, 'already' => $already], $added ? "{$added} added to {$label}." : "They were already on {$label}.", $added ? 201 : 200);
    }

    /** PUT /facilities/teams/{duty}/order {ids: []} - the order the rota takes turns in. */
    public function order(Request $request, string $duty): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['ids' => ['required', 'array', 'max:'.DutyTeamMember::MAX], 'ids.*' => ['integer']]);
        $team = DutyTeamMember::where('territory_id', $church->id)->where('duty', $duty)->get()->keyBy('id');
        $ids = array_values(array_unique(array_map('intval', $d['ids'])));
        if ($team->isEmpty() || array_diff($ids, $team->keys()->all())) {
            return $this->unprocessable('ids', 'That list is not this team - reload the page.');
        }
        // Anyone not sent keeps their place after the ones that were.
        $order = [...$ids, ...$team->keys()->diff($ids)->all()];
        DB::transaction(function () use ($order, $team) {
            foreach ($order as $pos => $id) {
                if ($team[$id]->position !== $pos) {
                    $team[$id]->update(['position' => $pos]);
                }
            }
        });

        return $this->ok(['ids' => $order], 'Turn order saved.');
    }

    /** DELETE /facilities/teams/members/{member} - off the team; the rota they were on stays. */
    public function destroy(Request $request, int $member): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $m = DutyTeamMember::where('territory_id', $church->id)->find($member);
        if (! $m) {
            return $this->notFound('They are not on that team.');
        }
        $who = $m->who;
        $label = $this->facilities->dutyLabel($church, $m->duty);
        $m->delete();

        return $this->ok(['id' => $member], "{$who} is off {$label}.");
    }

    private function dutyHere(Territory $church, string $duty): bool
    {
        return collect($this->facilities->dutyList($church))->contains('key', $duty);
    }

    /** "duty|who" => [times on duty in the last 3 months, the next date on the rota]. */
    private function served(Territory $church): Collection
    {
        $today = $this->facilities->today();
        $out = collect();
        DutyRota::where('territory_id', $church->id)->whereBetween('on', [$today->subMonths(3)->toDateString(), $today->addDays(90)->toDateString()])
            ->orderBy('on')->get(['on', 'duty', 'person_id', 'name'])
            ->each(function (DutyRota $r) use ($out, $today) {
                $k = $r->duty.'|'.($r->person_id ? "p{$r->person_id}" : 'n'.mb_strtolower((string) $r->name));
                $s = $out->get($k, ['times' => 0, 'next' => null]);
                $day = $r->on->toDateString();
                if ($day <= $today->toDateString()) {
                    $s['times']++;
                }
                if ($day >= $today->toDateString() && ! $s['next']) {
                    $s['next'] = $day;
                }
                $out->put($k, $s);
            });

        return $out;
    }

    private function row(DutyTeamMember $m, Collection $served): array
    {
        $name = $m->who;
        $s = $served->get($m->duty.'|'.($m->person_id ? "p{$m->person_id}" : 'n'.mb_strtolower($name)), ['times' => 0, 'next' => null]);

        return [
            'id' => $m->id, 'person_id' => $m->person_id, 'name' => $name,
            'initials' => mb_strtoupper(collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')),
            'away' => $m->away, 'typed' => $m->person_id === null,
            'times' => $s['times'], 'next' => $s['next'],
        ];
    }
}
