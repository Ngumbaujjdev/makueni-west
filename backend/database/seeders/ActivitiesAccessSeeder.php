<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\ActivityAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Events and Initiatives menus and permissions
 * (docs/specs/events-initiatives-spec.md).
 *
 * Each level's Programs group gets an "Events" and an "Initiatives" module,
 * each with one page (/{level}/events/, /{level}/initiatives/) and
 * {level}.{events|initiatives}.*.read / manage / register (plus .below.read
 * for region and diocese) linked to it. The empty placeholder modules are
 * reused rather than duplicated - the diocese's "Diocese Events Management"
 * and "Diocese Initiatives Management" and the region's "Regional Programs" -
 * and their unbuilt sub-pages are switched off.
 *
 * Idempotent - safe to re-run.
 */
class ActivitiesAccessSeeder extends Seeder
{
    /** kind => the module each level gets, and the old placeholder each level reuses (matched by name). */
    private const MODULES = [
        'event' => [
            'name' => 'Events', 'path' => 'events', 'icon' => 'ri-calendar-check-line',
            'description' => 'Events of this place, invitations from above, and who is coming.',
            'page' => 'Our events, invitations from above, and who is coming.',
            'reuse' => ['diocese' => 'Diocese Events Management', 'region' => 'Regional Programs'],
        ],
        'initiative' => [
            'name' => 'Initiatives', 'path' => 'initiatives', 'icon' => 'ri-seedling-line',
            'description' => 'Programmes that meet over time: sessions, attendance and the places taking part.',
            'page' => 'Our initiatives, their sessions, and the places taking part.',
            'reuse' => ['diocese' => 'Diocese Initiatives Management'],
        ],
    ];

    /** Role => abilities (ActivityAccess::ABILITIES keys), per level - the same for both kinds. */
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
        $this->command?->info('🎉 EVENTS AND INITIATIVES - menus and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        foreach (self::GRANTS as $level => $grants) {
            $group = ModuleGroup::where('slug', "{$level}-programs")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-programs not found - skipping {$level}");

                continue;
            }
            foreach (self::MODULES as $kind => $spec) {
                $module = $this->module($level, $group, $spec);
                $page = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => "/{$level}/{$spec['path']}/"],
                    ['title' => $spec['name'], 'description' => $spec['page'], 'is_active' => true],
                );

                $abilities = $level === 'church' ? ['read', 'manage', 'register'] : ['read', 'manage', 'register', 'below'];
                $permissions = [];
                foreach ($abilities as $ability) {
                    $name = "{$level}.".ActivityAccess::permission($kind, $ability);
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
                $this->command?->info("   ✅ {$level}: {$spec['name']} page, {$granted} new grant(s)");
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The level's module for a kind: the old placeholder renamed (its unbuilt pages switched off), or a new one. */
    private function module(string $level, ModuleGroup $group, array $spec): Module
    {
        $module = Module::where('module_group_id', $group->id)->where('name', $spec['name'])->first();
        $old = isset($spec['reuse'][$level]) ? Module::where('name', $spec['reuse'][$level])->first() : null;
        if (! $module && $old) {
            $module = $old;
        }
        $module ??= new Module(['module_group_id' => $group->id, 'number' => 0]);
        $module->forceFill([
            'name' => $spec['name'],
            'module_group_id' => $group->id,
            'icon' => $spec['icon'],
            'description' => $spec['description'],
            'is_active' => true,
        ])->save();

        // The placeholder's unbuilt pages (planning, execution, analytics...) go off.
        $unbuilt = Submodule::where('module_id', $module->id)->where('path', '!=', "/{$level}/{$spec['path']}/");
        SubSubmodule::whereIn('submodule_id', (clone $unbuilt)->pluck('id'))->update(['is_active' => false]);
        $unbuilt->update(['is_active' => false]);

        return $module;
    }
}
