<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class GrantDemographicsEntryPermissionsSeeder extends Seeder
{
    /**
     * ChurchSystemSeeder created every Demographics/Attendance permission
     * row (56 for module 27, 20 for module 28) but never granted any of
     * them to a real church-tier role - confirmed directly: every
     * permission under submodule 125 (Demographics Tracking) resolves to
     * "Global Administrator" only. Senior Pastor only held the generic
     * "...overview.read" permission (from FixDemographicsPermissionScaffoldingSeeder),
     * which is why the module appeared in the sidebar at all but nothing
     * inside it was usable. This blocks the whole module for real church
     * staff regardless of how complete the frontend build is.
     *
     * Grants the four roles who actually do data entry in practice (Senior
     * Pastor, Associate Pastor, Church Secretary, Church Administrator)
     * full create/read/update/export on the entry submodules, and
     * read/export only on the report submodules. Does not touch the other
     * 16 church-tier roles - they already have overview-level read access
     * and expanding that further wasn't asked for.
     *
     * Idempotent - safe to re-run, never revokes an existing grant.
     */
    private const ENTRY_ROLES = ['Senior Pastor', 'Associate Pastor', 'Church Secretary', 'Church Administrator'];

    /** submodule_id => actions to grant */
    private const SUBMODULE_ACTIONS = [
        125 => ['create', 'read', 'update', 'export'], // Demographics Tracking
        126 => ['create', 'read', 'update', 'export'], // Spiritual Activities
        127 => ['read', 'export'],                     // Growth Analytics (report)
        128 => ['read', 'export'],                     // Monthly Statistics (report)
        129 => ['create', 'read', 'update', 'export'], // Service Attendance
        130 => ['create', 'read', 'update', 'export'], // Ministry Attendance
        131 => ['create', 'read', 'update', 'export'], // Special Events Attendance
        132 => ['read', 'export'],                     // Attendance Reports (report)
    ];

    public function run(): void
    {
        $this->command->info('🔑 GRANTING DEMOGRAPHICS ENTRY PERMISSIONS');
        $this->command->info(str_repeat('=', 70));

        $roles = Role::whereIn('name', self::ENTRY_ROLES)->get()->keyBy('name');

        foreach (self::ENTRY_ROLES as $roleName) {
            if (!$roles->has($roleName)) {
                $this->command->error("   ❌ Role not found: {$roleName}");
            }
        }

        $granted = 0;
        $skipped = 0;

        foreach (self::SUBMODULE_ACTIONS as $submoduleId => $actions) {
            $submodule = Submodule::find($submoduleId);

            if (!$submodule) {
                $this->command->error("   ❌ Submodule not found: {$submoduleId}");
                continue;
            }

            $permissions = Permission::where('submodule_id', $submoduleId)
                ->whereIn('action', $actions)
                ->get();

            foreach ($roles as $roleName => $role) {
                foreach ($permissions as $permission) {
                    if ($role->hasPermissionTo($permission)) {
                        $skipped++;
                        continue;
                    }

                    $role->givePermissionTo($permission);
                    $granted++;
                }
            }

            $this->command->info("   ✅ {$submodule->title} ({$permissions->count()} permission(s)) -> " . implode(', ', self::ENTRY_ROLES));
        }

        $this->command->info('');
        $this->command->info("✅ DONE: {$granted} grant(s) made, {$skipped} already held.");
    }
}
