<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\DutyRota;
use App\Services\Facilities\Facilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who is on duty at each service (P5): the church's duties (Settings >
 * Facilities; ushering, welcome, sound, security and cleaning to start) -
 * by hand, a week copied, or filled from each duty's team in turn.
 */
class RotaController extends FacilitiesBase
{
    /** GET /rota?from=&to= - the services between the dates (from Service times) and who is on each duty. */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($d['from']) ? CarbonImmutable::parse($d['from'], Facilities::TZ) : $this->facilities->today()->startOfWeek(CarbonImmutable::MONDAY);
        $to = isset($d['to']) ? CarbonImmutable::parse($d['to'], Facilities::TZ) : $from->addWeeks(4)->subDay();
        $to = $to->min($from->addDays(62));

        return $this->ok($this->facilities->rota($church, $from->startOfDay(), $to->startOfDay()) + [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'today' => $this->facilities->today()->toDateString(),
            'duties' => $this->facilities->dutyList($church),
            'reminders' => (bool) app(\App\Services\Settings\Settings::class)->get('facilities.duty_reminder', $church),
            'reminder_time' => (string) (app(\App\Services\Settings\Settings::class)->get('facilities.duty_reminder_time', $church) ?: '18:00'),
            'can' => $this->can($request, $church),
        ]);
    }

    /** PUT /rota {on, service, duty, people: [{person_id}|{name}]} - who is on one duty at one service. */
    public function update(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate([
            'on' => ['required', 'date_format:Y-m-d'],
            'service' => ['required', 'string', 'max:60'],
            'duty' => ['required', Rule::in(array_keys($this->facilities->duties($church)))],
            'people' => ['present', 'array', 'max:12'],
            'people.*.person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->where('territory_id', $church->id)->whereNull('anonymised_at')],
            'people.*.name' => ['nullable', 'string', 'max:120'],
        ]);
        DB::transaction(function () use ($d, $church) {
            $cell = DutyRota::where('territory_id', $church->id)->where('on', $d['on'])->where('service', $d['service'])->where('duty', $d['duty'])->get();
            $cell->each->delete();
            foreach ($d['people'] as $p) {
                if (empty($p['person_id']) && empty(trim((string) ($p['name'] ?? '')))) {
                    continue;
                }
                DutyRota::create(['territory_id' => $church->id, 'on' => $d['on'], 'service' => $d['service'], 'duty' => $d['duty'], 'person_id' => $p['person_id'] ?? null, 'name' => empty($p['person_id']) ? trim($p['name']) : null]);
            }
        });
        $day = CarbonImmutable::parse($d['on'], Facilities::TZ);

        return $this->ok($this->facilities->rota($church, $day, $day), 'Rota saved.');
    }

    /**
     * POST /rota/fill {from, to} - the empty duties at each service between
     * the dates, from each duty's team in turn (as many as the duty needs).
     * Filled duties are left alone. Turns run on from the date, so filling
     * the same weeks again gives the same people.
     */
    public function fill(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = CarbonImmutable::parse($d['from'], Facilities::TZ)->startOfDay();
        $to = CarbonImmutable::parse($d['to'], Facilities::TZ)->startOfDay()->min($from->addDays(62));
        $duties = collect($this->facilities->dutyList($church))->filter(fn ($x) => $x['team'] !== []);
        if ($duties->isEmpty()) {
            return $this->unprocessable('team', 'No duty has a team yet - add the people for each duty on the Teams page.');
        }
        $rota = $this->facilities->rota($church, $from, $to);
        $filled = (array) $rota['cells'];
        $services = collect($this->facilities->services($church))->pluck('name')->values();
        $added = 0;
        DB::transaction(function () use ($church, $rota, $filled, $duties, $services, &$added) {
            foreach ($rota['rows'] as $row) {
                // The service's place in the weeks since 2024 - the same date always gets the same turn.
                $turn = intdiv((int) CarbonImmutable::parse('2024-01-01')->diffInDays(CarbonImmutable::parse($row['date'])), 7) * max(1, $services->count()) + max(0, (int) $services->search($row['service']));
                foreach ($duties as $duty) {
                    if (! empty($filled["{$row['date']}|{$row['service']}|{$duty['key']}"])) {
                        continue;
                    }
                    $team = $duty['team'];
                    $need = min(max(1, $duty['needed']), count($team));
                    for ($i = 0; $i < $need; $i++) {
                        $p = $team[($turn * $need + $i) % count($team)];
                        DutyRota::create(['territory_id' => $church->id, 'on' => $row['date'], 'service' => $row['service'], 'duty' => $duty['key'], 'person_id' => $p['person_id'] ?? null, 'name' => empty($p['person_id']) ? ($p['name'] ?? null) : null]);
                        $added++;
                    }
                }
            }
        });

        return $this->ok(['added' => $added], $added ? "{$added} ".($added === 1 ? 'person' : 'people').' put on duty from the teams.' : 'Every duty was already filled.');
    }

    /** POST /rota/copy {from, to} - a week's rota (Monday to Sunday) onto another week; the target week is replaced. */
    public function copy(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'different:from']]);
        $from = CarbonImmutable::parse($d['from'], Facilities::TZ)->startOfWeek(CarbonImmutable::MONDAY);
        $to = CarbonImmutable::parse($d['to'], Facilities::TZ)->startOfWeek(CarbonImmutable::MONDAY);
        $shift = (int) $from->diffInDays($to, false);
        $source = DutyRota::where('territory_id', $church->id)->whereBetween('on', [$from->toDateString(), $from->addDays(6)->toDateString()])->get();
        if ($source->isEmpty()) {
            return $this->unprocessable('from', 'That week has nobody on duty to copy.');
        }
        DB::transaction(function () use ($church, $to, $source, $shift) {
            DutyRota::where('territory_id', $church->id)->whereBetween('on', [$to->toDateString(), $to->addDays(6)->toDateString()])->get()->each->delete();
            foreach ($source as $e) {
                DutyRota::create(['territory_id' => $church->id, 'on' => CarbonImmutable::parse($e->on->toDateString())->addDays($shift)->toDateString(), 'service' => $e->service, 'duty' => $e->duty, 'person_id' => $e->person_id, 'name' => $e->name]);
            }
        });

        return $this->ok(['copied' => $source->count()], "{$source->count()} duties copied to the week of {$to->format('j M')}.");
    }
}
