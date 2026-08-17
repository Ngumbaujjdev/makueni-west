<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModuleGroup;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class AddSubregionDemographicsReviewSeeder extends Seeder
{
    /**
     * Phase 4 of the Demographics module plan: the Subregion review
     * workflow (approve/flag/request-changes on a church's monthly
     * demographics submission) has nowhere to live - unlike Church's
     * Growth/Attendance modules or Diocese's Demographics module, there
     * is ZERO subregion-tier module/submodule/permission scaffolding in
     * this database at all (verified directly against the DB before
     * writing this). This seeder originates it from scratch, modeled on
     * the module/submodule/permission shape DioceseBudgetModuleSeeder
     * used for Budgets.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('🧭 SUBREGION DEMOGRAPHICS REVIEW SEEDER');
        $this->command->info(str_repeat('=', 70));

        // === STEP 1: MODULE GROUP ===
        $moduleGroup = ModuleGroup::where('slug', 'subregion-oversight')->first();

        if ($moduleGroup) {
            $this->command->warn('⚠️  Module group already exists: Subregion Oversight');
        } else {
            $moduleGroup = ModuleGroup::create([
                'name' => 'Oversight',
                'slug' => 'subregion-oversight',
                'icon' => 'eye',
                'order' => 1,
                'territory_scope' => 'subregion',
                'description' => 'Subregion-level review and oversight of church submissions',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Module Group: {$moduleGroup->name} (ID: {$moduleGroup->id})");
        }

        // === STEP 2: MODULE ===
        $module = Module::where('name', 'Demographics Review')->first();

        if ($module) {
            $this->command->warn('⚠️  Module already exists: Demographics Review');

            if ($module->module_group_id !== $moduleGroup->id) {
                $module->module_group_id = $moduleGroup->id;
                $module->save();
                $this->command->info("   🔧 Fixed module_group_id -> {$moduleGroup->id}");
            }
        } else {
            $maxModuleNumber = Module::max('number');
            $nextNumber = $maxModuleNumber ? $maxModuleNumber + 1 : 38;

            $module = Module::create([
                'name' => 'Demographics Review',
                'icon' => 'clipboard-check',
                'number' => $nextNumber,
                'module_group_id' => $moduleGroup->id,
                'description' => 'Review church monthly demographics submissions - approve, flag, or request changes',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Module: {$module->name} (ID: {$module->id}, Number: {$module->number})");
        }

        $this->command->info('');

        // === STEP 3: SUBMODULE ===
        $submodule = Submodule::where('module_id', $module->id)
            ->where('title', 'Church Submissions')
            ->first();

        if ($submodule) {
            $this->command->warn('⚠️  Submodule already exists: Church Submissions');
        } else {
            $submodule = Submodule::create([
                'module_id' => $module->id,
                'title' => 'Church Submissions',
                'path' => 'subregion/demographics-review/church-list.php',
                'description' => 'List and review churches\' monthly demographics submissions',
                'is_active' => true,
            ]);
            $this->command->info("✅ Created Submodule: {$submodule->title} (ID: {$submodule->id})");
        }

        $this->command->info('');

        // === STEP 4: PERMISSIONS ===
        $this->command->info('🔐 Generating permissions...');

        $actions = ['read', 'approve', 'flag', 'request-changes'];
        $permissions = [];

        foreach ($actions as $action) {
            $permissionName = 'subregiondemographicsreview.churchsubmissions.' . str_replace('-', '', $action);

            $permission = Permission::where('name', $permissionName)->first();

            if ($permission) {
                $this->command->warn("   ⚠️  Permission already exists: {$permissionName}");
            } else {
                $permission = Permission::create([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                    'module_id' => $module->id,
                    'submodule_id' => $submodule->id,
                    'sub_submodule_id' => null,
                    'action' => $action,
                    'territory_scope' => 'subregion',
                ]);
                $this->command->info("   ✅ Created: {$permissionName}");
            }

            $permissions[] = $permission;
        }

        $this->command->info('');

        // === STEP 5: ASSIGN TO SUBREGION OVERSEER ===
        $this->command->info('👥 Assigning permissions to Subregional Overseer...');

        $role = Role::where('name', 'Subregional Overseer')->first();

        if (!$role) {
            $this->command->error('   ❌ Role not found: Subregional Overseer');
        } else {
            $permissionNames = collect($permissions)->pluck('name')->toArray();
            $existing = $role->permissions()->whereIn('name', $permissionNames)->pluck('name')->toArray();
            $new = array_diff($permissionNames, $existing);

            if (empty($new)) {
                $this->command->warn('   ⚠️  Subregional Overseer already has all permissions');
            } else {
                $role->givePermissionTo($new);
                $this->command->info('   ✅ Subregional Overseer - Assigned ' . count($new) . ' new permission(s)');
            }
        }

        $this->command->info('');
        $this->command->info('✅ SUBREGION DEMOGRAPHICS REVIEW SEEDED SUCCESSFULLY!');
    }
}
