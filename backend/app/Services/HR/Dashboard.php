<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Territory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Staff dashboard (docs/specs/hr-spec.md → Dashboard): how many people we
 * employ and what they cost a month, what was paid this year against last,
 * pay month by month, who is on what, what is coming up, the latest payroll
 * runs and - for a region or the diocese - each place below. Paid means a
 * payslip in an approved or paid run; drafts and returned runs don't count.
 */
class Dashboard
{
    private const PAID = ['posted', 'paid'];

    /** @param  int[]  $placeIds  the places counted (the place itself, or it and those below) */
    public function build(array $placeIds, int $year): array
    {
        $today = CarbonImmutable::today();
        $people = Employee::whereIn('territory_id', $placeIds)->get();
        $working = fn (CarbonImmutable $on) => $people->filter(fn (Employee $e) => (! $e->start_date || $e->start_date->lte($on)) && (! $e->end_date || $e->end_date->gte($on)));
        $now = $working($today);
        $yearAgo = $working($today->subYear());
        $gross = fn (Employee $e) => (float) $e->basic_pay + array_sum(array_map(fn ($a) => (float) ($a['amount'] ?? 0), $e->allowances ?? []));
        $monthly = round($now->sum($gross), 2);

        $series = $this->series($placeIds, $year);
        $before = $this->series($placeIds, $year - 1);
        // This year so far - up to its latest paid month - against the same months last year (a whole year for a past one).
        $upTo = 12;
        if ($year === (int) $today->year) {
            $upTo = max(1, (int) collect($series)->filter(fn ($m) => $m['net'] > 0)->keys()->max() + 1);
        }
        $paid = round(array_sum(array_column(array_slice($series, 0, $upTo), 'net')), 2);
        $paidBefore = round(array_sum(array_column(array_slice($before, 0, $upTo), 'net')), 2);
        $lastRun = PayrollRun::whereIn('territory_id', $placeIds)->whereIn('status', self::PAID)->where('month', '<', $today->format('Y-m'))
            ->orderByDesc('month')->value('month');
        $lastBill = $lastRun ? round((float) PayrollRun::whereIn('territory_id', $placeIds)->whereIn('status', self::PAID)->where('month', $lastRun)->sum('gross'), 2) : null;

        return [
            'year' => $year,
            'figures' => [
                'staff' => $now->count(), 'staff_year_ago' => $yearAgo->count(),
                'monthly_pay' => $monthly, 'last_bill' => $lastBill, 'last_bill_month' => $lastRun,
                'paid' => $paid, 'paid_before' => $paidBefore, 'months_counted' => $upTo,
                'average' => $now->count() ? round($monthly / $now->count(), 2) : 0,
                'average_year_ago' => $yearAgo->count() ? round($yearAgo->sum($gross) / $yearAgo->count(), 2) : 0,
                'contracts_ending' => $now->filter(fn ($e) => $e->contract_end && $e->contract_end->between($today, $today->addDays(60)))->count(),
                'linked' => $now->filter(fn ($e) => $e->person_id || $e->user_id)->count(),
            ],
            'months' => $series,
            'months_before' => $before,
            'by_type' => collect(Employee::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $now->where('employment_type', $key)->count()])->values(),
            'by_position' => $now->groupBy(fn ($e) => $e->position ?: 'No position')
                ->map(fn (Collection $g, $name) => ['name' => $name, 'position_id' => $g->first()->position_id, 'count' => $g->count(), 'monthly' => round($g->sum($gross), 2)])
                ->sortByDesc('monthly')->values()->take(8),
            'coming_up' => $this->comingUp($people, $today),
            'runs' => $this->runs($placeIds),
        ];
    }

    /** Per place below: staff, pay a month and paid this year - places with nobody are left out. */
    public function places(array $placeIds, int $year): array
    {
        $people = Employee::whereIn('territory_id', $placeIds)->get()->groupBy('territory_id');
        $paid = Payslip::join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')->whereIn('payroll_runs.territory_id', $placeIds)
            ->whereIn('payroll_runs.status', self::PAID)->where('payroll_runs.month', 'like', "{$year}-%")
            ->groupBy('payroll_runs.territory_id')->selectRaw('payroll_runs.territory_id as place, sum(payslips.net) as net')->pluck('net', 'place');
        $today = CarbonImmutable::today();
        $ids = $people->keys()->merge($paid->keys())->unique();

        return Territory::whereIn('id', $ids)->orderBy('name')->get()->map(function (Territory $t) use ($people, $paid, $today) {
            $now = ($people[$t->id] ?? collect())->filter(fn ($e) => (! $e->start_date || $e->start_date->lte($today)) && (! $e->end_date || $e->end_date->gte($today)));

            return [
                'id' => $t->id, 'name' => $t->name, 'level' => $t->territory_type->value, 'staff' => $now->count(),
                'monthly_pay' => round($now->sum(fn ($e) => (float) $e->basic_pay + array_sum(array_map(fn ($a) => (float) ($a['amount'] ?? 0), $e->allowances ?? []))), 2),
                'paid' => round((float) ($paid[$t->id] ?? 0), 2),
            ];
        })->values()->all();
    }

    /** Jan..Dec of a year: gross, net and the people paid, from approved or paid runs. */
    private function series(array $placeIds, int $year): array
    {
        $rows = Payslip::join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')->whereIn('payroll_runs.territory_id', $placeIds)
            ->whereIn('payroll_runs.status', self::PAID)->where('payroll_runs.month', 'like', "{$year}-%")
            ->groupBy('payroll_runs.month')->selectRaw('payroll_runs.month as month, sum(payslips.gross) as gross, sum(payslips.net) as net, count(distinct payslips.employee_id) as people')
            ->get()->keyBy('month');

        return array_map(function ($m) use ($rows, $year) {
            $key = sprintf('%d-%02d', $year, $m);
            $r = $rows[$key] ?? null;

            return ['month' => $key, 'gross' => round((float) ($r->gross ?? 0), 2), 'net' => round((float) ($r->net ?? 0), 2), 'people' => (int) ($r->people ?? 0)];
        }, range(1, 12));
    }

    /** The next 60 days: people starting or leaving, contracts ending, years of service. */
    private function comingUp(Collection $people, CarbonImmutable $today): array
    {
        $until = $today->addDays(60);
        $places = Territory::whereIn('id', $people->pluck('territory_id')->unique())->pluck('name', 'id');
        $items = [];
        $add = function (Employee $e, CarbonImmutable $on, string $kind, array $extra = []) use (&$items, $places) {
            $items[] = ['date' => $on->toDateString(), 'kind' => $kind, 'employee_id' => $e->id, 'name' => $e->name, 'position' => $e->position, 'place' => $places[$e->territory_id] ?? null] + $extra;
        };
        foreach ($people as $e) {
            $leftAlready = $e->end_date && $e->end_date->lt($today);
            if ($e->start_date && $e->start_date->between($today, $until)) {
                $add($e, CarbonImmutable::parse($e->start_date), 'starts');
            }
            if ($e->end_date && $e->end_date->between($today, $until)) {
                $add($e, CarbonImmutable::parse($e->end_date), 'leaves');
            }
            if (! $leftAlready && $e->contract_end && $e->contract_end->between($today, $until)) {
                $add($e, CarbonImmutable::parse($e->contract_end), 'contract');
            }
            if (! $leftAlready && $e->start_date && $e->start_date->lt($today)) {
                $start = CarbonImmutable::parse($e->start_date);
                $next = $start->setYear($today->year);
                if ($next->lt($today)) {
                    $next = $next->addYear();
                }
                $years = $next->year - $start->year;
                if ($years >= 1 && $next->lte($until)) {
                    $add($e, $next, 'anniversary', ['years' => $years]);
                }
            }
        }
        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_slice($items, 0, 12);
    }

    /** The latest six runs: month, place, where it stands and what it came to. */
    private function runs(array $placeIds): array
    {
        $runs = PayrollRun::whereIn('territory_id', $placeIds)->where('status', '!=', 'cancelled')->withCount('payslips')
            ->orderByDesc('month')->orderByDesc('id')->limit(6)->get();
        $places = Territory::whereIn('id', $runs->pluck('territory_id')->unique())->pluck('name', 'id');

        return $runs->map(fn (PayrollRun $r) => [
            'id' => $r->id, 'month' => $r->month, 'place' => $places[$r->territory_id] ?? null, 'place_id' => $r->territory_id,
            'status' => $r->status, 'status_label' => PayrollRun::STATUSES[$r->status] ?? $r->status,
            'people' => $r->payslips_count, 'gross' => (float) $r->gross, 'net' => (float) $r->net,
        ])->values()->all();
    }
}
