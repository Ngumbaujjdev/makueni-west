<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Support\PeopleAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * People & care menus and permissions (docs/specs/people-and-care-spec.md).
 *
 * The church's five old placeholder modules - Member Management, Visitor
 * Management, Pastoral Care, Ministry Coordination and Facility Management -
 * are reused (matched by name), renamed and pointed at their real pages
 * (/church/members/ ...). Their old sub-pages and permissions
 * (membermanagement.*, pastoralcare.* ...) are removed.
 *
 * Each module goes live with its phase: LIVE says which pages are built.
 * Until then the module, its pages and the region's and diocese's totals
 * page stay switched off (the permissions and grants are made either way).
 *
 * Idempotent - safe to re-run.
 */
class PeopleCareAccessSeeder extends Seeder
{
    /** Which modules' pages are built (flipped by each phase's PR). */
    public const LIVE = [
        'members' => true,
        'visitors' => true,
        'pastoral' => true,
        'ministries' => true,
        'facilities' => true,
    ];

    /** module => the church module, the placeholder names it reuses, and its pages (path => [title, description, the ability it holds]). */
    private const MODULES = [
        'members' => [
            'name' => 'Members', 'group' => 'church-members', 'path' => 'members', 'icon' => 'ri-contacts-book-2-line',
            'reuse' => ['Member Management', 'Members'],
            'description' => "Our church's private member register - only our leaders see names.",
            'pages' => [
                '' => ['Members', 'Everyone in our register: find, filter and open a member.', ['read', 'export']],
                'new.php' => ['Add member', 'Add someone to the register.', ['manage']],
                'transfers.php' => ['Transfers', 'Members who moved in from, or out to, another church.', ['transfers']],
                'insights.php' => ['Insights', 'Ages, joining and leaving, and birthdays.', ['insights']],
            ],
        ],
        'visitors' => [
            'name' => 'Visitors', 'group' => 'church-members', 'path' => 'visitors', 'icon' => 'ri-user-heart-line',
            'reuse' => ['Visitor Management', 'Visitors'],
            'description' => 'Visitors and their follow-up, until they belong.',
            'pages' => [
                '' => ['Visitors', 'Visitors and where each one is in their follow-up.', ['read', 'export']],
                'new.php' => ['Record visitors', "Add this Sunday's visitors quickly.", ['manage']],
                'insights.php' => ['Insights', 'How visitors heard of us, and how many stay.', ['insights']],
            ],
        ],
        'pastoral' => [
            'name' => 'Pastoral care', 'group' => 'church-members', 'path' => 'pastoral-care', 'icon' => 'ri-heart-pulse-line',
            'reuse' => ['Pastoral Care'],
            'description' => 'Visits, counselling, hospital, prayer and concerns.',
            'pages' => [
                '' => ['Pastoral care', 'Who needs care, and the care given.', ['read', 'manage', 'confidential']],
                'log.php' => ['Care log', 'Every visit, call and case, to find and follow up.', ['log']],
                'hospital.php' => ['Hospital', 'Who is in hospital, and when they were last visited.', ['hospital']],
                'prayer.php' => ['Prayer', 'Prayer requests, open and answered.', ['prayer']],
            ],
        ],
        'ministries' => [
            'name' => 'Ministries', 'group' => 'church-programs', 'path' => 'ministries', 'icon' => 'ri-team-line',
            'reuse' => ['Ministry Coordination', 'Ministries'],
            'description' => 'Youth, women, men, children, music, prayer and our own ministries.',
            'pages' => [
                '' => ['Ministries', 'Our ministries, their leaders, members and gatherings.', ['read', 'manage']],
                'insights.php' => ['Insights', "Who serves where, and who isn't in a ministry yet.", ['insights']],
            ],
        ],
        'facilities' => [
            'name' => 'Facilities', 'group' => 'church-programs', 'path' => 'facilities', 'icon' => 'ri-building-2-line',
            'reuse' => ['Facility Management', 'Facilities'],
            'description' => 'Rooms and bookings, equipment, repairs and the duty rota.',
            'pages' => [
                '' => ['Facilities', "Today's bookings, repairs and this Sunday's duty.", ['read', 'manage']],
                'bookings.php' => ['Bookings', 'Book a room, and see who has it when.', ['bookings', 'book']],
                'equipment.php' => ['Equipment', 'What we own, where it is kept, and who has borrowed it.', ['equipment']],
                'repairs.php' => ['Repairs', 'What needs fixing, who is on it, and what it cost.', ['repairs']],
                'rota.php' => ['Duty rota', 'Who is on duty at each service.', ['rota']],
            ],
        ],
    ];

