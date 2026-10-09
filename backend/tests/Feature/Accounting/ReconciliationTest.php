<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingAccount;
use App\Models\JournalLine;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bank and M-Pesa reconciliation (docs/specs/accounting-spec.md, A2):
 * statement + in transit - unpresented = cashbook; ticking, importing and
 * auto-matching, adding what only the bank knew, submit at a zero
 * difference, signed off by someone else.
 */
class ReconciliationTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private AccountingAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->bank = $this->chart->addPlaceAccount($this->myChurch, 'bank', ['name' => 'Equity']);
        // Deposits of 50,000 and 7,000, a payment of 3,000 out of the bank.
        $this->receive(50000, $this->bank->id, $this->day(1, 3));
        $this->receive(7000, $this->bank->id, $this->day(1, 29));
        Sanctum::actingAs($this->treasurer);
        $pv = $this->postJson('/api/accounting/payment-vouchers', ['date' => $this->day(1, 10), 'payee_name' => 'KPLC', 'pay_from_account_id' => $this->bank->id, 'narration' => 'Power', 'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 3000]]])->json('data.id');
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/authorise")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/pay", ['paid_on' => $this->day(1, 10), 'method' => 'cheque', 'reference' => '000123'])->assertOk();
    }

    private function start(float $statementBalance): array
    {
        Sanctum::actingAs($this->treasurer);

        return $this->postJson('/api/accounting/reconciliations', ['account_id' => $this->bank->id, 'statement_date' => $this->day(1, 31), 'statement_balance' => $statementBalance])->assertCreated()->json('data');
    }

    public function test_the_statement_formula_and_ticking(): void
    {
        // The statement shows the first deposit and the cheque; the last deposit is in transit.
        $r = $this->start(47000);
        $this->assertEquals(54000, $r['book_balance']);
        $this->assertEquals(57000, $r['in_transit']);
        $this->assertEquals(3000, $r['unpresented']);
        $this->assertEquals(47000, $r['difference'], '47,000 + 57,000 - 3,000 - 54,000 before anything is ticked');
        $ids = collect($r['book'])->filter(fn ($l) => $l['in'] == 50000 || $l['out'] == 3000)->pluck('id')->all();
        $r = $this->postJson("/api/accounting/reconciliations/{$r['id']}/tick", ['line_ids' => $ids, 'cleared' => true])->assertOk()->json('data');
        $this->assertEquals(7000, $r['in_transit']);
        $this->assertEquals(0, $r['unpresented']);
        $this->assertEquals(0, $r['difference'], '47,000 + 7,000 in transit - 0 = 54,000');
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->postJson("/api/accounting/reconciliations/{$r['id']}/approve")->assertForbidden();
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(2, JournalLine::whereNotNull('cleared_on')->where('account_id', $this->bank->id)->count());
    }

    public function test_submit_is_refused_until_it_agrees_and_the_preparer_never_signs_off(): void
    {
        $r = $this->start(47000);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/submit")->assertStatus(422)->assertJsonValidationErrors('difference');

        $both = $this->userWithRole('assoc3', 'Associate Pastor', 'church', $this->myChurch->id, $this->perms('church', ['read', 'reconcile', 'authorise']));
        Sanctum::actingAs($both);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/tick", ['line_ids' => collect($r['book'])->pluck('id')->all(), 'cleared' => true])->assertOk();
        $this->putJson("/api/accounting/reconciliations/{$r['id']}", ['statement_balance' => 54000])->assertOk()->assertJsonPath('data.difference', 0);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/submit")->assertOk();
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/approve")->assertStatus(422)->assertJsonValidationErrors('reconciliation');
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/return", ['reason' => 'Attach the statement'])->assertOk()->assertJsonPath('data.status', 'returned');
    }

    public function test_an_imported_statement_matches_one_to_one_and_bank_charges_are_added_from_it(): void
    {
        $r = $this->start(46950);
        $r = $this->postJson("/api/accounting/reconciliations/{$r['id']}/statement", ['rows' => [
            ['date' => $this->day(1, 4), 'description' => 'Deposit', 'money_in' => 50000],
            ['date' => $this->day(1, 12), 'description' => 'Cheque 000123', 'reference' => '000123', 'money_out' => 3000],
            ['date' => $this->day(1, 31), 'description' => 'Ledger fee', 'money_out' => 50],
            ['date' => $this->day(1, 31), 'description' => 'A second deposit of 50,000 that is not in the books', 'money_in' => 50000],
        ], 'mapping' => ['date' => 0, 'in' => 2]])->assertOk()->json('data');
        $statuses = collect($r['statement'])->pluck('status', 'description');
        $this->assertSame('matched', $statuses['Deposit']);
        $this->assertSame('matched', $statuses['Cheque 000123']);
        $this->assertSame('unmatched', $statuses['A second deposit of 50,000 that is not in the books'], 'one book line matches one statement line');
        $fee = collect($r['statement'])->firstWhere('description', 'Ledger fee');
        $second = collect($r['statement'])->firstWhere('description', 'A second deposit of 50,000 that is not in the books');
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/statement/{$second['id']}/ignore", ['ignore' => true])->assertOk();

        $r = $this->postJson("/api/accounting/reconciliations/{$r['id']}/statement/{$fee['id']}/add", ['account_id' => $this->acc('5800')->id])->assertOk()->json('data');
        $this->assertEquals(0, $r['difference'], '46,950 + 7,000 in transit - 0 = 53,950 after the fee');
        $this->assertEquals(50, app(Ledger::class)->balance($this->myChurch, $this->acc('5800')));
        $this->assertSame('added', collect($r['statement'])->firstWhere('id', $fee['id'])['status']);
        $this->assertNotNull(\DB::table('accounting_place_accounts')->where('account_id', $this->bank->id)->value('statement_mapping'), 'the CSV columns are remembered');
    }

    public function test_cash_is_not_reconciled_and_a_reconciled_date_cannot_be_done_again(): void
    {
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/reconciliations', ['account_id' => $this->cash()->id, 'statement_date' => $this->day(1, 31), 'statement_balance' => 0])->assertStatus(422);
        $r = $this->start(54000);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/tick", ['line_ids' => collect($r['book'])->pluck('id')->all(), 'cleared' => true]);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/submit")->assertOk();
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/approve")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/reconciliations', ['account_id' => $this->bank->id, 'statement_date' => $this->day(1, 31), 'statement_balance' => 54000])->assertStatus(422)->assertJsonValidationErrors('statement_date');
        $this->postJson("/api/accounting/reconciliations/{$r['id']}/tick", ['line_ids' => [1], 'cleared' => false])->assertStatus(422);
    }
}
