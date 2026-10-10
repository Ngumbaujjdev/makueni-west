<?php

namespace Tests\Feature\Accounting;

use App\Models\BudgetEntry;
use App\Models\Equipment;
use App\Models\PaymentVoucher;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Procurement (docs/specs/accounting-spec.md, A5): above the limit a
 * purchase needs quotations and an order; goods are received against it, the
 * bill is matched three ways and posted to suppliers payable, and paying it
 * is a voucher already authorised.
 */
class ProcurementTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $youth;

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        Storage::fake('local');
        $this->youth = $this->userWithRole('youth', 'Youth Leader', 'church', $this->myChurch->id, $this->perms('church', ['request', 'approvals']));
        $this->receive(300000);
        Sanctum::actingAs($this->treasurer);
        foreach (['Furniture Palace' => '0711000001', 'Chairs R Us' => '0711000002', 'Kitui Traders' => null] as $name => $phone) {
            $id = $this->postJson('/api/accounting/procurement/suppliers', ['name' => $name, 'phone' => $phone])->assertCreated()->json('data.id');
            $this->suppliers[$name] = Supplier::find($id);
        }
    }

    /** A purchase requisition, approved (no rule: the pastor approves). */
    private function approvedPurchase(float $amount, ?int $accountId = null): Requisition
    {
        Sanctum::actingAs($this->youth);
        $id = $this->postJson('/api/accounting/requisitions', ['kind' => 'purchase', 'purpose' => 'Chairs and a projector for the youth hall', 'amount' => $amount, 'account_id' => $accountId ?? $this->acc('5310')->id])
            ->assertCreated()->json('data.id');
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/requisitions/{$id}/approve")->assertOk();

        return Requisition::find($id);
    }

    private function quote(Requisition $r, string $supplier, float $amount, bool $file = false): int
    {
        return $this->post("/api/accounting/requisitions/{$r->id}/quotes", array_filter([
            'supplier_id' => $this->suppliers[$supplier]->id, 'amount' => $amount,
            'file' => $file ? UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%%EOF\n") : null,
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_a_big_purchase_goes_quotes_order_delivery_bill_and_payment(): void
    {
        $r = $this->approvedPurchase(120000);
        $ledger = app(Ledger::class);
        Sanctum::actingAs($this->treasurer);
        $this->getJson("/api/accounting/requisitions/{$r->id}")->assertJsonPath('data.can.pay', false)->assertJsonPath('data.procurement.must_order', true);
        $this->postJson("/api/accounting/requisitions/{$r->id}/pay", ['pay_from_account_id' => $this->cash()->id])->assertStatus(422);

        $lines = [
            ['description' => 'Plastic chairs', 'quantity' => 100, 'unit_price' => 1000, 'account_id' => $this->acc('5310')->id],
            ['description' => 'Projector', 'quantity' => 1, 'unit_price' => 15000, 'is_asset' => true],
        ];
        $a = $this->quote($r, 'Furniture Palace', 115000, true);
        $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['lines' => $lines])->assertStatus(422)->assertJsonValidationErrors('quotes');
        $this->quote($r, 'Chairs R Us', 105000);
        $this->quote($r, 'Kitui Traders', 118000);
        $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['lines' => $lines])->assertStatus(422)->assertJsonValidationErrors('quotes');
        $this->postJson("/api/accounting/requisitions/{$r->id}/quotes/{$a}/choose")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/accounting/requisitions/{$r->id}/quotes/{$a}/choose", ['reason' => 'Delivers this week; the others in a month'])->assertOk();

        // Its approvers see the quotations and their files through the approval.
        $this->assertStringContainsString('Furniture Palace KES 115,000 (chosen)', collect($r->fresh()->approvalDetails()['facts'])->firstWhere(0, 'Quotations')[1]);
        $this->get("/api/accounting/requisitions/{$r->id}/quotes/{$a}/file")->assertOk();

        $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['lines' => [['description' => 'Chairs', 'quantity' => 2, 'unit_price' => 70000, 'account_id' => $this->acc('5310')->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $po = $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['lines' => $lines, 'supplier_id' => $this->suppliers['Kitui Traders']->id, 'date' => $this->day(1, 5)])
            ->assertCreated()->assertJsonPath('data.supplier', 'Furniture Palace')->assertJsonPath('data.amount', 115000)->json('data');
        $this->assertSame('ordered', $r->fresh()->status);
        $this->postJson("/api/accounting/requisitions/{$r->id}/cancel")->assertStatus(422);
        [$chairs, $projector] = [$po['lines'][0]['id'], $po['lines'][1]['id']];

        // Goods received: part of the chairs and the projector - which becomes the church's equipment.
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/receive", ['date' => $this->day(1, 8), 'lines' => [['line_id' => $chairs, 'quantity' => 60], ['line_id' => $projector, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.status', 'part_received');
        $this->assertSame(1, Equipment::where('territory_id', $this->myChurch->id)->where('name', 'Projector')->where('value', 15000)->where('supplier', 'Furniture Palace')->count());
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/receive", ['date' => $this->day(1, 9), 'lines' => [['line_id' => $chairs, 'quantity' => 50]]])->assertStatus(422);

        // The bill, matched three ways: no more than received, at no more than the order's price.
        $bill = fn (array $over) => $this->postJson("/api/accounting/procurement/orders/{$po['id']}/bill", array_replace(['supplier_ref' => 'INV-778', 'date' => $this->day(1, 10), 'lines' => [['line_id' => $chairs, 'quantity' => 60, 'unit_price' => 1000], ['line_id' => $projector, 'quantity' => 1, 'unit_price' => 15000]]], $over));
        $bill(['lines' => [['line_id' => $chairs, 'quantity' => 70, 'unit_price' => 1000]]])->assertStatus(422);
        $bill(['lines' => [['line_id' => $chairs, 'quantity' => 60, 'unit_price' => 1100]]])->assertStatus(422);
        $bill([])->assertCreated();
        $bill(['lines' => [['line_id' => $chairs, 'quantity' => 0], ['line_id' => $projector, 'quantity' => 0]]])->assertStatus(422);
        $this->assertEquals(60000, $ledger->balance($this->myChurch, $this->acc('5310')));
        $this->assertEquals(15000, $ledger->balance($this->myChurch, $this->chart->account('fixed_assets')));
        $this->assertEquals(75000, $ledger->balance($this->myChurch, $this->chart->account('suppliers_payable')), 'owed to the supplier');
        $b = SupplierInvoice::firstOrFail();
        $this->assertStringContainsString('/BILL/', $b->number);

        // Pay it: a voucher already authorised by whoever approved the requisition.
        $pvId = $this->postJson("/api/accounting/procurement/bills/{$b->id}/pay", ['pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data.voucher_id');
        $pv = PaymentVoucher::find($pvId);
        $this->assertSame('authorised', $pv->status);
        $this->assertSame($this->authoriser->id, $pv->authorised_by);
        $this->postJson("/api/accounting/procurement/bills/{$b->id}/pay", ['pay_from_account_id' => $this->cash()->id])->assertStatus(422);
        $this->postJson("/api/accounting/procurement/bills/{$b->id}/reverse", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/accounting/payment-vouchers/{$pvId}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->chart->account('suppliers_payable')));
        $this->assertSame('ordered', $r->fresh()->status, '40 chairs are still to come');

        // The rest never comes: close the order - everything on it is billed and paid.
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/close", ['reason' => 'Out of stock'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('paid', $r->fresh()->status);

        // Reversing the payment owes the supplier again.
        $this->postJson("/api/accounting/payment-vouchers/{$pvId}/reverse", ['reason' => 'Bounced cheque'])->assertOk();
        $this->assertSame('posted', $b->fresh()->status);
        $this->assertSame('ordered', $r->fresh()->status);
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);
    }

    public function test_a_small_purchase_orders_without_quotes_and_mistakes_are_undone(): void
    {
        $r = $this->approvedPurchase(20000);
        Sanctum::actingAs($this->treasurer);
        $this->getJson("/api/accounting/requisitions/{$r->id}")->assertJsonPath('data.can.pay', true)->assertJsonPath('data.procurement.can.order', true);
        $po = $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['supplier_id' => $this->suppliers['Chairs R Us']->id, 'lines' => [['description' => 'Keyboard', 'quantity' => 1, 'unit_price' => 18000, 'is_asset' => true]]])
            ->assertCreated()->json('data');
        $grn = $this->postJson("/api/accounting/procurement/orders/{$po['id']}/receive", ['date' => now()->toDateString(), 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.status', 'received')->json('data.deliveries.0.id');
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/bill", ['supplier_ref' => 'K-1', 'date' => now()->toDateString(), 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => 1, 'unit_price' => 17500]]])->assertCreated();

        $this->postJson("/api/accounting/procurement/deliveries/{$grn}/undo")->assertStatus(422);
        $bill = SupplierInvoice::firstOrFail();
        $this->postJson("/api/accounting/procurement/bills/{$bill->id}/reverse", ['reason' => 'Wrong amount'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertEquals(0, app(Ledger::class)->balance($this->myChurch, $this->chart->account('suppliers_payable')));
        $this->postJson("/api/accounting/procurement/deliveries/{$grn}/undo")->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertSame(0, Equipment::where('territory_id', $this->myChurch->id)->count(), 'its equipment goes with it');

        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/cancel", ['reason' => 'Bought elsewhere'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('approved', $r->fresh()->status);
        $this->postJson("/api/accounting/requisitions/{$r->id}/pay", ['pay_from_account_id' => $this->cash()->id])->assertCreated();
    }

    public function test_the_bill_counts_in_the_budget_once_and_only_the_place_buys(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine], now()->year, 1);
        // The line posts to Building maintenance, as the standard chart links real lines.
        $account = $this->acc('5300');
        $this->churchLine->update(['account_id' => $account->id]);
        $r = $this->approvedPurchase(10000, $account->id);
        Sanctum::actingAs($this->treasurer);
        $po = $this->postJson("/api/accounting/requisitions/{$r->id}/order", ['date' => $this->day(1, 5), 'supplier_id' => $this->suppliers['Kitui Traders']->id, 'lines' => [['description' => 'Office rent deposit', 'quantity' => 1, 'unit_price' => 9000, 'account_id' => $account->id]]])
            ->assertCreated()->json('data');
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/receive", ['date' => $this->day(1, 6), 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => 1]]])->assertCreated();
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/bill", ['supplier_ref' => 'R-9', 'date' => $this->day(1, 7), 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => 1]]])->assertCreated();
        $this->assertEquals(9000, BudgetEntry::where('budget_id', $budget->id)->sum('amount'), 'counted when the bill is posted');
        $pvId = $this->postJson('/api/accounting/procurement/bills/'.SupplierInvoice::firstOrFail()->id.'/pay', ['pay_from_account_id' => $this->cash()->id])->json('data.voucher_id');
        $this->postJson("/api/accounting/payment-vouchers/{$pvId}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertEquals(9000, BudgetEntry::where('budget_id', $budget->id)->sum('amount'), 'and not again when it is paid');
        $this->assertSame('paid', $r->fresh()->status);

        // The bill's journal is reversed only from Procurement.
        $this->postJson('/api/accounting/journals/'.SupplierInvoice::firstOrFail()->journal_id.'/reverse', ['reason' => 'x'])->assertForbidden();

        // A youth leader asks, but doesn't buy; another church sees nothing; the region reads, never writes.
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/procurement')->assertForbidden();
        $this->postJson('/api/accounting/procurement/suppliers', ['name' => 'Mine'])->assertForbidden();
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/procurement/orders/{$po['id']}")->assertNotFound();
        Sanctum::actingAs($this->regionReader);
        $this->getJson("/api/accounting/procurement/orders/{$po['id']}")->assertOk()->assertJsonPath('data.can.receive', false);
        $this->postJson("/api/accounting/procurement/orders/{$po['id']}/close")->assertForbidden();
    }

    public function test_suppliers_are_unique_and_kept_once_used(): void
    {
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/procurement/suppliers', ['name' => 'chairs r us'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->deleteJson("/api/accounting/procurement/suppliers/{$this->suppliers['Kitui Traders']->id}")->assertOk();
        $this->assertNull(Supplier::find($this->suppliers['Kitui Traders']->id));

        $r = $this->approvedPurchase(60000);
        Sanctum::actingAs($this->treasurer);
        $this->quote($r, 'Chairs R Us', 58000);
        $this->deleteJson("/api/accounting/procurement/suppliers/{$this->suppliers['Chairs R Us']->id}")->assertOk();
        $this->assertFalse(Supplier::find($this->suppliers['Chairs R Us']->id)->is_active, 'switched off, kept on record');
    }
}
