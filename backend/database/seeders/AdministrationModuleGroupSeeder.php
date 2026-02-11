<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ModuleGroup;
use App\Enums\TerritoryType;

class AdministrationModuleGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🔐 ADMINISTRATION MODULE GROUP SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Adding Administration groups for Diocese, Region, and Church levels...');
        $this->command->info('');

        $administrationGroups = [
            // DIOCESE LEVEL
            [
                'name' => 'Administration',
                'slug' => 'diocese-administration',
                'icon' => 'shield-user',
                'order' => 6,
                'territory_scope' => TerritoryType::DIOCESE->value,
                'description' => 'User management, roles, and access control for diocese',
                'is_active' => true,
            ],

            // REGION LEVEL
            [
                'name' => 'Administration',
                'slug' => 'region-administration',
                'icon' => 'shield-user',
                'order' => 6,
                'territory_scope' => TerritoryType::REGION->value,
                'description' => 'User management, roles, and access control for region',
                'is_active' => true,
            ],

            // CHURCH LEVEL
            [
                'name' => 'Administration',
                'slug' => 'church-administration',
                'icon' => 'shield-user',
                'order' => 6,
                'territory_scope' => TerritoryType::CHURCH->value,
                'description' => 'User management, roles, and access control for church',
                'is_active' => true,
            ],
        ];

        foreach ($administrationGroups as $groupData) {
            ModuleGroup::updateOrCreate(
                [
                    'slug' => $groupData['slug'],
                    'territory_scope' => $groupData['territory_scope']
                ],
                $groupData
            );

            $this->command->info("   ✅ Created: {$groupData['slug']}");
        }

        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ Administration groups seeded successfully (3 groups)!');
        $this->command->info(str_repeat('=', 70));
    }
}