    /** The region's and diocese's read-only totals pages - counts per church, never names. */
    private const TOTALS = [
        'members' => ['members.php', 'Members', 'Members per church: active, new, transfers and baptisms.'],
        'visitors' => ['visitors.php', 'Visitors', 'Visitors and how many became members, per church.'],
        'pastoral' => ['care.php', 'Pastoral care', 'Pastoral visits and open cases per church.'],
        'ministries' => ['ministries.php', 'Ministries', 'Ministries and the people serving in them, per church.'],
    ];

    /** Role => module => abilities (PeopleAccess::ABILITIES keys), per level. */
    private const GRANTS = [
        'church' => [
            'Senior Pastor' => ['members' => ['read', 'manage', 'export'], 'visitors' => ['read', 'manage', 'export'], 'pastoral' => ['read', 'manage', 'confidential'], 'ministries' => ['read', 'manage'], 'facilities' => ['read', 'manage', 'book']],
            'Associate Pastor' => ['members' => ['read', 'manage'], 'visitors' => ['read', 'manage'], 'pastoral' => ['read', 'manage'], 'ministries' => ['read', 'manage'], 'facilities' => ['read', 'manage', 'book']],
            'Church Administrator' => ['members' => ['read', 'manage', 'export'], 'visitors' => ['read', 'manage', 'export'], 'ministries' => ['read', 'manage'], 'facilities' => ['read', 'manage', 'book']],
            'Church Secretary' => ['members' => ['read', 'manage'], 'visitors' => ['read', 'manage'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            'Elder' => ['members' => ['read'], 'visitors' => ['read', 'manage'], 'pastoral' => ['read', 'manage'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            'Deacon' => ['members' => ['read'], 'visitors' => ['read', 'manage'], 'pastoral' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            'Youth Pastor' => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            'Youth Leader' => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            "Women's Ministry Leader" => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            "Men's Ministry Leader" => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            "Children's Ministry Leader" => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read', 'book']],
            'Church Treasurer' => ['ministries' => ['read'], 'facilities' => ['read']],
            'Church Committee Member' => ['members' => ['read'], 'visitors' => ['read'], 'ministries' => ['read'], 'facilities' => ['read']],
        ],
        'region' => [
            'Regional Overseer' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
            'Regional Secretary' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
            'Regional Coordinator' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
        ],
        'diocese' => [
            'Bishop' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
            'Diocese Secretary' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
            'Diocese Administrator' => ['members' => ['below'], 'visitors' => ['below'], 'pastoral' => ['below'], 'ministries' => ['below']],
        ],
    ];

    /** The old placeholders' permission prefixes, removed once nothing uses them. */
    private const RETIRED_PREFIXES = ['membermanagement.', 'visitormanagement.', 'pastoralcare.', 'ministrycoordination.', 'facilitymanagement.'];

    public function run(): void
    {
        $this->command?->info('🫶 PEOPLE & CARE - menus and permissions');
        $this->command?->info(str_repeat('=', 70));

        $permissions = []; // "{level}.{suffix}" => Permission

        // The church's five modules.
        foreach (self::MODULES as $key => $spec) {
            $group = ModuleGroup::where('slug', $spec['group'])->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$spec['group']} not found - skipping {$spec['name']}");

                continue;
            }
            $live = self::LIVE[$key];
            $module = $this->module($group, $spec, $live);
            $keep = [];
            foreach ($spec['pages'] as $file => [$title, $description, $abilities]) {
                $path = "/church/{$spec['path']}/{$file}";
                $keep[] = $path;
                $page = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => $path],
                    ['title' => $title, 'description' => $description, 'is_active' => $live],
                );
                foreach ($abilities as $ability) {
                    $permissions["church.{$key}.{$ability}"] = $this->permission('church', $key, $ability, $module, $page);
                }
            }
            $this->retireOldPages($module, $keep);
            $this->command?->info('   '.($live ? '✅' : '💤')." church: {$spec['name']} (".($live ? 'live' : 'not built yet - switched off').')');
        }

        // The region's and diocese's totals pages.
        foreach (['region', 'diocese'] as $level) {
            $group = ModuleGroup::where('slug', "{$level}-churches")->first();
            if (! $group) {
                $this->command?->error("   ❌ Module group {$level}-churches not found - skipping {$level}");

                continue;
            }
            $anyLive = (bool) array_filter(array_intersect_key(self::LIVE, self::TOTALS));
            $module = Module::firstOrNew(['module_group_id' => $group->id, 'name' => 'Church care']);
            $module->forceFill([
                'number' => $module->number ?? 0,
                'icon' => 'ri-hand-heart-line',
                'description' => "Our churches' members, visitors, pastoral care and ministries - totals only, never names.",
                'is_active' => $anyLive,
            ])->save();
            foreach (self::TOTALS as $key => [$file, $title, $description]) {
                $page = Submodule::updateOrCreate(
                    ['module_id' => $module->id, 'path' => "/{$level}/people/{$file}"],
                    ['title' => $title, 'description' => $description, 'is_active' => self::LIVE[$key]],
                );
                $permissions["{$level}.{$key}.below"] = $this->permission($level, $key, 'below', $module, $page);
            }
            $this->command?->info('   '.($anyLive ? '✅' : '💤')." {$level}: Church care totals");
        }

        // Grants.
        $granted = 0;
        foreach (self::GRANTS as $level => $roles) {
            foreach ($roles as $roleName => $modules) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    continue;
                }
                foreach ($modules as $key => $abilities) {
                    // Whoever reads a module also gets its own pages (Transfers, Insights).
                    if (in_array('read', $abilities, true)) {
                        $abilities = [...$abilities, ...array_intersect(PeopleAccess::PAGE_READS, array_keys(PeopleAccess::ABILITIES[$key]))];
                    }
                    foreach ($abilities as $ability) {
                        $permission = $permissions["{$level}.{$key}.{$ability}"] ?? null;
                        if ($permission && ! $role->hasPermissionTo($permission)) {
                            $role->givePermissionTo($permission);
                            $granted++;
                        }
                    }
                }
            }
        }
        $this->command?->info("   ✅ {$granted} new grant(s)");

