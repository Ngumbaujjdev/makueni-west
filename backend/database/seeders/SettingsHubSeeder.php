<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
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

    public function run(): void
    {
        $this->command?->info('⚙️  SETTINGS HUB - menu and permissions per level');
        $this->command?->info(str_repeat('=', 70));

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
