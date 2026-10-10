<?php

namespace Tests\Feature\Accounting;

use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payroll (docs/specs/accounting-spec.md, A7): pay less any other deduction -
 * the churches don't deduct PAYE, NSSF, SHIF or the Housing Levy - a run
 * approved and posted, the staff paid; salaries and personal numbers private.
 */
class PayrollTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $youth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        $this->youth = $this->userWithRole('youth', 'Youth Leader', 'church', $this->myChurch->id, $this->perms('church', ['request']));
        $this->authoriser->roles()->first()->givePermissionTo($this->perms('church', ['payrollread']));
        $this->receive(500000);
    }

    private function employee(array $over = []): int
    {
        Sanctum::actingAs($this->treasurer);

        return $this->postJson('/api/accounting/payroll/employees', array_replace([
            'name' => 'Grace Mwende', 'position' => 'Church secretary', 'pay_method' => 'mpesa', 'pay_to' => '0712345678', 'basic_pay' => 90000,
            'allowances' => [['name' => 'House', 'amount' => 10000]],
        ], $over))->assertCreated()->json('data.id');
    }

    public function test_a_run_is_approved_posted_and_the_staff_are_paid(): void
    {
        $this->employee(['id_number' => '23456789', 'kra_pin' => 'A012345678Z']);
        $this->employee(['name' => 'Peter Kioko', 'position' => 'Caretaker', 'basic_pay' => 30000, 'allowances' => []]);
        $this->employee(['name' => 'Old Hand', 'basic_pay' => 20000, 'allowances' => [], 'end_date' => now()->subMonths(2)->toDateString()]);
        $month = now()->format('Y-m');

        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => $month])->assertCreated()->json('data');
        $this->assertCount(2, $run['payslips'], 'someone who left is not paid');
        $this->postJson('/api/accounting/payroll/runs', ['month' => $month])->assertStatus(422);
        $slip = collect($run['payslips'])->firstWhere('name', 'Grace Mwende');
        $this->assertEquals(100000, $slip['net'], 'no PAYE, NSSF, SHIF or Housing Levy - pay is paid in full');
        $this->assertArrayNotHasKey('paye', $slip);

        // Another deduction (a SACCO) comes off; Recalculate re-reads pay and keeps it.
        $this->putJson("/api/accounting/payroll/runs/{$run['id']}/payslips/{$slip['id']}", ['other' => 200000])->assertStatus(422);
        $this->putJson("/api/accounting/payroll/runs/{$run['id']}/payslips/{$slip['id']}", ['other' => 2000, 'other_note' => 'SACCO'])->assertOk()->assertJsonPath('data.net', 128000);
        Employee::where('name', 'Peter Kioko')->update(['basic_pay' => 31000]);
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/recalculate")->assertOk()->assertJsonPath('data.net', 129000);
        $this->assertEquals(2000, DB::table('payslips')->where('id', $slip['id'])->value('other'));
        Employee::where('name', 'Peter Kioko')->update(['basic_pay' => 30000]);
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/recalculate")->assertOk()->assertJsonPath('data.net', 128000);

        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/approve")->assertStatus(422);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/approve")->assertOk()->assertJsonPath('data.status', 'posted');

        // Posted: Dr salaries / Cr net pay payable / Cr deductions payable (the SACCO).
        $r = PayrollRun::find($run['id']);
        $ledger = app(Ledger::class);
        $this->assertEquals(130000, $ledger->balance($this->myChurch, $this->acc('5000')));
        $this->assertEquals(128000, $ledger->balance($this->myChurch, $this->acc('2310')));
        $this->assertEquals(2000, $ledger->balance($this->myChurch, $this->acc('2300')));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);
        $this->postJson('/api/accounting/journals/'.$r->journal_id.'/reverse', ['reason' => 'x'])->assertForbidden();

        // The staff are paid by one voucher, a line per person, already authorised by the approver.
        Sanctum::actingAs($this->treasurer);
        $pay = fn () => $this->postJson("/api/accounting/payroll/runs/{$r->id}/pay", ['pay_from_account_id' => $this->cash()->id]);
        $pv = $pay()->assertCreated()->json('data.voucher_id');
        $this->assertSame($this->authoriser->id, \App\Models\PaymentVoucher::find($pv)->authorised_by);
        $pay()->assertStatus(422);
        // A cancelled voucher frees it to be paid again.
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/cancel")->assertOk();
        $pv = $pay()->assertCreated()->json('data.voucher_id');
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc('2310')));
        $this->assertSame(2, JournalLine::where('territory_id', $this->myChurch->id)->where('account_id', $this->acc('2310')->id)->where('debit', '>', 0)->count(), 'a line per person');

        // Reversing the payment opens it again.
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/reverse", ['reason' => 'Wrong M-Pesa number'])->assertOk();
        $this->assertSame('posted', $r->fresh()->status);
        $this->postJson("/api/accounting/payment-vouchers/{$pv}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $r->fresh()->status);

        $this->putJson("/api/accounting/payroll/runs/{$r->id}/references", ['refs' => [$slip['id'] => 'QWE123ABC']])->assertOk();
        $this->assertSame('QWE123ABC', DB::table('payslips')->where('id', $slip['id'])->value('reference'));
    }

    public function test_salaries_and_personal_numbers_stay_private(): void
    {
        $id = $this->employee(['id_number' => '23456789', 'kra_pin' => 'A012345678Z']);
        $raw = DB::table('employees')->where('id', $id)->first();
        $this->assertNotSame('23456789', $raw->id_number, 'encrypted at rest');
        $this->assertSame('A012345678Z', Employee::find($id)->kra_pin);

        $body = $this->getJson('/api/accounting/payroll')->assertOk()->getContent();
        foreach (['23456789', 'A012345678Z'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('6789', $body);
        // Saving without the numbers keeps them.
        $this->putJson("/api/accounting/payroll/employees/{$id}", ['name' => 'Grace Mwende', 'pay_method' => 'bank', 'basic_pay' => 95000])->assertOk();
        $this->assertSame('A012345678Z', Employee::find($id)->kra_pin);

        // The pastor reads it (payroll.read) but doesn't run it; a youth leader and another church see nothing.
        Sanctum::actingAs($this->authoriser);
        $this->getJson('/api/accounting/payroll')->assertOk();
        $this->postJson('/api/accounting/payroll/runs', ['month' => now()->format('Y-m')])->assertForbidden();
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/payroll')->assertForbidden();
        Sanctum::actingAs($this->treasurer);
        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => now()->format('Y-m')])->json('data.id');
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/payroll/runs/{$run}")->assertNotFound();
        $this->assertSame(0, DB::table('audits')->where('auditable_type', 'employee')->where('new_values', 'like', '%23456789%')->count(), 'not in the audit trail either');
    }
}
