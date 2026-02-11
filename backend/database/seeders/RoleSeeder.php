<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing roles (optional - remove if you want to keep existing)
        // Role::whereNotIn('name', ['Global Administrator'])->delete();

        $this->createDioceseRoles();
        $this->createRegionRoles();
        $this->createSubregionRoles();
        $this->createChurchRoles();

        $this->command->info('✅ All diocese roles seeded successfully!');
    }

    /**
     * Create Diocese Level Roles
     */
    private function createDioceseRoles(): void
    {
        $dioceseRoles = [
            [
                'name' => 'Bishop',
                'territory_level' => 'diocese',
                'description' => 'Overall spiritual leader and administrator of the diocese',
                'is_active' => true
            ],
            [
                'name' => 'Diocese Secretary',
                'territory_level' => 'diocese',
                'description' => 'Administrative oversight, record keeping and correspondence',
                'is_active' => true
            ],
            [
                'name' => 'Diocese Treasurer',
                'territory_level' => 'diocese',
                'description' => 'Financial oversight and management of diocese resources',
                'is_active' => true
            ],
            [
                'name' => 'Diocese Administrator',
                'territory_level' => 'diocese',
                'description' => 'Operations, facilities and administrative management',
                'is_active' => true
            ],
            [
                'name' => 'Diocese Council Member',
                'territory_level' => 'diocese',
                'description' => 'Governance, policy making and strategic decision making',
                'is_active' => true
            ],
            [
                'name' => 'Diocese Finance Officer',
                'territory_level' => 'diocese',
                'description' => 'Financial operations and budget management',
                'is_active' => true
            ]
        ];

        foreach ($dioceseRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name'], 'guard_name' => 'web'],
                $roleData
            );
        }

        $this->command->info('Diocese roles created: ' . count($dioceseRoles));
    }

    /**
     * Create Region Level Roles
     */
    private function createRegionRoles(): void
    {
        $regionRoles = [
            [
                'name' => 'Regional Overseer',
                'territory_level' => 'region',
                'description' => 'Regional spiritual and administrative leadership',
                'is_active' => true
            ],
            [
                'name' => 'Regional Secretary',
                'territory_level' => 'region',
                'description' => 'Regional administration, coordination and record keeping',
                'is_active' => true
            ],
            [
                'name' => 'Regional Coordinator',
                'territory_level' => 'region',
                'description' => 'Program implementation and support coordination',
                'is_active' => true
            ],
            [
                'name' => 'Regional Committee Member',
                'territory_level' => 'region',
                'description' => 'Regional governance and decision making participation',
                'is_active' => true
            ],
            [
                'name' => 'Regional Treasurer',
                'territory_level' => 'region',
                'description' => 'Regional financial coordination and oversight',
                'is_active' => true
            ]
        ];

        foreach ($regionRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name'], 'guard_name' => 'web'],
                $roleData
            );
        }

        $this->command->info('Region roles created: ' . count($regionRoles));
    }

    /**
     * Create Subregion Level Roles
     */
    private function createSubregionRoles(): void
    {
        $subregionRoles = [
            [
                'name' => 'Subregional Overseer',
                'territory_level' => 'subregion',
                'description' => 'Subregional leadership and church coordination',
                'is_active' => true
            ],
            [
                'name' => 'Area Pastor',
                'territory_level' => 'subregion',
                'description' => 'Multi-church pastoral oversight and support',
                'is_active' => true
            ],
            [
                'name' => 'Subregional Secretary',
                'territory_level' => 'subregion',
                'description' => 'Subregional administration and communication',
                'is_active' => true
            ]
        ];

        foreach ($subregionRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name'], 'guard_name' => 'web'],
                $roleData
            );
        }

        $this->command->info('Subregion roles created: ' . count($subregionRoles));
    }

    /**
     * Create Church Level Roles
     */
    private function createChurchRoles(): void
    {
        $churchRoles = [
            // Primary Leadership
            [
                'name' => 'Senior Pastor',
                'territory_level' => 'church',
                'description' => 'Primary church leadership and spiritual oversight',
                'is_active' => true
            ],
            [
                'name' => 'Associate Pastor',
                'territory_level' => 'church',
                'description' => 'Assistant pastoral duties and ministry support',
                'is_active' => true
            ],
            [
                'name' => 'Youth Pastor',
                'territory_level' => 'church',
                'description' => 'Dedicated youth ministry pastoral leadership',
                'is_active' => true
            ],

            // Administration
            [
                'name' => 'Church Secretary',
                'territory_level' => 'church',
                'description' => 'Church administration, records and communication',
                'is_active' => true
            ],
            [
                'name' => 'Church Treasurer',
                'territory_level' => 'church',
                'description' => 'Church financial management and stewardship',
                'is_active' => true
            ],
            [
                'name' => 'Church Administrator',
                'territory_level' => 'church',
                'description' => 'Church operations and facility management',
                'is_active' => true
            ],

            // Governance
            [
                'name' => 'Church Committee Member',
                'territory_level' => 'church',
                'description' => 'Church governance and decision making participation',
                'is_active' => true
            ],
            [
                'name' => 'Elder',
                'territory_level' => 'church',
                'description' => 'Spiritual guidance, counseling and leadership support',
                'is_active' => true
            ],
            [
                'name' => 'Deacon',
                'territory_level' => 'church',
                'description' => 'Service ministry, community outreach and support',
                'is_active' => true
            ],

            // Ministry Leaders
            [
                'name' => 'Youth Leader',
                'territory_level' => 'church',
                'description' => 'Youth ministry coordination and leadership',
                'is_active' => true
            ],
            [
                'name' => 'Women\'s Ministry Leader',
                'territory_level' => 'church',
                'description' => 'Women\'s programs, fellowship and ministry coordination',
                'is_active' => true
            ],
            [
                'name' => 'Men\'s Ministry Leader',
                'territory_level' => 'church',
                'description' => 'Men\'s programs, fellowship and ministry coordination',
                'is_active' => true
            ],
            [
                'name' => 'Children\'s Ministry Leader',
                'territory_level' => 'church',
                'description' => 'Children\'s programs, Sunday school and ministry coordination',
                'is_active' => true
            ],
            [
                'name' => 'Music Director',
                'territory_level' => 'church',
                'description' => 'Worship music, choir and musical ministry leadership',
                'is_active' => true
            ],
            [
                'name' => 'Worship Leader',
                'territory_level' => 'church',
                'description' => 'Worship service coordination and spiritual leadership',
                'is_active' => true
            ],

            // Specialized Ministries
            [
                'name' => 'Sunday School Teacher',
                'territory_level' => 'church',
                'description' => 'Religious education and teaching ministry',
                'is_active' => true
            ],
            [
                'name' => 'Choir Director',
                'territory_level' => 'church',
                'description' => 'Choir coordination and musical worship leadership',
                'is_active' => true
            ],
            [
                'name' => 'Usher Coordinator',
                'territory_level' => 'church',
                'description' => 'Service coordination and congregational assistance',
                'is_active' => true
            ],
            [
                'name' => 'Prayer Group Leader',
                'territory_level' => 'church',
                'description' => 'Prayer ministry coordination and spiritual support',
                'is_active' => true
            ]
        ];

        foreach ($churchRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name'], 'guard_name' => 'web'],
                $roleData
            );
        }

        $this->command->info('Church roles created: ' . count($churchRoles));
    }
}
