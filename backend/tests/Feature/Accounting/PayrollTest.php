<?php

namespace Tests\Feature\Accounting;

use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payroll (docs/specs/accounting-spec.md, A7): Kenyan deductions from the
 * rates in Settings, a run approved and posted, net pay and each authority
 * paid - and salaries and personal numbers kept private.
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
            'allowances' => [['name' => 'House', 'amount' => 10000]], 'statutory' => true,
        ], $over))->assertCreated()->json('data.id');
    }

    public function test_the_deductions_match_the_published_examples(): void
    {
        $c = app(PayrollCalculator::class);
        $a = $c->compute(100000);
        $this->assertEquals([6000, 2750, 1500, 89750, 19308.35], [$a['nssf'], $a['shif'], $a['ahl'], $a['taxable'], $a['paye']]);
        $this->assertEquals(731.25, $c->compute(30000)['paye']);
        $this->assertEquals(6480, $c->compute(200000)['nssf'], 'NSSF stops at the upper earnings limit');
        $this->assertEquals(300, $c->compute(8000)['shif'], 'SHIF has a minimum');
        $this->assertEquals(0, $c->compute(15000)['paye'], 'relief covers the tax on low pay');
        $this->assertEquals(0, $c->compute(50000, false)['paye'] + $c->compute(50000, false)['nssf'], 'allowance-only: no deductions');
    }

    public function test_a_run_is_approved_posted_and_everyone_is_paid(): void
    {
        $grace = $this->employee(['id_number' => '23456789', 'kra_pin' => 'A012345678Z']);
        $this->employee(['name' => 'Peter Kioko', 'position' => 'Caretaker', 'basic_pay' => 30000, 'allowances' => []]);
        $this->employee(['name' => 'Old Hand', 'basic_pay' => 20000, 'allowances' => [], 'end_date' => now()->subMonths(2)->toDateString()]);
        $month = now()->format('Y-m');

        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => $month])->assertCreated()->json('data');
        $this->assertCount(2, $run['payslips'], 'someone who left is not paid');
        $this->postJson('/api/accounting/payroll/runs', ['month' => $month])->assertStatus(422);
        $slip = collect($run['payslips'])->firstWhere('name', 'Grace Mwende');
        $this->assertEquals(19308.35, $slip['paye']);

        // A figure typed over is marked; Recalculate puts the worked-out one back.
        $this->putJson("/api/accounting/payroll/runs/{$run['id']}/payslips/{$slip['id']}", ['overrides' => ['paye' => 19000], 'other' => 2000, 'other_note' => 'SACCO'])->assertOk();
        $this->assertTrue(DB::table('payslips')->where('id', $slip['id'])->value('manual') == 1);
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/recalculate")->assertOk();
        $this->assertEquals(19308.35, DB::table('payslips')->where('id', $slip['id'])->value('paye'));

        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/approve")->assertStatus(422);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payroll/runs/{$run['id']}/approve")->assertOk()->assertJsonPath('data.status', 'posted');

        // Posted: Dr salaries (gross + employer) / Cr net pay payable / Cr deductions payable.
        $r = PayrollRun::find($run['id']);
        $ledger = app(Ledger::class);
        $this->assertEquals(130000 + (float) $r->employer, $ledger->balance($this->myChurch, $this->acc('5000')));
        $this->assertEquals((float) $r->net, $ledger->balance($this->myChurch, $this->acc('2310')));
        $this->assertEquals(round((float) $r->deductions + (float) $r->employer, 2), $ledger->balance($this->myChurch, $this->acc('2300')));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);
        $this->postJson('/api/accounting/journals/'.$r->journal_id.'/reverse', ['reason' => 'x'])->assertForbidden();

        // Net pay, then each authority - vouchers already authorised by the approver.
        Sanctum::actingAs($this->treasurer);
        foreach (['net', 'paye', 'nssf', 'shif', 'ahl'] as $kind) {
            $pv = $this->postJson("/api/accounting/payroll/runs/{$r->id}/pay", ['kind' => $kind, 'pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data.voucher_id');
            $this->assertSame($this->authoriser->id, \App\Models\PaymentVoucher::find($pv)->authorised_by);
            $this->postJson("/api/accounting/payroll/runs/{$r->id}/pay", ['kind' => $kind, 'pay_from_account_id' => $this->cash()->id])->assertStatus(422);
            if ($kind === 'paye') {
                // A cancelled voucher frees it to be paid again.
                $this->postJson("/api/accounting/payment-vouchers/{$pv}/cancel")->assertOk();
                $pv = $this->postJson("/api/accounting/payroll/runs/{$r->id}/pay", ['kind' => 'paye', 'pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data.voucher_id');
            }
            $this->postJson("/api/accounting/payment-vouchers/{$pv}/pay", ['paid_on' => now()->toDateString()])->assertOk();
            $this->assertSame($kind === 'ahl' ? 'paid' : 'posted', $r->fresh()->status);
        }
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc('2310')));
        $this->assertEquals(2000, $ledger->balance($this->myChurch, $this->acc('2300')), 'only the SACCO deduction is left - paid with an ordinary voucher');
        $this->assertSame(2, JournalLine::where('territory_id', $this->myChurch->id)->where('account_id', $this->acc('2310')->id)->where('debit', '>', 0)->count(), 'a line per person');

        // Reversing a payment opens it again.
        $netPv = \App\Models\PayrollPayment::where('payroll_run_id', $r->id)->where('kind', 'net')->value('payment_voucher_id');
        $this->postJson("/api/accounting/payment-vouchers/{$netPv}/reverse", ['reason' => 'Wrong M-Pesa number'])->assertOk();
        $this->assertSame('posted', $r->fresh()->status);
        $this->postJson("/api/accounting/payment-vouchers/{$netPv}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $r->fresh()->status);

        $this->putJson("/api/accounting/payroll/runs/{$r->id}/references", ['refs' => [$slip['id'] => 'QWE123ABC']])->assertOk();
        $this->assertSame('QWE123ABC', DB::table('payslips')->where('id', $slip['id'])->value('reference'));
    }

    public function test_salaries_and_personal_numbers_stay_private(): void
    {
        $id = $this->employee(['id_number' => '23456789', 'kra_pin' => 'A012345678Z', 'nssf_no' => '2001234567', 'shif_no' => 'SHA9988776']);
        $raw = DB::table('employees')->where('id', $id)->first();
        $this->assertNotSame('23456789', $raw->id_number, 'encrypted at rest');
        $this->assertSame('A012345678Z', Employee::find($id)->kra_pin);

        $body = $this->getJson('/api/accounting/payroll')->assertOk()->getContent();
        foreach (['23456789', 'A012345678Z', '2001234567', 'SHA9988776'] as $secret) {
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
