<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\CalendarAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Calendar's menu and permissions (docs/specs/calendar-spec.md).
 *
 * Each level's Programs group gets one "Calendar" module with the calendar,
 * /{level}/calendar/ ({level}.calendar.events.read), and "New date", the
 * calendar with its form open ({level}.calendar.events.manage). Everyone at
 * a level can read; the people in MANAGERS add their place's events. The diocese also gets a "CCI national calendar" page for
 * global admins (no permission - global admins see every page).
 *
 * The old "Diocese Calendar" module (views, management, sync, meetings,
 * reports - all empty pages) is switched off. Idempotent - safe to re-run.
 */
class CalendarAccessSeeder extends Seeder
{
    private const MODULE_NAME = 'Calendar';

    private const MODULE_ICON = 'ri-calendar-event-line';

    private const MANAGERS = [
        'church' => ['Senior Pastor', 'Church Administrator', 'Church Secretary'],
        'region' => ['Regional Overseer', 'Regional Secretary'],
        'diocese' => ['Bishop', 'Diocese Administrator', 'Diocese Secretary'],
    ];

    /** The old, empty calendar module. */
    private const RETIRED_MODULE = 'Diocese Calendar';

    public function run(): void
    {
        $this->command?->info('📅 CALENDAR - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        foreach (array_keys(self::MANAGERS) as $level) {
            $group = ModuleGroup::where('slug', "{$level}-programs")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-programs not found - skipping {$level}");

                continue;
            }
            $module = Module::firstOrCreate(
                ['name' => self::MODULE_NAME, 'module_group_id' => $group->id],
                ['icon' => self::MODULE_ICON, 'number' => 0, 'description' => 'Your events, and the CCI, diocese and regional calendars above you.', 'is_active' => true],
            );
            $module->forceFill(['is_active' => true])->save();
            $page = Submodule::updateOrCreate(
                ['module_id' => $module->id, 'path' => "/{$level}/calendar/"],
                ['title' => 'Calendar', 'description' => 'Month, week and list views of every event you can see.', 'is_active' => true],
            );
            // The way in for those who can add: the calendar with its form open.
            $add = Submodule::updateOrCreate(
                ['module_id' => $module->id, 'path' => "/{$level}/calendar/?add=1"],
                ['title' => 'New date', 'description' => 'Put a service, meeting or other date on the calendar.', 'is_active' => true],
            );
            if ($level === 'diocese') {
                Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => '/diocese/calendar/?tab=cci'],
                    ['title' => 'CCI national calendar', 'description' => 'The national calendar of Christian Church International - global admins.', 'is_active' => true],
                );
            }

            $granted = 0;
            foreach (['read', 'manage'] as $action) {
                // Each permission sits on the page it opens: read on the
                // calendar, manage on "New date".
                $on = $action === 'manage' ? $add : $page;
                $permission = Permission::firstOrCreate(
                    ['name' => CalendarAccess::permission($level, $action)],
                    ['guard_name' => 'web', 'module_id' => $module->id, 'submodule_id' => $on->id, 'sub_submodule_id' => null, 'action' => $action, 'territory_scope' => $level],
                );
                $permission->forceFill(['module_id' => $module->id, 'submodule_id' => $on->id])->save();
                $roles = $action === 'read'
                    ? Role::where('territory_level', $level)->get()
                    : Role::whereIn('name', self::MANAGERS[$level])->get();
                foreach ($roles as $role) {
                    if (! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                        $granted++;
                    }
                }
            }
            $this->command?->info("   ✅ {$level}: Calendar and New date pages, {$granted} new grant(s)");
        }

        $old = Module::where('name', self::RETIRED_MODULE)->get();
        foreach ($old as $module) {
            $pages = Submodule::where('module_id', $module->id);
            SubSubmodule::whereIn('submodule_id', (clone $pages)->pluck('id'))->update(['is_active' => false]);
            $pages->update(['is_active' => false]);
            $module->forceFill(['is_active' => false])->save();
        }
        if ($old->isNotEmpty()) {
            $this->command?->info('   ✅ The old, empty "'.self::RETIRED_MODULE.'" module is switched off');
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
