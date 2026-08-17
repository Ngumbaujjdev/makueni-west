<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModuleGroup;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class AddRegionDemographicsSummarySeeder extends Seeder
{
    /**
     * Phase 5 of the Demographics module plan: Region-tier read-only
     * demographics/attendance summary access. Like Subregion before Phase
     * 4, there is zero Region-tier module/submodule/permission scaffolding
     * for demographics in this database - originated from scratch here.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('🗺️  REGION DEMOGRAPHICS SUMMARY SEEDER');
        $this->command->info(str_repeat('=', 70));

        $moduleGroup = ModuleGroup::where('slug', 'region-analytics')->first();

        if ($moduleGroup) {
            $this->command->warn('⚠️  Module group already exists: Region Analytics');
        } else {
            $moduleGroup = ModuleGroup::create([
                'name' => 'Analytics',
                'slug' => 'region-analytics',
                'icon' => 'bar-chart',
                'order' => 1,
                'territory_scope' => 'region',
                'description' => 'Region-level demographics and growth analytics',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Module Group: {$moduleGroup->name} (ID: {$moduleGroup->id})");
        }

        $module = Module::where('name', 'Demographics Summary')
            ->whereHas('moduleGroup', fn ($q) => $q->where('territory_scope', 'region'))
            ->first();

        if ($module) {
            $this->command->warn('⚠️  Module already exists: Demographics Summary (region)');

            if ($module->module_group_id !== $moduleGroup->id) {
                $module->module_group_id = $moduleGroup->id;
                $module->save();
                $this->command->info("   🔧 Fixed module_group_id -> {$moduleGroup->id}");
            }
        } else {
            $maxModuleNumber = Module::max('number');
            $nextNumber = $maxModuleNumber ? $maxModuleNumber + 1 : 39;

            $module = Module::create([
                'name' => 'Demographics Summary',
                'icon' => 'bar-chart',
                'number' => $nextNumber,
                'module_group_id' => $moduleGroup->id,
                'description' => 'Region-wide rollup of church demographics and attendance',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Module: {$module->name} (ID: {$module->id}, Number: {$module->number})");
        }

        $this->command->info('');

        $submodule = Submodule::where('module_id', $module->id)
            ->where('title', 'Region Summary')
            ->first();

        if ($submodule) {
            $this->command->warn('⚠️  Submodule already exists: Region Summary');
        } else {
            $submodule = Submodule::create([
                'module_id' => $module->id,
                'title' => 'Region Summary',
                'path' => 'region/regional-reporting/statistics.php',
                'description' => 'Rolled-up demographics/attendance summary for the region',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Submodule: {$submodule->title} (ID: {$submodule->id})");
        }

        $this->command->info('');

        $permissionName = 'regiondemographicsanalytics.summary.read';
        $permission = Permission::where('name', $permissionName)->first();

        if ($permission) {
            $this->command->warn("⚠️  Permission already exists: {$permissionName}");
        } else {
            $permission = Permission::create([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $module->id,
                'submodule_id' => $submodule->id,
                'sub_submodule_id' => null,
                'action' => 'read',
                'territory_scope' => 'region',
            ]);
            $this->command->info("✅ Created: {$permissionName}");
        }

        $this->command->info('');
        $this->command->info('👥 Assigning permission to Regional Overseer...');

        $role = Role::where('name', 'Regional Overseer')->first();

        if (!$role) {
            $this->command->error('   ❌ Role not found: Regional Overseer');
        } elseif ($role->hasPermissionTo($permission)) {
            $this->command->warn('   ⚠️  Regional Overseer already has this permission');
        } else {
            $role->givePermissionTo($permission);
            $this->command->info('   ✅ Regional Overseer - permission granted');
        }

        $this->command->info('');
        $this->command->info('✅ REGION DEMOGRAPHICS SUMMARY SEEDED SUCCESSFULLY!');
    }
}
