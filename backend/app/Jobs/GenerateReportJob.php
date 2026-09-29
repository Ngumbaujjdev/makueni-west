<?php

namespace App\Jobs;

use App\Models\ReportRun;
use App\Reports\ReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Builds one report in the background; the monitor polls the run for progress. */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public ReportRun $run)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportGenerator $generator): void
    {
        try {
            $generator->generate($this->run);
        } catch (Throwable $e) {
            Log::error('Report generation failed', ['run' => $this->run->uuid, 'error' => $e->getMessage()]);
            $this->run->forceFill([
                'status' => ReportRun::STATUS_FAILED,
                'stage' => 'Failed',
                'error' => $e instanceof \Illuminate\Validation\ValidationException
                    ? collect($e->errors())->flatten()->first()
                    : 'Something went wrong while building this report. Please try again.',
                'finished_at' => now(),
            ])->save();
        }
    }
}
