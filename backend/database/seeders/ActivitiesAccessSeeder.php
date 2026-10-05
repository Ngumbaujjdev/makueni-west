<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\EventsAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Events menu and permissions (docs/specs/events-initiatives-spec.md).
 *
 * Each level's Programs group gets an "Events" module with one page,
 * /{level}/events/, and {level}.events.events.read / manage / register
 * (plus .events.below.read for region and diocese) linked to it. The empty
 * placeholder modules are reused rather than duplicated: the diocese's
 * "Diocese Events Management" and the region's "Regional Programs" become
 * Events, and their unbuilt sub-pages are switched off.
 *
 * Idempotent - safe to re-run.
 */
class ActivitiesAccessSeeder extends Seeder
{
    private const MODULE_NAME = 'Events';

    private const MODULE_ICON = 'ri-calendar-star-line';

    /** The old placeholder module each level reuses (matched by name). */
    private const REUSE = [
        'diocese' => 'Diocese Events Management',
        'region' => 'Regional Programs',
    ];

    /** Role => abilities (EventsAccess::ABILITIES keys), per level. */
    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['read', 'manage', 'register'],
            'Associate Pastor' => ['read', 'manage', 'register'],
            'Church Secretary' => ['read', 'manage', 'register'],
            'Church Administrator' => ['read', 'manage', 'register'],
            'Youth Pastor' => ['read', 'register'],
            'Youth Leader' => ['read', 'register'],
            "Women's Ministry Leader" => ['read', 'register'],
            "Men's Ministry Leader" => ['read', 'register'],
            "Children's Ministry Leader" => ['read', 'register'],
            'Church Treasurer' => ['read'],
            'Church Committee Member' => ['read'],
            'Elder' => ['read'],
            'Deacon' => ['read'],
        ],
        'region' => [
            'Regional Overseer' => ['read', 'manage', 'register', 'below'],
            'Regional Secretary' => ['read', 'manage', 'register', 'below'],
            'Regional Coordinator' => ['read', 'manage', 'register', 'below'],
            'Regional Treasurer' => ['read', 'below'],
            'Regional Committee Member' => ['read', 'below'],
        ],
        'diocese' => [
            'Bishop' => ['read', 'manage', 'register', 'below'],
            'Diocese Secretary' => ['read', 'manage', 'register', 'below'],
            'Diocese Administrator' => ['read', 'manage', 'register', 'below'],
            'Diocese Treasurer' => ['read', 'below'],
            'Diocese Finance Officer' => ['read', 'below'],
            'Diocese Council Member' => ['read', 'below'],
        ],
    ];

    public function run(): void
    {
        $this->command?->info('🎉 EVENTS - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        foreach (self::GRANTS as $level => $grants) {
            $group = ModuleGroup::where('slug', "{$level}-programs")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-programs not found - skipping {$level}");

                continue;
            }
            $module = $this->module($level, $group);
            $page = Submodule::updateOrCreate(
                ['module_id' => $module->id, 'path' => "/{$level}/events/"],
                ['title' => 'Events', 'description' => 'Our events, invitations from above, and who is coming.', 'is_active' => true],
            );

            $abilities = $level === 'church' ? ['read', 'manage', 'register'] : ['read', 'manage', 'register', 'below'];
            $permissions = [];
            foreach ($abilities as $ability) {
                $name = "{$level}.".EventsAccess::ABILITIES[$ability];
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
            $this->command?->info("   ✅ {$level}: Events page, {$granted} new grant(s)");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The level's Events module: the old placeholder renamed (its unbuilt pages switched off), or a new one. */
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
            'description' => 'Events of this place, invitations from above, and who is coming.',
            'is_active' => true,
        ])->save();

        // The placeholder's unbuilt pages (planning, execution, conferences...) go off.
        $unbuilt = Submodule::where('module_id', $module->id)->where('path', '!=', "/{$level}/events/");
        SubSubmodule::whereIn('submodule_id', (clone $unbuilt)->pluck('id'))->update(['is_active' => false]);
        $unbuilt->update(['is_active' => false]);

        return $module;
    }
}
