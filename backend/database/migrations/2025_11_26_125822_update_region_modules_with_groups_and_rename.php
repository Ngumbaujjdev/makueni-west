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
            ['slug' => 'region-overview', 'territory_scope' => TerritoryType::REGION->value],
            ['name' => 'Overview', 'icon' => 'ri-dashboard-line', 'order' => 1, 'is_active' => true]
        );

        $churchesGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'region-churches', 'territory_scope' => TerritoryType::REGION->value],
            ['name' => 'Churches', 'icon' => 'ri-building-line', 'order' => 2, 'is_active' => true]
        );

        $financeGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'region-finance', 'territory_scope' => TerritoryType::REGION->value],
            ['name' => 'Finance', 'icon' => 'ri-money-dollar-circle-line', 'order' => 3, 'is_active' => true]
        );

        $programsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'region-programs', 'territory_scope' => TerritoryType::REGION->value],
            ['name' => 'Programs', 'icon' => 'ri-calendar-event-line', 'order' => 4, 'is_active' => true]
        );

        $settingsGroup = ModuleGroup::firstOrCreate(
            ['slug' => 'region-settings', 'territory_scope' => TerritoryType::REGION->value],
            ['name' => 'Settings', 'icon' => 'ri-settings-3-line', 'order' => 5, 'is_active' => true]
        );

        // =========================================
        // GROUP 1: OVERVIEW (2 modules)
        // =========================================

        // 1. Regional Oversight → Oversight
        Module::where('name', 'Regional Oversight')->update([
            'name' => 'Oversight',
            'module_group_id' => $overviewGroup->id
        ]);

        // 2. Regional Reporting → Reporting
        Module::where('name', 'Regional Reporting')->update([
            'name' => 'Reporting',
            'module_group_id' => $overviewGroup->id
        ]);

        // =========================================
        // GROUP 2: CHURCHES (2 modules)
        // =========================================

        // 3. Church Support → Support
        Module::where('name', 'Church Support')->update([
            'name' => 'Church Support',
            'module_group_id' => $churchesGroup->id
        ]);

        // 4. Pastoral Development (keep as is)
        Module::where('name', 'Pastoral Development')->update([
            'module_group_id' => $churchesGroup->id
        ]);

        // =========================================
        // GROUP 3: FINANCE (2 modules)
        // =========================================

        // 5. Resource Management → Resources
        Module::where('name', 'Resource Management')->update([
            'name' => 'Resources',
            'module_group_id' => $financeGroup->id
        ]);

        // 6. Regional Finances → Finances
        Module::where('name', 'Regional Finances')->update([
            'name' => 'Finances',
            'module_group_id' => $financeGroup->id
        ]);

        // =========================================
        // GROUP 4: PROGRAMS (1 module)
        // =========================================

        // 7. Regional Programs → Programs
        Module::where('name', 'Regional Programs')->update([
            'name' => 'Programs',
            'module_group_id' => $programsGroup->id
        ]);

        // =========================================
        // GROUP 5: SETTINGS (1 module)
        // =========================================

        // 8. Conflict Resolution (keep as is - sensitive module)
        Module::where('name', 'Conflict Resolution')->update([
            'module_group_id' => $settingsGroup->id
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original names and remove group assignments

        Module::where('name', 'Oversight')->update([
            'name' => 'Regional Oversight',
            'module_group_id' => null
        ]);

        Module::where('name', 'Reporting')->update([
            'name' => 'Regional Reporting',
            'module_group_id' => null
        ]);

        Module::where('name', 'Support')->update([
            'name' => 'Church Support',
            'module_group_id' => null
        ]);

        Module::where('name', 'Pastoral Development')->update([
            'module_group_id' => null
        ]);

        Module::where('name', 'Resources')->update([
            'name' => 'Resource Management',
            'module_group_id' => null
        ]);

        Module::where('name', 'Finances')->update([
            'name' => 'Regional Finances',
            'module_group_id' => null
        ]);

        Module::where('name', 'Programs')->update([
            'name' => 'Regional Programs',
            'module_group_id' => null
        ]);

        Module::where('name', 'Conflict Resolution')->update([
            'module_group_id' => null
        ]);
    }
};