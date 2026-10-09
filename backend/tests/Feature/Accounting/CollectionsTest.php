<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingFund;
use App\Models\BudgetEntry;
use App\Models\Collection;
use App\Models\JournalLine;
use App\Services\Accounting\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sunday collections (docs/specs/accounting-spec.md, A3): counted by one,
 * confirmed by a second person - one receipt, a line per kind and fund -
 * sent back to fix, banked, and kept out of a month's close until confirmed.
 */
class CollectionsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private \App\Models\User $usher;

    private \App\Models\User $elder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->usher = $this->userWithRole('usher', 'Usher Coordinator', 'church', $this->myChurch->id, $this->perms('church', ['collect']));
        $this->elder = $this->userWithRole('elder', 'Elder', 'church', $this->myChurch->id, $this->perms('church', ['collect', 'confirm']));
    }

    private function body(array $over = []): array
    {
        $bld = AccountingFund::where('code', 'BLD')->value('id');

        return array_replace([
            'date' => $this->day(1, 4),
            'title' => 'Sunday main service',
            'witnesses' => ['Mary Mwende'],
            'denominations' => ['1000' => 9, '500' => 3, '100' => 5],
            'lines' => [
                ['label' => 'Offering', 'account_id' => $this->acc('4010')->id, 'cash_amount' => 6000, 'mpesa_amount' => 1200],
                ['label' => 'Tithe', 'account_id' => $this->acc('4000')->id, 'cash_amount' => 4000, 'mpesa_amount' => 3000],
                ['label' => 'Building', 'account_id' => $this->acc('4020')->id, 'fund_id' => $bld, 'cash_amount' => 1000],
            ],
        ], $over);
    }

    public function test_counted_by_one_confirmed_by_another_posts_one_receipt_per_kind_and_fund(): void
    {
        Sanctum::actingAs($this->usher);
        $c = $this->postJson('/api/accounting/collections', $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'counted')->assertJsonPath('data.cash_total', 11000)->assertJsonPath('data.mpesa_total', 4200)->json('data');
        $this->assertSame(0, JournalLine::count(), 'nothing in the books until it is confirmed');
        $this->getJson('/api/accounting/overview')->assertForbidden();
        $this->postJson("/api/accounting/collections/{$c['id']}/confirm")->assertForbidden();

        $both = $this->elder;
        Sanctum::actingAs($both);
        $own = $this->postJson('/api/accounting/collections', $this->body(['title' => 'Evening service', 'denominations' => null]))->assertCreated()->json('data.id');
        $this->postJson("/api/accounting/collections/{$own}/confirm")->assertStatus(422)->assertJsonValidationErrors('collection');
        $done = $this->postJson("/api/accounting/collections/{$c['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'posted')->json('data');

        $ledger = app(Ledger::class);
        $this->assertEquals(11000, $ledger->balance($this->myChurch, $this->cash()));
        $this->assertEquals(4200, $ledger->balance($this->myChurch, $this->chart->placeAccount($this->myChurch, 'mpesa')), 'an M-Pesa account is made on first use');
        $this->assertEquals(7200, $ledger->balance($this->myChurch, $this->acc('4010')));
        $this->assertEquals(7000, $ledger->balance($this->myChurch, $this->acc('4000')));
        $this->assertSame(AccountingFund::where('code', 'BLD')->value('id'), JournalLine::where('journal_id', $done['journal']['id'])->where('account_id', $this->acc('4020')->id)->value('fund_id'));
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/journals/{$done['journal']['id']}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_notes_and_coins_must_agree_and_only_income_is_collected(): void
    {
        Sanctum::actingAs($this->usher);
        $this->postJson('/api/accounting/collections', $this->body(['denominations' => ['1000' => 1]]))->assertStatus(422)->assertJsonValidationErrors('denominations');
        $this->postJson('/api/accounting/collections', $this->body(['lines' => [['account_id' => $this->acc('5400')->id, 'cash_amount' => 5]]]))->assertStatus(422);
        $this->postJson('/api/accounting/collections', $this->body(['lines' => [['account_id' => $this->acc('4010')->id, 'cash_amount' => 0]]]))->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->postJson('/api/accounting/collections', $this->body(['date' => now()->addDay()->toDateString()]))->assertStatus(422);
        Sanctum::actingAs($this->overseer);
        $this->getJson('/api/accounting/collections')->assertForbidden();
    }

    public function test_a_sent_back_count_is_fixed_and_confirmed_and_the_budget_follows(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine], now()->year, 1);
        Sanctum::actingAs($this->usher);
        $id = $this->postJson('/api/accounting/collections', $this->body())->json('data.id');
        Sanctum::actingAs($this->elder);
        $this->postJson("/api/accounting/collections/{$id}/return", ['reason' => 'Recount the tithe envelopes'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->postJson("/api/accounting/collections/{$id}/confirm")->assertStatus(422);
        Sanctum::actingAs($this->usher);
        $this->putJson("/api/accounting/collections/{$id}", $this->body(['denominations' => null, 'lines' => [['label' => 'Tithe', 'account_id' => $this->acc('4000')->id, 'cash_amount' => 4500]]]))->assertOk()->assertJsonPath('data.status', 'counted');
        Sanctum::actingAs($this->elder);
        $this->postJson("/api/accounting/collections/{$id}/confirm")->assertOk();
        $this->assertEquals(4500, BudgetEntry::where('budget_id', $budget->id)->sum('amount'), 'the Tithes line of the budget in use');
    }

    public function test_bank_it_moves_the_cash_and_a_month_waits_for_unconfirmed_collections(): void
    {
        $m = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $bank = $this->chart->addPlaceAccount($this->myChurch, 'bank', ['name' => 'Equity']);
        Sanctum::actingAs($this->usher);
        $id = $this->postJson('/api/accounting/collections', $this->body(['date' => $m->addDays(2)->toDateString(), 'denominations' => null]))->json('data.id');

        Sanctum::actingAs($this->elder);
        $this->postJson("/api/accounting/collections/{$id}/confirm")->assertOk();
        Sanctum::actingAs($this->usher);
        $this->postJson('/api/accounting/collections', $this->body(['date' => $m->addDays(9)->toDateString(), 'denominations' => null]))->assertCreated();
        Sanctum::actingAs($this->treasurer);
        $month = collect($this->getJson('/api/accounting/periods?year='.$m->year)->json('data.months'))->firstWhere('month', $m->month);
        $this->assertTrue(collect($month['blockers'])->contains(fn ($b) => str_contains($b, 'waiting to be confirmed')));
        $this->assertTrue(collect($month['warnings'])->contains(fn ($b) => str_contains($b, 'not banked')));

        $this->postJson("/api/accounting/collections/{$id}/bank", ['to_account_id' => $bank->id, 'date' => $m->addDays(3)->toDateString(), 'reference' => 'Slip 44'])->assertOk()->assertJsonPath('data.banked.number', fn ($n) => str_contains($n, '/TRF/'));
        $this->postJson("/api/accounting/collections/{$id}/bank", ['to_account_id' => $bank->id, 'date' => $m->addDays(3)->toDateString()])->assertStatus(422);
        $this->assertEquals(11000, app(Ledger::class)->balance($this->myChurch, $bank));
        $this->postJson("/api/accounting/collections/{$id}/reverse", ['reason' => 'x'])->assertStatus(422);
        $this->assertSame(1, Collection::where('status', 'posted')->count());
        $over = $this->getJson('/api/accounting/overview')->assertOk()->json('data.collections');
        $this->assertEquals(15200, $over['last']['total']);
    }
}
