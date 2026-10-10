<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\GatheringType;
use App\Models\ReportRun;
use App\Models\Territory;
use App\Reports\Demographics\MetricReport;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Reports\ReportRegistry;
use App\Support\BudgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reports: catalogue, preview, queued generation, the user's runs, download,
 * and the public verify endpoint behind the PDF's QR code. See
 * docs/specs/reports-spec.md.
 *
 * Nothing here uses $request->validate(): on a failure that would redirect,
 * and the frontend's fetch() would save the redirect's HTML as the "report".
 * Every failure is a JSON response instead.
 */
class ReportController extends Controller
{
    /** GET /reports/catalogue?territory_id=&module= (module: demographics | attendance | budget, optional) */
    public function catalogue(Request $request): JsonResponse
    {
        $territory = Territory::find((int) $request->query('territory_id'));
        if (! $territory || ! $request->user()->canAccessTerritory($territory)) {
            return $this->fail(403, 'You do not have access to reports for this territory.');
        }

        return response()->json([
            'success' => true,
            'data' => array_values(array_map(
                fn (Report $r) => $r->toCatalogue(),
                array_filter(ReportRegistry::forScope($territory->territory_type, $request->query('module') ?: null), fn (Report $r) => $r->authorize($request->user(), $territory) === null),
            )),
        ]);
    }

    /** POST /reports/preview - builds the report without queuing it, for the modal. */
    public function preview(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$report, $context] = $resolved;

        try {
            $data = $report->build($context);
        } catch (ValidationException $e) {
            return $this->fail(422, collect($e->errors())->flatten()->first(), $e->errors());
        }

