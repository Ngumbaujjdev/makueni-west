<?php

namespace Tests\Feature\Accounting;

use App\Models\BankReconciliation;
use App\Models\Collection;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Reports\ReportContext;
use App\Reports\ReportRegistry;
use App\Services\Accounting\Books;
use App\Services\Pdf\DioceseReportPdf;
use Database\Seeders\AccountingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every Accounting document and report as a diocese PDF (docs/specs/
 * accounting-spec.md, Redesign R4): each builds and renders on real books,
 * the cashbook agrees with the cashbook page, the trial balance balances, and
 * a record of another place is refused.
 */
class AccountingReportsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        (new AccountingDemoSeeder)->build($this->myChurch, $this->diocese, $this->treasurer, $this->authoriser, $this->regionReader, $this->authoriser, '2026-06-01');
    }

    private function render(string $key, array $params): array
    {
        $report = ReportRegistry::find($key);
        $this->assertNotNull($report, $key);
        $context = new ReportContext($this->myChurch, $this->treasurer, $params);
        $this->assertNull($report->checkParams($context), $key);
        $data = $report->build($context);
        $pdf = (new DioceseReportPdf($data, 'MWD-ACC-TEST', 'https://example.test/v', 'Tester'))->build()->toPdfString();
        $this->assertStringStartsWith('%PDF', $pdf, $key);

        return [$data, $pdf];
    }

    public function test_every_accounting_report_builds_and_renders(): void
    {
        $place = $this->myChurch->id;
        $range = ['date_from' => '2026-06-01', 'date_to' => now()->toDateString()];
        $cash = $this->cash()->id;
        $records = [
            'accounting.receipt' => Journal::where('territory_id', $place)->where('doc_type', 'receipt')->value('id'),
            'accounting.voucher' => PaymentVoucher::where('territory_id', $place)->where('status', 'paid')->value('id'),
            'accounting.lpo' => PurchaseOrder::where('territory_id', $place)->value('id'),
            'accounting.payslips' => PayrollRun::where('territory_id', $place)->where('status', 'paid')->value('id'),
            'accounting.payroll' => PayrollRun::where('territory_id', $place)->where('status', 'paid')->value('id'),
            'accounting.collection' => Collection::where('territory_id', $place)->value('id'),
            'accounting.reconciliation' => BankReconciliation::where('territory_id', $place)->value('id'),
        ];
        foreach ($records as $key => $id) {
            $this->assertNotNull($id, "a record for {$key}");
            $this->render($key, ['record_id' => $id]);
        }
        foreach (['accounting.trial_balance', 'accounting.receipts', 'accounting.vouchers', 'accounting.collections', 'accounting.remittances'] as $key) {
            $this->render($key, $range);
        }

        // The cashbook PDF says what the cashbook page says, and has its cover.
        [$data] = $this->render('accounting.cashbook', ['account_id' => $cash] + $range);
        $book = app(Books::class)->cashbook($this->myChurch, $this->cash(), $range['date_from'], $range['date_to']);
        $this->assertSame('Cashbook', $data->cover['title']);
        $this->assertSame('KES '.number_format($book['closing'], 2), $data->tiles[3]['value']);
        $this->assertCount(count($book['rows']) + 2, $data->sections[0]->rows, 'every movement, plus brought and carried forward');

        // The trial balance balances.
        [$tb] = $this->render('accounting.trial_balance', ['date_to' => now()->toDateString(), 'date_from' => '2026-01-01']);
        $this->assertSame('KES 0.00', $tb->tiles[2]['value']);
    }

    public function test_a_record_or_account_from_elsewhere_is_refused(): void
    {
        Sanctum::actingAs($this->treasurer);
        $voucher = PaymentVoucher::where('territory_id', $this->myChurch->id)->value('id');
        $this->postJson('/api/reports/preview', ['report_key' => 'accounting.voucher', 'territory_id' => $this->myChurch->id, 'record_id' => $voucher])->assertOk();
        $this->postJson('/api/reports/preview', ['report_key' => 'accounting.voucher', 'territory_id' => $this->myChurch->id, 'record_id' => 999999])->assertStatus(422);
        $this->postJson('/api/reports/preview', ['report_key' => 'accounting.cashbook', 'territory_id' => $this->myChurch->id, 'account_id' => $this->acc('4000')->id])->assertStatus(422);

        // Another church's treasurer can't print our voucher, even naming our place.
        Sanctum::actingAs($this->userWithRole('othertr2', 'Church Treasurer', 'church', $this->otherChurch->id, $this->perms('church', ['read'])));
        $this->postJson('/api/reports/preview', ['report_key' => 'accounting.voucher', 'territory_id' => $this->otherChurch->id, 'record_id' => $voucher])->assertStatus(422);
        $this->assertContains($this->postJson('/api/reports/preview', ['report_key' => 'accounting.voucher', 'territory_id' => $this->myChurch->id, 'record_id' => $voucher])->status(), [403, 422]);
    }
}
