<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Opens budgeting to churches, and gives the diocese's budget pages real
 * nav and permission rows (the sidebar is built from these, never hardcoded).
 *
 * Church:
 * - the Finance → "Budget" module (was the switched-off "Financial
 *   Management", id 29) shows one page, Budgets (church/budget/*.php).
 *   Income, Expenses and Financial Reports stay hidden until they're built;
 * - pastors, the church administrator and treasurer get budget planning
 *   read/create/update plus a new submit - never approve, which stays with
 *   the diocese;
 * - Settings → "Budget Settings" → Budget Lines: the diocese's shared types,
 *   categories and lines (read-only) and the church's own lines.
 *
 * Diocese: the Budget Overview and Budget Settings pages checked permission
 * names that didn't exist, so only a global admin could open them. They get
 * those rows and nav entries, granted to every role already holding a
 * diocese budget permission (Bishop, council, secretary, treasurer, admin).
 *
 * Idempotent - safe to re-run.
 */
class ChurchBudgetAccessSeeder extends Seeder
{
    private const CHURCH_ROLES = ['Senior Pastor', 'Associate Pastor', 'Church Administrator', 'Church Treasurer'];

    private const CHURCH_BUDGET_PREFIX = 'financialmanagement.budgetmanagement.budgetplanning';

    private const CHURCH_LINES_PREFIX = 'church.settings.budgetsettings.budgetlines';

    public function run(): void
    {
        $this->command->info('💰 CHURCH BUDGETING ACCESS');

        $churchRoles = Role::whereIn('name', self::CHURCH_ROLES)->get();
        $dioceseRoles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'like', 'diocesebudgetmanagement.%'))->get();

        $this->churchBudgets($churchRoles);
        $this->churchBudgetLines($churchRoles);
        $this->dioceseBudgetPages($dioceseRoles);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done - users log out and back in to see the new menu items');
    }

    private function churchBudgets($roles): void
    {
        // The church Finance module: the one whose submodule is "Budget Management".
        $submodule = Submodule::where('title', 'Budget Management')->where('path', '/finance/budget')->first()
            ?? Submodule::where('path', '/church/budget/all-budgets.php')->first();
        if (! $submodule) {
            $this->command->error('   ❌ Church Budget Management submodule not found');

            return;
        }
        $module = Module::find($submodule->module_id);
        $module->update(['name' => 'Budget', 'is_active' => true, 'description' => 'Prepare this church\'s budget and send it to the diocese for approval']);
        $submodule->update(['title' => 'Budgets', 'path' => '/church/budget/all-budgets.php', 'is_active' => true, 'description' => 'This church\'s budgets: prepare, submit, follow approval']);

        // Only Budgets shows: the placeholder sub-pages and the not-yet-built finance pages stay hidden.
        DB::table('sub_submodules')->where('submodule_id', $submodule->id)->update(['is_active' => false, 'updated_at' => now()]);
        Submodule::where('module_id', $module->id)->whereKeyNot($submodule->id)->update(['is_active' => false]);
        $this->command->info("   ✅ Module: {$module->name} → {$submodule->title} ({$submodule->path})");

        // The existing budget planning permissions, plus submit.
        $subSubmoduleId = Permission::where('name', self::CHURCH_BUDGET_PREFIX.'.read')->value('sub_submodule_id');
        $permissions = collect(['read', 'create', 'update', 'submit'])->map(fn ($action) => Permission::firstOrCreate(
            ['name' => self::CHURCH_BUDGET_PREFIX.".{$action}", 'guard_name' => 'web'],
            ['module_id' => $module->id, 'submodule_id' => $submodule->id, 'sub_submodule_id' => $subSubmoduleId, 'action' => $action, 'territory_scope' => 'church'],
        ));
        $this->grant($roles, $permissions);
    }

    private function churchBudgetLines($roles): void
    {
        $groupId = DB::table('module_groups')->where('slug', 'church-settings')->value('id');
        if (! $groupId) {
            $this->command->error('   ❌ Church Settings module group not found');

            return;
        }
        $module = Module::firstOrCreate(
            ['module_group_id' => $groupId, 'name' => 'Budget Settings'],
            ['icon' => 'ri-settings-3-line', 'number' => 3, 'is_active' => true, 'description' => 'The diocese\'s budget types, categories and lines, and this church\'s own lines.'],
        );
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'path' => '/church/settings/budget-settings/budget-lines.php'],
            ['title' => 'Budget Lines', 'is_active' => true, 'description' => 'Shared diocese budget lines, and lines only this church uses.'],
        );
        $this->command->info("   ✅ Module: {$module->name} → {$submodule->title}");

        $permissions = collect(['read', 'create', 'update', 'delete'])->map(fn ($action) => Permission::firstOrCreate(
            ['name' => self::CHURCH_LINES_PREFIX.".{$action}", 'guard_name' => 'web'],
            ['module_id' => $module->id, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => $action, 'territory_scope' => 'church'],
        ));
        $this->grant($roles, $permissions);
    }

    private function dioceseBudgetPages($roles): void
    {
        // Budget Overview, under the Diocese Budget Management module.
        $budgetModuleId = Submodule::where('path', '/diocese/financial/budget/planning')->value('module_id')
            ?? Submodule::where('path', '/diocese/budget-management/budget-overview/all-budgets.php')->value('module_id');
        if ($budgetModuleId) {
            $overview = Submodule::firstOrCreate(
                ['module_id' => $budgetModuleId, 'path' => '/diocese/budget-management/budget-overview/all-budgets.php'],
                ['title' => 'Budget Overview', 'is_active' => true, 'description' => 'Every budget across the diocese - review and approve church budgets'],
            );
            $permissions = collect(['read', 'allbudgets.read'])->map(fn ($name) => Permission::firstOrCreate(
                ['name' => "diocese.budgetmanagement.budgetoverview.{$name}", 'guard_name' => 'web'],
                ['module_id' => $budgetModuleId, 'submodule_id' => $overview->id, 'sub_submodule_id' => null, 'action' => 'read', 'territory_scope' => 'diocese'],
            ));
            $this->command->info("   ✅ Diocese: {$overview->title}");
            $this->grant($roles, $permissions);
        } else {
            $this->command->error('   ❌ Diocese Budget Management module not found');
        }

        // Budget Settings, under the diocese Settings group.
        $groupId = DB::table('module_groups')->where('territory_scope', 'diocese')->where('name', 'Settings')->value('id');
        if (! $groupId) {
            $this->command->error('   ❌ Diocese Settings module group not found');

            return;
        }
        $module = Module::firstOrCreate(
            ['module_group_id' => $groupId, 'name' => 'Budget Settings'],
            ['icon' => 'ri-settings-3-line', 'number' => 2, 'is_active' => true, 'description' => 'The budget types, categories and lines every budget is built from.'],
        );
        foreach ([
            'budgettype' => ['Budget Types', 'budget-type.php'],
            'budgetcategory' => ['Budget Categories', 'budget-category.php'],
            'budgetline' => ['Budget Lines', 'budget-line.php'],
        ] as $key => [$title, $file]) {
            $submodule = Submodule::firstOrCreate(
                ['module_id' => $module->id, 'path' => "/diocese/settings/budget-settings/{$file}"],
                ['title' => $title, 'is_active' => true, 'description' => $title],
            );
            $permissions = collect(['read', 'create', 'update', 'delete'])->map(fn ($action) => Permission::firstOrCreate(
                ['name' => "diocese.settings.budgetsettings.{$key}.{$action}", 'guard_name' => 'web'],
                ['module_id' => $module->id, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => $action, 'territory_scope' => 'diocese'],
            ));
            $this->grant($roles, $permissions);
        }
        $this->command->info("   ✅ Diocese: {$module->name} (types, categories, lines)");
    }

    private function grant($roles, $permissions): void
    {
        foreach ($roles as $role) {
            $missing = $permissions->reject(fn ($p) => $role->hasPermissionTo($p));
            if ($missing->isNotEmpty()) {
                $role->givePermissionTo($missing->all());
                $this->command->info("      → {$role->name}: ".$missing->pluck('name')->implode(', '));
            }
        }
    }
}
