<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Adds the church "Reports" page (church/demographics-growth/reports.php)
 * to the Demographics & Growth module, so it shows in the sidebar and the
 * secondary nav - both are built from these rows, never hardcoded. See
 * docs/specs/reports-spec.md.
 *
 * The read permission goes to every role that can already export Growth
 * Analytics - the people who export reports are the ones who need the page.
 *
 * Idempotent - safe to re-run.
 */
class AddDemographicsReportsSubmoduleSeeder extends Seeder
{
    private const PERMISSION = 'churchdemographicsgrowth.reports.read';

    private const GRANT_FROM = 'churchdemographicsgrowth.growthanalytics.export';

    public function run(): void
    {
        $this->command->info('📄 DEMOGRAPHICS REPORTS SUBMODULE');

        // The Demographics & Growth module is the one holding Growth Analytics.
        $moduleId = Submodule::where('path', 'like', '%church/demographics-growth/growth-analytics%')->value('module_id');
        if (! $moduleId) {
            $this->command->error('   ❌ Demographics & Growth module not found (no Growth Analytics submodule)');

            return;
        }

        $submodule = Submodule::firstOrCreate(
            ['module_id' => $moduleId, 'path' => '/church/demographics-growth/reports.php'],
            ['title' => 'Reports', 'description' => 'PDF and Excel reports with insights and recommendations', 'is_active' => true],
        );
        $this->command->info("   ✅ Submodule: {$submodule->title} (ID: {$submodule->id})");

        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['module_id' => $moduleId, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => 'read', 'territory_scope' => 'church'],
        );
        $this->command->info("   ✅ Permission: {$permission->name}");

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', self::GRANT_FROM))->get();
        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
                $this->command->info("   ✅ Granted to {$role->name}");
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done');
    }
}
