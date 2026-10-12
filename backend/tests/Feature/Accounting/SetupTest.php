<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingAccount;
use App\Models\JournalLine;
use App\Services\Accounting\Statements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting up the books (docs/specs/accounting-spec.md, setup): a place's own
 * finer lines under a standard account, the diocese placing a new account
 * under a heading, and the checklist of what a place still has to do and who
 * holds each job.
 */
class SetupTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_a_place_adds_its_own_line_under_a_standard_account_and_it_counts_on_the_parent(): void
    {
        $this->incomeLine->update(['account_id' => $this->acc('4010')->id]);
        Sanctum::actingAs($this->treasurer);
        $sub = $this->postJson('/api/accounting/accounts', ['name' => 'Youth offering', 'parent_id' => $this->acc('4010')->id])
            ->assertCreated()->assertJsonPath('data.code', '4010-01')->json('data.id');
        $this->assertSame('income', AccountingAccount::find($sub)->type);
        $this->postJson('/api/accounting/accounts', ['name' => 'Youth offering', 'parent_id' => $this->acc('4010')->id])->assertUnprocessable();
        $this->postJson('/api/accounting/accounts', ['name' => 'Odd', 'parent_id' => $this->cash()->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $j = $this->postJson('/api/accounting/receipts', ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'Youth', 'lines' => [['account_id' => $sub, 'amount' => 1200]]])
            ->assertCreated()->json('data.id');
        $this->assertSame($this->incomeLine->id, JournalLine::where('journal_id', $j)->where('account_id', $sub)->value('budget_line_id'), 'the budget sees it on Offerings');

        // Added together for the region it shows as the standard account.
        $ie = app(Statements::class)->incomeExpenditure($this->region, now()->startOfYear()->toDateString(), now()->toDateString(), true);
        $this->assertSame(['4010'], array_column($ie['income'], 'code'));
        // Another church can't post to it.
        Sanctum::actingAs($this->otherTreasurer);
        $this->postJson('/api/accounting/receipts', ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'X', 'lines' => [['account_id' => $sub, 'amount' => 5]]])->assertUnprocessable();
    }

    public function test_a_church_adds_what_it_owns_owes_and_a_fund_of_its_own(): void
    {
        Sanctum::actingAs($this->treasurer);
        $chart = collect($this->getJson('/api/accounting/accounts')->assertOk()->json('data.chart'));
        $this->assertTrue($chart->firstWhere('code', '1500')['can_parent'], 'Fixed assets takes our own lines');
        $this->assertFalse($chart->firstWhere('code', '2200')['can_parent'], 'what we owe the diocese is the system\'s');

        $van = $this->postJson('/api/accounting/accounts', ['name' => 'Church van', 'parent_id' => $this->acc('1500')->id])->assertCreated()->assertJsonPath('data.code', '1500-01')->json('data.id');
        $this->assertSame(['asset', $this->myChurch->id], [AccountingAccount::find($van)->type, AccountingAccount::find($van)->territory_id]);
        $this->postJson('/api/accounting/accounts', ['name' => 'SACCO loan', 'parent_id' => $this->acc('2400')->id])->assertCreated()->assertJsonPath('data.code', '2400-01');
        foreach (['1300', '2200', '2300'] as $closed) {
            $this->postJson('/api/accounting/accounts', ['name' => "Odd {$closed}", 'parent_id' => $this->acc($closed)->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        $fund = $this->postJson('/api/accounting/accounts', ['kind' => 'fund', 'name' => 'Choir trip fund'])->assertCreated()->json('data.fund');
        $this->assertSame('CHOIRTRIPF', $fund['code']);
        $this->assertSame($this->myChurch->id, \App\Models\AccountingFund::find($fund['id'])->territory_id);
        $this->postJson('/api/accounting/accounts', ['kind' => 'fund', 'name' => 'Choir trip fund'])->assertCreated()->assertJsonPath('data.fund.code', 'CHOIRTRIP2');

        // Ours only: the church next door doesn't see them; nobody here changes the standard chart.
        Sanctum::actingAs($this->otherTreasurer);
        $this->assertNull(collect($this->getJson('/api/accounting/accounts')->json('data.chart'))->firstWhere('id', $van));
        $this->postJson('/api/accounting/chart', ['code' => '5250', 'name' => 'Odd', 'type' => 'expense'])->assertForbidden();
    }

    public function test_the_diocese_puts_a_new_account_under_a_heading_of_its_kind(): void
    {
        Sanctum::actingAs($this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'chart'])));
        $head = $this->postJson('/api/accounting/chart', ['code' => '5900', 'name' => 'Mission costs', 'type' => 'expense', 'is_header' => true])->assertCreated()->json('data.id');
        $this->postJson('/api/accounting/chart', ['code' => '5910', 'name' => 'Mission travel', 'type' => 'expense', 'parent_id' => $head])->assertCreated();
        $this->assertSame($head, AccountingAccount::whereNull('territory_id')->where('code', '5910')->value('parent_id'));
        $this->assertTrue(AccountingAccount::find($head)->is_header);
        $this->postJson('/api/accounting/chart', ['code' => '4950', 'name' => 'Odd income', 'type' => 'income', 'parent_id' => $head])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_the_checklist_says_what_is_left_and_who_holds_each_job(): void
    {
        Sanctum::actingAs($this->treasurer);
        $d = $this->getJson('/api/accounting/setup')->assertOk()->json('data');
        $steps = collect($d['steps'])->keyBy('key');
        $this->assertFalse($steps['accounts']['done']);
        $this->assertSame([0, 3], [$d['done'], $d['of']]);
        $jobs = collect($d['jobs'])->keyBy('ability');
        $this->assertContains('Test Treasurer', $jobs['receipt']['holders']);
        $this->assertContains('Test Senior', $jobs['authorise']['holders']);
        $this->assertArrayHasKey('collect', $jobs->all(), 'a church records collections');

        $this->postJson('/api/accounting/accounts', ['name' => 'KCB', 'cash_kind' => 'bank', 'bank_name' => 'KCB'])->assertCreated();
        $this->postJson('/api/accounting/journal-vouchers', ['date' => $this->day(1, 2), 'narration' => 'Opening balances', 'lines' => [
            ['account_id' => $this->cash()->id, 'debit' => 5000], ['account_id' => $this->acc('3000')->id, 'credit' => 5000],
        ]])->assertCreated();
        $d = $this->getJson('/api/accounting/setup')->json('data');
        $this->assertSame(2, $d['done']);
        Sanctum::actingAs($this->regionReader);
        $this->assertArrayNotHasKey('collect', collect($this->getJson('/api/accounting/setup')->assertOk()->json('data.jobs'))->keyBy('ability')->all(), 'a region has no Sunday collections');
    }
}
