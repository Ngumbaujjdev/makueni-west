<?php

namespace Tests\Feature\Accounting;

use App\Models\Employee;
use App\Models\Gift;
use App\Models\PaymentVoucher;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Support\PayTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payee details and detail pages (docs/specs/accounting-spec.md): where money
 * goes - M-Pesa, Airtel, paybill, till, bank, cash - checked by shape on
 * suppliers, staff, requisitions and vouchers, said in one line; and the
 * record pages for a bill, a receipt, a gift, a supplier and a person on the
 * payroll (the last only for whoever sees the payroll).
 */
class PayeeDetailsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_each_way_of_paying_is_checked_and_said_in_one_line(): void
    {
        $this->assertSame('M-Pesa 0757150682', PayTo::describe(PayTo::from(['method' => 'mpesa', 'phone' => '+254 757 150682'])));
        $this->assertSame('Paybill 247247, account 0123456789', PayTo::describe(PayTo::from(['method' => 'paybill', 'paybill_number' => '247247', 'paybill_account' => '0123456789'])));
        $this->assertSame('Till 5123456', PayTo::describe(PayTo::from(['method' => 'till', 'till_number' => '5123456'])));
        $this->assertSame('KCB 1234567890 (St Mark Hardware)', PayTo::describe(PayTo::from(['method' => 'bank', 'bank_name' => 'KCB', 'bank_account' => '1234 567 890', 'bank_account_name' => 'St Mark Hardware'])));
        $this->assertNull(PayTo::from(['method' => '']));

        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/procurement/suppliers', ['name' => 'St Mark Hardware', 'payee' => ['method' => 'bank', 'bank_name' => 'KCB']])
            ->assertUnprocessable()->assertJsonValidationErrors('payee.bank_account');
        $this->postJson('/api/accounting/procurement/suppliers', ['name' => 'St Mark Hardware', 'payee' => ['method' => 'airtel', 'phone' => '12345']])
            ->assertUnprocessable()->assertJsonValidationErrors('payee.phone');
        $id = $this->postJson('/api/accounting/procurement/suppliers', ['name' => 'St Mark Hardware', 'payee' => ['method' => 'paybill', 'paybill_number' => '247247', 'paybill_account' => '0123456789']])
            ->assertCreated()->assertJsonPath('data.payee_text', 'Paybill 247247, account 0123456789')->json('data.id');
        // A change that doesn't mention the payee keeps it.
        $this->putJson("/api/accounting/procurement/suppliers/{$id}", ['name' => 'St Mark Hardware Ltd'])->assertOk();
        $this->assertSame('247247', Supplier::find($id)->payee['paybill_number']);
    }

    public function test_vouchers_requisitions_and_staff_carry_where_to_pay(): void
    {
        Sanctum::actingAs($this->treasurer);
        $pv = $this->postJson('/api/accounting/payment-vouchers', ['date' => $this->day(), 'payee_name' => 'Mama Mboga', 'pay_from_account_id' => $this->cash()->id, 'narration' => 'Food for the youth camp',
            'payee' => ['method' => 'till', 'till_number' => '5123456'], 'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 3000]]])->assertCreated()->assertJsonPath('data.payee_text', 'Till 5123456')->json('data.id');
        $this->assertContains(['Pay by', 'Till 5123456'], PaymentVoucher::find($pv)->approvalDetails()['facts']);

        $r = $this->postJson('/api/accounting/requisitions', ['kind' => 'payment', 'purpose' => 'Chairs', 'amount' => 5000, 'account_id' => $this->acc('5310')->id, 'payee_name' => 'Kyalo Furniture',
            'payee' => ['method' => 'mpesa', 'phone' => '0757150682']])->assertCreated()->json('data.id');
        $this->assertSame('M-Pesa 0757150682', PayTo::describe(Requisition::find($r)->payee));

        Sanctum::actingAs($this->userWithRole('payrollclerk', 'Church Administrator', 'church', $this->myChurch->id, $this->perms('church', ['read', 'payroll'])));
        $e = $this->postJson('/api/accounting/payroll/employees', ['name' => 'Ruth Mwende', 'basic_pay' => 15000, 'payee' => ['method' => 'airtel', 'phone' => '0733000111']])->assertCreated()->json('data.id');
        $emp = Employee::find($e);
        $this->assertSame(['airtel', 'Airtel Money 0733000111'], [$emp->pay_method, $emp->pay_to]);
    }

    public function test_the_record_pages_for_a_receipt_a_gift_a_supplier_and_staff(): void
    {
        Sanctum::actingAs($this->treasurer);
        $j = $this->postJson('/api/accounting/receipts', ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'Members', 'lines' => [['account_id' => $this->acc('4010')->id, 'amount' => 2500]]])
            ->assertCreated()->json('data.id');
        $t = $this->getJson("/api/accounting/trail/receipt/{$j}")->assertOk()->json('data');
        $this->assertSame(2500.0, (float) $t['summary']['amount']);
        $this->assertNotEmpty($t['summary']['lines']);
        $this->assertSame('Posted', substr($t['events'][0]['text'], 0, 6));

        $gift = Gift::create(['reference' => 'GFT-TRAIL1', 'territory_id' => $this->myChurch->id, 'purpose' => 'T', 'amount' => 700, 'method' => 'mpesa', 'status' => 'failed', 'result' => 'DS timeout user cannot be reached.', 'giver_name' => 'Joshua']);
        $g = $this->getJson("/api/accounting/trail/gift/{$gift->id}")->assertOk()->json('data.summary');
        $this->assertContains(['Why', 'DS timeout user cannot be reached.'], $g['facts']);

        $s = Supplier::create(['territory_id' => $this->myChurch->id, 'name' => 'Kyalo Furniture', 'payee' => ['method' => 'mpesa', 'phone' => '254757150682'], 'is_active' => true]);
        $this->assertContains(['Pay to', 'M-Pesa 0757150682'], $this->getJson("/api/accounting/trail/supplier/{$s->id}")->assertOk()->json('data.summary.facts'));

        // A person on the payroll: only for whoever sees the payroll.
        $emp = Employee::create(['territory_id' => $this->myChurch->id, 'name' => 'Ruth Mwende', 'pay_method' => 'bank', 'basic_pay' => 15000, 'payee' => ['method' => 'bank', 'bank_name' => 'KCB', 'bank_account' => '1234567890', 'bank_account_name' => 'Ruth Mwende'], 'is_active' => true]);
        $this->getJson("/api/accounting/trail/employee/{$emp->id}")->assertOk()->assertJsonPath('data.summary.title', 'Ruth Mwende');
        Sanctum::actingAs($this->authoriser);
        $this->getJson("/api/accounting/trail/employee/{$emp->id}")->assertNotFound();
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/trail/supplier/{$s->id}")->assertNotFound();
    }
}
