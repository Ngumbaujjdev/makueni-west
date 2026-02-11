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
            ['slug' => 'church-overview', 'territory_scope' => TerritoryType::CHURCH->value],
            ['name' => 'Overview', 'icon' => 'ri-dashboard-line', 'order' => 1, 'is_active' => true]
        );

        $membersGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'church-members', 'territory_scope' => TerritoryType::CHURCH->value],
            ['name' => 'Members', 'icon' => 'ri-group-line', 'order' => 2, 'is_active' => true]
        );

        $financeGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'church-finance', 'territory_scope' => TerritoryType::CHURCH->value],
            ['name' => 'Finance', 'icon' => 'ri-money-dollar-circle-line', 'order' => 3, 'is_active' => true]
        );

        $programsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'church-programs', 'territory_scope' => TerritoryType::CHURCH->value],
            ['name' => 'Programs', 'icon' => 'ri-calendar-event-line', 'order' => 4, 'is_active' => true]
        );

        $settingsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'church-settings', 'territory_scope' => TerritoryType::CHURCH->value],
            ['name' => 'Settings', 'icon' => 'ri-settings-3-line', 'order' => 5, 'is_active' => true]
        );

        // =========================================
        // GROUP 1: OVERVIEW (2 modules)
        // =========================================

        // 1. Church Demographics & Growth → Growth
        Module::where('name', 'Church Demographics & Growth')->update([
            'name' => 'Growth',
            'module_group_id' => $overviewGroup->id
        ]);

        // 2. Church Reports → Church Reporting (changed to avoid conflict with diocese "Reports")
        Module::where('name', 'Church Reports')->update([
            'name' => 'Church Reporting',
            'module_group_id' => $overviewGroup->id
        ]);

        // =========================================
        // GROUP 2: MEMBERS (4 modules)
        // =========================================

        // 3. Member Management → Members
        Module::where('name', 'Member Management')->update([
            'name' => 'Members',
            'module_group_id' => $membersGroup->id
        ]);

        // 4. Attendance Management → Attendance
        Module::where('name', 'Attendance Management')->update([
            'name' => 'Attendance',
            'module_group_id' => $membersGroup->id
        ]);

        // 5. Visitor Management → Visitors
        Module::where('name', 'Visitor Management')->update([
            'name' => 'Visitors',
            'module_group_id' => $membersGroup->id
        ]);

        // 6. Pastoral Care (keep as is)
        Module::where('name', 'Pastoral Care')->update([
            'module_group_id' => $membersGroup->id
        ]);

        // =========================================
        // GROUP 3: FINANCE (2 modules)
        // =========================================

        // 7. Financial Management → Finance
        Module::where('name', 'Financial Management')->update([
            'name' => 'Finance',
            'module_group_id' => $financeGroup->id
        ]);

        // 8. Diocesan Contributions (keep as is)
        Module::where('name', 'Diocesan Contributions')->update([
            'module_group_id' => $financeGroup->id
        ]);

        // =========================================
        // GROUP 4: PROGRAMS (3 modules)
        // =========================================

        // 9. Ministry Coordination → Ministries
        Module::where('name', 'Ministry Coordination')->update([
            'name' => 'Ministries',
            'module_group_id' => $programsGroup->id
        ]);

        // 10. Service Management (keep as is)
        Module::where('name', 'Service Management')->update([
            'module_group_id' => $programsGroup->id
        ]);

        // 11. Facility Management (keep as is)
        Module::where('name', 'Facility Management')->update([
            'module_group_id' => $programsGroup->id
        ]);

        // =========================================
        // GROUP 5: SETTINGS (1 module)
        // =========================================

        // 12. Communication (keep as is)
        Module::where('name', 'Communication')->update([
            'module_group_id' => $settingsGroup->id
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original names and remove group assignments

        Module::where('name', 'Growth')->update([
            'name' => 'Church Demographics & Growth',
            'module_group_id' => null
        ]);

        Module::where('name', 'Church Reporting')->update([
            'name' => 'Church Reports',
            'module_group_id' => null
        ]);

        Module::where('name', 'Members')->update([
            'name' => 'Member Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Attendance')->update([
            'name' => 'Attendance Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Visitors')->update([
            'name' => 'Visitor Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Pastoral Care')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Finance')->update([
            'name' => 'Financial Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Diocesan Contributions')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Ministries')->update([
            'name' => 'Ministry Coordination',
            'module_group_id' => null
        ]);

        Module::where('name', 'Service Management')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Facility Management')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Communication')->update([
            'module_group_id' => null
        ]);
    }
};
