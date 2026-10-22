<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Services\Accounting\Books;
use App\Services\Accounting\Remittances;
use App\Services\Reports\MonthlyReports;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone app's church screens (docs/specs/mobile-app-spec.md, M5): one call
 * for the pastor's home, and the church's demographics in its own cadence. The
 * church is the acting role's (X-Assignment-Id); nothing here reads another's.
 */
class ChurchAppController extends Controller
{
    public function __construct(private MonthlyReports $reports, private Remittances $remittances, private Books $books) {}

    /** GET /app/home - members, this month, the monthly report due and the share owed. */
    public function home(Request $request): JsonResponse
    {
        // A subregion, region or diocese role gets its own home (OverseerAppController).
        $acting = \App\Support\BudgetAccess::assignment($request->user())?->territory;
        if (! $acting || $acting->territory_type !== \App\Enums\TerritoryType::CHURCH) {
            return app(OverseerAppController::class)->home($request);
        }
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $user = $request->user();
        $today = CarbonImmutable::today();

        [$latest, $previous] = $this->lastTwo($church);

        // The report people are working on: last month until it is sent, then this month.
        $last = $today->subMonthNoOverflow();
        $lastReport = MonthlyReport::where('territory_id', $church->id)->where('year', $last->year)->where('month', $last->month)->first();
        $report = $this->reports->state($church, $last->year, $last->month, $lastReport);
        if (in_array($report['status'], ['sent', 'seen', 'not_tracked'], true)) {
            $now = MonthlyReport::where('territory_id', $church->id)->where('year', $today->year)->where('month', $today->month)->first();
            $report = $this->reports->state($church, $today->year, $today->month, $now);
        }

        $money = null;
        if (AccountingAccess::canRead($user, $church)) {
            $owing = $this->remittances->owing($church, $today->year);
            $owed = round(array_sum(array_column($owing, 'owed')), 2);
            $money = $this->books->inOut($church, $today->startOfMonth()->toDateString(), $today->toDateString()) + [
                'share' => ['owed' => $owed, 'status' => $owing === [] ? 'none' : ($owed > 0 ? 'owed' : 'paid'), 'to' => $owing[0]['to']['name'] ?? null],
            ];
        }

        return successResponse('Home', [
            'place' => ['id' => $church->id, 'name' => $church->name, 'code' => $church->code],
            'members' => [
                'total' => $latest?->total_members,
                'change' => $latest && $previous ? $latest->total_members - $previous->total_members : null,
                'period' => $latest ? $this->periodLabel($latest) : null,
            ],
            'latest' => $latest ? ['youth' => $latest->youth_count, 'baptisms' => $latest->baptisms_count, 'conversions' => $latest->conversions_count] : null,
            'report' => $report,
            'money' => $money,
        ]);
    }

    /**
     * GET /app/demographics - the church's cadence (monthly, half-yearly or
     * yearly), this year's and last year's periods with what is already
     * recorded for each, and the history - so the phone needs no other call.
     */
    public function demographics(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $mode = Church::find($church->id)?->getDemographicsMode() ?? 'half_yearly';
        $today = CarbonImmutable::today();
        $years = FiscalYear::with('semiAnnuals')->whereIn('year', [$today->year, $today->year - 1])->orderByDesc('year')->get();
        $months = FiscalMonth::orderBy('number')->get();
        $rows = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])->where('territory_id', $church->id)->get();

        $periods = [];
        foreach ($years as $fy) {
            $slots = match ($mode) {
                'monthly' => $months->filter(fn ($m) => $fy->year < $today->year || $m->number <= $today->month)->sortByDesc('number')
                    ->map(fn ($m) => ['fiscal_month_id' => $m->id, 'fiscal_semi_annual_id' => null, 'label' => "{$m->name} {$fy->year}"]),
                'half_yearly' => $fy->semiAnnuals->filter(fn ($h) => $fy->year < $today->year || $h->number === 1 || $today->month > 6)->sortByDesc('number')
                    ->map(fn ($h) => ['fiscal_month_id' => null, 'fiscal_semi_annual_id' => $h->id, 'label' => ($h->number === 1 ? 'January–June ' : 'July–December ').$fy->year]),
                default => collect([['fiscal_month_id' => null, 'fiscal_semi_annual_id' => null, 'label' => (string) $fy->year]]),
            };
            foreach ($slots as $slot) {
                $record = $rows->first(fn ($r) => $r->fiscal_year_id === $fy->id
                    && (int) $r->fiscal_month_id === (int) $slot['fiscal_month_id']
                    && (int) $r->fiscal_semi_annual_id === (int) $slot['fiscal_semi_annual_id']);
                $periods[] = $slot + ['fiscal_year_id' => $fy->id, 'year' => $fy->year, 'record' => $record ? $this->present($record) : null];
            }
        }

        // History in the church's current cadence only: a church that moved from
        // monthly to half-yearly would otherwise compare a month with a half-year.
        $inMode = fn ($r) => match ($mode) {
            'monthly' => (bool) $r->fiscal_month_id,
            'half_yearly' => (bool) $r->fiscal_semi_annual_id,
            default => ! $r->fiscal_month_id && ! $r->fiscal_semi_annual_id,
        };
        $history = $rows->filter(fn ($r) => $r->status === 'approved' && $inMode($r))
            ->sortByDesc(fn ($r) => sprintf('%04d-%02d-%02d', $r->fiscalYear?->year, $r->fiscalSemiAnnual?->number ?? 0, $r->fiscalMonth?->number ?? 0))
            ->values()->take(24)->map(fn ($r) => $this->present($r));

        return successResponse('Demographics', [
            'place' => ['id' => $church->id, 'name' => $church->name],
            'mode' => $mode,
            'periods' => $periods,
            'history' => $history,
        ]);
    }

    /** The acting role's own church, or the reason it can't be used. */
    private function church(Request $request): Territory|JsonResponse
    {
        $place = PlaceAccess::place($request->user());
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return errorResponse('These screens are for a church role.', 403);
        }

        return $place;
    }

    /** The two latest approved demographics, newest first. */
    private function lastTwo(Territory $church): array
    {
        $rows = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])->where('territory_id', $church->id)->where('status', 'approved')->get()
            ->sortByDesc(fn ($r) => sprintf('%04d-%02d-%02d', $r->fiscalYear?->year, $r->fiscalSemiAnnual?->number ?? 0, $r->fiscalMonth?->number ?? 0))->values();

        return [$rows[0] ?? null, $rows[1] ?? null];
    }

    private function periodLabel(ChurchDemographic $r): string
    {
        $year = $r->fiscalYear?->year;
        if ($r->fiscalMonth) {
            return "{$r->fiscalMonth->name} {$year}";
        }
        if ($r->fiscalSemiAnnual) {
            return ($r->fiscalSemiAnnual->number === 1 ? 'January–June ' : 'July–December ').$year;
        }

        return (string) $year;
    }

    private function present(ChurchDemographic $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'label' => $this->periodLabel($r),
            'fiscal_year_id' => $r->fiscal_year_id, 'fiscal_month_id' => $r->fiscal_month_id, 'fiscal_semi_annual_id' => $r->fiscal_semi_annual_id,
            'review_notes' => $r->review_notes, 'submitted_at' => $r->submitted_at?->toIso8601String(),
        ] + $r->only([
            'total_members', 'male_count', 'female_count', 'youth_count', 'womens_fellowship_count', 'mens_fellowship_count',
            'sunday_school_male_count', 'sunday_school_female_count', 'seniors_count', 'new_members_count', 'transferred_out_count',
            'baptisms_count', 'communion_participants_count', 'conversions_count',
        ]);
    }
}
