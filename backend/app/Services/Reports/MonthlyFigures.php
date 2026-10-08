<?php

namespace App\Services\Reports;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\ActivitySession;
use App\Models\ChurchDemographic;
use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Reports\Attendance\AttendanceData;
use App\Reports\Attendance\AttendancePeriod;
use App\Reports\Budget\BudgetData;
use App\Reports\Budget\BudgetRollup;
use App\Services\Activities\Activities;
use Carbon\CarbonImmutable;

/**
 * The figures of a monthly report, filled in from what is already recorded
 * (docs/specs/monthly-reports-spec.md): people, attendance, money, events
 * and initiatives - and for a region, its churches. Nothing is typed twice
 * and nothing is worked out a second way: each block reads the module that
 * owns it.
 */
final class MonthlyFigures
{
    /** Dates of events and sessions are the diocese's own days. */
    private const TZ = 'Africa/Nairobi';

    public function __construct(private BudgetRollup $rollup, private Activities $activities) {}

    public function for(Territory $place, int $year, int $month): array
    {
        $level = $place->territory_type->value;
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TZ);
        $end = $start->endOfMonth();

        return [
            'period' => ['year' => $year, 'month' => $month, 'label' => $start->format('F Y'), 'start' => $start->toDateString(), 'end' => $end->toDateString()],
            'people' => $level === 'church' ? $this->people($place, $end) : null,
            // People & care P3: the month's pastoral visits and shared testimonies - offered to the report, never filled in.
            'pastoral' => $level === 'church' ? app(\App\Services\People\Care::class)->monthly($place, $year, $month) : null,
            'attendance' => $level === 'church' ? $this->attendance([(int) $place->id], $start) : null,
            'money' => $this->money($place, $year, $month),
            'events' => $this->events($place, $start, $end),
            'initiatives' => $this->initiatives($place, $start, $end),
            'churches' => $level === 'region' ? $this->churches($place, $year, $month, $start) : null,
            'worked_out_at' => now()->toIso8601String(),
        ];
    }

    /** The latest Demographics submission for the month or before; the month's own counts only when it is that month's. */
    private function people(Territory $place, CarbonImmutable $end): array
    {
        $rows = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])
            ->where('territory_type', 'church')->where('territory_id', $place->id)->where('status', '!=', 'draft')->get()
            ->map(fn (ChurchDemographic $d) => ['d' => $d, 'end' => $this->periodEnd($d)])
            ->filter(fn ($r) => $r['end'] && $r['end'] <= $end->toDateString())
            ->sortByDesc('end')->values();
        $latest = $rows->get(0);
        if (! $latest) {
            return ['recorded' => false, 'note' => 'No Demographics recorded yet for '.$end->format('F Y').' or before.'];
        }
        $d = $latest['d'];
        $before = $rows->get(1)['d'] ?? null;
        $thisMonth = $d->fiscalMonth && (int) $d->fiscalMonth->number === (int) $end->month && (int) $d->fiscalYear?->year === (int) $end->year;

        return [
            'recorded' => true,
            'from' => $this->periodLabel($d),
            'this_month' => $thisMonth,
            'members' => (int) $d->total_members,
            'members_change' => $before ? (int) $d->total_members - (int) $before->total_members : null,
            'new_members' => $thisMonth ? (int) $d->new_members_count : null,
            'baptisms' => $thisMonth ? (int) $d->baptisms_count : null,
            'conversions' => $thisMonth ? (int) $d->conversions_count : null,
            'communion' => $thisMonth ? (int) $d->communion_participants_count : null,
            'note' => $thisMonth ? null : 'Members are from '.$this->periodLabel($d).'. This month\'s baptisms, conversions and communion show when the church records monthly.',
        ];
    }

    private function periodEnd(ChurchDemographic $d): ?string
    {
        if ($d->fiscalMonth && $d->fiscalYear) {
            return CarbonImmutable::create((int) $d->fiscalYear->year, (int) $d->fiscalMonth->number, 1)->endOfMonth()->toDateString();
        }
        if ($d->fiscalSemiAnnual?->end_date) {
            return CarbonImmutable::parse($d->fiscalSemiAnnual->end_date)->toDateString();
        }

        return $d->fiscalYear?->end_date ? CarbonImmutable::parse($d->fiscalYear->end_date)->toDateString() : null;
    }

    private function periodLabel(ChurchDemographic $d): string
    {
        if ($d->fiscalMonth && $d->fiscalYear) {
            return "{$d->fiscalMonth->name} {$d->fiscalYear->year}";
        }

        return $d->fiscalSemiAnnual ? "{$d->fiscalSemiAnnual->name} {$d->fiscalYear?->year}" : (string) $d->fiscalYear?->year;
    }

    /** Sundays recorded, the average Sunday, and other gatherings held. */
    private function attendance(array $churchIds, CarbonImmutable $start): array
    {
        $data = new AttendanceData($churchIds, AttendancePeriod::range($start->format('Y-m'), $start->format('Y-m')));
        $sundays = $data->sundays();
        $other = $data->records()->filter(fn ($r) => $r->gatheringCategory?->slug !== AttendanceData::SUNDAY);

        return [
            'recorded' => $sundays !== [] || $other->isNotEmpty(),
            'sundays' => count($sundays),
            'average_sunday' => AttendanceData::averageSunday($sundays),
            'highest_sunday' => $sundays ? max(array_column($sundays, 'total')) : null,
            'gatherings' => $other->count(),
            'gathering_attendance' => (int) $other->sum(fn ($r) => AttendanceData::total($r)),
            'note' => $sundays ? null : 'No Sunday attendance recorded for '.$start->format('F').'.',
        ];
    }

    /** Income, expenses and what's left (Budgets); the diocese share for the month (Contributions). */
    private function money(Territory $place, int $year, int $month): array
    {
        $level = $place->territory_type->value;
        $dash = (new BudgetData($level, (int) $place->id, $year, $month))->dashboard();
        $t = $dash['totals'];
        $share = array_values(array_filter($this->rollup->contributionsOf($level, (int) $place->id, $year), fn ($r) => (int) $r['month'] === $month && $r['deduction_id']));

        return [
            'recorded' => count($dash['budgets']) > 0 || $t['entries'] > 0,
            'budget' => $dash['budget'] ? ['id' => $dash['budget']['id'] ?? null, 'label' => $dash['budget']['period_label'] ?? null, 'status' => $dash['budget']['status'] ?? null] : null,
            'income' => $t['in_actual'],
            'expenses' => $t['out_actual'],
            'left' => $t['left_actual'],
            'income_planned' => $t['in_planned'],
            'expenses_planned' => $t['out_planned'],
            'share' => $share ? [
                'name' => $share[0]['name'],
                'due' => round(array_sum(array_column($share, 'due')), 2),
                'sent' => round(array_sum(array_column($share, 'sent')), 2),
                'still_to_send' => round(array_sum(array_column($share, 'owed')), 2),
                'status' => collect($share)->contains('status', 'late') ? 'late' : (collect($share)->contains('status', 'pending') ? 'pending' : $share[0]['status']),
            ] : null,
            'note' => ! count($dash['budgets']) && ! $t['entries'] ? 'No budget or money recorded for '.CarbonImmutable::create($year, $month, 1)->format('F').'.' : null,
        ];
    }

    /** Our events that month, and the ones we took part in. */
    private function events(Territory $place, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $in = fn ($q) => $q->where('starts_at', '>=', $start->setTimezone('UTC'))->where('starts_at', '<=', $end->setTimezone('UTC'));
        $ours = Activity::with('registrations')->where('kind', 'event')->where('territory_id', $place->id)->where('status', '!=', 'cancelled')->where($in)->orderBy('starts_at')->get();
        $joined = ActivityRegistration::with('activity.territory')->where('territory_id', $place->id)->where('status', 'registered')
            ->whereHas('activity', fn ($q) => $q->where('kind', 'event')->where('status', '!=', 'cancelled')->where($in))->get();

        return [
            'ours' => $ours->map(fn (Activity $a) => [
                'id' => $a->id, 'title' => $a->title, 'type' => $a->typeLabel(), 'date' => $a->starts_at->setTimezone(self::TZ)->toDateString(), 'status' => $a->status,
                'expected' => $this->activities->totals($a)['expected'], 'came' => $this->activities->totals($a)['came'],
            ])->values()->all(),
            'took_part' => $joined->sortBy(fn ($r) => $r->activity->starts_at)->map(fn (ActivityRegistration $r) => [
                'id' => $r->activity_id, 'title' => $r->activity->title, 'organiser' => $r->activity->territory?->name,
                'date' => $r->activity->starts_at->setTimezone(self::TZ)->toDateString(), 'expected' => $r->expected(), 'came' => $r->came(),
            ])->values()->all(),
        ];
    }

    /** Our initiatives' sessions held that month, with attendance. */
    private function initiatives(Territory $place, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sessions = ActivitySession::with('activity')->where('status', 'held')->whereBetween('held_on', [$start->toDateString(), $end->toDateString()])
            ->whereHas('activity', fn ($q) => $q->where('kind', 'initiative')->where('territory_id', $place->id))->get();

        return $sessions->groupBy('activity_id')->map(fn ($group) => [
            'id' => $group->first()->activity_id,
            'title' => $group->first()->activity->title,
            'sessions' => $group->count(),
            'attendance' => (int) $group->sum(fn (ActivitySession $s) => (int) $s->attendance()),
        ])->values()->all();
    }

    /** A region's own report: its churches' reports, Sunday attendance and money that month. */
    private function churches(Territory $region, int $year, int $month, CarbonImmutable $start): array
    {
        $places = $this->rollup->placesBelow($region, 'church');
        $ids = array_column($places, 'id');
        $reports = MonthlyReport::whereIn('territory_id', $ids ?: [0])->where('year', $year)->where('month', $month)->get();
        $attendance = $this->attendance($ids ?: [0], $start);
        $money = $this->rollup->summary($region, $year, $month, 'church')['totals'];

        return [
            'churches' => count($ids),
            'sent' => $reports->whereIn('status', ['sent', 'seen'])->count(),
            'seen' => $reports->where('status', 'seen')->count(),
            'average_sunday' => $attendance['average_sunday'],
            'income' => $money['in_actual'],
            'expenses' => $money['out_actual'],
            'share_sent' => $money['sent'],
            'share_still_to_send' => $money['owed'],
        ];
    }
}
