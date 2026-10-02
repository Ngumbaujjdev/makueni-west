<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The Settings hub's menu and permissions (docs/specs/settings-spec.md).
 *
 * Each level's "Settings" group gets one "Settings" module whose pages are
 * the hub's sections (Overview, Profile, Service times, ...), read from
 * config/settings.php - so a new section only needs a config entry and a
 * re-run. Each section page has {level}.settings.hub.{section}.{action}
 * permissions linked to it, so the sidebar only offers what a role can open.
 *
 * Who gets what (ROLES): the leaders who run a place can change its
 * settings; the other office holders can look. A section can add roles
 * with config 'grants' => ['update' => ['church' => ['Church Treasurer']]].
 *
 * Existing settings pages (Budget Settings, Gathering Types, Recording
 * Cadence - config 'kind' => 'link') are moved in rather than copied: their
 * menu row (found by its path, 'absorbs') goes under Settings with its
 * permissions, and the module it came from is switched off once nothing in
 * it is still on the menu. The page and its permission names don't change.
 *
 * Idempotent - safe to re-run; it also brings paths and labels up to date.
 */
class SettingsHubSeeder extends Seeder
{
    private const MODULE_NAME = 'Settings';

    private const MODULE_ICON = 'ri-settings-4-line';

    private const ROLES = [
        'church' => [
            'update' => ['Senior Pastor', 'Church Administrator'],
            'read' => ['Associate Pastor', 'Youth Pastor', 'Church Secretary', 'Church Treasurer'],
        ],
        'region' => [
            'update' => ['Regional Overseer'],
            'read' => ['Regional Secretary', 'Regional Treasurer'],
        ],
        'diocese' => [
            'update' => ['Bishop', 'Diocese Administrator'],
            'read' => ['Diocese Secretary', 'Diocese Treasurer', 'Diocese Council Member'],
        ],
    ];

    /**
     * Old menu rows that opened empty pages, replaced by hub sections (S4b):
     * General Configuration -> Profile/Email/SMS, Notifications -> Email/SMS,
     * Security Settings -> Security, System Maintenance -> Maintenance.
     * Switched off, not deleted, so their permissions keep their history.
     */
    private const RETIRED = [
        'submodules' => ['/diocese/settings/general', '/diocese/settings/compliance', '/diocese/settings/notifications', '/diocese/settings/support'],
        'sub_submodules' => ['/diocese/settings/general/info', '/diocese/settings/general/communication', '/diocese/settings/general/financial', '/diocese/settings/admin/security', '/diocese/settings/admin/maintenance'],
    ];

    public function run(): void
    {
        $this->command?->info('⚙️  SETTINGS HUB - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

        $retired = Submodule::whereIn('path', self::RETIRED['submodules'])->where('is_active', true)->update(['is_active' => false])
            + SubSubmodule::whereIn('path', self::RETIRED['sub_submodules'])->where('is_active', true)->update(['is_active' => false]);
        if ($retired) {
            $this->command?->info("   ✅ {$retired} empty settings page(s) switched off");
        }

        foreach (SettingsRegistry::LEVELS as $level) {
            $group = ModuleGroup::where('slug', "{$level}-settings")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-settings not found - skipping {$level}");

                continue;
            }

            $module = Module::firstOrCreate(
                ['name' => self::MODULE_NAME, 'module_group_id' => $group->id],
                ['icon' => self::MODULE_ICON, 'number' => 0, 'description' => 'Your place\'s profile, people and setup, in one place.', 'is_active' => true],
            );

            $granted = 0;
            foreach (SettingsRegistry::sections($level) as $key => $section) {
                if (($section['kind'] ?? null) === 'link' || isset($section['absorbs'][$level])) {
                    $this->absorb($module, $level, $key, $section);

                    continue;
                }
                $submodule = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'title' => $section['label']],
                    ['path' => $this->path($level, $key, $section), 'description' => $section['sentence'] ?? $section['label'], 'is_active' => true],
                );

                foreach ($section['actions'] ?? ['read', 'update'] as $action) {
                    $permission = Permission::firstOrCreate(
                        ['name' => SettingsRegistry::permission($level, $key, $action)],
                        ['guard_name' => 'web', 'module_id' => $module->id, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => $action, 'territory_scope' => $level],
                    );
                    $permission->forceFill(['module_id' => $module->id, 'submodule_id' => $submodule->id])->save();

                    foreach (Role::whereIn('name', $this->rolesFor($level, $action, $section))->get() as $role) {
                        if (! $role->hasPermissionTo($permission)) {
                            $role->givePermissionTo($permission);
                            $granted++;
                        }
                    }
                }
            }

            $this->command?->info("   ✅ {$level}: ".count(SettingsRegistry::sections($level))." section page(s), {$granted} new grant(s)");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('✅ Settings hub menu ready.');
    }

    /** Move an existing settings page's menu row (and its permissions) under the Settings module. */
    private function absorb(Module $settings, string $level, string $key, array $section): void
    {
        $path = $section['absorbs'][$level] ?? null;
        $page = $path ? Submodule::where('path', $path)->first() : null;
        if (! $page) {
            $this->command?->warn("   ⚠️  {$level}: {$section['label']} page ({$path}) isn't on the menu yet - run its own seeder first");

            return;
        }
        $from = $page->module_id;
        if ((int) $from !== (int) $settings->id) {
            $page->forceFill(['module_id' => $settings->id])->save();
            Permission::where('submodule_id', $page->id)->update(['module_id' => $settings->id]);
            if (! Submodule::where('module_id', $from)->where('is_active', true)->exists()) {
                // e.g. "Diocese Settings", once System Administration has moved and its empty pages are off.
                Module::whereKey($from)->update(['is_active' => false]);
            }
        }
        // A link keeps its own page; a hub section (Access control) opens the hub on it.
        $page->forceFill(['title' => $section['label'], 'is_active' => true]
            + (($section['kind'] ?? null) === 'link' ? [] : ['path' => $this->path($level, $key, $section)]))->save();
    }

    /** The overview opens the hub itself; other sections open it on their section, links open their own page. */
    private function path(string $level, string $key, array $section): string
    {
        if (($section['kind'] ?? null) === 'link' && isset($section['url'][$level])) {
            return $section['url'][$level];
        }

        return "/{$level}/settings/".($key === 'overview' ? '' : "?section={$key}");
    }

    /** read goes to readers and changers; any other action to the changers (plus a section's extra grants). */
    private function rolesFor(string $level, string $action, array $section): array
    {
        $changers = [...self::ROLES[$level]['update'], ...($section['grants']['update'][$level] ?? [])];
        $extra = $section['grants'][$action][$level] ?? [];

        return array_values(array_unique($action === 'read'
            ? [...$changers, ...self::ROLES[$level]['read'], ...$extra]
            : [...($action === 'update' ? $changers : self::ROLES[$level]['update']), ...$extra]));
    }
}
