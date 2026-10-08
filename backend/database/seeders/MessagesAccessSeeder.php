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
 * Each level has a "Communication" module group (after Programs, 2026-10-08)
 * holding the "Messages" module and its pages, each with the permission that
 * opens it:
 *   Inbox            /{level}/messages/              {level}.messages.inbox.read (every role)
 *   Send a message   /{level}/messages/new.php       {level}.messages.messages.send
 *   Campaigns        /{level}/messages/campaigns.php {level}.messages.campaigns.read
 *   Templates        /{level}/messages/templates.php {level}.messages.templates.manage
 *   Message log      /{level}/messages/log.php       {level}.messages.log.read
 * plus {level}.messages.messages.read (on the Inbox) for the API. The module
 * is found by its Inbox page, so moving it never makes a second one; the old
 * placeholders ("Diocese Communications Hub", the church's "Communication")
 * are reused the first time.
 *
 * Idempotent - safe to re-run.
 */
class MessagesAccessSeeder extends Seeder
{
    private const MODULE_NAME = 'Messages';

    private const MODULE_ICON = 'ri-chat-3-line';

    /** The Messages pages: key => [file, title, description]. */
    private const PAGES = [
        'inbox' => ['', 'Inbox', 'Messages sent to us, and our replies.'],
        'send' => ['new.php', 'Send a message', 'One message or a whole campaign - by SMS, email or in the app.'],
        'campaigns' => ['campaigns.php', 'Campaigns', 'Everything we have sent out, with who it reached.'],
        'templates' => ['templates.php', 'Templates', 'Ready-made messages - the diocese\'s and our own.'],
        'log' => ['log.php', 'Message log', 'Every email and SMS that went out.'],
    ];

    /** The page permissions beyond MessagesAccess's three (menu and page access). */
    private const PAGE_PERMISSIONS = [
        'campaigns' => 'messages.campaigns.read',
        'templates' => 'messages.templates.manage',
        'log' => 'messages.log.read',
    ];

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
            $group = $this->group($level);
            if (! $group) {
                $this->command?->error("   ❌ No module groups for {$level} - skipping");

                continue;
            }
            $module = $this->module($level, $group);
            $pages = [];
            foreach (self::PAGES as $key => [$file, $title, $description]) {
                $pages[$key] = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => "/{$level}/messages/{$file}"],
                    ['title' => $title, 'description' => $description, 'is_active' => true],
                );
            }

            // Each permission sits on the page it opens.
            $permissions = [];
            foreach (['read' => 'inbox', 'send' => 'send', 'inbox' => 'inbox', 'campaigns' => 'campaigns', 'templates' => 'templates', 'log' => 'log'] as $ability => $pageKey) {
                $name = "{$level}.".(MessagesAccess::ABILITIES[$ability] ?? self::PAGE_PERMISSIONS[$ability]);
                $permissions[$ability] = Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module_id' => $module->id, 'submodule_id' => $pages[$pageKey]->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level],
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
                    foreach (['read', 'send', 'inbox', 'campaigns', 'templates', 'log'] as $ability) {
                        $give($role, $ability);
                    }
                }
            }
            // Anyone who already reads messages (given by hand) also sees Campaigns and the log.
            foreach (Role::where('territory_level', $level)->get() as $role) {
                if ($role->hasPermissionTo($permissions['read'])) {
                    $give($role, 'campaigns');
                    $give($role, 'log');
                }
                if ($role->hasPermissionTo($permissions['send'])) {
                    $give($role, 'templates');
                }
            }
            $this->command?->info("   ✅ {$level}: Communication > Messages, {$granted} new grant(s)");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The level's "Communication" group, right after Programs (the groups after it move down one). */
    private function group(string $level): ?ModuleGroup
    {
        $programs = ModuleGroup::where('slug', "{$level}-programs")->first();
        if (! $programs && ! ModuleGroup::where('territory_scope', $level)->exists()) {
            return null;
        }
        $group = ModuleGroup::firstOrNew(['slug' => "{$level}-communication"]);
        if (! $group->exists) {
            $order = ($programs?->order ?? ModuleGroup::where('territory_scope', $level)->max('order')) + 1;
            // Make room once: everything from that place down moves one along.
            ModuleGroup::where('territory_scope', $level)->where('order', '>=', $order)->increment('order');
            $group->forceFill(['order' => $order]);
        }
        $group->forceFill(['name' => 'Communication', 'icon' => 'ri-chat-voice-line', 'territory_scope' => $level, 'is_active' => true])->save();

        return $group;
    }

    /** The level's module (found by its Inbox page), moved into the group; else the old placeholder, or a new one. */
    private function module(string $level, ModuleGroup $group): Module
    {
        $module = Submodule::where('path', "/{$level}/messages/")->first()?->module()->first()
            ?? Module::where('module_group_id', $group->id)->where('name', self::MODULE_NAME)->first()
            ?? Module::where('name', self::MODULE_NAME)->whereIn('module_group_id', ModuleGroup::where('territory_scope', $level)->pluck('id'))->first();
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

        $unbuilt = Submodule::where('module_id', $module->id)->whereNotIn('path', array_map(fn ($p) => "/{$level}/messages/{$p[0]}", self::PAGES));
        SubSubmodule::whereIn('submodule_id', (clone $unbuilt)->pluck('id'))->update(['is_active' => false]);
        $unbuilt->update(['is_active' => false]);

        return $module;
    }
}
