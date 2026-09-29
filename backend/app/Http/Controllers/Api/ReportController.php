<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\ReportRun;
use App\Models\Territory;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Reports\ReportRegistry;
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
    /** GET /reports/catalogue?territory_id= */
    public function catalogue(Request $request): JsonResponse
    {
        $territory = Territory::find((int) $request->query('territory_id'));
        if (! $territory || ! $request->user()->canAccessTerritory($territory)) {
            return $this->fail(403, 'You do not have access to reports for this territory.');
        }

        return response()->json([
            'success' => true,
            'data' => array_map(fn (Report $r) => $r->toCatalogue(), ReportRegistry::forScope($territory->territory_type)),
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
            'title' => $report->title(),
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

        return Storage::disk('local')->download($run->file_path, $run->file_name, ['Content-Type' => $mime]);
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
            'fiscal_year_id' => 'nullable|integer|exists:fiscal_years,id',
            'years' => 'nullable|in:1,3,5,all',
            'demographic_id' => 'nullable|integer',
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
        if (in_array('submission', $report->inputs(), true) && ! $request->filled('demographic_id')) {
            return $this->fail(422, 'Choose the submission to report on.', ['demographic_id' => ['Required for this report.']]);
        }

        $params = array_filter($request->only(['fiscal_year_id', 'years', 'demographic_id']), fn ($v) => $v !== null && $v !== '');

        return [$report, new ReportContext($territory, $request->user(), $params)];
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
