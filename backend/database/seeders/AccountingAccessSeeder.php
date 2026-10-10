<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Services\Accounting\Chart;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Accounting for every level (docs/specs/accounting-spec.md): the standard
 * chart of accounts, the Finance -> Accounting menu at church, region and
 * diocese, the {level}.accounting.* permissions - each linked to the page it
 * opens - and which roles hold them. One engine for every level; the
 * chart page is the diocese's only.
 *
 * Idempotent - safe to re-run. Users log out and back in for the new menu.
 */
class AccountingAccessSeeder extends Seeder
{
    /**
     * The pages: key => [file, title, description, levels (null = all)], in
     * the order the menu shows them - as money moves: where it is, money in,
     * asking and approving, money out, between places, then the books and
     * the month's close.
     */
    private const PAGES = [
        'overview' => ['', 'Overview', 'Where the money is now, this month in and out, by fund, the latest documents', null],
        'accounts' => ['accounts.php', 'Cash & bank', 'Cash at hand, the bank and M-Pesa with their balances; the chart of accounts', null],
        'collections' => ['collections.php', 'Collections', 'Sunday collections: counted by one, confirmed by another, receipted per fund, banked', ['church']],
        'receipts' => ['receipts.php', 'Receipts', 'Write official receipts for money received - tithes, offerings, contributions', null],
        'giving' => ['giving.php', 'Online giving', 'Gifts made on our giving page - by M-Pesa or card - and the link to share', null],
        'paybill' => ['paybill.php', 'Paybill', 'The diocese M-Pesa paybill: giving by church code, what waits to be sorted, monthly settlements', null],
        'requisitions' => ['requisitions.php', 'Requisitions', 'Ask for money - to pay, to buy, or an advance - approved, then paid', null],
        'approvals' => ['approvals.php', 'Approvals', 'What waits for my approval, what I asked for, and who I hand it to while away', null],
        'procurement' => ['procurement.php', 'Procurement', 'Bigger purchases: quotations, the order, goods received, the supplier\'s bill, then payment', null],
        'payments' => ['payments.php', 'Payment vouchers', 'Prepare, authorise and pay - every payment with its papers', null],
        'payroll' => ['payroll.php', 'Payroll', 'The people this place pays, the monthly run, payslips and paying the staff', null],
        'remittances' => ['remittances.php', 'Remittances', 'The share sent up and support sent down - due from the books, sent by voucher, confirmed by the place receiving it', null],
        'cashbook' => ['cashbook.php', 'Cashbook', 'Every shilling in and out of one account, with the running balance', null],
        'journals' => ['journals.php', 'Journals', 'Opening balances and corrections, in balanced journals', null],
        'reconciliation' => ['reconciliation.php', 'Reconciliation', 'Count the cash, match the bank and M-Pesa to their statements - and the places below', null],
        'close' => ['close.php', 'Month-end close', 'Close each month once every account is proven; the level above reopens', null],
        'documents' => ['documents.php', 'All documents', 'Every receipt, payment, transfer and journal, newest first', null],
        'gateways' => ['gateways.php', 'Gateways', 'Each church\'s Paystack for online giving, and the payouts recorded', ['diocese']],
        'chart' => ['chart.php', 'Chart of accounts', 'The standard accounts every church, region and diocese posts to', ['diocese']],
        'rules' => ['approval-rules.php', 'Approval rules', 'Who approves what, by level and amount, and who it goes to when late', ['diocese']],
    ];