        return response()->json(['success' => true, 'data' => $data->toPreview()]);
    }

    /** POST /reports - queues a run. */
    public function store(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request, requireFormat: true);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$report, $context] = $resolved;

        $run = ReportRun::create([
            'user_id' => $request->user()->id,
            'territory_id' => $context->territory->id,
            'report_key' => $report->key(),
            'format' => $request->input('format'),
            'params' => $context->params,
            'status' => ReportRun::STATUS_QUEUED,
            'stage' => 'Waiting in the queue',
            'title' => $report->titleFor($context->params),
            'scope_label' => $context->territory->name,
        ]);

        GenerateReportJob::dispatch($run);

        return response()->json(['success' => true, 'data' => $run->fresh()->toApi()], 202);
    }

    /** GET /reports/runs - the user's recent runs, for the monitor. */
    public function runs(Request $request): JsonResponse
    {
        $runs = ReportRun::where('user_id', $request->user()->id)->latest()->limit(20)->get();

        return response()->json(['success' => true, 'data' => $runs->map->toApi()->values()]);
    }

    /** GET /reports/runs/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $run = $this->ownRun($request, $uuid);

        return $run ? response()->json(['success' => true, 'data' => $run->toApi()]) : $this->fail(404, 'Report not found.');
    }

    /** GET /reports/runs/{uuid}/download */
    public function download(Request $request, string $uuid)
    {
        $run = $this->ownRun($request, $uuid);
        if (! $run || ! $run->isReady()) {
            return $this->fail(404, 'Report not found.');
        }
        if (! $run->file_path || ! Storage::disk('local')->exists($run->file_path)) {
            return $this->fail(410, 'This file has expired. Generate the report again.');
        }

        $mime = $run->format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';

        // Content-Length lets the browser show real download progress.
        return Storage::disk('local')->download($run->file_path, $run->file_name, [
            'Content-Type' => $mime,
            'Content-Length' => Storage::disk('local')->size($run->file_path),
        ]);
    }

    /** GET /reports/verify/{code} - public. Who made it and when; never any figures. */
    public function verify(string $code): JsonResponse
    {
        $run = ReportRun::with('user')
            ->where('verification_code', strtoupper(trim($code)))
            ->where('status', ReportRun::STATUS_READY)
            ->first();

        if (! $run) {
            return response()->json([
                'success' => true,
                'data' => ['genuine' => false, 'code' => strtoupper(trim($code))],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'genuine' => true,
                'code' => $run->verification_code,
                'title' => $run->title,
                'scope_label' => $run->scope_label,
                'period_label' => $run->period_label,
                'format' => $run->format,
                'generated_at' => $run->finished_at?->toIso8601String(),
                'generated_by' => $run->user?->full_name,
                'file_hash' => $run->file_hash,
            ],
        ]);
    }

    /**
     * Shared checks for preview/store: known report, a territory the user can
     * see, a report that runs at that level, and the report's own inputs.
     *
     * @return JsonResponse|array{0: Report, 1: ReportContext}
     */
    private function resolve(Request $request, bool $requireFormat = false): JsonResponse|array
    {
        $validator = Validator::make($request->all(), [
            'report_key' => 'required|string',
            'territory_id' => 'required|integer',
            'format' => ($requireFormat ? 'required' : 'nullable').'|in:pdf,xlsx',
            // A fiscal year id, or "all" for every approved submission.
            'fiscal_year_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'all' && ! (ctype_digit((string) $value) && FiscalYear::whereKey($value)->exists())) {
                    $fail('Choose a fiscal year, or all time.');
                }
            }],
            'years' => 'nullable|in:1,3,5,all',
            'demographic_id' => 'nullable|integer',
            'metric' => 'nullable|string|max:40',
            'month' => 'nullable|integer|between:1,12',
            // Attendance reports: a range of months instead of a year.
            'from' => ['nullable', 'date_format:Y-m', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m', 'required_with:from', 'after_or_equal:from'],
            'gathering_type_id' => 'nullable|integer',
            // Budget statement: the one budget it's about.
            'budget_id' => 'nullable|integer',
            // Budget line report: the line of that budget.
            'line_id' => 'nullable|integer',
            // Event summary: the event it's about.
            'activity_id' => 'nullable|integer',
            // Monthly report: the report; reports sent: the year (with month).
            'report_id' => 'nullable|integer',
            'year' => 'nullable|integer|between:2000,2100',
            // Accounting: the money account, the one record (voucher, receipt...), a date range.
            'account_id' => 'nullable|integer',
            'record_id' => 'nullable|integer',
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from'],
        ]);
        if ($validator->fails()) {
            return $this->fail(422, $validator->errors()->first(), $validator->errors()->toArray());
        }

        $report = ReportRegistry::find($request->input('report_key'));
        if (! $report) {
            return $this->fail(422, 'Unknown report.');
        }

        $territory = Territory::find((int) $request->input('territory_id'));
        if (! $territory || ! $request->user()->canAccessTerritory($territory)) {
            return $this->fail(403, 'You do not have access to reports for this territory.');
        }
        if (! $report->supports($territory->territory_type)) {
            return $this->fail(422, 'This report is not available at this level yet.');
        }
        if ($reason = $report->authorize($request->user(), $territory)) {
            return $this->fail(403, $reason);
        }
        if (in_array('budget', $report->inputs(), true)) {
            $budget = Budget::find($request->integer('budget_id'));
            if (! $budget || (int) $budget->territory_id !== (int) $territory->id) {
                return $this->fail(422, 'Choose the budget to report on.', ['budget_id' => ['Unknown budget for this place.']]);
            }
            if (! BudgetAccess::canSee($request->user(), $budget)) {
                return $this->fail(403, 'You do not have access to this budget.');
            }
            if (in_array('line', $report->inputs(), true) && ! $budget->budgetLineItems()->where('budget_line_id', $request->integer('line_id'))->exists()) {
                return $this->fail(422, 'Choose a line of this budget.', ['line_id' => ['That line is not in this budget.']]);
            }
        }
        if (in_array('submission', $report->inputs(), true) && ! $request->filled('demographic_id')) {
            return $this->fail(422, 'Choose the submission to report on.', ['demographic_id' => ['Required for this report.']]);
        }

        if (in_array('metric', $report->inputs(), true) && ! MetricReport::has($request->input('metric'))) {
            return $this->fail(422, 'Choose a metric to report on.', ['metric' => ['Unknown metric.']]);
        }

        if (in_array('activity', $report->inputs(), true)) {
            $activity = \App\Models\Activity::find($request->integer('activity_id'));
            // The organiser's own event, or (from above) one of a place below.
            if (! $activity || ! in_array(app(\App\Services\Activities\Activities::class)->relation($activity, $territory), ['own', 'below'], true)) {
                return $this->fail(422, 'Choose the event to report on.', ['activity_id' => ['Unknown event for this place.']]);
            }
        }

        if (in_array('monthly_report', $report->inputs(), true)) {
            $monthly = \App\Models\MonthlyReport::find($request->integer('report_id'));
            $relation = $monthly ? \App\Support\ReportsAccess::relation($territory, $monthly) : null;
            // Your own report, or a sent one of a place below.
            if (! $monthly || ! $relation || ($relation === 'below' && $monthly->status === 'draft')) {
                return $this->fail(422, 'Choose the report to export.', ['report_id' => ['Unknown report for this place.']]);
            }
        }

        $params = array_filter($request->only(['fiscal_year_id', 'years', 'demographic_id', 'metric', 'month', 'gathering_type_id', 'from', 'to', 'budget_id', 'line_id', 'activity_id', 'report_id', 'year', 'account_id', 'record_id', 'date_from', 'date_to']), fn ($v) => $v !== null && $v !== '');
        foreach (['account' => ['account_id'], 'record' => ['record_id'], 'dates' => ['date_from', 'date_to']] as $input => $keys) {
            if (! in_array($input, $report->inputs(), true)) {
                foreach ($keys as $k) {
                    unset($params[$k]);
                }
            }
        }
        if (! in_array('monthly_report', $report->inputs(), true)) {
            unset($params['report_id']);
        }
        if (! in_array('report_month', $report->inputs(), true)) {
            unset($params['year']);
        }
        if (! in_array('activity', $report->inputs(), true)) {
            unset($params['activity_id']);
        }
        if (! in_array('budget', $report->inputs(), true)) {
            unset($params['budget_id']);
        }
        if (! in_array('line', $report->inputs(), true)) {
            unset($params['line_id']);
        }
        // A range only means something to reports that take a month.
        if (! in_array('fiscal_month', $report->inputs(), true)) {
            unset($params['from'], $params['to']);
        }
        $context = new ReportContext($territory, $request->user(), $params);

        // A ministry/event report can be narrowed to one gathering type - one of this territory's own.
        if (isset($params['gathering_type_id'])) {
            if (! in_array('gathering_type', $report->inputs(), true)) {
                unset($params['gathering_type_id']);
                $context = new ReportContext($territory, $request->user(), $params);
            } elseif (! GatheringType::whereKey($params['gathering_type_id'])->whereIn('territory_id', $context->churchIds())->exists()) {
                return $this->fail(422, 'That gathering type does not belong to this church.', ['gathering_type_id' => ['Invalid gathering type for this church.']]);
            }
        }

        // The report's own checks on what it was given (an accounting record of this place...).
        if ($reason = $report->checkParams($context)) {
            return $this->fail(422, $reason);
        }

        return [$report, $context];
    }

    private function ownRun(Request $request, string $uuid): ?ReportRun
    {
        return ReportRun::where('uuid', $uuid)->where('user_id', $request->user()->id)->first();
    }

    private function fail(int $status, string $message, ?array $errors = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
        ], fn ($v) => $v !== null), $status);
    }
}
