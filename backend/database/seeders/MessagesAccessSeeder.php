<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\MessagesAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Messages menu and permissions (docs/specs/messages-spec.md).
 *
 * Each level's Programs group gets a "Messages" module with one page,
 * /{level}/messages/, and {level}.messages.messages.read / send (the leaders)
 * and {level}.messages.inbox.read (every role at the level). The empty
 * placeholders are reused: the diocese's "Diocese Communications Hub" and
 * the church's "Communication".
 *
 * Idempotent - safe to re-run.
 */
class MessagesAccessSeeder extends Seeder
{
    private const MODULE_NAME = 'Messages';

    private const MODULE_ICON = 'ri-chat-3-line';

    private const REUSE = ['diocese' => 'Diocese Communications Hub', 'church' => 'Communication'];

    /** Who sends, per level. Everyone at the level gets the Inbox. */
    private const SENDERS = [
        'church' => ['Senior Pastor', 'Associate Pastor', 'Church Secretary', 'Church Administrator'],
        'region' => ['Regional Overseer', 'Regional Secretary', 'Regional Coordinator'],
        'diocese' => ['Bishop', 'Diocese Secretary', 'Diocese Administrator'],
    ];

    public function run(): void
    {
        $this->command?->info('💬 MESSAGES - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        foreach (self::SENDERS as $level => $senders) {
            $group = ModuleGroup::where('slug', "{$level}-programs")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-programs not found - skipping {$level}");

                continue;
            }
            $module = $this->module($level, $group);
            $page = Submodule::updateOrCreate(
                ['module_id' => $module->id, 'path' => "/{$level}/messages/"],
                ['title' => 'Messages', 'description' => 'The Inbox, sending to our leaders and the places below, and saved messages.', 'is_active' => true],
            );

            $permissions = [];
            foreach (['read', 'send', 'inbox'] as $ability) {
                $name = "{$level}.".MessagesAccess::ABILITIES[$ability];
                $permissions[$ability] = Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module_id' => $module->id, 'submodule_id' => $page->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level],
                );
            }

            $granted = 0;
            $give = function (Role $role, string $ability) use ($permissions, &$granted) {
                if (! $role->hasPermissionTo($permissions[$ability])) {
                    $role->givePermissionTo($permissions[$ability]);
                    $granted++;
                }
            };
            foreach (Role::where('territory_level', $level)->get() as $role) {
                $give($role, 'inbox');
            }
            foreach ($senders as $name) {
                if ($role = Role::where('name', $name)->first()) {
                    $give($role, 'read');
                    $give($role, 'send');
                    $give($role, 'inbox');
                }
            }
            $this->command?->info("   ✅ {$level}: Messages page, {$granted} new grant(s)");
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
            'description' => 'Send to our leaders and the places below by SMS, email or in the app; the Inbox and replies.',
            'is_active' => true,
        ])->save();

        $unbuilt = Submodule::where('module_id', $module->id)->where('path', '!=', "/{$level}/messages/");
        SubSubmodule::whereIn('submodule_id', (clone $unbuilt)->pluck('id'))->update(['is_active' => false]);
        $unbuilt->update(['is_active' => false]);

        return $module;
    }
}
