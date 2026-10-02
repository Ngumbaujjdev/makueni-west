<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Budgets for every level - church, region and diocese - each running its
 * own, with no approval (docs/specs/budgets-spec.md). Sets up, per level:
 * - the Finance → Budgets menu (the sidebar is built from these rows);
 * - the {level}.budgets.* permissions and which roles hold them.
 *
 * Reuses the existing budget menu of each level: the church "Budget"
 * module, the diocese "Diocese Budget Management" module and the region's
 * placeholder "Regional Finances" module, whose pages were never built.
 * Overview, Spending, New budget and Reports sit next to Budgets; a region
 * and the diocese also get the read-only page of the places below them.
 *
 * Idempotent - safe to re-run. Users log out and back in for the new menu.
 */
class BudgetsAccessSeeder extends Seeder
{
    /**
     * Permission names from before the overhaul that nothing checks any more
     * (the approval workflow, the old Budget Management menus, the old
     * Budget Lines / Types / Categories pages). Retired on every run, so a
     * seeder that still creates them can't bring them back.
     */
    private const RETIRED_PERMISSIONS = [
        'financialmanagement.budgetmanagement.%',
        'diocesebudgetmanagement.%',
        'diocese.budgetmanagement.budgetoverview.%',
        'church.settings.budgetsettings.budgetlines.%',
        'diocese.settings.budgetsettings.budgettype.%',
        'diocese.settings.budgetsettings.budgetcategory.%',
        'diocese.settings.budgetsettings.budgetline.%',
    ];

    /** The old settings pages, now redirects to Budget Settings - out of the menu. */
    private const RETIRED_PAGES = [
        '/church/settings/budget-settings/budget-lines.php',
        '/diocese/settings/budget-settings/budget-type.php',
        '/diocese/settings/budget-settings/budget-category.php',
        '/diocese/settings/budget-settings/budget-line.php',
    ];

