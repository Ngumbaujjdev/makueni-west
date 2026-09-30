<?php

namespace App\Reports;

use App\Exports\ReportWorkbook;
use App\Models\ReportRun;
use App\Models\Territory;
use App\Services\Pdf\DioceseReportPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Turns a ReportRun into a finished file: builds the ReportData, renders the
 * PDF or workbook, stores it and stamps the run with its size and SHA-256
 * fingerprint (what the Verify page compares a dropped file against).
 */
final class ReportGenerator
{
    public function contextFor(ReportRun $run): ReportContext
    {
        return new ReportContext(Territory::findOrFail($run->territory_id), $run->user, $run->params ?? []);
    }

    public static function verifyUrl(string $code): string
    {
        return config('app.frontend_url').'/verify-report?code='.urlencode($code);
    }

    public function generate(ReportRun $run): void
    {
        $report = ReportRegistry::find($run->report_key);
        if (! $report) {
            throw new \RuntimeException("Unknown report {$run->report_key}.");
        }

        $run->forceFill(['status' => ReportRun::STATUS_RUNNING, 'started_at' => now()])->save();
        $run->stage('Collecting the figures', 15);

        $context = $this->contextFor($run);
        $data = $report->build($context);
        $run->stage('Working out insights', 45);
        $run->forceFill(['title' => $data->title, 'period_label' => $data->periodLabel, 'scope_label' => $data->scopeLabel])->save();

        $extension = $run->format === 'xlsx' ? 'xlsx' : 'pdf';
        $path = "reports/{$run->uuid}.{$extension}";

        if ($extension === 'pdf') {
            $run->stage('Drawing the PDF', 70);
            $pdf = new DioceseReportPdf($data, $run->verification_code, self::verifyUrl($run->verification_code), $context->preparedBy());
            Storage::disk('local')->put($path, $pdf->build()->toPdfString());
        } else {
            $run->stage('Building the workbook', 70);
            Excel::store(new ReportWorkbook($data, $run->verification_code), $path, 'local', ExcelWriter::XLSX);
        }

        $run->stage('Finishing up', 92);
        $absolute = Storage::disk('local')->path($path);
        $run->forceFill([
            'status' => ReportRun::STATUS_READY,
            'progress' => 100,
            'stage' => 'Ready',
            'file_path' => $path,
            'file_name' => Str::slug("{$data->title} {$data->scopeLabel} {$data->periodLabel}").".{$extension}",
            'file_size' => filesize($absolute),
            'file_hash' => hash_file('sha256', $absolute),
            'finished_at' => now(),
            'expires_at' => now()->addDays(ReportRun::KEEP_DAYS),
        ])->save();
    }
}
