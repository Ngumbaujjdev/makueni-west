<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\DutyRota;
use App\Services\Facilities\Facilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Who is on duty at each service (P5): ushering, welcome, sound, security, cleaning. */
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
            'duties' => collect(DutyRota::DUTIES)->map(fn ($x, $k) => ['key' => $k, 'label' => $x[0], 'icon' => $x[1], 'color' => $x[2]])->values(),
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
            'duty' => ['required', Rule::in(array_keys(DutyRota::DUTIES))],
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
