<?php

namespace App\Reports\Attendance;

use App\Models\ChurchAttendanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the attendance record page shows about one recorded Sunday or
 * meeting: its counts, the meeting before and after it of the same
 * gathering, how it compares with the usual and the best, the last few
 * meetings for a small chart, and - for a Sunday - the share of members
 * who came and the Sundays either side (a missed one included).
 */
final class AttendanceRecordDetail
{
    private const USUAL_OF = 8;

    public function __construct(private ChurchAttendanceRecord $record) {}

    /** Every record of the same gathering at the same church, oldest first. */
    private function sameGathering(): Collection
    {
        $r = $this->record;
        $query = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $r->territory_id)
            ->where('gathering_category_id', $r->gathering_category_id);

        if (! $r->gatheringCategory?->is_weekly) {
            $r->gathering_type_id
                ? $query->where('gathering_type_id', $r->gathering_type_id)
                : $query->whereNull('gathering_type_id')->where('event_name', $r->event_name);
        }

        return $query->orderBy('service_date')->orderBy('id')->get();
    }

    private static function brief(?ChurchAttendanceRecord $r): ?array
    {
        return $r ? ['id' => $r->id, 'date' => $r->service_date->toDateString(), 'total' => AttendanceData::total($r)] : null;
    }

    public function toArray(): array
    {
        $r = $this->record->loadMissing(['gatheringCategory', 'gatheringType', 'creator:id,firstname,lastname', 'updater:id,firstname,lastname']);
        $all = $this->sameGathering();
        $index = $all->search(fn ($x) => $x->id === $r->id);
        $before = $all->slice(0, $index)->values();
        $previous = $before->last();
        $next = $all->get($index + 1);
        $recent = $before->slice(-self::USUAL_OF);
        $total = AttendanceData::total($r);
        $usual = $recent->isEmpty() ? null : (int) round($recent->avg(fn ($x) => AttendanceData::total($x)));
        $best = $all->sortByDesc(fn ($x) => AttendanceData::total($x))->first();
        $isSunday = (bool) $r->gatheringCategory?->is_weekly;

        return [
            'record' => $r,
            'total' => $total,
            'name' => $isSunday ? 'Sunday service' : ($r->gatheringType?->name ?? $r->event_name),
            'is_sunday' => $isSunday,
            'recorded_by' => $r->creator ? trim("{$r->creator->firstname} {$r->creator->lastname}") : null,
            'updated_by' => $r->updater && $r->updated_at?->ne($r->created_at) ? trim("{$r->updater->firstname} {$r->updater->lastname}") : null,
            'previous' => self::brief($previous),
            'next' => self::brief($next),
            'usual' => $usual,
            'usual_of' => $recent->count(),
            'best' => self::brief($best),
            'is_best' => $best && $best->id === $r->id && $all->count() > 1,
            'rank' => $all->count() > 1 ? $all->sortByDesc(fn ($x) => AttendanceData::total($x))->values()->search(fn ($x) => $x->id === $r->id) + 1 : null,
            'of' => $all->count(),
            // The last meetings up to this one, for a small chart.
            'recent' => $all->slice(max(0, $index - self::USUAL_OF + 1), min(self::USUAL_OF, $index + 1))->map(fn ($x) => self::brief($x))->values()->all(),
            'members' => $isSunday ? $this->members($total) : null,
            'sundays' => $isSunday ? $this->sundaysAround() : null,
        ];
    }

    /** Total members at the time (latest approved demographics up to that month) and the share who came. */
    private function members(int $total): ?array
    {
        $month = $this->record->service_date->format('Y-m');
        $data = new AttendanceData([$this->record->territory_id], AttendancePeriod::range($month, $month));

        return $data->membership($total);
    }

    /** The Sunday before and after this one: its record, or "not recorded" (never a future Sunday). */
    private function sundaysAround(): array
    {
        $date = CarbonImmutable::parse($this->record->service_date);
        $find = fn (CarbonImmutable $d) => ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $this->record->territory_id)
            ->where('gathering_category_id', $this->record->gathering_category_id)
            ->whereDate('service_date', $d->toDateString())
            ->first();
        $side = function (CarbonImmutable $d) use ($find) {
            if ($d->isAfter(CarbonImmutable::today())) {
                return null;
            }
            $rec = $find($d);

            return ['date' => $d->toDateString(), 'id' => $rec?->id, 'total' => $rec ? AttendanceData::total($rec) : null];
        };

        return ['previous' => $side($date->subWeek()), 'next' => $side($date->addWeek())];
    }
}
