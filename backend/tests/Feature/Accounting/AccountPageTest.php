<?php

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An account's own page (docs/specs/accounting-spec.md, Redesign R2): its
 * balance, money in and out, the latest movements and the budget lines that
 * post to it - from the same books the cashbook reads.
 */
class AccountPageTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_cash_in_and_out_match_the_cashbook(): void
    {
        $this->receive(5000, null, now()->startOfMonth()->addDay()->toDateString());
        $this->receive(2500, null, now()->toDateString());
        Sanctum::actingAs($this->treasurer);
        $cash = $this->cash();

        $page = $this->getJson("/api/accounting/accounts/{$cash->id}")->assertOk()->json('data');
        $book = $this->getJson("/api/accounting/cashbook?account_id={$cash->id}&from=".now()->startOfMonth()->toDateString().'&to='.now()->toDateString())->json('data');

        $this->assertEquals($book['in'], $page['this_month']['in']);
        $this->assertEquals($book['out'], $page['this_month']['out']);
        $this->assertEquals($book['closing'], $page['balance']);
        $this->assertSame('Money in', $page['labels']['in']);
        $this->assertCount(2, $page['movements']);
        $this->assertEquals(2500, $page['movements'][0]['in'], 'newest first');
        $this->assertCount(12, $page['series']['in']);
    }

    public function test_an_income_account_reads_received_and_another_church_gets_nothing(): void
    {
        $this->receive(5000);
        Sanctum::actingAs($this->treasurer);
        $income = $this->getJson('/api/accounting/accounts')->json('data.chart');
        $tithes = collect($income)->firstWhere('code', '4000');
        $page = $this->getJson("/api/accounting/accounts/{$tithes['id']}")->assertOk()->json('data');
        $this->assertSame('Received', $page['labels']['in']);

        Sanctum::actingAs($this->userWithRole('othertr2', 'Church Treasurer', 'church', $this->otherChurch->id, $this->perms('church', ['read'])));
        $own = $this->getJson('/api/accounting/accounts')->json('data.cash');
        $this->getJson('/api/accounting/accounts/'.$this->cash()->id.'?territory_id='.$this->myChurch->id)->assertForbidden();
        $this->assertNotEmpty($own);
    }
}
