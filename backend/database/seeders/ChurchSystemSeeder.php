<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Models\Permission;

class ChurchSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🏛️  CHURCH SYSTEM SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Creating 12 comprehensive church modules...');
        $this->command->info('');

        // Delete existing church modules (1-12) if any
        $this->command->info('🗑️  Cleaning existing church modules...');
        $churchModuleNumbers = range(1, 12);
        $deleted = Module::whereIn('number', $churchModuleNumbers)->delete();
        if ($deleted > 0) {
            $this->command->warn("   Deleted {$deleted} existing church modules");
        }
        $this->command->info('');

        $this->seedMemberManagement();
        $this->seedChurchDemographicsGrowth();
        $this->seedAttendanceManagement();
        $this->seedFinancialManagement();
        $this->seedDiocesanContributions();
        $this->seedMinistryCoordination();
        $this->seedServiceManagement();
        $this->seedFacilityManagement();
        $this->seedCommunication();
        $this->seedVisitorManagement();
        $this->seedPastoralCare();
        $this->seedChurchReports();

        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ Church system seeded successfully (12 modules)!');
        $this->command->info(str_repeat('=', 70));
    }

    private function seedMemberManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Member Management'],
            [
                'icon' => 'users',
                'number' => 1,
                'description' => 'Church member registration, directory, and management',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Member Registration', 'path' => '/members/registration'],
            ['title' => 'Member Directory', 'path' => '/members/directory'],
            ['title' => 'Member Profiles', 'path' => '/members/profiles'],
            ['title' => 'Member Transfer', 'path' => '/members/transfer'],
            ['title' => 'Member Reports', 'path' => '/members/reports']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'delete', 'export']);
        }

        $this->command->info('✅ [1/12] Member Management');
    }

    private function seedChurchDemographicsGrowth()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Church Demographics & Growth'],
            [
                'icon' => 'chart-line',
                'number' => 2,
                'description' => 'Track church demographics, spiritual activities, and growth metrics',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Demographics Tracking' => [
                'path' => '/demographics/tracking',
                'sub_submodules' => [
                    ['title' => 'Sunday School Enrollment', 'path' => '/demographics/tracking/sunday-school'],
                    ['title' => 'Youth Ministry Tracking', 'path' => '/demographics/tracking/youth'],
                    ['title' => 'Men\'s Fellowship', 'path' => '/demographics/tracking/men'],
                    ['title' => 'Women\'s Ministry', 'path' => '/demographics/tracking/women'],
                    ['title' => 'Age Group Distribution', 'path' => '/demographics/tracking/age-groups']
                ]
            ],
            'Spiritual Activities' => [
                'path' => '/demographics/activities',
                'sub_submodules' => [
                    ['title' => 'Baptism Records', 'path' => '/demographics/activities/baptisms'],
                    ['title' => 'Communion Participation', 'path' => '/demographics/activities/communion'],
                    ['title' => 'New Converts Tracking', 'path' => '/demographics/activities/converts'],
                    ['title' => 'Member Departures', 'path' => '/demographics/activities/departures']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title,
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'update', 'export']);
            }
        }

        // General demographics submodules
        $generalSubmodules = [
            ['title' => 'Growth Analytics', 'path' => '/demographics/analytics'],
            ['title' => 'Monthly Statistics', 'path' => '/demographics/monthly-stats']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export']);
        }

        $this->command->info('✅ [2/12] Church Demographics & Growth');
    }

    private function seedAttendanceManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Attendance Management'],
            [
                'icon' => 'calendar-check',
                'number' => 3,
                'description' => 'Track attendance for services, ministries, and events',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Service Attendance', 'path' => '/attendance/services'],
            ['title' => 'Ministry Attendance', 'path' => '/attendance/ministries'],
            ['title' => 'Special Events Attendance', 'path' => '/attendance/events'],
            ['title' => 'Attendance Reports', 'path' => '/attendance/reports']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' tracking',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'export']);
        }

        $this->command->info('✅ [3/12] Attendance Management');
    }

    private function seedFinancialManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Financial Management'],
            [
                'icon' => 'dollar-sign',
                'number' => 4,
                'description' => 'Church finances, tithe, offerings, and expense management',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Income Management' => [
                'path' => '/finance/income',
                'sub_submodules' => [
                    ['title' => 'Tithe Collection', 'path' => '/finance/income/tithe'],
                    ['title' => 'Offerings', 'path' => '/finance/income/offerings'],
                    ['title' => 'Special Collections', 'path' => '/finance/income/special'],
                    ['title' => 'Other Income', 'path' => '/finance/income/other']
                ]
            ],
            'Expense Management' => [
                'path' => '/finance/expenses',
                'sub_submodules' => [
                    ['title' => 'Utility Bills', 'path' => '/finance/expenses/utilities'],
                    ['title' => 'Ministry Expenses', 'path' => '/finance/expenses/ministry'],
                    ['title' => 'Maintenance Costs', 'path' => '/finance/expenses/maintenance'],
                    ['title' => 'Pastoral Support', 'path' => '/finance/expenses/pastoral']
                ]
            ],
            'Budget Management' => [
                'path' => '/finance/budget',
                'sub_submodules' => [
                    ['title' => 'Budget Planning', 'path' => '/finance/budget/planning'],
                    ['title' => 'Budget Tracking', 'path' => '/finance/budget/tracking'],
                    ['title' => 'Budget Reports', 'path' => '/finance/budget/reports']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title,
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'update', 'delete', 'approve', 'export']);
            }
        }

        // Financial Reports
        $reportsSubmodule = Submodule::firstOrCreate([
            'module_id' => $module->id,
            'title' => 'Financial Reports'
        ], [
            'path' => '/finance/reports',
            'description' => 'Financial reports and summaries',
            'is_active' => true
        ]);

        $this->createPermissions($module->id, $reportsSubmodule->id, null, ['read', 'export', 'approve']);

        $this->command->info('✅ [4/12] Financial Management');
    }

    private function seedDiocesanContributions()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocesan Contributions'],
            [
                'icon' => 'hand-holding-dollar',
                'number' => 5,
                'description' => 'Manage diocesan tithe remittance and compliance tracking',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Tithe Calculation' => [
                'path' => '/diocesan/tithe/calculation',
                'sub_submodules' => [
                    ['title' => 'Monthly Income Summary', 'path' => '/diocesan/tithe/calculation/income'],
                    ['title' => 'Tithe Percentage Setup', 'path' => '/diocesan/tithe/calculation/percentage'],
                    ['title' => 'Auto-calculation', 'path' => '/diocesan/tithe/calculation/auto']
                ]
            ],
            'Tithe Remittance' => [
                'path' => '/diocesan/tithe/remittance',
                'sub_submodules' => [
                    ['title' => 'Payment Recording', 'path' => '/diocesan/tithe/remittance/payment'],
                    ['title' => 'Payment History', 'path' => '/diocesan/tithe/remittance/history'],
                    ['title' => 'Payment Receipts', 'path' => '/diocesan/tithe/remittance/receipts']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title,
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

        // Additional submodules
        $generalSubmodules = [
            ['title' => 'Compliance Status', 'path' => '/diocesan/tithe/compliance'],
            ['title' => 'Late Payment Alerts', 'path' => '/diocesan/tithe/alerts'],
            ['title' => 'Tithe Reports', 'path' => '/diocesan/tithe/reports']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export', 'approve']);
        }

        $this->command->info('✅ [5/12] Diocesan Contributions');
    }

    private function seedMinistryCoordination()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Ministry Coordination'],
            [
                'icon' => 'heart',
                'number' => 6,
                'description' => 'Coordinate various church ministries and programs',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Youth Ministry', 'path' => '/ministries/youth'],
            ['title' => 'Women\'s Ministry', 'path' => '/ministries/women'],
            ['title' => 'Men\'s Ministry', 'path' => '/ministries/men'],
            ['title' => 'Children\'s Ministry', 'path' => '/ministries/children'],
            ['title' => 'Music Ministry', 'path' => '/ministries/music'],
            ['title' => 'Prayer Groups', 'path' => '/ministries/prayer'],
            ['title' => 'Ministry Reports', 'path' => '/ministries/reports']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'delete', 'export']);
        }

        $this->command->info('✅ [6/12] Ministry Coordination');
    }

    private function seedServiceManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Service Management'],
            [
                'icon' => 'church',
                'number' => 7,
                'description' => 'Worship planning, sermons, and special services',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Worship Planning', 'path' => '/services/worship'],
            ['title' => 'Sermon Scheduling', 'path' => '/services/sermons'],
            ['title' => 'Music Coordination', 'path' => '/services/music'],
            ['title' => 'Special Services', 'path' => '/services/special'],
            ['title' => 'Service Calendar', 'path' => '/services/calendar']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve']);
        }

        $this->command->info('✅ [7/12] Service Management');
    }

    private function seedFacilityManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Facility Management'],
            [
                'icon' => 'building',
                'number' => 8,
                'description' => 'Church building, maintenance, and equipment management',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Building Maintenance', 'path' => '/facility/maintenance'],
            ['title' => 'Equipment Management', 'path' => '/facility/equipment'],
            ['title' => 'Cleaning Schedule', 'path' => '/facility/cleaning'],
            ['title' => 'Security Management', 'path' => '/facility/security'],
            ['title' => 'Facility Booking', 'path' => '/facility/booking']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'],
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'delete']);
        }

        $this->command->info('✅ [8/12] Facility Management');
    }

    private function seedCommunication()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Communication'],
            [
                'icon' => 'megaphone',
                'number' => 9,
                'description' => 'Church announcements, bulletins, and member communication',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Church Announcements', 'path' => '/communication/announcements'],
            ['title' => 'Member Communication', 'path' => '/communication/members'],
            ['title' => 'Bulletin Management', 'path' => '/communication/bulletin'],
            ['title' => 'Event Notifications', 'path' => '/communication/events'],
            ['title' => 'SMS/Email Templates', 'path' => '/communication/templates']
        ];

        foreach ($submodules as $submoduleData) {
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

        $this->command->info('✅ [9/12] Communication');
    }

    private function seedVisitorManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Visitor Management'],
            [
                'icon' => 'user-plus',
                'number' => 10,
                'description' => 'Visitor registration, follow-up, and conversion tracking',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Visitor Registration', 'path' => '/visitors/registration'],
            ['title' => 'Follow-up Tracking', 'path' => '/visitors/followup'],
            ['title' => 'Visitor Reports', 'path' => '/visitors/reports'],
            ['title' => 'Conversion Tracking', 'path' => '/visitors/conversion']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'],
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'export']);
        }

        $this->command->info('✅ [10/12] Visitor Management');
    }

    private function seedPastoralCare()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Pastoral Care'],
            [
                'icon' => 'heart-handshake',
                'number' => 11,
                'description' => 'Counseling, visits, prayer requests, and member care',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Counseling Records', 'path' => '/pastoral/counseling'],
            ['title' => 'Sick & Shut-in Visits', 'path' => '/pastoral/visits'],
            ['title' => 'Prayer Requests', 'path' => '/pastoral/prayer'],
            ['title' => 'Member Concerns', 'path' => '/pastoral/concerns'],
            ['title' => 'Hospital Visits', 'path' => '/pastoral/hospital']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'],
                'is_active' => true
            ]);

            // Restricted permissions for pastoral care
            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update']);
        }

        $this->command->info('✅ [11/12] Pastoral Care');
    }

    private function seedChurchReports()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Church Reports'],
            [
                'icon' => 'file-text',
                'number' => 12,
                'description' => 'Monthly reports, statistics, and summaries for regional submission',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Monthly Reporting' => [
                'path' => '/reports/monthly',
                'sub_submodules' => [
                    ['title' => 'Statistical Report', 'path' => '/reports/monthly/statistics'],
                    ['title' => 'Financial Summary', 'path' => '/reports/monthly/financial'],
                    ['title' => 'Activities Report', 'path' => '/reports/monthly/activities'],
                    ['title' => 'Demographics Summary', 'path' => '/reports/monthly/demographics']
                ]
            ],
            'Compliance Reporting' => [
                'path' => '/reports/compliance',
                'sub_submodules' => [
                    ['title' => 'Tithe Compliance', 'path' => '/reports/compliance/tithe'],
                    ['title' => 'Reporting Compliance', 'path' => '/reports/compliance/reporting'],
                    ['title' => 'Activity Compliance', 'path' => '/reports/compliance/activities']
                ]
            ]
        ];

        foreach ($submodulesData as $title => $data) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $title
            ], [
                'path' => $data['path'],
                'description' => $title,
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['create', 'read', 'export', 'approve']);
            }
        }

        // General reports
        $generalSubmodules = [
            ['title' => 'Annual Reports', 'path' => '/reports/annual'],
            ['title' => 'Custom Reports', 'path' => '/reports/custom'],
            ['title' => 'Reports Archive', 'path' => '/reports/archive']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export', 'approve']);
        }

        $this->command->info('✅ [12/12] Church Reports');
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
            $permissionName = preg_replace('/[^a-z0-9.]/', '', str_replace([' ', "'", '&'], ['', '', ''], $permissionName));

            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $moduleId,
                'submodule_id' => $submoduleId,
                'sub_submodule_id' => $subSubmoduleId,
                'action' => $action,
                'territory_scope' => 'church'
            ]);
        }
    }
}
