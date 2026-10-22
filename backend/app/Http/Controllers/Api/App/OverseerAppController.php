<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\TerritoryType;
use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\Territory;
use App\Services\App\OverseerFigures;
use App\Support\BudgetAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone app's subregion, region and diocese screens (docs/specs/mobile-app-spec.md,
 * M5, M8-M10). The place is the acting role's own (X-Assignment-Id) - a subregion too,
 * which the web's place helpers don't serve - and only churches below it are read.
 * Names stay with the leaders: pastors' names, never members'.
 */
class OverseerAppController extends Controller
{
    private const PERIOD_MONTHS = ['month' => 1, 'quarter' => 3, 'year' => 12];

    public function __construct(private OverseerFigures $figures) {}

    /** GET /app/home for an overseer: totals, reporting, the groups below, late churches, the latest figures. */
    public function home(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $f = $this->figures->for($place);
        $month = $f->reportMonth();
        $reports = $f->reports($month->year, $month->month);
        $pastors = $f->pastors();
        $totals = $f->totals();

        $groups = array_map(function ($g) use ($f, $reports) {
            $c = $f->compliance($reports->only($g['church_ids']));

            return $g + ['churches' => count($g['church_ids']), 'members' => $f->totals($g['church_ids'])['members'],
                'reporting' => $c, 'growth' => $f->growth($g['church_ids'], 1)['percent']];
        }, $f->groups($place));

        $late = $reports->filter(fn ($s) => $s['late'])->keys()->map(fn ($id) => [
            'id' => $id, 'name' => $f->churches()[$id]->name, 'pastor' => $pastors[$id] ?? null, 'due_on' => $reports[$id]['due_on'],
        ])->values();

        $recent = $f->churches()->map(fn ($c) => $f->latest($c->id))->filter()
            ->sortByDesc(fn ($r) => optional($r->submitted_at ?? $r->updated_at)->timestamp)->take(5)
            ->map(fn ($r) => ['church_id' => $r->territory_id, 'church' => $f->churches()[$r->territory_id]->name, 'label' => $f->label($r),
                'members' => $r->total_members, 'at' => optional($r->submitted_at ?? $r->updated_at)->toIso8601String()])->values();

        return successResponse('Home', [
            'place' => $this->info($place),
            'churches' => $f->churches()->count(),
            'totals' => $totals,
            'growth' => $f->growth(null, 1),
            'reporting' => $f->compliance($reports) + ['month' => $month->format('F Y')],
            'groups' => array_values($groups),
            'late' => $late,
            'recent' => $recent,
        ]);
    }

    /** GET /app/places?group= - the churches below (or in one group): pastor, members, report, latest period. */
    public function places(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $f = $this->figures->for($place);
        $month = $f->reportMonth();
        $reports = $f->reports($month->year, $month->month);
        $pastors = $f->pastors();
        $groupOf = [];
        foreach ($f->groups($place) as $g) {
            foreach ($g['church_ids'] as $id) {
                $groupOf[$id] = $g['type'] === 'church' ? null : $g['name'];
            }
        }
        $only = $request->integer('group') ?: null;
        if ($only && ! in_array($only, PlaceAccess::descendantIds($place), true)) {
            return errorResponse('That place is not in your area.', 403);
        }
        $onlyIds = $only ? array_values(array_filter(PlaceAccess::descendantIds(Territory::findOrFail($only)), fn ($id) => $f->churches()->has($id))) : null;

        $rows = $f->churches()->filter(fn ($c) => $onlyIds === null || in_array($c->id, $onlyIds, true))->map(function ($c) use ($f, $reports, $pastors, $groupOf) {
            $latest = $f->latest($c->id);
            $prev = $f->previous($c->id);

            return [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'group' => $groupOf[$c->id] ?? null,
                'pastor' => $pastors[$c->id] ?? null,
                'members' => $latest?->total_members, 'change' => $latest && $prev ? $latest->total_members - $prev->total_members : null,
                'latest' => $latest ? ['id' => $latest->id, 'label' => $f->label($latest), 'status' => $latest->status, 'at' => optional($latest->submitted_at ?? $latest->updated_at)->toIso8601String()] : null,
                'report' => $reports[$c->id],
            ];
        })->values();

        return successResponse('Places', ['place' => $this->info($place), 'month' => $month->format('F Y'), 'items' => $rows]);
    }

