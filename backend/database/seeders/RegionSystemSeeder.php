<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Models\Permission;

class RegionSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedRegionalOversight();
        $this->seedChurchSupport();
        $this->seedPastoralDevelopment();
        $this->seedResourceManagement();
        $this->seedRegionalPrograms();
        $this->seedConflictResolution();
        $this->seedRegionalFinances();
        $this->seedRegionalReporting();

        $this->command->info('✅ Complete regional system seeded successfully!');
    }

    private function seedRegionalOversight()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Regional Oversight'],
            [
                'icon' => 'eye',
                'number' => 11,
                'description' => 'Oversight and coordination of churches within the region',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Church Monitoring', 'path' => '/regional/monitoring'],
            ['title' => 'Performance Assessment', 'path' => '/regional/assessment'],
            ['title' => 'Church Visits', 'path' => '/regional/visits'],
            ['title' => 'Regional Meetings', 'path' => '/regional/meetings']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' for regional churches',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedChurchSupport()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Church Support'],
            [
                'icon' => 'handshake',
                'number' => 12,
                'description' => 'Support services and assistance for regional churches',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Technical Support' => [
                'path' => '/support/technical',
                'sub_submodules' => [
                    ['title' => 'IT Assistance', 'path' => '/support/technical/it'],
                    ['title' => 'System Training', 'path' => '/support/technical/training'],
                    ['title' => 'Equipment Support', 'path' => '/support/technical/equipment']
                ]
            ],
            'Administrative Support' => [
                'path' => '/support/administrative',
                'sub_submodules' => [
                    ['title' => 'Documentation Help', 'path' => '/support/admin/documentation'],
                    ['title' => 'Process Guidance', 'path' => '/support/admin/processes'],
                    ['title' => 'Compliance Assistance', 'path' => '/support/admin/compliance']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title . ' for churches',
                'is_active' => true
            ]);

            foreach ($data['sub_submodules'] as $subSubmoduleData) {
                $subSubmodule = SubSubmodule::firstOrCreate([
                    'submodule_id' => $submodule->id,
                    'title' => $subSubmoduleData['title']
                ], [
                    'path' => $subSubmoduleData['path'],
                    'description' => $subSubmoduleData['title'],
                    'is_active' => true
                ]);

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'update', 'approve']);
            }
        }

        // General support submodules
        $generalSubmodules = [
            ['title' => 'Resource Sharing', 'path' => '/support/resources'],
            ['title' => 'Emergency Assistance', 'path' => '/support/emergency']
        ];

        foreach ($generalSubmodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'],
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve']);
        }
    }

    private function seedPastoralDevelopment()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Pastoral Development'],
            [
                'icon' => 'graduation-cap',
                'number' => 13,
                'description' => 'Training, mentoring, and development of regional pastors',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Training Programs', 'path' => '/pastoral/training'],
            ['title' => 'Mentorship Coordination', 'path' => '/pastoral/mentorship'],
            ['title' => 'Performance Evaluation', 'path' => '/pastoral/evaluation'],
            ['title' => 'Continuing Education', 'path' => '/pastoral/education'],
            ['title' => 'Pastoral Transfers', 'path' => '/pastoral/transfers']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' management',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedResourceManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Resource Management'],
            [
                'icon' => 'package',
                'number' => 14,
                'description' => 'Distribution and management of regional resources',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Material Distribution' => [
                'path' => '/resources/materials',
                'sub_submodules' => [
                    ['title' => 'Literature Distribution', 'path' => '/resources/materials/literature'],
                    ['title' => 'Equipment Allocation', 'path' => '/resources/materials/equipment'],
                    ['title' => 'Supply Management', 'path' => '/resources/materials/supplies']
                ]
            ],
            'Financial Resources' => [
                'path' => '/resources/financial',
                'sub_submodules' => [
                    ['title' => 'Church Grants', 'path' => '/resources/financial/grants'],
                    ['title' => 'Emergency Funds', 'path' => '/resources/financial/emergency'],
                    ['title' => 'Development Funds', 'path' => '/resources/financial/development']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title . ' management',
                'is_active' => true
            ]);

            foreach ($data['sub_submodules'] as $subSubmoduleData) {
                $subSubmodule = SubSubmodule::firstOrCreate([
                    'submodule_id' => $submodule->id,
                    'title' => $subSubmoduleData['title']
                ], [
                    'path' => $subSubmoduleData['path'],
                    'description' => $subSubmoduleData['title'],
                    'is_active' => true
                ]);

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'update', 'approve', 'export']);
            }
        }
    }

    private function seedRegionalPrograms()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Regional Programs'],
            [
                'icon' => 'calendar',
                'number' => 15,
                'description' => 'Regional-wide programs, events, and initiatives',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Regional Conferences', 'path' => '/programs/conferences'],
            ['title' => 'Youth Rallies', 'path' => '/programs/youth'],
            ['title' => 'Women\'s Conventions', 'path' => '/programs/women'],
            ['title' => 'Men\'s Retreats', 'path' => '/programs/men'],
            ['title' => 'Evangelism Campaigns', 'path' => '/programs/evangelism'],
            ['title' => 'Leadership Seminars', 'path' => '/programs/leadership']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' coordination',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedConflictResolution()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Conflict Resolution'],
            [
                'icon' => 'scale',
                'number' => 16,
                'description' => 'Mediation and resolution of church and leadership conflicts',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Church Disputes', 'path' => '/conflicts/church'],
            ['title' => 'Leadership Conflicts', 'path' => '/conflicts/leadership'],
            ['title' => 'Member Grievances', 'path' => '/conflicts/grievances'],
            ['title' => 'Disciplinary Actions', 'path' => '/conflicts/discipline'],
            ['title' => 'Mediation Sessions', 'path' => '/conflicts/mediation']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' management',
                'is_active' => true
            ]);

            // Restricted permissions for sensitive conflict resolution
            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve']);
        }
    }

    private function seedRegionalFinances()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Regional Finances'],
            [
                'icon' => 'coins',
                'number' => 17,
                'description' => 'Regional financial management and church contributions',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Church Contributions' => [
                'path' => '/finances/contributions',
                'sub_submodules' => [
                    ['title' => 'Monthly Assessments', 'path' => '/finances/contributions/assessments'],
                    ['title' => 'Special Offerings', 'path' => '/finances/contributions/special'],
                    ['title' => 'Project Contributions', 'path' => '/finances/contributions/projects']
                ]
            ],
            'Regional Expenses' => [
                'path' => '/finances/expenses',
                'sub_submodules' => [
                    ['title' => 'Administrative Costs', 'path' => '/finances/expenses/admin'],
                    ['title' => 'Program Expenses', 'path' => '/finances/expenses/programs'],
                    ['title' => 'Infrastructure Costs', 'path' => '/finances/expenses/infrastructure']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title . ' management',
                'is_active' => true
            ]);

            foreach ($data['sub_submodules'] as $subSubmoduleData) {
                $subSubmodule = SubSubmodule::firstOrCreate([
                    'submodule_id' => $submodule->id,
                    'title' => $subSubmoduleData['title']
                ], [
                    'path' => $subSubmoduleData['path'],
                    'description' => $subSubmoduleData['title'],
                    'is_active' => true
                ]);

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'update', 'approve', 'export']);
            }
        }

        // Regional financial reports
        $reportsSubmodule = Submodule::firstOrCreate([
            'module_id' => $module->id,
            'title' => 'Regional Financial Reports'
        ], [
            'path' => '/finances/reports',
            'description' => 'Regional financial summaries and reports',
            'is_active' => true
        ]);

        $this->createPermissions($module->id, $reportsSubmodule->id, null, ['read', 'export', 'approve']);
    }

    private function seedRegionalReporting()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Regional Reporting'],
            [
                'icon' => 'file-bar-chart',
                'number' => 18,
                'description' => 'Regional reports and statistics for diocese submission',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Church Statistics', 'path' => '/reporting/statistics'],
            ['title' => 'Regional Performance', 'path' => '/reporting/performance'],
            ['title' => 'Diocese Submissions', 'path' => '/reporting/diocese'],
            ['title' => 'Annual Reports', 'path' => '/reporting/annual'],
            ['title' => 'Dashboard Analytics', 'path' => '/reporting/analytics']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' management',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export', 'approve']);
        }
    }

    /**
     * Helper method to create permissions
     */
    private function createPermissions($moduleId, $submoduleId, $subSubmoduleId, $actions)
    {
        $module = Module::find($moduleId);
        $submodule = Submodule::find($submoduleId);
        $subSubmodule = $subSubmoduleId ? SubSubmodule::find($subSubmoduleId) : null;

        foreach ($actions as $action) {
            // Create permission name
            $permissionName = strtolower($module->name) . '.' . strtolower($submodule->title);
            if ($subSubmodule) {
                $permissionName .= '.' . strtolower($subSubmodule->title);
            }
            $permissionName .= '.' . strtolower($action);

            // Clean the permission name (remove spaces, special characters)
            $permissionName = preg_replace('/[^a-z0-9.]/', '', str_replace([' ', "'"], ['', ''], $permissionName));

            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $moduleId,
                'submodule_id' => $submoduleId,
                'sub_submodule_id' => $subSubmoduleId,
                'action' => $action,
                'territory_scope' => 'region'
            ]);
        }
    }
}
