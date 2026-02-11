<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Enums\TerritoryType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create module groups if they don't exist (for fresh installs)
        $overviewGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'diocese-overview', 'territory_scope' => TerritoryType::DIOCESE->value],
            ['name' => 'Overview', 'icon' => 'ri-dashboard-line', 'order' => 1, 'is_active' => true]
        );

        $churchesGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'diocese-churches', 'territory_scope' => TerritoryType::DIOCESE->value],
            ['name' => 'Churches', 'icon' => 'ri-building-line', 'order' => 2, 'is_active' => true]
        );

        $financeGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'diocese-finance', 'territory_scope' => TerritoryType::DIOCESE->value],
            ['name' => 'Finance', 'icon' => 'ri-money-dollar-circle-line', 'order' => 3, 'is_active' => true]
        );

        $programsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'diocese-programs', 'territory_scope' => TerritoryType::DIOCESE->value],
            ['name' => 'Programs', 'icon' => 'ri-calendar-event-line', 'order' => 4, 'is_active' => true]
        );

        $settingsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'diocese-settings', 'territory_scope' => TerritoryType::DIOCESE->value],
            ['name' => 'Settings', 'icon' => 'ri-settings-3-line', 'order' => 5, 'is_active' => true]
        );

        // =========================================
        // GROUP 1: OVERVIEW (3 modules)
        // =========================================

        // 1. Diocese Dashboard → Dashboard
        Module::where('name', 'Diocese Dashboard')->update([
            'name' => 'Dashboard',
            'module_group_id' => $overviewGroup->id
        ]);

        // 2. Demographics Analytics → Demographics
        Module::where('name', 'Demographics Analytics')->update([
            'name' => 'Demographics',
            'module_group_id' => $overviewGroup->id
        ]);

        // 3. Diocese Reports & Analytics → Reports
        Module::where('name', 'Diocese Reports & Analytics')->update([
            'name' => 'Reports',
            'module_group_id' => $overviewGroup->id
        ]);

        // =========================================
        // GROUP 2: CHURCHES (3 modules)
        // =========================================

        // 4. All Churches Overview → All Churches
        Module::where('name', 'All Churches Overview')->update([
            'name' => 'All Churches',
            'module_group_id' => $churchesGroup->id
        ]);

        // 5. Individual Church Details → Church Details
        Module::where('name', 'Individual Church Details')->update([
            'name' => 'Church Details',
            'module_group_id' => $churchesGroup->id
        ]);

        // 6. Diocese Compliance Monitoring → Compliance Monitoring
        Module::where('name', 'Diocese Compliance Monitoring')->update([
            'name' => 'Compliance Monitoring',
            'module_group_id' => $churchesGroup->id
        ]);

        // =========================================
        // GROUP 3: FINANCE (6 modules)
        // =========================================

        // 7. Tithe Management (keep as is)
        Module::where('name', 'Tithe Management')->update([
            'module_group_id' => $financeGroup->id
        ]);

        // 8. Diocese Budget Management → Budget Management
        Module::where('name', 'Diocese Budget Management')->update([
            'name' => 'Budget Management',
            'module_group_id' => $financeGroup->id
        ]);

        // 9. Resource Allocation Management → Resource Allocation
        Module::where('name', 'Resource Allocation Management')->update([
            'name' => 'Resource Allocation',
            'module_group_id' => $financeGroup->id
        ]);

        // 10. Diocese Income Tracking → Income Tracking
        Module::where('name', 'Diocese Income Tracking')->update([
            'name' => 'Income Tracking',
            'module_group_id' => $financeGroup->id
        ]);

        // 11. Diocese Expense Tracking → Expense Tracking
        Module::where('name', 'Diocese Expense Tracking')->update([
            'name' => 'Expense Tracking',
            'module_group_id' => $financeGroup->id
        ]);

        // 12. Financial Reports (keep as is)
        Module::where('name', 'Financial Reports')->update([
            'module_group_id' => $financeGroup->id
        ]);

        // =========================================
        // GROUP 4: PROGRAMS (3 modules)
        // =========================================

        // 13. Diocese Initiatives Management → Initiatives
        Module::where('name', 'Diocese Initiatives Management')->update([
            'name' => 'Initiatives',
            'module_group_id' => $programsGroup->id
        ]);

        // 14. Diocese Events Management → Events
        Module::where('name', 'Diocese Events Management')->update([
            'name' => 'Events',
            'module_group_id' => $programsGroup->id
        ]);

        // 15. Diocese Calendar → Calendar
        Module::where('name', 'Diocese Calendar')->update([
            'name' => 'Calendar',
            'module_group_id' => $programsGroup->id
        ]);

        // =========================================
        // GROUP 5: SETTINGS (2 modules)
        // =========================================

        // 16. Diocese Communications Hub → Communications
        Module::where('name', 'Diocese Communications Hub')->update([
            'name' => 'Communications',
            'module_group_id' => $settingsGroup->id
        ]);

        // 17. Diocese Settings → Settings
        Module::where('name', 'Diocese Settings')->update([
            'name' => 'Settings',
            'module_group_id' => $settingsGroup->id
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original names and remove group assignments

        Module::where('name', 'Dashboard')->update([
            'name' => 'Diocese Dashboard',
            'module_group_id' => null
        ]);

        Module::where('name', 'Demographics')->update([
            'name' => 'Demographics Analytics',
            'module_group_id' => null
        ]);

        Module::where('name', 'Reports')->update([
            'name' => 'Diocese Reports & Analytics',
            'module_group_id' => null
        ]);

        Module::where('name', 'All Churches')->update([
            'name' => 'All Churches Overview',
            'module_group_id' => null
        ]);

        Module::where('name', 'Church Details')->update([
            'name' => 'Individual Church Details',
            'module_group_id' => null
        ]);

        Module::where('name', 'Compliance Monitoring')->update([
            'name' => 'Diocese Compliance Monitoring',
            'module_group_id' => null
        ]);

        Module::where('name', 'Tithe Management')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Budget Management')->update([
            'name' => 'Diocese Budget Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Resource Allocation')->update([
            'name' => 'Resource Allocation Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Income Tracking')->update([
            'name' => 'Diocese Income Tracking',
            'module_group_id' => null
        ]);

        Module::where('name', 'Expense Tracking')->update([
            'name' => 'Diocese Expense Tracking',
            'module_group_id' => null
        ]);

        Module::where('name', 'Financial Reports')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Initiatives')->update([
            'name' => 'Diocese Initiatives Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Events')->update([
            'name' => 'Diocese Events Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Calendar')->update([
            'name' => 'Diocese Calendar',
            'module_group_id' => null
        ]);

        Module::where('name', 'Communications')->update([
            'name' => 'Diocese Communications Hub',
            'module_group_id' => null
        ]);

        Module::where('name', 'Settings')->update([
            'name' => 'Diocese Settings',
            'module_group_id' => null
        ]);
    }
};