        // The old placeholders' permissions.
        $retired = 0;
        foreach (self::RETIRED_PREFIXES as $prefix) {
            foreach (Permission::where('name', 'like', "{$prefix}%")->get() as $old) {
                $old->delete();
                $retired++;
            }
        }
        if ($retired) {
            $this->command?->info("   🧹 {$retired} old placeholder permission(s) removed");
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The church module: the old placeholder renamed, or a new one. */
    private function module(ModuleGroup $group, array $spec, bool $live): Module
    {
        $module = Module::where('name', $spec['name'])->whereHas('submodules', fn ($q) => $q->where('path', 'like', "/church/{$spec['path']}/%"))->first()
            ?? Module::whereIn('name', $spec['reuse'])->orderBy('id')->first()
            ?? new Module(['number' => 0]);
        $module->forceFill([
            'name' => $spec['name'],
            'module_group_id' => $group->id,
            'icon' => $spec['icon'],
            'description' => $spec['description'],
            'is_active' => $live,
        ])->save();

        return $module;
    }

    /** The placeholder's old pages (/members/registration ...) go, with their sub-pages. */
    private function retireOldPages(Module $module, array $keep): void
    {
        $old = Submodule::where('module_id', $module->id)->whereNotIn('path', $keep);
        $ids = (clone $old)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        Permission::whereIn('submodule_id', $ids)->update(['submodule_id' => null]);
        SubSubmodule::whereIn('submodule_id', $ids)->delete();
        $old->delete();
    }

    private function permission(string $level, string $key, string $ability, Module $module, Submodule $page): Permission
    {
        $name = "{$level}.".PeopleAccess::permission($key, $ability);

        return Permission::updateOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['module_id' => $module->id, 'submodule_id' => $page->id, 'sub_submodule_id' => null, 'action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level],
        );
    }
}
