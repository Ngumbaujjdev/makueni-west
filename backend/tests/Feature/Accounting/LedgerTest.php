<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\AccountingPeriod;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The books (docs/specs/accounting-spec.md, A1): the standard chart,
 * receipts, transfers, journal vouchers, numbering, the checks every journal
 * passes, reversals, the cashbook and the trial balance.
 */
class LedgerTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    private function receipt(array $over = [], $as = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as ?? $this->treasurer);

        return $this->postJson('/api/accounting/receipts', array_replace([
            'date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'Sunday service',
            'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 1500]],
        ], $over));
    }

    public function test_the_standard_chart_is_written_once_and_budget_lines_are_mapped(): void
    {
        $accounts = AccountingAccount::count();
        $funds = AccountingFund::count();
        $fresh = new \App\Services\Accounting\Chart;
        $fresh->ensureStandard();
        $this->assertSame($accounts, AccountingAccount::count());
        $this->assertSame($funds, AccountingFund::count());
        $this->assertSame($this->acc('4000')->id, $this->incomeLine->fresh()->account_id, 'Tithes posts to 4000');
        $this->assertSame('5990', $this->chart->forBudgetLine($this->churchLine->fresh())->code, 'an unmapped expense line falls back to Other expenses');
    }

    public function test_a_receipt_posts_cash_against_income_and_is_numbered_per_place_and_year(): void
    {
        $res = $this->receipt()->assertCreated();
        $year = now()->year;
        $res->assertJsonPath('data.number', "MY-CH/RCT/{$year}/000001");
        $lines = JournalLine::where('journal_id', $res->json('data.id'))->get();
        $this->assertEquals(1500, $lines->firstWhere('account_id', $this->cash()->id)->debit);
        $this->assertEquals(1500, $lines->firstWhere('account_id', $this->acc('4000')->id)->credit);
        $this->assertSame($this->incomeLine->id, $lines->firstWhere('account_id', $this->acc('4000')->id)->budget_line_id, 'found the place\'s Tithes line');
        $this->assertSame(AccountingFund::where('code', 'GEN')->value('id'), $lines->first()->fund_id, 'General fund by default');
        $this->assertEquals(1500, app(Ledger::class)->balance($this->myChurch, $this->cash()));

        $this->receipt()->assertJsonPath('data.number', "MY-CH/RCT/{$year}/000002");
        $other = $this->userWithRole('othert2', 'Church Treasurer', 'church', $this->otherChurch->id, $this->perms('church', ['read', 'receipt']));
        $this->receipt([], $other)->assertCreated()->assertJsonPath('data.number', "OTHER-CH/RCT/{$year}/000001");
        $this->assertEquals(3000, app(Ledger::class)->balance($this->myChurch, $this->cash()), 'the other church\'s cash is in its own books');
    }

    public function test_one_receipt_can_hold_several_things_and_funds(): void
    {
        $bld = AccountingFund::where('code', 'BLD')->value('id');
        $res = $this->receipt(['lines' => [
            ['account_id' => $this->acc('4000')->id, 'amount' => 1000],
            ['account_id' => $this->acc('4010')->id, 'amount' => 500, 'fund_id' => $bld, 'memo' => 'Building'],
        ]])->assertCreated();
        $res->assertJsonPath('data.amount', 1500);
        $this->assertSame($bld, JournalLine::where('journal_id', $res->json('data.id'))->where('account_id', $this->acc('4010')->id)->value('fund_id'));
    }

    public function test_receipts_are_checked(): void
    {
        $this->receipt(['date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->receipt(['account_id' => $this->acc('4000')->id])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->receipt(['lines' => [['account_id' => $this->acc('1100')->id, 'amount' => 5]]])->assertStatus(422);
        $this->receipt(['lines' => [['account_id' => $this->cash()->id, 'amount' => 5]]])->assertStatus(422);
        $this->receipt(['lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 0]]])->assertStatus(422);
        $this->receipt(['party_name' => ''])->assertStatus(422)->assertJsonValidationErrors('party_name');

        $theirBank = $this->chart->addPlaceAccount($this->otherChurch, 'bank', ['name' => 'Their bank']);
        $this->receipt(['account_id' => $theirBank->id])->assertStatus(422);

        $this->acc('4010')->update(['is_active' => false]);
        $this->receipt(['lines' => [['account_id' => $this->acc('4010')->id, 'amount' => 5]]])->assertStatus(422);
    }

    public function test_the_ledger_refuses_what_does_not_balance_and_closed_months(): void
    {
        $ledger = app(Ledger::class);
        $post = fn (array $lines, ?string $date = null) => $ledger->post($this->myChurch, ['doc_type' => 'journal', 'date' => $date ?? $this->day()], $lines, $this->treasurer);
        foreach ([
            [['account_id' => $this->cash()->id, 'debit' => 100], ['account_id' => $this->acc('4000')->id, 'credit' => 99.99]],
            [['account_id' => $this->cash()->id, 'debit' => 100]],
            [['account_id' => $this->cash()->id, 'debit' => 100, 'credit' => 100], ['account_id' => $this->acc('4000')->id, 'credit' => 0.01]],
            [['account_id' => $this->acc('1000')->id, 'debit' => 100], ['account_id' => $this->acc('4000')->id, 'credit' => 100]],
        ] as $lines) {
            try {
                $post($lines);
                $this->fail('should have been refused: '.json_encode($lines));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        AccountingPeriod::create(['territory_id' => $this->myChurch->id, 'year' => now()->year, 'month' => 1, 'status' => 'closed']);
        $this->expectException(ValidationException::class);
        $post([['account_id' => $this->cash()->id, 'debit' => 100], ['account_id' => $this->acc('4000')->id, 'credit' => 100]], now()->setDate(now()->year, 1, 5)->toDateString());
    }

    public function test_a_transfer_moves_money_without_income_or_spending(): void
    {
        $this->receipt(['lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 10000]]])->assertCreated();
        Sanctum::actingAs($this->treasurer);
        $bank = $this->postJson('/api/accounting/accounts', ['cash_kind' => 'bank', 'name' => 'Equity Main', 'bank_name' => 'Equity', 'account_number' => '0123456789'])
            ->assertCreated()->assertJsonPath('data.code', '1100-01')->assertJsonPath('data.number_masked', '••••••6789')->json('data');
        $this->postJson('/api/accounting/transfers', ['date' => $this->day(1, 11), 'from_account_id' => $this->cash()->id, 'to_account_id' => $bank['id'], 'amount' => 8000])->assertCreated();
        $this->postJson('/api/accounting/transfers', ['date' => $this->day(1, 11), 'from_account_id' => $bank['id'], 'to_account_id' => $bank['id'], 'amount' => 1])->assertStatus(422);

        $ledger = app(Ledger::class);
        $this->assertEquals(2000, $ledger->balance($this->myChurch, $this->cash()));
        $this->assertEquals(8000, $ledger->balance($this->myChurch, AccountingAccount::find($bank['id'])));
        $over = $this->getJson('/api/accounting/overview')->assertOk()->json('data');
        $this->assertEquals(10000, collect($over['cash'])->sum('balance'), 'all the money is still there');
        $this->assertEquals(10000, $over['year']['in']);
        $this->assertEquals(0, $over['year']['out'], 'a transfer is not spending');
    }

    public function test_a_reversal_is_the_mirror_image_and_only_once(): void
    {
        $id = $this->receipt()->json('data.id');
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/journals/{$id}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $rev = $this->postJson("/api/accounting/journals/{$id}/reverse", ['reason' => 'Counted twice'])->assertOk()->json('data');
        $this->assertSame('reversal', $rev['doc_type']);
        $this->assertSame($id, $rev['reverses_id']);
        $this->assertSame('reversed', Journal::find($id)->status);
        $this->assertEquals(0, app(Ledger::class)->balance($this->myChurch, $this->cash()));
        $this->postJson("/api/accounting/journals/{$id}/reverse", ['reason' => 'Again'])->assertForbidden();
        $this->postJson("/api/accounting/journals/{$rev['id']}/reverse", ['reason' => 'Undo'])->assertForbidden();
        $this->getJson("/api/accounting/journals/{$id}")->assertOk()->assertJsonPath('data.reversed_by.id', $rev['id']);
    }

    public function test_the_cashbook_runs_its_balance_and_the_trial_balance_balances(): void
    {
        $this->receipt(['date' => $this->day(1, 5), 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 1000]]]);
        $this->receipt(['date' => $this->day(2, 5), 'lines' => [['account_id' => $this->acc('4010')->id, 'amount' => 600]]]);
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/journal-vouchers', ['date' => $this->day(2, 6), 'narration' => 'Opening float', 'lines' => [
            ['account_id' => $this->cash()->id, 'debit' => 250],
            ['account_id' => $this->acc('3000')->id, 'credit' => 250],
        ]])->assertCreated();
        $this->postJson('/api/accounting/journal-vouchers', ['date' => $this->day(2, 6), 'narration' => 'Wrong', 'lines' => [
            ['account_id' => $this->cash()->id, 'debit' => 250],
            ['account_id' => $this->acc('3000')->id, 'credit' => 200],
        ]])->assertStatus(422);

        $book = $this->getJson('/api/accounting/cashbook?'.http_build_query(['account_id' => $this->cash()->id, 'from' => $this->day(2, 1), 'to' => $this->day(12, 31)]))->assertOk()->json('data');
        $this->assertEquals(1000, $book['opening'], 'January brought forward');
        $this->assertEquals(850, $book['in']);
        $this->assertEquals(0, $book['out']);
        $this->assertEquals(1850, $book['closing']);
        $this->assertEquals([1600, 1850], array_column($book['rows'], 'balance'));
        $this->assertSame(['Offerings'], $book['rows'][0]['against']);

        $tb = $this->getJson('/api/accounting/trial-balance')->assertOk()->json('data');
        $this->assertTrue($tb['balanced']);
        $this->assertEquals(1850, $tb['debit']);
    }
}
