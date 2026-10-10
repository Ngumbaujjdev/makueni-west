<?php

namespace Tests\Feature\Accounting;

use App\Models\Journal;
use App\Models\ReportRun;
use Database\Seeders\AccountingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Build it now" (docs/specs/accounting-spec.md, Redesign R4b): a report that
 * has waited in the queue - no worker running - is built in the request, and
 * the queued copy then has nothing left to do.
 */
class ReportRunNowTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    public function test_a_waiting_report_is_built_now_and_only_by_its_owner(): void
    {
        $this->buildBooks();
        (new AccountingDemoSeeder)->build($this->myChurch, $this->diocese, $this->treasurer, $this->authoriser, $this->regionReader, $this->authoriser, '2026-09-01');
        Queue::fake();
        Sanctum::actingAs($this->treasurer);
        $receipt = Journal::where('territory_id', $this->myChurch->id)->where('doc_type', 'receipt')->value('id');
        $uuid = $this->postJson('/api/reports', ['report_key' => 'accounting.receipt', 'territory_id' => $this->myChurch->id, 'record_id' => $receipt, 'format' => 'pdf'])->assertSuccessful()->json('data.uuid');

        $this->postJson("/api/reports/runs/{$uuid}/now")->assertStatus(422); // too soon - the worker may still take it
        ReportRun::where('uuid', $uuid)->update(['created_at' => now()->subMinute()]);

        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/reports/runs/{$uuid}/now")->assertNotFound();

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/reports/runs/{$uuid}/now")->assertOk()->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/reports/runs/{$uuid}/now")->assertStatus(422);

        // The queued copy, when a worker reaches it, leaves the finished file alone.
        $run = ReportRun::where('uuid', $uuid)->first();
        $file = $run->file_path;
        (new \App\Jobs\GenerateReportJob($run))->handle(app(\App\Reports\ReportGenerator::class));
        $this->assertSame($file, $run->fresh()->file_path);
    }
}
