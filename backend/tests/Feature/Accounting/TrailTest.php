<?php

namespace Tests\Feature\Accounting;

use App\Models\Collection;
use App\Models\PaymentVoucher;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\Remittance;
use App\Models\Requisition;
use Database\Seeders\AccountingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A record's trail (docs/specs/accounting-spec.md, Redesign R1): what happened,
 * who did it, and the documents it is chained to - for whoever may read the
 * books where it sits, and nobody else.
 */
class TrailTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        (new AccountingDemoSeeder)->build($this->myChurch, $this->diocese, $this->treasurer, $this->authoriser, $this->regionReader, $this->authoriser, '2026-06-01');
    }

    private function trail(string $type, int $id): array
    {
        return $this->getJson("/api/accounting/trail/{$type}/{$id}")->assertOk()->json('data');
    }

    public function test_a_bought_item_is_chained_from_requisition_to_payment(): void
    {
        Sanctum::actingAs($this->treasurer);
        $order = PurchaseOrder::where('territory_id', $this->myChurch->id)->firstOrFail();

        $req = $this->trail('requisition', $order->requisition_id);
        $this->assertSame(['order', 'delivery', 'bill', 'voucher', 'journal'], array_column($req['links'], 'type'));
        $this->assertStringContainsString('Asked for KES 68,000.00', end($req['events'])['text'], 'the oldest event is the request');
        $this->assertNotEmpty(array_intersect(['Approved', 'Approved it'], array_column($req['events'], 'text')), 'the decision is in the trail');

        $ord = $this->trail('order', $order->id);
        $this->assertSame('requisition', $ord['links'][0]['type']);
        $this->assertTrue(collect($ord['events'])->contains(fn ($e) => str_starts_with($e['text'], 'Received the goods')));
    }

    public function test_every_kind_of_record_has_a_trail(): void
    {
        Sanctum::actingAs($this->treasurer);
        $place = $this->myChurch->id;

        $waiting = $this->trail('voucher', PaymentVoucher::where('territory_id', $place)->where('status', 'prepared')->value('id'));
        $this->assertStringStartsWith('Prepared the voucher for KES 7,500.00', end($waiting['events'])['text']);

        $payroll = $this->trail('payroll', PayrollRun::where('territory_id', $place)->where('status', 'paid')->value('id'));
        $this->assertTrue(collect($payroll['events'])->contains(fn ($e) => str_starts_with($e['text'], 'Paid net pay')));
        $this->assertContains('voucher', array_column($payroll['links'], 'type'));

        // The test world has no diocese share rule, so send one by hand.
        $rem = Remittance::create(['number' => 'T/REM/1', 'from_territory_id' => $place, 'to_territory_id' => $this->diocese->id, 'kind' => 'share', 'purpose' => 'Share for June', 'amount' => 1000, 'status' => 'sent', 'sent_on' => '2026-07-02', 'reference' => 'RTGS-1', 'created_by' => $this->treasurer->id]);
        $rem->forceFill(['created_at' => '2026-07-01 09:00:00'])->save();
        $trail = $this->trail('remittance', $rem->id);
        $this->assertSame(['Sent - reference RTGS-1', 'Raised it: share sent up - KES 1,000.00 to '.$this->diocese->name], array_column($trail['events'], 'text'));

        $col = $this->trail('collection', Collection::where('territory_id', $place)->where('status', 'posted')->value('id'));
        $this->assertSame(['journal', 'journal'], array_column($col['links'], 'type'));
    }

    public function test_only_people_who_may_read_those_books_see_it(): void
    {
        $id = Requisition::where('territory_id', $this->myChurch->id)->value('id');

        Sanctum::actingAs($this->regionReader);
        $this->getJson("/api/accounting/trail/requisition/{$id}")->assertOk();

        Sanctum::actingAs($this->userWithRole('othertr2', 'Church Treasurer', 'church', $this->otherChurch->id, $this->perms('church', ['read'])));
        $this->getJson("/api/accounting/trail/requisition/{$id}")->assertNotFound();
        $this->getJson('/api/accounting/trail/budget/1')->assertNotFound();
    }
}