    /** Ability => [permission (after "{level}.") => the page it opens]. */
    private const PERMISSIONS = [
        'read' => [
            'accounting.books.read' => 'overview',
            'accounting.accounts.read' => 'accounts',
            'accounting.cashbook.read' => 'cashbook',
            'accounting.payments.read' => 'payments',
            'accounting.documents.read' => 'documents',
            'accounting.reconciliation.read' => 'reconciliation',
            'accounting.periods.read' => 'close',
            'accounting.collections.read' => 'collections',
            'accounting.requisitions.read' => 'requisitions',
            'accounting.procurement.read' => 'procurement',
            'accounting.remittances.read' => 'remittances',
            'accounting.paybill.read' => 'paybill',
            'accounting.giving.read' => 'giving',
        ],
        'receipt' => ['accounting.receipts.create' => 'receipts'],
        'prepare' => ['accounting.payments.prepare' => 'payments'],
        'authorise' => ['accounting.payments.authorise' => 'payments'],
        'pay' => ['accounting.payments.pay' => 'payments'],
        'journal' => ['accounting.journals.post' => 'journals'],
        'accounts' => ['accounting.accounts.manage' => 'accounts'],
        'chart' => ['accounting.chart.manage' => 'chart'],
        'below' => ['accounting.below.read' => 'overview'],
        'reconcile' => ['accounting.reconcile.do' => 'reconciliation'],
        'petty' => ['accounting.pettycash.spend' => 'accounts'],
        'close' => ['accounting.periods.close' => 'close'],
        'reopen' => ['accounting.periods.reopen' => 'close'],
        'collect' => ['accounting.collections.record' => 'collections', 'accounting.collections.read' => 'collections'],
        'confirm' => ['accounting.collections.confirm' => 'collections', 'accounting.collections.read' => 'collections'],
        'approvals' => ['accounting.approvals.read' => 'approvals'],
        'request' => ['accounting.requisitions.create' => 'requisitions'],
        'rules' => ['accounting.approvalrules.manage' => 'rules'],
        'procure' => ['accounting.procurement.manage' => 'procurement', 'accounting.procurement.read' => 'procurement'],
        'payroll' => ['accounting.payroll.manage' => 'payroll', 'accounting.payroll.read' => 'payroll'],
        'payrollread' => ['accounting.payroll.read' => 'payroll'],
        'paybill' => ['accounting.paybill.manage' => 'paybill', 'accounting.paybill.read' => 'paybill'],
        'gateways' => ['accounting.gateways.manage' => 'gateways'],
    ];

    /** What every role at a level gets: their approvals, and asking for money. */
    private const EVERYONE = ['approvals', 'request'];

    /** Who does what, per level - the standard separation of duties. */
    private const GRANTS = [
        'church' => [
            'Church Treasurer' => ['read', 'receipt', 'prepare', 'pay', 'accounts', 'journal', 'reconcile', 'petty', 'close', 'collect', 'confirm', 'procure', 'payroll'],
            'Senior Pastor' => ['read', 'authorise', 'confirm', 'payrollread'],
            'Associate Pastor' => ['read', 'authorise', 'confirm'],
            'Church Administrator' => ['read', 'receipt', 'prepare', 'petty', 'collect', 'procure'],
            'Church Secretary' => ['read', 'prepare', 'petty', 'collect'],
            'Usher Coordinator' => ['collect'],
            'Deacon' => ['collect'],
            'Elder' => ['collect', 'confirm'],
            'Church Committee Member' => ['read'],
        ],
        'region' => [
            'Regional Treasurer' => ['read', 'receipt', 'prepare', 'pay', 'accounts', 'journal', 'below', 'reconcile', 'petty', 'close', 'reopen', 'procure', 'payroll'],
            'Regional Overseer' => ['read', 'authorise', 'below', 'reopen', 'payrollread'],
            'Regional Secretary' => ['read', 'prepare', 'below', 'procure'],
            'Regional Coordinator' => ['read', 'below'],
            'Regional Committee Member' => ['read', 'below'],
        ],
        'diocese' => [
            'Diocese Finance Officer' => ['read', 'receipt', 'prepare', 'pay', 'accounts', 'journal', 'chart', 'below', 'reconcile', 'petty', 'close', 'reopen', 'rules', 'procure', 'payroll', 'paybill', 'gateways'],
            'Diocese Treasurer' => ['read', 'receipt', 'prepare', 'pay', 'accounts', 'journal', 'below', 'reconcile', 'petty', 'close', 'reopen', 'procure', 'payroll', 'paybill'],
            'Bishop' => ['read', 'authorise', 'below', 'payrollread'],
            'Diocese Administrator' => ['read', 'prepare', 'below', 'procure'],
            'Diocese Secretary' => ['read', 'prepare', 'below'],
            'Diocese Council Member' => ['read', 'below'],
        ],
    ];