    /**
     * GET /app/review/{church} (M9): the church's latest demographics, the period
     * before, the change in each count, and notes worth a second look - a
     * membership group over the total, or a change of more than a quarter.
     * `can` says whether this role may flag it or ask for changes (the subregion
     * overseer, by permission).
     */
    public function review(Request $request, int $church): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! in_array($church, PlaceAccess::descendantIds($place), true)) {
            return errorResponse('That church is not in your area.', 403);
        }
        $c = Territory::findOrFail($church);
        $f = $this->figures->for($place);
        $rows = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])->where('territory_id', $c->id)
            ->whereIn('status', ['submitted', 'approved', 'flagged', 'changes_requested'])->get()
            ->sortByDesc(fn ($r) => $f->endsOn($r)->toDateString())->values();
        $latest = $rows->first();
        $before = $rows->slice(1)->first(fn ($r) => $r->status === 'approved');

        $fields = [
            'total_members' => 'Total members', 'youth_count' => 'Youth (13–35)', 'womens_fellowship_count' => "Women's fellowship",
            'mens_fellowship_count' => "Men's fellowship", 'sunday_school' => 'Sunday school', 'seniors_count' => 'Senior citizens',
            'new_members_count' => 'New members', 'transferred_out_count' => 'Transferred out', 'baptisms_count' => 'Baptisms',
            'communion_participants_count' => 'Communion', 'conversions_count' => 'New conversions',
        ];
        $value = fn ($r, $k) => $r ? ($k === 'sunday_school' ? (int) $r->sunday_school_male_count + (int) $r->sunday_school_female_count : (int) $r->{$k}) : null;

        $lines = [];
        $notes = [];
        if ($latest) {
            foreach ($fields as $k => $label) {
                $now = $value($latest, $k);
                $then = $before ? $value($before, $k) : null;
                $change = $then !== null ? $now - $then : null;
                $pct = $then ? round($change / $then * 100) : null;
                $lines[] = ['key' => $k, 'label' => $label, 'value' => $now, 'previous' => $then, 'change' => $change, 'percent' => $pct];
                // Only the membership groups are part of the total (communion and new members may not be).
                if (in_array($k, ['youth_count', 'womens_fellowship_count', 'mens_fellowship_count', 'sunday_school', 'seniors_count'], true) && $now > (int) $latest->total_members) {
                    $notes[] = "{$label} ({$now}) is more than the total members ({$latest->total_members}) - check the count.";
                }
                if (in_array($k, ['total_members', 'youth_count', 'sunday_school', 'womens_fellowship_count', 'mens_fellowship_count'], true) && $pct !== null && abs($pct) > 25) {
                    $notes[] = sprintf('%s %s %d%% on %s - check the count.', $label, $pct > 0 ? 'up' : 'down', abs($pct), $f->label($before));
                }
            }
        }

        $user = $request->user();
        $oversees = $user->hasGlobalAccess() || (Church::find($c->id)?->isUnderSubregion()
            && $user->activeAssignments()->where('territory_id', $c->parent_territory_id)->exists());
        $can = fn ($action) => $latest && $oversees && $user->can('subregiondemographicsreview.churchsubmissions.'.$action);

        return successResponse('Review', [
            'church' => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'pastor' => $f->pastors()[$c->id] ?? null],
            'record' => $latest ? ['id' => $latest->id, 'label' => $f->label($latest), 'status' => $latest->status,
                'review_notes' => $latest->review_notes, 'at' => optional($latest->submitted_at ?? $latest->updated_at)->toIso8601String()] : null,
            'previous_label' => $before ? $f->label($before) : null,
            'lines' => $lines,
            'notes' => $notes,
            'can' => [
                'approve' => $can('approve') && in_array($latest?->status, ['submitted', 'flagged'], true),
                'flag' => $can('flag') && in_array($latest?->status, ['submitted', 'approved'], true),
                'request_changes' => $can('requestchanges') && in_array($latest?->status, ['submitted', 'approved', 'flagged'], true),
            ],
        ]);
    }

    /**
     * GET /app/compare?period=month|quarter|year (M10): each group below with its
     * members, youth share, baptisms and growth over the period; the fastest
     * growing and declining; the members trend for 12 months and a 3-month
     * estimate ahead (a straight line through the trend).
     */
    public function compare(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $period = in_array($request->query('period'), array_keys(self::PERIOD_MONTHS), true) ? $request->query('period') : 'month';
        $f = $this->figures->for($place);
        $month = $f->reportMonth();
        $reports = $f->reports($month->year, $month->month);

        $rows = array_map(function ($g) use ($f, $period, $reports) {
            $t = $f->totals($g['church_ids']);
            $growth = $f->growth($g['church_ids'], self::PERIOD_MONTHS[$period]);

            return ['id' => $g['id'], 'name' => $g['name'], 'type' => $g['type'], 'churches' => count($g['church_ids'])] + [
                'members' => $t['members'], 'youth' => $t['youth'], 'youth_share' => $t['members'] ? (int) round($t['youth'] / $t['members'] * 100) : null,
                'baptisms' => $t['baptisms'], 'conversions' => $t['conversions'], 'growth' => $growth['percent'],
                'reporting' => $f->compliance($reports->only($g['church_ids'])),
            ];
        }, $f->groups($place));

        $ranked = collect($rows)->filter(fn ($r) => $r['growth'] !== null);
        $trend = $f->trend();
        $totals = $f->totals();

        return successResponse('Compare', [
            'place' => $this->info($place),
            'period' => $period,
            'totals' => $totals + ['youth_share' => $totals['members'] ? (int) round($totals['youth'] / $totals['members'] * 100) : null],
            'growth' => $f->growth(null, self::PERIOD_MONTHS[$period]),
            'rows' => $rows,
            'fastest' => $ranked->sortByDesc('growth')->take(3)->values(),
            'declining' => $ranked->filter(fn ($r) => $r['growth'] < 0)->sortBy('growth')->take(3)->values(),
            'trend' => $trend,
            'forecast' => $f->forecast($trend),
        ]);
    }

    /** The acting role's own place - a subregion, region or diocese. */
    private function place(Request $request): Territory|JsonResponse
    {
        $user = $request->user();
        $place = BudgetAccess::assignment($user)?->territory;
        if (! $place && $user->hasGlobalAccess()) {
            $place = Territory::where('territory_type', TerritoryType::DIOCESE->value)->orderBy('id')->first();
        }
        if ($place && $place->territory_type === TerritoryType::GLOBAL) {
            $place = Territory::where('territory_type', TerritoryType::DIOCESE->value)->where('parent_territory_id', $place->id)->orderBy('id')->first() ?? $place;
        }
        if (! $place || $place->territory_type === TerritoryType::CHURCH) {
            return errorResponse('These screens are for a subregion, region or diocese role.', 403);
        }

        return $place;
    }

    private function info(Territory $place): array
    {
        return ['id' => $place->id, 'name' => $place->name, 'code' => $place->code, 'level' => $place->territory_type->value];
    }
}
