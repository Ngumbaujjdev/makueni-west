<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\DutyRota;
use App\Models\Equipment;
use App\Models\MaintenanceJob;
use App\Models\Ministry;
use App\Models\Person;
use App\Models\Room;
use App\Services\Activities\Activities;
use App\Services\People\People;
use App\Support\PeopleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Facilities page and what its windows choose from (P5). */
class FacilitiesController extends FacilitiesBase
{
    /** GET /facilities/overview */
    public function overview(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->facilities->overview($church, $request->user()) + ['can' => $this->can($request, $church)]);
    }

    /** GET /facilities/options - rooms, kinds and duties, ministries, leaders, opening hours. */
    public function options(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        [$from, $to] = $this->facilities->hours($church);
        $activities = app(Activities::class);
        $leaders = $activities->leadersWith([$church->id], PeopleAccess::permission('facilities', 'manage'))
            ->merge($activities->leadersWith([$church->id], PeopleAccess::permission('facilities', 'book')))->unique('id')->sortBy('firstname')->values();

        return $this->ok([
            'rooms' => $this->facilities->rooms($church, $this->facilities->canManage($request->user(), $church))->map(fn (Room $r) => $this->facilities->roomRow($r))->values(),
            'colours' => Room::COLOURS,
            'categories' => collect(Equipment::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'icon' => $c[1], 'color' => $c[2]])->values(),
            'conditions' => collect(Equipment::CONDITIONS)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'color' => $c[1]])->values(),
            'statuses' => collect(MaintenanceJob::STATUSES)->map(fn ($s, $k) => ['key' => $k, 'label' => $s[0], 'icon' => $s[1], 'color' => $s[2], 'hint' => $s[3]])->values(),
            'duties' => collect(DutyRota::DUTIES)->map(fn ($d, $k) => ['key' => $k, 'label' => $d[0], 'icon' => $d[1], 'color' => $d[2]])->values(),
            'ministries' => Ministry::where('territory_id', $church->id)->where('active', true)->orderBy('order')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'icon' => $m->icon_name, 'colour' => $m->colour_name, 'meets_day' => $m->meets_day, 'meets_time' => $m->meets_time ? substr($m->meets_time, 0, 5) : null])->values(),
            'leaders' => $leaders->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")])->values(),
            'hours' => ['from' => $from, 'to' => $to],
            'min_notice_hours' => $this->facilities->minNoticeHours($church),
            'loan_days' => $this->facilities->loanDays($church),
            'services' => $this->facilities->services($church),
            'reminders' => (bool) app(\App\Services\Settings\Settings::class)->get('facilities.duty_reminder', $church),
            'can' => $this->can($request, $church),
        ]);
    }

    /** GET /facilities/people?q= - our members and visitors to put on duty or lend to (no phone needed). */
    public function people(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $rows = app(People::class)->query($church, ['q' => $d['q'], 'status' => ['member', 'visitor']])->orderBy('first_name')->limit(20)->get();

        return $this->ok($rows->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'initials' => $p->initials, 'area' => $p->area, 'kind' => $p->status === 'visitor' ? 'visitor' : 'member'])->values());
    }
}