    public function run(): void
    {
        $this->command?->info('📒 ACCOUNTING - chart of accounts, menu and permissions per level');
        app(Chart::class)->ensureStandard();
        $this->command?->info('   ✅ Standard chart of accounts and funds');

        foreach (self::GRANTS as $level => $grants) {
            $group = ModuleGroup::where('slug', "{$level}-finance")->first();
            if (! $group) {
                $this->command?->error("   ❌ No Finance group for {$level} - skipping");

                continue;
            }
            $module = Submodule::where('path', "/{$level}/accounting/")->first()?->module()->first()
                ?? Module::where('module_group_id', $group->id)->where('name', 'Accounting')->first()
                ?? new Module(['number' => 0]);
            $budgets = Module::where('module_group_id', $group->id)->where('name', 'Budgets')->first();
            $module->forceFill([
                'name' => 'Accounting',
                'module_group_id' => $group->id,
                'icon' => 'ri-bank-line',
                'description' => 'The real money: receipts, payments, cash and bank, the cashbook',
                'is_active' => true,
                'number' => $module->exists ? $module->number : (($budgets?->number ?? 0) + 1),
            ])->save();

            $pages = [];
            $position = 0;
            foreach (self::PAGES as $key => [$file, $title, $description, $levels]) {
                $position++;
                if ($levels && ! in_array($level, $levels, true)) {
                    continue;
                }
                $pages[$key] = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => "/{$level}/accounting/{$file}"],
                    ['title' => $title, 'description' => $description, 'is_active' => true, 'order' => $position],
                );
            }
            // Pages that are no longer ours leave the menu.
            Submodule::where('module_id', $module->id)->whereNotIn('id', collect($pages)->pluck('id'))->update(['is_active' => false]);

            $permissions = [];
            foreach (self::PERMISSIONS as $ability => $names) {
                foreach ($names as $suffix => $pageKey) {
                    if (! isset($pages[$pageKey]) || ($ability === 'below' && $level === 'church')) {
                        continue;
                    }
                    $name = "{$level}.{$suffix}";
                    $permissions[$ability][] = Permission::updateOrCreate(
                        ['name' => $name, 'guard_name' => 'web'],
                        ['module_id' => $module->id, 'submodule_id' => $pages[$pageKey]->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level],
                    );
                }
            }

            $granted = 0;
            foreach ($grants as $roleName => $abilities) {
                $role = Role::where('name', $roleName)->where('territory_level', $level)->first() ?? Role::where('name', $roleName)->first();
                if (! $role) {
                    continue;
                }
                $missing = collect($abilities)->flatMap(fn ($a) => $permissions[$a] ?? [])->reject(fn ($p) => $role->hasPermissionTo($p));
                if ($missing->isNotEmpty()) {
                    $role->givePermissionTo($missing->all());
                    $granted += $missing->count();
                }
            }
            foreach (Role::where('territory_level', $level)->get() as $role) {
                $missing = collect(self::EVERYONE)->flatMap(fn ($a) => $permissions[$a] ?? [])->reject(fn ($p) => $role->hasPermissionTo($p));
                if ($missing->isNotEmpty()) {
                    $role->givePermissionTo($missing->all());
                    $granted += $missing->count();
                }
            }
            $this->command?->info("   ✅ {$level}: Finance > Accounting (".count($pages)." pages), {$granted} new grant(s)");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
