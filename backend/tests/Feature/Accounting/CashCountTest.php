<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalLine;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Counting the cash against the book (docs/specs/accounting-spec.md, A2):
 * a count that agrees is just recorded; a difference waits for someone else,
 * who approves it into 5950 Cash shortage / over or sends it back.
 */
class CashCountTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->receive(12400);
    }

    private function countCash(array $over = [])
    {
        Sanctum::actingAs($this->treasurer);

        return $this->postJson('/api/accounting/cash-counts', array_replace(['account_id' => $this->cash()->id, 'counted_on' => now()->toDateString()], $over));
    }

    public function test_a_count_that_agrees_posts_nothing(): void
    {
        $this->countCash(['denominations' => ['1000' => 12, '200' => 2]])->assertCreated()
            ->assertJsonPath('data.status', 'balanced')->assertJsonPath('data.counted', 12400)->assertJsonPath('data.book', 12400);
        $this->assertSame(2, JournalLine::count(), 'only the receipt');
    }

    public function test_a_shortage_needs_a_reason_and_someone_else_then_posts_to_cash_short(): void
    {
        $this->countCash(['counted_total' => 12000])->assertStatus(422)->assertJsonValidationErrors('reason');
        $id = $this->countCash(['counted_total' => 12000, 'reason' => 'Change given twice'])->assertCreated()
            ->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.difference', -400)->json('data.id');
        $this->countCash(['counted_total' => 12400])->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->postJson("/api/accounting/cash-counts/{$id}/approve")->assertForbidden();
        $both = $this->userWithRole('assoc2', 'Associate Pastor', 'church', $this->myChurch->id, $this->perms('church', ['read', 'reconcile', 'authorise']));
        Sanctum::actingAs($both);
        $own = $this->postJson('/api/accounting/cash-counts', ['account_id' => $this->chart->account('petty_cash')->id, 'counted_on' => now()->toDateString(), 'counted_total' => 5, 'reason' => 'Found'])->json('data.id');
        $this->postJson("/api/accounting/cash-counts/{$own}/approve")->assertStatus(422);

        Sanctum::actingAs($this->authoriser);
        $waiting = $this->getJson('/api/accounting/reconciliation')->assertOk()->json('data.waiting');
        $this->assertSame([$id], array_column(array_filter($waiting, fn ($w) => $w['type'] === 'count' && $w['account']['id'] === $this->cash()->id), 'id'), 'the approver sees it waiting');
        $this->postJson("/api/accounting/cash-counts/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/accounting/reconciliation')->assertOk();
        $ledger = app(Ledger::class);
        $this->assertEquals(12000, $ledger->balance($this->myChurch, $this->cash()), 'the book now matches the cash');
        $this->assertEquals(400, $ledger->balance($this->myChurch, $this->acc('5950')));
    }

    public function test_an_overage_is_posted_the_other_way_and_a_sent_back_count_is_done_again(): void
    {
        $id = $this->countCash(['counted_total' => 12500, 'reason' => 'Unrecorded offering'])->json('data.id');
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/cash-counts/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/accounting/cash-counts/{$id}/reject", ['reason' => 'Count again with a witness'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $id = $this->countCash(['counted_total' => 12500, 'reason' => 'Unrecorded offering'])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/cash-counts/{$id}/approve")->assertOk();
        $this->assertEquals(12500, app(Ledger::class)->balance($this->myChurch, $this->cash()));
        $this->assertEquals(-100, app(Ledger::class)->balance($this->myChurch, $this->acc('5950')), 'an overage reduces the expense');
    }

    public function test_only_cash_is_counted(): void
    {
        $bank = $this->chart->addPlaceAccount($this->myChurch, 'bank', ['name' => 'Bank']);
        $this->countCash(['account_id' => $bank->id, 'counted_total' => 0])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->countCash(['counted_on' => now()->addDay()->toDateString(), 'counted_total' => 0])->assertStatus(422);
        $this->countCash([])->assertStatus(422)->assertJsonValidationErrors('counted_total');
    }
}
