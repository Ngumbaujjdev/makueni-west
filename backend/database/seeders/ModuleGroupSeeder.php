<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModuleGroup;
use App\Enums\TerritoryType;
use Illuminate\Support\Facades\DB;

class ModuleGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Seeding Module Groups...');

        DB::beginTransaction();

        try {
            // Delete existing data (instead of truncate)
            $this->command->info('🗑️  Deleting existing module groups...');
            ModuleGroup::query()->delete();

            // =========================================
            // DIOCESE LEVEL GROUPS
            // =========================================

            $dioceseGroups = [
                [
                    'name' => 'Overview',
                    'slug' => 'diocese-overview',
                    'icon' => 'ri-dashboard-line',
                    'order' => 1,
                    'territory_scope' => TerritoryType::DIOCESE->value,
                    'description' => 'Dashboard, reports, and analytics',
                    'is_active' => true
                ],
                [
                    'name' => 'Churches',
                    'slug' => 'diocese-churches',
                    'icon' => 'ri-building-line',
                    'order' => 2,
                    'territory_scope' => TerritoryType::DIOCESE->value,
                    'description' => 'Manage churches across the diocese',
                    'is_active' => true
                ],
                [
                    'name' => 'Finance',
                    'slug' => 'diocese-finance',
                    'icon' => 'ri-money-dollar-circle-line',
                    'order' => 3,
                    'territory_scope' => TerritoryType::DIOCESE->value,
                    'description' => 'Financial operations and reporting',
                    'is_active' => true
                ],
                [
                    'name' => 'Programs',
                    'slug' => 'diocese-programs',
                    'icon' => 'ri-calendar-event-line',
                    'order' => 4,
                    'territory_scope' => TerritoryType::DIOCESE->value,
                    'description' => 'Initiatives, events, and activities',
                    'is_active' => true
                ],
                [
                    'name' => 'Settings',
                    'slug' => 'diocese-settings',
                    'icon' => 'ri-settings-3-line',
                    'order' => 5,
                    'territory_scope' => TerritoryType::DIOCESE->value,
                    'description' => 'Communications and system settings',
                    'is_active' => true
                ]
            ];

            foreach ($dioceseGroups as $group) {
                ModuleGroup::create($group);
            }

            $this->command->info('✅ Diocese module groups created (5 groups)');

            // =========================================
            // REGION LEVEL GROUPS
            // =========================================

            $regionGroups = [
                [
                    'name' => 'Overview',
                    'slug' => 'region-overview',
                    'icon' => 'ri-dashboard-line',
                    'order' => 1,
                    'territory_scope' => TerritoryType::REGION->value,
                    'description' => 'Regional dashboard and analytics',
                    'is_active' => true
                ],
                [
                    'name' => 'Churches',
                    'slug' => 'region-churches',
                    'icon' => 'ri-building-line',
                    'order' => 2,
                    'territory_scope' => TerritoryType::REGION->value,
                    'description' => 'Monitor churches in the region',
                    'is_active' => true
                ],
                [
                    'name' => 'Finance',
                    'slug' => 'region-finance',
                    'icon' => 'ri-money-dollar-circle-line',
                    'order' => 3,
                    'territory_scope' => TerritoryType::REGION->value,
                    'description' => 'Regional financial operations',
                    'is_active' => true
                ],
                [
                    'name' => 'Programs',
                    'slug' => 'region-programs',
                    'icon' => 'ri-calendar-event-line',
                    'order' => 4,
                    'territory_scope' => TerritoryType::REGION->value,
                    'description' => 'Regional programs and events',
                    'is_active' => true
                ],
                [
                    'name' => 'Settings',
                    'slug' => 'region-settings',
                    'icon' => 'ri-settings-3-line',
                    'order' => 5,
                    'territory_scope' => TerritoryType::REGION->value,
                    'description' => 'Regional communications and settings',
                    'is_active' => true
                ]
            ];

            foreach ($regionGroups as $group) {
                ModuleGroup::create($group);
            }

            $this->command->info('✅ Region module groups created (5 groups)');

            // =========================================
            // SUBREGION LEVEL GROUPS
            // =========================================

            $subregionGroups = [
                [
                    'name' => 'Overview',
                    'slug' => 'subregion-overview',
                    'icon' => 'ri-dashboard-line',
                    'order' => 1,
                    'territory_scope' => TerritoryType::SUBREGION->value,
                    'description' => 'Subregional dashboard and analytics',
                    'is_active' => true
                ],
                [
                    'name' => 'Churches',
                    'slug' => 'subregion-churches',
                    'icon' => 'ri-building-line',
                    'order' => 2,
                    'territory_scope' => TerritoryType::SUBREGION->value,
                    'description' => 'Monitor churches in the subregion',
                    'is_active' => true
                ],
                [
                    'name' => 'Finance',
                    'slug' => 'subregion-finance',
                    'icon' => 'ri-money-dollar-circle-line',
                    'order' => 3,
                    'territory_scope' => TerritoryType::SUBREGION->value,
                    'description' => 'Subregional financial operations',
                    'is_active' => true
                ],
                [
                    'name' => 'Programs',
                    'slug' => 'subregion-programs',
                    'icon' => 'ri-calendar-event-line',
                    'order' => 4,
                    'territory_scope' => TerritoryType::SUBREGION->value,
                    'description' => 'Subregional programs and events',
                    'is_active' => true
                ],
                [
                    'name' => 'Settings',
                    'slug' => 'subregion-settings',
                    'icon' => 'ri-settings-3-line',
                    'order' => 5,
                    'territory_scope' => TerritoryType::SUBREGION->value,
                    'description' => 'Subregional communications and settings',
                    'is_active' => true
                ]
            ];

            foreach ($subregionGroups as $group) {
                ModuleGroup::create($group);
            }

            $this->command->info('✅ Subregion module groups created (5 groups)');

            // =========================================
            // CHURCH LEVEL GROUPS
            // =========================================

            $churchGroups = [
                [
                    'name' => 'Overview',
                    'slug' => 'church-overview',
                    'icon' => 'ri-dashboard-line',
                    'order' => 1,
                    'territory_scope' => TerritoryType::CHURCH->value,
                    'description' => 'Church dashboard and analytics',
                    'is_active' => true
                ],
                [
                    'name' => 'Members',
                    'slug' => 'church-members',
                    'icon' => 'ri-user-line',
                    'order' => 2,
                    'territory_scope' => TerritoryType::CHURCH->value,
                    'description' => 'Manage church members',
                    'is_active' => true
                ],
                [
                    'name' => 'Finance',
                    'slug' => 'church-finance',
                    'icon' => 'ri-money-dollar-circle-line',
                    'order' => 3,
                    'territory_scope' => TerritoryType::CHURCH->value,
                    'description' => 'Church financial operations',
                    'is_active' => true
                ],
                [
                    'name' => 'Programs',
                    'slug' => 'church-programs',
                    'icon' => 'ri-calendar-event-line',
                    'order' => 4,
                    'territory_scope' => TerritoryType::CHURCH->value,
                    'description' => 'Church programs and events',
                    'is_active' => true
                ],
                [
                    'name' => 'Settings',
                    'slug' => 'church-settings',
                    'icon' => 'ri-settings-3-line',
                    'order' => 5,
                    'territory_scope' => TerritoryType::CHURCH->value,
                    'description' => 'Church communications and settings',
                    'is_active' => true
                ]
            ];

            foreach ($churchGroups as $group) {
                ModuleGroup::create($group);
            }

            $this->command->info('✅ Church module groups created (5 groups)');

            DB::commit();
            $this->command->info('🎉 All module groups seeded successfully! (20 total groups)');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
