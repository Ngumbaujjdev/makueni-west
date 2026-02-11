<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\ModuleGroup;
use Illuminate\Support\Facades\DB;

class UpdateModuleGroupsSeeder extends Seeder
{
    /**
     * Update all modules with their correct module_group_id
     */
    public function run(): void
    {
        $this->command->info('🔗 Connecting modules to their groups...');

        // Get all groups
        $overviewGroup = ModuleGroup::where('slug', 'diocese-overview')->first();
        $churchesGroup = ModuleGroup::where('slug', 'diocese-churches')->first();
        $financeGroup = ModuleGroup::where('slug', 'diocese-finance')->first();
        $programsGroup = ModuleGroup::where('slug', 'diocese-programs')->first();
        $settingsGroup = ModuleGroup::where('slug', 'diocese-settings')->first();

        if (!$financeGroup || !$overviewGroup) {
            $this->command->error('Module groups not found! Run ModuleGroupSeeder first.');
            return;
        }

        // Overview modules
        Module::where('name', 'LIKE', '%Dashboard%')->update(['module_group_id' => $overviewGroup->id]);
        Module::where('name', 'LIKE', '%Demographic%')->update(['module_group_id' => $overviewGroup->id]);
        Module::where('name', 'LIKE', '%Report%')->whereNot('name', 'LIKE', '%Financial%')->update(['module_group_id' => $overviewGroup->id]);
        $this->command->info('  ✓ Overview modules linked');

        // Churches modules
        Module::where('name', 'LIKE', '%Church%')->update(['module_group_id' => $churchesGroup->id]);
        Module::where('name', 'LIKE', '%Compliance%')->update(['module_group_id' => $churchesGroup->id]);
        $this->command->info('  ✓ Churches modules linked');

        // Finance modules
        Module::where('name', 'LIKE', '%Budget%')->update(['module_group_id' => $financeGroup->id]);
        Module::where('name', 'LIKE', '%Tithe%')->update(['module_group_id' => $financeGroup->id]);
        Module::where('name', 'LIKE', '%Income%')->update(['module_group_id' => $financeGroup->id]);
        Module::where('name', 'LIKE', '%Expense%')->update(['module_group_id' => $financeGroup->id]);
        Module::where('name', 'LIKE', '%Financial%')->update(['module_group_id' => $financeGroup->id]);
        Module::where('name', 'LIKE', '%Resource%')->update(['module_group_id' => $financeGroup->id]);
        $this->command->info('  ✓ Finance modules linked');

        // Programs modules
        Module::where('name', 'LIKE', '%Event%')->update(['module_group_id' => $programsGroup->id]);
        Module::where('name', 'LIKE', '%Initiative%')->update(['module_group_id' => $programsGroup->id]);
        Module::where('name', 'LIKE', '%Calendar%')->update(['module_group_id' => $programsGroup->id]);
        $this->command->info('  ✓ Programs modules linked');

        // Settings modules
        Module::where('name', 'LIKE', '%Setting%')->update(['module_group_id' => $settingsGroup->id]);
        Module::where('name', 'LIKE', '%Communication%')->update(['module_group_id' => $settingsGroup->id]);
        $this->command->info('  ✓ Settings modules linked');

        // Count how many modules now have groups
        $linkedCount = Module::whereNotNull('module_group_id')->count();
        $totalCount = Module::count();
        
        $this->command->info("🎉 Done! {$linkedCount}/{$totalCount} modules now have groups.");
    }
}
