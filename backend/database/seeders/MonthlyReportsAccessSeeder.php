<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\ReportsAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Monthly reports menu and permissions
 * (docs/specs/monthly-reports-spec.md).
 *
 * Each level's Overview group gets a "Monthly reports" module with one page,
 * /{level}/monthly-reports/, and {level}.reports.monthly.* (church and
 * region write their own) and {level}.reports.below.* (region and diocese
 * read the places below) linked to it. The empty placeholders are reused:
 * the church's "Church Reports" and the region's "Regional Reporting".
 *
 * Idempotent - safe to re-run.
 */
class MonthlyReportsAccessSeeder extends Seeder
{
    private const MODULE_NAME = 'Monthly reports';

    private const MODULE_ICON = 'ri-file-chart-line';

    /** The old placeholder each level reuses (matched by name). */
    private const REUSE = ['church' => 'Church Reports', 'region' => 'Regional Reporting'];

    /** The abilities each level has at all. */
    private const LEVEL_ABILITIES = [
        'church' => ['read', 'write', 'send'],
        'region' => ['read', 'write', 'send', 'below', 'review'],
        'diocese' => ['read', 'below', 'review'],
    ];

    /** Role => abilities, per level. */
    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['read', 'write', 'send'],
            'Associate Pastor' => ['read', 'write', 'send'],
            'Church Secretary' => ['read', 'write', 'send'],
            'Church Administrator' => ['read', 'write', 'send'],
            'Church Treasurer' => ['read'],
            'Church Committee Member' => ['read'],
            'Elder' => ['read'],
            'Deacon' => ['read'],
        ],
        'region' => [
            'Regional Overseer' => ['read', 'write', 'send', 'below', 'review'],
            'Regional Secretary' => ['read', 'write', 'send', 'below', 'review'],
            'Regional Coordinator' => ['read', 'write', 'send', 'below', 'review'],
            'Regional Treasurer' => ['read', 'below'],
            'Regional Committee Member' => ['read', 'below'],
        ],
        'diocese' => [
            'Bishop' => ['read', 'below', 'review'],
            'Diocese Secretary' => ['read', 'below', 'review'],
            'Diocese Administrator' => ['read', 'below', 'review'],
            'Diocese Treasurer' => ['read', 'below'],
            'Diocese Finance Officer' => ['read', 'below'],
            'Diocese Council Member' => ['read', 'below'],
        ],
    ];

    public function run(): void
    {
        $this->command?->info('📝 MONTHLY REPORTS - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        foreach (self::GRANTS as $level => $grants) {
            $group = ModuleGroup::where('slug', "{$level}-overview")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-overview not found - skipping {$level}");

                continue;
            }
            $module = $this->module($level, $group);
            $page = Submodule::updateOrCreate(
                ['module_id' => $module->id, 'path' => "/{$level}/monthly-reports/"],
                ['title' => 'Monthly reports', 'description' => $level === 'diocese' ? 'The monthly reports of the regions and churches.' : 'Our monthly reports, and the places below.', 'is_active' => true],
            );

            $permissions = [];
            foreach (self::LEVEL_ABILITIES[$level] as $ability) {
                $name = "{$level}.".ReportsAccess::ABILITIES[$ability];
                $permissions[$ability] = Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module_id' => $module->id, 'submodule_id' => $page->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level],
                );
            }

            $granted = 0;
            foreach ($grants as $roleName => $roleAbilities) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    continue;
                }
                foreach ($roleAbilities as $ability) {
                    if (isset($permissions[$ability]) && ! $role->hasPermissionTo($permissions[$ability])) {
                        $role->givePermissionTo($permissions[$ability]);
                        $granted++;
                    }
                }
            }
            $this->command?->info("   ✅ {$level}: Monthly reports page, {$granted} new grant(s)");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The level's module: the old placeholder renamed (its unbuilt pages switched off), or a new one. */
    private function module(string $level, ModuleGroup $group): Module
    {
        $module = Module::where('module_group_id', $group->id)->where('name', self::MODULE_NAME)->first();
        $old = isset(self::REUSE[$level]) ? Module::where('name', self::REUSE[$level])->first() : null;
        if (! $module && $old) {
            $module = $old;
        }
        $module ??= new Module(['module_group_id' => $group->id, 'number' => 0]);
        $module->forceFill([
            'name' => self::MODULE_NAME,
            'module_group_id' => $group->id,
            'icon' => self::MODULE_ICON,
            'description' => 'Each month\'s report: figures filled in, the pastor\'s words, sent to the place above.',
            'is_active' => true,
        ])->save();

        $unbuilt = Submodule::where('module_id', $module->id)->where('path', '!=', "/{$level}/monthly-reports/");
        SubSubmodule::whereIn('submodule_id', (clone $unbuilt)->pluck('id'))->update(['is_active' => false]);
        $unbuilt->update(['is_active' => false]);

        return $module;
    }
}
