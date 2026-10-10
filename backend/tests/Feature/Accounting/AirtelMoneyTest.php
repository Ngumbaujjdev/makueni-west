<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\Chart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Airtel Money and money in by channel (docs/specs/accounting-spec.md,
 * Redesign R2b): a place adds its Airtel Money number as an account, receipts
 * can say they came by Airtel, and the Overview splits money in by channel.
 */
class AirtelMoneyTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_an_airtel_money_account_takes_receipts_and_shows_as_a_channel(): void
    {
        Sanctum::actingAs($this->treasurer);
        $acc = $this->postJson('/api/accounting/accounts', ['cash_kind' => 'airtel', 'name' => 'Airtel Money 0733 000 111', 'mpesa_number' => '0733000111'])->assertCreated()->json('data');
        $this->assertSame('1160-01', $acc['code']);
        $this->assertSame('airtel', $acc['cash_kind']);

        $this->postJson('/api/accounting/receipts', [
            'date' => now()->toDateString(), 'account_id' => $acc['id'], 'party_name' => 'Members', 'method' => 'airtel',
            'lines' => [['account_id' => $this->acc('4010')->id, 'amount' => 1500]],
        ])->assertCreated()->assertJsonPath('data.method', 'airtel');
        $this->receive(2000, null, now()->toDateString());

        $overview = $this->getJson('/api/accounting/overview')->assertOk()->json('data.channels.items');
        $by = collect($overview)->keyBy('key');
        $this->assertEquals(1500, $by['airtel']['this_month']);
        $this->assertEquals(2000, $by['cash']['this_month']);
        $this->assertSame('Airtel Money', $by['airtel']['label']);
        $this->assertEquals(1500, $this->getJson("/api/accounting/accounts/{$acc['id']}")->json('data.balance'));
    }

    public function test_the_standard_chart_has_the_airtel_header_once(): void
    {
        app(Chart::class)->ensureStandard();
        $this->assertSame(1, \App\Models\AccountingAccount::whereNull('territory_id')->where('code', '1160')->where('cash_kind', 'airtel')->where('is_header', true)->count());
    }
}