    /** Which permissions each role gets, per level. */
    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['read', 'prepare', 'export', 'settings'],
            'Associate Pastor' => ['read', 'prepare', 'export', 'settings'],
            'Church Treasurer' => ['read', 'prepare', 'export', 'settings'],
            'Church Administrator' => ['read', 'prepare', 'settings'],
            'Church Secretary' => ['read'],
            'Church Committee Member' => ['read'],
        ],
        'region' => [
            'Regional Overseer' => ['read', 'prepare', 'export', 'below', 'settings'],
            'Regional Treasurer' => ['read', 'prepare', 'export', 'below', 'settings'],
            'Regional Secretary' => ['read', 'prepare', 'below'],
            'Regional Coordinator' => ['read', 'below'],
            'Regional Committee Member' => ['read', 'below'],
        ],
        'diocese' => [
            'Bishop' => ['read', 'prepare', 'export', 'below', 'settings'],
            'Diocese Treasurer' => ['read', 'prepare', 'export', 'below', 'settings'],
            'Diocese Finance Officer' => ['read', 'prepare', 'export', 'below', 'settings'],
            'Diocese Secretary' => ['read', 'prepare', 'below'],
            'Diocese Administrator' => ['read', 'prepare', 'below', 'settings'],
            'Diocese Council Member' => ['read', 'below'],
        ],
    ];

    /**
     * What each grant gives - permission => the menu page it belongs to.
     * Reading budgets includes their Overview and Spending; preparing
     * includes recording money in and out.
     */
    private const PERMISSIONS = [
        'read' => ['budgets.budgets.read' => 'budgets', 'budgets.overview.read' => 'overview', 'budgets.spending.read' => 'spending'],
        // Preparing is linked to the New budget page, so only people who can prepare see it in the menu.
        'prepare' => ['budgets.budgets.prepare' => 'new', 'budgets.spending.record' => 'spending'],
        // Exporting is linked to the Reports page, so only people who can export see it.
        'export' => ['budgets.budgets.export' => 'reports'],
        // Seeing the places below is linked to its own page (region and diocese only).
        'below' => ['budgets.below.read' => 'below'],
        // Budget Settings (lines and deductions), on its own page under Settings.
        'settings' => ['settings.budgetsettings.read' => 'settings', 'settings.budgetsettings.update' => 'settings'],
    ];

    /** The Budgets module's pages, per level (the sidebar lists them by title). */
    private const PAGES = [
        'overview' => ['Overview', 'overview.php', 'This month or year at a glance: planned, received, spent, what we noticed'],
        'spending' => ['Spending', 'spending.php', 'Record money in and out, and see every entry'],
        'new' => ['New budget', 'form.php', 'Plan a month or a year, step by step'],
        'reports' => ['Reports', 'reports.php', 'Budget reports as PDF or Excel: summary, money in and out, one budget'],
        'below' => ['Churches\' budgets', 'below.php', 'The budgets of the places below, read-only: who has one, received, spent, still owed'],
    ];

    /** The places-below page is a region's and the diocese's only, named for what it shows. */
    private const BELOW_TITLES = ['region' => 'Churches\' budgets', 'diocese' => 'Regions and churches'];

    public function run(): void
    {
        $this->command->info('💰 BUDGETS (church, region, diocese)');

        foreach (['church', 'region', 'diocese'] as $level) {
            $submodule = $this->menu($level);
            if (! $submodule) {
                continue;
            }
            $pages = ['budgets' => $submodule];
            foreach (self::PAGES as $key => [$title, $file, $description]) {
                if ($key === 'below' && ! isset(self::BELOW_TITLES[$level])) {
                    continue;
                }
                $title = $key === 'below' ? self::BELOW_TITLES[$level] : $title;
                $pages[$key] = Submodule::updateOrCreate(
                    ['module_id' => $submodule->module_id, 'path' => dirname($submodule->path).'/'.$file],
                    ['title' => $title, 'is_active' => true, 'description' => $description],
                );
            }
            if ($settings = $this->settingsMenu($level)) {
                $pages['settings'] = $settings;
            }
            $this->permissions($level, $pages);
        }

        $this->retire();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done - users log out and back in to see the new menu');
    }

    /**
     * Settings → Budget Settings for a level: one page with the place's lines
     * and deductions. The old Budget Lines / Types / Categories pages leave
     * the menu in retire() - they redirect here.
     */
    private function settingsMenu(string $level): ?Submodule
    {
        $groupId = match ($level) {
            'church' => DB::table('module_groups')->where('slug', 'church-settings')->value('id'),
            'region' => DB::table('module_groups')->where('slug', 'region-settings')->value('id'),
            'diocese' => DB::table('module_groups')->where('territory_scope', 'diocese')->where('name', 'Settings')->value('id'),
        };
        if (! $groupId) {
            $this->command->error("   ❌ {$level}: settings menu group not found");

            return null;
        }
        $module = Module::firstOrCreate(
            ['module_group_id' => $groupId, 'name' => 'Budget Settings'],
            ['icon' => 'ri-settings-3-line', 'number' => 3, 'is_active' => true, 'description' => 'The lines and deductions budgets are built from'],
        );
        $module->update(['is_active' => true]);
        $path = match ($level) {
            'church' => '/church/settings/budget-settings/index.php',
            'region' => '/region/settings/budget-settings/index.php',
            'diocese' => '/diocese/settings/budget-settings/index.php',
        };
        $submodule = Submodule::updateOrCreate(
            ['module_id' => $module->id, 'path' => $path],
            ['title' => 'Lines and deductions', 'is_active' => true, 'description' => 'Money in and money out lines, and the shares worked out from money in'],
        );
        $this->command->info("   ✅ {$level}: Settings → Budget Settings → {$submodule->title} ({$path})");

        return $submodule;
    }

    /** Old permission names and pages out: the permissions deleted (and taken off roles), the pages off the menu. */
    private function retire(): void
    {
        $ids = Permission::where(function ($q) {
            foreach (self::RETIRED_PERMISSIONS as $pattern) {
                $q->orWhere('name', 'like', $pattern);
            }
        })->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            Permission::whereIn('id', $ids)->delete();
            $this->command->info("   🧹 Retired {$ids->count()} old budget permissions");
        }
        $pages = Submodule::whereIn('path', self::RETIRED_PAGES)->where('is_active', true)->update(['is_active' => false]);
        if ($pages) {
            $this->command->info("   🧹 {$pages} old budget settings pages taken off the menu");
        }
    }

    /** Finance → Budgets → Budgets for a level; returns the Budgets submodule. */
    private function menu(string $level): ?Submodule
    {
        $path = match ($level) {
            'church' => '/church/budget/budgets.php',
            'region' => '/region/budgets/budgets.php',
            'diocese' => '/diocese/budgets/budgets.php',
        };

        $module = match ($level) {
            'church' => Submodule::whereIn('path', ['/church/budget/all-budgets.php', '/finance/budget', $path])->first()?->module,
            'region' => Module::where('name', 'Regional Finances')->first() ?? Submodule::where('path', $path)->first()?->module,
            'diocese' => Submodule::whereIn('path', ['/diocese/budget-management/budget-overview/all-budgets.php', '/diocese/financial/budget/planning', $path])->first()?->module,
        };
        if (! $module) {
            $this->command->error("   ❌ {$level}: budget module not found");

            return null;
        }
        $module->update(['name' => 'Budgets', 'is_active' => true, 'description' => 'Plan a month or a year, and see how the money is going']);

        // The Budgets page: reuse the existing budget list entry where there is one.
        $submodule = Submodule::where('module_id', $module->id)
            ->whereIn('path', ['/church/budget/all-budgets.php', '/diocese/budget-management/budget-overview/all-budgets.php', $path])
            ->first()
            ?? new Submodule(['module_id' => $module->id]);
        $submodule->fill(['title' => 'Budgets', 'path' => $path, 'is_active' => true, 'description' => 'Every budget of this '.$level.': prepare, start using, close'])->save();

        // Placeholder pages that were never built stay out of the menu.
        $ours = array_map(fn ($page) => dirname($path).'/'.$page[1], self::PAGES);
        Submodule::where('module_id', $module->id)->whereKeyNot($submodule->id)->whereNotIn('path', $ours)->update(['is_active' => false]);
        DB::table('sub_submodules')->whereIn('submodule_id', Submodule::where('module_id', $module->id)->pluck('id'))->update(['is_active' => false, 'updated_at' => now()]);

        $this->command->info("   ✅ {$level}: Finance → {$module->name} → {$submodule->title} ({$path})");

        return $submodule;
    }

    /** @param array<string, Submodule> $pages */
    private function permissions(string $level, array $pages): void
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $ability => $names) {
            if ($ability === 'below' && $level === 'church') {
                continue;
            }
            foreach ($names as $name => $page) {
                $permissions[$ability][] = Permission::updateOrCreate(
                    ['name' => "{$level}.{$name}", 'guard_name' => 'web'],
                    ['module_id' => $pages[$page]->module_id, 'submodule_id' => $pages[$page]->id, 'sub_submodule_id' => null, 'action' => substr(strrchr($name, '.'), 1), 'territory_scope' => $level],
                );
            }
        }

        foreach (self::GRANTS[$level] as $roleName => $abilities) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            $wanted = collect($abilities)->flatMap(fn ($a) => $permissions[$a] ?? []);
            $missing = $wanted->reject(fn ($p) => $role->hasPermissionTo($p));
            if ($missing->isNotEmpty()) {
                $role->givePermissionTo($missing->all());
                $this->command->info("      → {$roleName}: ".$missing->pluck('name')->implode(', '));
            }
        }
    }
}
