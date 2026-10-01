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
 * Later phases add Overview, Spending and Reports next to Budgets.
 *
 * Idempotent - safe to re-run. Users log out and back in for the new menu.
 */
class BudgetsAccessSeeder extends Seeder
{
    /** Which permissions each role gets, per level. */
    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['read', 'prepare', 'export'],
            'Associate Pastor' => ['read', 'prepare', 'export'],
            'Church Treasurer' => ['read', 'prepare', 'export'],
            'Church Administrator' => ['read', 'prepare'],
            'Church Secretary' => ['read'],
            'Church Committee Member' => ['read'],
        ],
        'region' => [
            'Regional Overseer' => ['read', 'prepare', 'export', 'below'],
            'Regional Treasurer' => ['read', 'prepare', 'export', 'below'],
            'Regional Secretary' => ['read', 'prepare', 'below'],
            'Regional Coordinator' => ['read', 'below'],
            'Regional Committee Member' => ['read', 'below'],
        ],
        'diocese' => [
            'Bishop' => ['read', 'prepare', 'export', 'below'],
            'Diocese Treasurer' => ['read', 'prepare', 'export', 'below'],
            'Diocese Finance Officer' => ['read', 'prepare', 'export', 'below'],
            'Diocese Secretary' => ['read', 'prepare', 'below'],
            'Diocese Administrator' => ['read', 'prepare', 'below'],
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
        'export' => ['budgets.budgets.export' => 'budgets'],
        'below' => ['budgets.below.read' => 'budgets'],
    ];

    /** The Budgets module's pages, per level (the sidebar lists them by title). */
    private const PAGES = [
        'overview' => ['Overview', 'overview.php', 'This month or year at a glance: planned, received, spent, what we noticed'],
        'spending' => ['Spending', 'spending.php', 'Record money in and out, and see every entry'],
        'new' => ['New budget', 'form.php', 'Plan a month or a year, step by step'],
    ];

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
                $pages[$key] = Submodule::updateOrCreate(
                    ['module_id' => $submodule->module_id, 'path' => dirname($submodule->path).'/'.$file],
                    ['title' => $title, 'is_active' => true, 'description' => $description],
                );
            }
            $this->permissions($level, $pages);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done - users log out and back in to see the new menu');
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
