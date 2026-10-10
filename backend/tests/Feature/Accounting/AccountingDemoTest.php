<?php

namespace Tests\Feature\Accounting;

use App\Models\Equipment;
use App\Models\PaymentVoucher;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Models\UserTerritoryAssignment;
use Database\Seeders\AccountingDemoRemoveSeeder;
use Database\Seeders\AccountingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Accounting demo (docs/specs/accounting-spec.md, "Demo data"): books
 * that balance, a few things left waiting, Budgets and the leaders' roles
 * left as they were, and all of it removable.
 */
class AccountingDemoTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    private function seedDemo(): array
    {
        return (new AccountingDemoSeeder)->build($this->myChurch, $this->diocese, $this->treasurer, $this->authoriser, $this->regionReader, $this->authoriser, '2026-06-01');
    }

    public function test_the_demo_books_balance_and_leave_budgets_and_roles_alone(): void
    {
        $entries = DB::table('budget_entries')->count();
        $roles = UserTerritoryAssignment::pluck('effective_from', 'id')->map(fn ($d) => (string) $d)->all();

        $made = $this->seedDemo();

        $this->assertGreaterThan(10, $made['Sunday collections']);
        $this->assertSame(0, DB::table('journal_lines')->selectRaw('journal_id, SUM(debit) - SUM(credit) AS d')->groupBy('journal_id')->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.004')->count(), 'every journal balances');
        $this->assertSame($entries, DB::table('budget_entries')->count(), 'nothing reached Budgets');
        $this->assertSame($roles, UserTerritoryAssignment::pluck('effective_from', 'id')->map(fn ($d) => (string) $d)->all(), 'the leaders\' start dates are back as they were');

        // Done, and waiting.
        $this->assertGreaterThan(10, PaymentVoucher::where('territory_id', $this->myChurch->id)->where('status', 'paid')->count());
        $this->assertSame(1, PaymentVoucher::where('territory_id', $this->myChurch->id)->where('status', 'prepared')->count());
        $this->assertSame(1, Requisition::where('territory_id', $this->myChurch->id)->where('status', 'submitted')->count());
        $this->assertSame(1, PayrollRun::where('territory_id', $this->myChurch->id)->where('status', 'submitted')->count());
        $this->assertTrue(PayrollRun::where('territory_id', $this->myChurch->id)->where('status', 'paid')->exists());
        $this->assertSame(3, DB::table('accounting_periods')->where('territory_id', $this->myChurch->id)->where('status', 'closed')->count(), 'June to August closed');
        $this->assertTrue(Equipment::where('territory_id', $this->myChurch->id)->where('name', 'Yamaha MG16XU sound mixer')->exists(), 'the mixer bought on an order is in Equipment');
        $this->assertSame(0, DB::table('notifications')->count(), 'nobody was told anything');

        // Again: it replaces itself rather than doubling.
        $this->seedDemo();
        $this->assertSame(1, Equipment::where('territory_id', $this->myChurch->id)->where('name', 'Yamaha MG16XU sound mixer')->count());
        $this->assertSame($made['payment vouchers'], PaymentVoucher::where('territory_id', $this->myChurch->id)->count());
    }

    public function test_removing_the_demo_leaves_nothing_behind(): void
    {
        $this->seedDemo();
        $this->assertGreaterThan(0, AccountingDemoRemoveSeeder::removeDemo($this->myChurch));

        foreach (['journals', 'payment_vouchers', 'collections', 'requisitions', 'payroll_runs', 'employees', 'suppliers', 'purchase_orders', 'cash_counts', 'bank_reconciliations', 'accounting_periods', 'approval_requests'] as $table) {
            $this->assertSame(0, DB::table($table)->where('territory_id', $this->myChurch->id)->count(), "{$table} is empty");
        }
        $this->assertSame(0, DB::table('accounting_accounts')->where('territory_id', $this->myChurch->id)->count());
        $this->assertFalse(Equipment::where('name', 'Yamaha MG16XU sound mixer')->exists());
        $this->assertArrayNotHasKey(AccountingDemoSeeder::KEY, $this->myChurch->fresh()->metadata ?? []);
    }
}
