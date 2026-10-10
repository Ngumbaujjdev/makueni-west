<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingFund;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Journal;
use App\Reports\ReportContext;
use App\Reports\ReportRegistry;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Statements;
use App\Services\Pdf\DioceseReportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The financial statements and the year-end close (docs/specs/accounting-spec.md,
 * A9): each statement ties to the trial balance and to the others; with the
 * places below added in, money between them is counted once and money on its
 * way still balances; a year closes into its funds once its months are
 * closed, and the level above reopens it.
 */
class StatementsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private int $ly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->ly = now()->year - 1;
    }

    private function book($place, string $date, array $lines, string $type = 'journal'): Journal
    {
        return app(Ledger::class)->post($place, ['doc_type' => $type, 'date' => $date, 'narration' => 'Test'], $lines, $this->treasurer);
    }

    private function fund(string $code): int
    {
        return AccountingFund::where('code', $code)->value('id');
    }

    /** Last year: opening balance 10,000; offerings 5,000; a building gift 3,000; electricity 2,000. */
    private function lastYearBooks(): void
    {
        $cash = $this->cash()->id;
        $this->book($this->myChurch, "{$this->ly}-01-05", [['account_id' => $cash, 'debit' => 10000], ['account_id' => $this->acc('3000')->id, 'credit' => 10000]]);
        $this->book($this->myChurch, "{$this->ly}-03-10", [['account_id' => $cash, 'debit' => 5000], ['account_id' => $this->acc('4010')->id, 'credit' => 5000]], 'receipt');
        $this->book($this->myChurch, "{$this->ly}-04-10", [['account_id' => $cash, 'debit' => 3000, 'fund_id' => $this->fund('BLD')], ['account_id' => $this->acc('4030')->id, 'credit' => 3000, 'fund_id' => $this->fund('BLD')]], 'receipt');
        $this->book($this->myChurch, "{$this->ly}-05-10", [['account_id' => $this->acc('5400')->id, 'debit' => 2000], ['account_id' => $cash, 'credit' => 2000]], 'payment');
    }

    public function test_the_statements_tie_to_each_other_and_the_trial_balance(): void
    {
        $this->lastYearBooks();
        $this->book($this->myChurch, $this->day(1, 12), [['account_id' => $this->cash()->id, 'debit' => 4000], ['account_id' => $this->acc('4010')->id, 'credit' => 4000]], 'receipt');
        // Money moved between our own accounts is neither received nor paid.
        $this->book($this->myChurch, $this->day(1, 13), [['account_id' => $this->acc('1020')->id, 'debit' => 500], ['account_id' => $this->cash()->id, 'credit' => 500]], 'transfer');
        $s = app(Statements::class);
        $from = "{$this->ly}-01-01";
        $to = "{$this->ly}-12-31";

        $ie = $s->incomeExpenditure($this->myChurch, $from, $to);
        $this->assertSame([8000.0, 2000.0, 6000.0], [$ie['totals']['income'], $ie['totals']['expense'], $ie['totals']['surplus']]);
        $this->assertSame(3000.0, $ie['totals']['by_fund'][$this->fund('BLD')]['income']);
        $this->assertSame(['GEN', 'BLD'], array_column($ie['funds'], 'code'));
        $this->assertSame(['4010', '4030'], array_column($ie['income'], 'code'));
        // This year beside last year.
        $now = $s->incomeExpenditure($this->myChurch, now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertSame(4000.0, $now['totals']['income']);

        $pos = $s->position($this->myChurch, $to);
        $this->assertTrue($pos['totals']['balanced']);
        $this->assertSame([16000.0, 16000.0], [$pos['totals']['net_assets'], $pos['totals']['funds']]);
        $this->assertSame([13000.0, 3000.0], array_column($pos['funds'], 'amount'), 'General keeps the opening balance and its surplus; Building its gift');

        $rp = $s->receiptsPayments($this->myChurch, $from, $to);
        $this->assertTrue($rp['totals']['balanced']);
        $this->assertSame([0.0, 18000.0, 2000.0, 16000.0], [$rp['totals']['opening'], $rp['totals']['receipts'], $rp['totals']['payments'], $rp['totals']['closing']]);
        $thisYear = $s->receiptsPayments($this->myChurch, now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertSame([16000.0, 4000.0, 0.0, 20000.0], [$thisYear['totals']['opening'], $thisYear['totals']['receipts'], $thisYear['totals']['payments'], $thisYear['totals']['closing']]);

        $funds = $s->changesInFunds($this->myChurch, $from, $to);
        $this->assertTrue($funds['totals']['balanced']);
        $gen = collect($funds['funds'])->firstWhere('code', 'GEN');
        $this->assertSame([0.0, 5000.0, 2000.0, 10000.0, 13000.0], [$gen['opening'], $gen['income'], $gen['expense'], $gen['transfers'], $gen['closing']]);

        $tb = $s->trialBalance($this->myChurch, now()->toDateString());
        $ledgerTb = app(Ledger::class)->trialBalance($this->myChurch, now()->toDateString());
        $this->assertSame([$ledgerTb['debit'], $ledgerTb['credit'], true], [$tb['debit'], $tb['credit'], $tb['balanced']]);

        // Through the API, for whoever reads the books; a church has nothing below to add in.
        Sanctum::actingAs($this->treasurer);
        $this->getJson("/api/accounting/statements/ie?from={$from}&to={$to}")->assertOk()->assertJsonPath('data.totals.surplus', 6000);
        $this->getJson("/api/accounting/statements/position?at={$to}")->assertOk()->assertJsonPath('data.totals.balanced', true);
        $this->getJson('/api/accounting/statements/ie?consolidated=1')->assertForbidden();
        $this->getJson('/api/accounting/statements/nope')->assertNotFound();
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/statements/ie?territory_id={$this->myChurch->id}")->assertForbidden();
    }

    public function test_added_together_money_between_places_is_counted_once_and_money_on_its_way_still_balances(): void
    {
        $cash = $this->cash()->id;
        $this->book($this->myChurch, $this->day(1, 5), [['account_id' => $cash, 'debit' => 10000], ['account_id' => $this->acc('4000')->id, 'credit' => 10000]], 'receipt');
        // The share: paid by the church, received by the region.
        $this->book($this->myChurch, $this->day(1, 6), [['account_id' => $this->acc('5700')->id, 'debit' => 1000, 'for_territory_id' => $this->region->id], ['account_id' => $cash, 'credit' => 1000]], 'payment');
        $this->book($this->region, $this->day(1, 7), [['account_id' => $cash, 'debit' => 1000], ['account_id' => $this->acc('4100')->id, 'credit' => 1000, 'for_territory_id' => $this->myChurch->id]], 'receipt');
        // Another, paid but not yet received.
        $this->book($this->myChurch, $this->day(1, 8), [['account_id' => $this->acc('5700')->id, 'debit' => 600, 'for_territory_id' => $this->region->id], ['account_id' => $cash, 'credit' => 600]], 'payment');
        // A share to the diocese - outside the region, so it stays.
        $this->book($this->myChurch, $this->day(1, 9), [['account_id' => $this->acc('5700')->id, 'debit' => 400, 'for_territory_id' => $this->diocese->id], ['account_id' => $cash, 'credit' => 400]], 'payment');
        $s = app(Statements::class);
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();

        $ie = $s->incomeExpenditure($this->region, $from, $to, true);
        $this->assertTrue($ie['consolidated']);
        $this->assertSame([10000.0, 400.0], [$ie['totals']['income'], $ie['totals']['expense']], 'the share inside the region is taken out on both sides');
        $this->assertSame(['4100', '5700'], array_column($ie['eliminated'], 'code'));
        $alone = $s->incomeExpenditure($this->region, $from, $to);
        $this->assertSame(1000.0, $alone['totals']['income'], 'the region on its own still sees its income');

        $pos = $s->position($this->region, $to, true);
        $this->assertTrue($pos['totals']['balanced']);
        $this->assertSame(600.0, $pos['in_transit']);
        $this->assertSame(600.0, collect($pos['assets'])->firstWhere('name', 'Money on its way between places')['amount']);
        $this->assertTrue($s->trialBalance($this->region, $to, true)['balanced']);
        $this->assertTrue($s->changesInFunds($this->region, $from, $to, true)['totals']['balanced']);
        $rp = $s->receiptsPayments($this->region, $from, $to, true);
        $this->assertTrue($rp['totals']['balanced']);
        $this->assertSame(600.0, collect($rp['payments'])->firstWhere('name', 'Sent between places, not yet received')['amount']);

        // Whoever reads the books below may add them in; the diocese sees all of it.
        Sanctum::actingAs($this->regionReader);
        $this->getJson('/api/accounting/statements/ie?consolidated=1')->assertOk()->assertJsonPath('data.places', 3)->assertJsonPath('data.can.consolidate', true);
        $this->getJson("/api/accounting/statements/trial-balance?consolidated=1&at={$to}")->assertOk()->assertJsonPath('data.balanced', true);
    }

    public function test_a_year_closes_into_its_funds_and_the_level_above_reopens_it(): void
    {
        $this->lastYearBooks();
        Sanctum::actingAs($this->treasurer);
        $years = $this->getJson('/api/accounting/years')->assertOk()->json('data');
        $this->assertTrue($years['can']['close']);
        $this->assertFalse($years['can']['reopen']);
        $row = collect($years['years'])->firstWhere('year', $this->ly);
        $this->assertSame('Close 12 months first (January, February, March...).', $row['blockers'][0]);
        $this->assertSame(now()->year.' hasn\'t ended yet.', collect($years['years'])->firstWhere('year', now()->year)['blockers'][0]);
        $this->postJson("/api/accounting/years/{$this->ly}/close")->assertUnprocessable();

        foreach (range(1, 12) as $m) {
            AccountingPeriod::create(['territory_id' => $this->myChurch->id, 'year' => $this->ly, 'month' => $m, 'status' => 'closed', 'closed_by' => $this->treasurer->id, 'closed_at' => now()]);
        }
        $this->postJson("/api/accounting/years/{$this->ly}/close")->assertOk()->assertJsonPath('data.surplus', 6000);
        $this->postJson("/api/accounting/years/{$this->ly}/close")->assertUnprocessable();
        $year = AccountingYear::where('territory_id', $this->myChurch->id)->where('year', $this->ly)->firstOrFail();
        $closing = Journal::findOrFail($year->closing_journal_id);
        $this->assertSame(['closing', "{$this->ly}-12-31"], [$closing->doc_type, $closing->date->toDateString()]);
        $this->assertStringContainsString('/YEC/', $closing->number);

        $s = app(Statements::class);
        $end = "{$this->ly}-12-31";
        $after = collect($s->trialBalance($this->myChurch, $end)['lines'])->pluck('debit', 'code');
        $this->assertFalse($after->has('4010') || $after->has('5400'), 'income and spending are emptied into the funds');
        $credits = collect($s->trialBalance($this->myChurch, $end)['lines'])->pluck('credit', 'code');
        $this->assertSame([13000, 3000], [$credits['3000'], $credits['3100']]);
        $before = collect($s->trialBalance($this->myChurch, $end, false, true)['lines'])->pluck('credit', 'code');
        $this->assertSame(5000, $before['4010'], 'before the close they are still there');
        // The year's statements don't count the closing as income or spending.
        $this->assertSame(6000.0, $s->incomeExpenditure($this->myChurch, "{$this->ly}-01-01", $end)['totals']['surplus']);
        $this->assertTrue($s->changesInFunds($this->myChurch, "{$this->ly}-01-01", $end)['totals']['balanced']);
        $this->assertSame([13000.0, 3000.0], array_column($s->position($this->myChurch, $end)['funds'], 'amount'));
        $this->assertSame(13000.0, collect($s->changesInFunds($this->myChurch, now()->startOfYear()->toDateString(), now()->toDateString())['funds'])->firstWhere('code', 'GEN')['opening']);

        // Nobody reverses the closing journal by hand, or reopens one of its months alone.
        $this->postJson("/api/accounting/journals/{$closing->id}/reverse", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/accounting/years/{$this->ly}/reopen", ['reason' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->regionReader);
        $this->postJson('/api/accounting/periods/reopen', ['territory_id' => $this->myChurch->id, 'year' => $this->ly, 'month' => 12, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('errors.month.0', "{$this->ly} is closed - reopen the year first.");
        $this->postJson("/api/accounting/years/{$this->ly}/reopen", ['territory_id' => $this->myChurch->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/accounting/years/{$this->ly}/reopen", ['territory_id' => $this->myChurch->id, 'reason' => 'The auditor moved a bill'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertSame('reversed', $closing->fresh()->status);
        $this->assertSame(5000, collect($s->trialBalance($this->myChurch, $end)['lines'])->pluck('credit', 'code')['4010']);
        $this->assertSame(6000.0, $s->incomeExpenditure($this->myChurch, "{$this->ly}-01-01", $end)['totals']['surplus'], 'the reversal isn\'t income or spending either');

        // And it closes again.
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/years/{$this->ly}/close")->assertOk();
    }

    public function test_the_statements_and_the_audit_pack_render(): void
    {
        $this->lastYearBooks();
        $range = ['date_from' => "{$this->ly}-01-01", 'date_to' => "{$this->ly}-12-31"];
        foreach (['accounting.statement.ie', 'accounting.statement.position', 'accounting.statement.receipts-payments', 'accounting.statement.funds', 'accounting.statement.audit-pack', 'accounting.trial_balance'] as $key) {
            $report = ReportRegistry::find($key);
            $this->assertNotNull($report, $key);
            $context = new ReportContext($this->myChurch, $this->treasurer, $range);
            $this->assertNull($report->checkParams($context), $key);
            $data = $report->build($context);
            $pdf = (new DioceseReportPdf($data, 'MWD-ACC-TEST', 'https://example.test/v', 'Tester'))->build()->toPdfString();
            $this->assertStringStartsWith('%PDF', $pdf, $key);
        }
        $ie = ReportRegistry::find('accounting.statement.ie')->build(new ReportContext($this->myChurch, $this->treasurer, $range));
        $this->assertSame('KES 6,000.00', $ie->tiles[2]['value']);

        // Added together needs the books below.
        $consolidated = new ReportContext($this->region, $this->treasurer, $range + ['consolidated' => '1']);
        $this->assertNotNull(ReportRegistry::find('accounting.statement.ie')->checkParams($consolidated));
        $this->assertNull(ReportRegistry::find('accounting.statement.ie')->checkParams(new ReportContext($this->region, $this->regionReader, $range + ['consolidated' => '1'])));
    }
}
