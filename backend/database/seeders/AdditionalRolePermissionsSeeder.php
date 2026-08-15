<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Permission;
use App\Models\Module;

class AdditionalRolePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Diocese/Region/ChurchRolePermissionsSeeder only ever covered the roles
     * SuperAdminSeeder originally created. 14 roles added later (via the admin
     * Role Management UI) had a territory_level but zero permissions:
     *
     * - Church (7): Choir Director, Church Administrator, Prayer Group Leader,
     *   Sunday School Teacher, Usher Coordinator, Worship Leader, Youth Pastor.
     *   Same tier, same treatment as the 12 already-seeded church roles: full
     *   access to all church modules.
     *
     * - Diocese (1) / Region (2): Diocese Finance Officer, Regional Treasurer —
     *   named for a specific function, so scoped to that tier's Finance module
     *   group only, not full tier access.
     *
     * - Region (1): Regional Coordinator — full region access, same "Overseer/
     *   Secretary"-tier treatment as Regional Overseer/Regional Secretary.
     *
     * - Region (1): Regional Committee Member — this is the role pastors get as
     *   a *secondary* assignment (their regional committee seat), not a primary
     *   position. Deliberately narrower than the operational roles above:
     *   oversight/reporting access only, not budget/user/settings management.
     *
     * - Subregion (3): Area Pastor, Subregional Overseer, Subregional Secretary
     *   — no subregion-scoped modules exist in this diocese (confirmed: it
     *   doesn't use the subregion tier in practice), so these mirror the full
     *   region module set as the closest analogous tier, ready for a future
     *   diocese that does use subregions.
     */
    public function run(): void
    {
        $this->command->info('🧩 ADDITIONAL ROLE PERMISSIONS SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Assigning permissions to roles added after the original seed set...');
        $this->command->info('');

        $churchModules = Module::whereHas('moduleGroup', fn($q) => $q->where('territory_scope', 'church'))->get();
        $regionModules = Module::whereHas('moduleGroup', fn($q) => $q->where('territory_scope', 'region'))->get();
        $dioceseFinanceModules = Module::whereHas('moduleGroup', function ($q) {
            $q->where('territory_scope', 'diocese')->where('name', 'Finance');
        })->get();
        $regionFinanceModules = Module::whereHas('moduleGroup', function ($q) {
            $q->where('territory_scope', 'region')->where('name', 'Finance');
        })->get();
        $regionReportingModules = Module::where('name', 'Reporting')
            ->whereHas('moduleGroup', fn($q) => $q->where('territory_scope', 'region'))
            ->get();

        $churchPermissions = Permission::whereIn('module_id', $churchModules->pluck('id'))->get();
        $regionPermissions = Permission::whereIn('module_id', $regionModules->pluck('id'))->get();
        $dioceseFinancePermissions = Permission::whereIn('module_id', $dioceseFinanceModules->pluck('id'))->get();
        $regionFinancePermissions = Permission::whereIn('module_id', $regionFinanceModules->pluck('id'))->get();
        $regionReportingPermissions = Permission::whereIn('module_id', $regionReportingModules->pluck('id'))->get();

        $this->command->info("📊 Church: {$churchModules->count()} modules / {$churchPermissions->count()} permissions");
        $this->command->info("📊 Region (full): {$regionModules->count()} modules / {$regionPermissions->count()} permissions");
        $this->command->info("📊 Diocese Finance group: {$dioceseFinanceModules->count()} modules / {$dioceseFinancePermissions->count()} permissions");
        $this->command->info("📊 Region Finance group: {$regionFinanceModules->count()} modules / {$regionFinancePermissions->count()} permissions");
        $this->command->info("📊 Region Reporting only: {$regionReportingModules->count()} modules / {$regionReportingPermissions->count()} permissions");
        $this->command->info('');

        // Church — same full-tier-access pattern as the 12 roles ChurchRolePermissionsSeeder already covers
        foreach ([
            'Choir Director', 'Church Administrator', 'Prayer Group Leader',
            'Sunday School Teacher', 'Usher Coordinator', 'Worship Leader', 'Youth Pastor',
        ] as $roleName) {
            $this->assignPermissionsToRole($roleName, $churchPermissions, 'Full access to all church modules');
        }

        // Diocese — finance-specific function
        $this->assignPermissionsToRole('Diocese Finance Officer', $dioceseFinancePermissions, 'Diocese Finance modules only (Tithe/Budget/Resource Allocation/Income/Expense/Financial Reports)');

        // Region — operational leadership tier, full region access
        $this->assignPermissionsToRole('Regional Coordinator', $regionPermissions, 'Full access to all region modules');

        // Region — finance-specific function
        $this->assignPermissionsToRole('Regional Treasurer', $regionFinancePermissions, 'Region Finance modules only (Resources/Finances)');

        // Region — secondary/committee seat, deliberately narrower
        $this->assignPermissionsToRole('Regional Committee Member', $regionReportingPermissions, 'Reporting/oversight only — this is the secondary seat pastors get on their regional committee, not an operational role');

        // Subregion — no subregion-scoped modules exist in this diocese; mirror
        // the region module set as the closest tier, ready for a future diocese
        // that actually uses the subregion level
        foreach (['Area Pastor', 'Subregional Overseer', 'Subregional Secretary'] as $roleName) {
            $this->assignPermissionsToRole($roleName, $regionPermissions, 'Mirrors region-tier modules — no subregion-scoped modules exist yet');
        }

        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ ADDITIONAL ROLE PERMISSIONS ASSIGNED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
    }

    /**
     * Helper method to assign permissions to a role
     */
    private function assignPermissionsToRole($roleName, $permissions, $description)
    {
        $role = Role::where('name', $roleName)->first();

        if (!$role) {
            $this->command->error("   ❌ Role '{$roleName}' not found!");
            return;
        }

        $role->permissions()->detach();
        $role->givePermissionTo($permissions->pluck('name')->toArray());

        $this->command->info("✅ {$roleName}");
        $this->command->line("   - {$description}");
        $this->command->line("   - Assigned {$permissions->count()} permissions");
        $this->command->info('');
    }
}
