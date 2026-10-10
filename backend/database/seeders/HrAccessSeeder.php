<?php

namespace Database\Seeders;

use App\Models\HrAllowanceType;
use App\Models\HrPosition;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\Territory;
use App\Support\HrAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Staff (docs/specs/hr-spec.md): a "Staff" menu group after Finance at the
 * church, region and diocese, with its two pages and their permissions; who
 * holds them to start with; and the diocese's default positions and
 * allowance types (names only - each level sets its own pay).
 *
 * Idempotent. Each default grant is made once, ever (recorded with the
 * Accounting ones in accounting_seeded_grants): one the admin removes in
 * Roles & permissions is not put back.
 */
class HrAccessSeeder extends Seeder
{
    /** key => [file, title, description] */
    private const PAGES = [
        'staff' => ['', 'Staff', 'The people we employ - their job, grade and pay, where they have served, their papers'],
        'setup' => ['positions.php', 'Positions & pay', 'Our positions, grades and allowances, and those set by the places above'],
    ];

    /** ability => the page it opens */
    private const PERMISSIONS = ['read' => 'staff', 'manage' => 'staff', 'setup' => 'setup', 'below' => 'staff'];

    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['read', 'manage', 'setup'],
            'Church Administrator' => ['read', 'manage', 'setup'],
            'Church Treasurer' => ['read'],
        ],
        'region' => [
            'Regional Overseer' => ['read', 'manage', 'setup', 'below'],
            'Regional Treasurer' => ['read', 'manage', 'setup', 'below'],
            'Regional Secretary' => ['read', 'manage', 'setup', 'below'],
        ],
        'diocese' => [
            'Bishop' => ['read', 'manage', 'setup', 'below'],
            'Diocese Finance Officer' => ['read', 'manage', 'setup', 'below'],
            'Diocese Administrator' => ['read', 'manage', 'setup', 'below'],
        ],
    ];

    /** The diocese's defaults: name => levels it is used at. */
    private const POSITIONS = [
        'Senior Pastor' => ['church'], 'Associate Pastor' => ['church'], 'Youth Pastor' => ['church'], 'Church Secretary' => ['church'],
        'Caretaker' => null, 'Cleaner' => null, 'Night guard' => null, 'Driver' => null, 'Accountant' => null,
        'Regional Overseer' => ['region'], 'Regional Secretary' => ['region'], 'Diocese Administrator' => ['diocese'],
    ];

    private const ALLOWANCES = ['House', 'Transport', 'Responsibility', 'Airtime'];

    public function run(): void
    {
        $this->command?->info('👥 STAFF - menu, permissions and the diocese\'s default positions');
        foreach (self::GRANTS as $level => $grants) {
            $group = $this->group($level);
            if (! $group) {
                $this->command?->error("   ❌ No module groups for {$level} - skipping");

                continue;
            }
            $module = Submodule::where('path', "/{$level}/hr/")->first()?->module()->first()
                ?? Module::where('module_group_id', $group->id)->where('name', 'Staff')->first() ?? new Module(['number' => 0]);
            $module->forceFill(['name' => 'Staff', 'module_group_id' => $group->id, 'icon' => 'ri-team-line', 'description' => 'The people we employ, and the positions and pay each level sets up', 'is_active' => true])->save();
            $pages = [];
            $order = 0;
            foreach (self::PAGES as $key => [$file, $title, $description]) {
                $pages[$key] = Submodule::updateOrCreate(['module_id' => $module->id, 'path' => "/{$level}/hr/{$file}"], ['title' => $title, 'description' => $description, 'is_active' => true, 'order' => ++$order]);
            }
            $permissions = [];
            foreach (self::PERMISSIONS as $ability => $page) {
                if ($ability === 'below' && $level === 'church') {
                    continue;
                }
                $name = "{$level}.".HrAccess::ABILITIES[$ability];
                $permissions[$ability] = Permission::updateOrCreate(['name' => $name, 'guard_name' => 'web'],
                    ['module_id' => $module->id, 'submodule_id' => $pages[$page]->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level]);
            }
            $granted = 0;
            foreach ($grants as $roleName => $abilities) {
                $role = Role::where('name', $roleName)->where('territory_level', $level)->first() ?? Role::where('name', $roleName)->first();
                if ($role) {
                    $granted += $this->grantOnce($role, collect($abilities)->map(fn ($a) => $permissions[$a] ?? null)->filter());
                }
            }
            $this->command?->info("   ✅ {$level}: Staff (".count($pages)." pages), {$granted} new grant(s)");
        }
        $this->defaults();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The diocese's default positions and allowances, once - names only, the pay is each level's. */
    private function defaults(): void
    {
        $diocese = Territory::where('territory_type', 'diocese')->orderBy('id')->first();
        if (! $diocese || ! Schema::hasTable('hr_positions')) {
            return;
        }
        $order = 0;
        foreach (self::POSITIONS as $name => $levels) {
            HrPosition::firstOrCreate(['territory_id' => $diocese->id, 'name' => $name], ['levels' => $levels, 'display_order' => ++$order]);
        }
        $order = 0;
        foreach (self::ALLOWANCES as $name) {
            HrAllowanceType::firstOrCreate(['territory_id' => $diocese->id, 'name' => $name], ['display_order' => ++$order]);
        }
    }

    /** The level's "Staff" group, right after Finance (the groups after it move down one). */
    private function group(string $level): ?ModuleGroup
    {
        $finance = ModuleGroup::where('slug', "{$level}-finance")->first();
        if (! $finance && ! ModuleGroup::where('territory_scope', $level)->exists()) {
            return null;
        }
        $group = ModuleGroup::firstOrNew(['slug' => "{$level}-staff"]);
        if (! $group->exists) {
            $order = ($finance?->order ?? ModuleGroup::where('territory_scope', $level)->max('order')) + 1;
            ModuleGroup::where('territory_scope', $level)->where('order', '>=', $order)->increment('order');
            $group->forceFill(['order' => $order]);
        }
        $group->forceFill(['name' => 'Staff', 'icon' => 'ri-team-line', 'territory_scope' => $level, 'is_active' => true])->save();

        return $group;
    }

    /** Each default grant once, ever - the admin's later choices stand. */
    private function grantOnce(Role $role, Collection $permissions): int
    {
        $seeded = Schema::hasTable('accounting_seeded_grants') ? DB::table('accounting_seeded_grants')->where('role_id', $role->id)->pluck('permission_id')->all() : [];
        $given = 0;
        foreach ($permissions->unique('id') as $p) {
            if (in_array($p->id, $seeded, true)) {
                continue;
            }
            if (! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
                $given++;
            }
            Schema::hasTable('accounting_seeded_grants') && DB::table('accounting_seeded_grants')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $given;
    }
}
