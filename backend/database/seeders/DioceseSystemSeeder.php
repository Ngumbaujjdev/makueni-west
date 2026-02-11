<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Models\Permission;

class DioceseSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Group 1: Overview & Dashboards
        $this->seedDioceseDashboard();
        $this->seedDemographicsAnalytics();
        $this->seedDioceseReportsAnalytics();

        // Group 2: Church Management
        $this->seedAllChurchesOverview();
        $this->seedIndividualChurchDetails();
        $this->seedDioceseComplianceMonitoring();

        // Group 3: Financial Management
        $this->seedTitheManagement();
        $this->seedDioceseBudgetManagement();
        $this->seedResourceAllocationManagement();
        $this->seedDioceseIncomeTracking();
        $this->seedDioceseExpenseTracking();
        $this->seedFinancialReports();

        // Group 4: Programs & Activities
        $this->seedDioceseInitiativesManagement();
        $this->seedDioceseEventsManagement();
        $this->seedDioceseCalendar();

        // Group 5: Communication & Administration
        $this->seedDioceseCommunicationsHub();
        $this->seedDioceseSettings();

        $this->command->info('✅ Complete diocese system seeded successfully (17 modules in 5 groups)!');
    }

    // === GROUP 1: OVERVIEW & DASHBOARDS ===

    private function seedDioceseDashboard()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Dashboard'],
            [
                'icon' => 'dashboard',
                'number' => 19,
                'description' => 'Comprehensive overview of entire diocese operations',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Key Metrics Cards', 'path' => '/diocese/dashboard/metrics'],
            ['title' => 'Diocese Trends Charts', 'path' => '/diocese/dashboard/trends'],
            ['title' => 'Regional Comparison', 'path' => '/diocese/dashboard/comparison'],
            ['title' => 'Compliance Dashboard', 'path' => '/diocese/dashboard/compliance'],
            ['title' => 'Financial Summary', 'path' => '/diocese/dashboard/financial'],
            ['title' => 'Map View', 'path' => '/diocese/dashboard/map']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' for diocese overview',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export']);
        }
    }

    private function seedDemographicsAnalytics()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Demographics Analytics'],
            [
                'icon' => 'users-chart',
                'number' => 20,
                'description' => 'Diocese-wide demographic analysis and insights',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Demographics Summary', 'path' => '/diocese/analytics/demographics/summary'],
            ['title' => 'Growth Analysis', 'path' => '/diocese/analytics/demographics/growth'],
            ['title' => 'Comparative Analysis', 'path' => '/diocese/analytics/demographics/comparison'],
            ['title' => 'Demographic Forecasting', 'path' => '/diocese/analytics/demographics/forecasting'],
            ['title' => 'Demographic Heatmap', 'path' => '/diocese/analytics/demographics/heatmap']
        ];

        foreach ($submodules as $submoduleData) {
            $submodule = Submodule::firstOrCreate([
                'module_id' => $module->id,
                'title' => $submoduleData['title']
            ], [
                'path' => $submoduleData['path'],
                'description' => $submoduleData['title'] . ' analytics',
                'is_active' => true
            ]);

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export']);
        }
    }

    private function seedDioceseReportsAnalytics()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Reports & Analytics'],
            [
                'icon' => 'chart-line',
                'number' => 21,
                'description' => 'Comprehensive reporting and data analytics for strategic decisions',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Pre-configured Reports' => [
                'path' => '/diocese/reports/templates',
                'sub_submodules' => [
                    ['title' => 'Monthly Diocese Summary', 'path' => '/diocese/reports/templates/monthly'],
                    ['title' => 'Quarterly Performance', 'path' => '/diocese/reports/templates/quarterly'],
                    ['title' => 'Annual Comprehensive', 'path' => '/diocese/reports/templates/annual']
                ]
            ],
            'Custom Report Builder' => [
                'path' => '/diocese/reports/builder',
                'sub_submodules' => [
                    ['title' => 'Report Wizard', 'path' => '/diocese/reports/builder/wizard'],
                    ['title' => 'Data Fields Selection', 'path' => '/diocese/reports/builder/fields'],
                    ['title' => 'Visualization Options', 'path' => '/diocese/reports/builder/visualization']
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['read', 'create', 'export', 'approve']);
            }
        }

        // General reporting submodules
        $generalSubmodules = [
            ['title' => 'Reports Archive', 'path' => '/diocese/reports/archive'],
            ['title' => 'Scheduled Reports', 'path' => '/diocese/reports/scheduled'],
            ['title' => 'Real-time Analytics', 'path' => '/diocese/reports/realtime']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'create', 'export']);
        }
    }

    // === GROUP 2: CHURCH MANAGEMENT ===

    private function seedAllChurchesOverview()
    {
        $module = Module::firstOrCreate(
            ['name' => 'All Churches Overview'],
            [
                'icon' => 'church-building',
                'number' => 22,
                'description' => 'View and analyze all churches in diocese',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Churches Summary Statistics', 'path' => '/diocese/churches/all/summary'],
            ['title' => 'Church List Management', 'path' => '/diocese/churches/all/list'],
            ['title' => 'Church Search & Filter', 'path' => '/diocese/churches/all/search'],
            ['title' => 'Church Comparison Tool', 'path' => '/diocese/churches/all/comparison'],
            ['title' => 'Churches Map View', 'path' => '/diocese/churches/all/map'],
            ['title' => 'Bulk Actions', 'path' => '/diocese/churches/all/bulk']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export', 'create']);
        }
    }

    private function seedIndividualChurchDetails()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Individual Church Details'],
            [
                'icon' => 'church-detail',
                'number' => 23,
                'description' => 'Detailed view of specific church (Read-Only for Diocese)',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Church Basic Information', 'path' => '/diocese/churches/details/basic'],
            ['title' => 'Demographics Dashboard', 'path' => '/diocese/churches/details/demographics'],
            ['title' => 'Financial Summary', 'path' => '/diocese/churches/details/financial'],
            ['title' => 'Initiatives Participation', 'path' => '/diocese/churches/details/initiatives'],
            ['title' => 'Events Attendance', 'path' => '/diocese/churches/details/events'],
            ['title' => 'Church Performance Score', 'path' => '/diocese/churches/details/performance']
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

            // Read-only for diocese level
            $this->createPermissions($module->id, $submodule->id, null, ['read', 'export']);
        }
    }

    private function seedDioceseComplianceMonitoring()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Compliance Monitoring'],
            [
                'icon' => 'shield-check',
                'number' => 24,
                'description' => 'Monitor church compliance with reporting and financial requirements',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Compliance Overview' => [
                'path' => '/diocese/compliance/overview',
                'sub_submodules' => [
                    ['title' => 'Compliance Dashboard', 'path' => '/diocese/compliance/overview/dashboard'],
                    ['title' => 'Church Compliance List', 'path' => '/diocese/compliance/overview/list'],
                    ['title' => 'Compliance Categories', 'path' => '/diocese/compliance/overview/categories']
                ]
            ],
            'Compliance Management' => [
                'path' => '/diocese/compliance/management',
                'sub_submodules' => [
                    ['title' => 'Compliance Alerts', 'path' => '/diocese/compliance/management/alerts'],
                    ['title' => 'Compliance Reminders', 'path' => '/diocese/compliance/management/reminders'],
                    ['title' => 'Compliance Exceptions', 'path' => '/diocese/compliance/management/exceptions']
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['read', 'create', 'update', 'approve', 'export']);
            }
        }

        // General compliance submodules
        $generalSubmodules = [
            ['title' => 'Compliance Analytics', 'path' => '/diocese/compliance/analytics'],
            ['title' => 'Compliance Reports', 'path' => '/diocese/compliance/reports']
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
    }

    // === GROUP 3: FINANCIAL MANAGEMENT ===

    private function seedTitheManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Tithe Management'],
            [
                'icon' => 'hand-holding-dollar',
                'number' => 25,
                'description' => 'Monitor and manage tithe collections from all churches',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Tithe Overview Dashboard', 'path' => '/diocese/financial/tithe/overview'],
            ['title' => 'Tithe Collection by Region', 'path' => '/diocese/financial/tithe/regions'],
            ['title' => 'Church Tithe Compliance', 'path' => '/diocese/financial/tithe/compliance'],
            ['title' => 'Tithe Payment Recording', 'path' => '/diocese/financial/tithe/recording'],
            ['title' => 'Tithe Reminders', 'path' => '/diocese/financial/tithe/reminders'],
            ['title' => 'Tithe Analytics', 'path' => '/diocese/financial/tithe/analytics']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedDioceseBudgetManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Budget Management'],
            [
                'icon' => 'calculator',
                'number' => 26,
                'description' => 'Manage diocese master budget and allocations',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Budget Planning' => [
                'path' => '/diocese/financial/budget/planning',
                'sub_submodules' => [
                    ['title' => 'Annual Budget Creation', 'path' => '/diocese/financial/budget/planning/annual'],
                    ['title' => 'Budget Categories Setup', 'path' => '/diocese/financial/budget/planning/categories'],
                    ['title' => 'Budget Approval Workflow', 'path' => '/diocese/financial/budget/planning/approval']
                ]
            ],
            'Budget Monitoring' => [
                'path' => '/diocese/financial/budget/monitoring',
                'sub_submodules' => [
                    ['title' => 'Budget Tracking', 'path' => '/diocese/financial/budget/monitoring/tracking'],
                    ['title' => 'Variance Analysis', 'path' => '/diocese/financial/budget/monitoring/variance'],
                    ['title' => 'Budget Adjustments', 'path' => '/diocese/financial/budget/monitoring/adjustments']
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

        // Budget reports
        $reportsSubmodule = Submodule::firstOrCreate([
            'module_id' => $module->id,
            'title' => 'Budget Reports'
        ], [
            'path' => '/diocese/financial/budget/reports',
            'description' => 'Budget reports and comparisons',
            'is_active' => true
        ]);

        $this->createPermissions($module->id, $reportsSubmodule->id, null, ['read', 'export', 'approve']);
    }

    private function seedResourceAllocationManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Resource Allocation Management'],
            [
                'icon' => 'share-alt',
                'number' => 27,
                'description' => 'Manage distribution of funds from diocese to lower levels',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Allocation Programs' => [
                'path' => '/diocese/financial/allocations/programs',
                'sub_submodules' => [
                    ['title' => 'Create Allocation Program', 'path' => '/diocese/financial/allocations/programs/create'],
                    ['title' => 'Allocation Formula Setup', 'path' => '/diocese/financial/allocations/programs/formula'],
                    ['title' => 'Program Approval', 'path' => '/diocese/financial/allocations/programs/approval']
                ]
            ],
            'Disbursement Management' => [
                'path' => '/diocese/financial/allocations/disbursement',
                'sub_submodules' => [
                    ['title' => 'Disbursement Processing', 'path' => '/diocese/financial/allocations/disbursement/processing'],
                    ['title' => 'Payment Tracking', 'path' => '/diocese/financial/allocations/disbursement/tracking'],
                    ['title' => 'Utilization Monitoring', 'path' => '/diocese/financial/allocations/disbursement/utilization']
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

        // General allocation submodules
        $generalSubmodules = [
            ['title' => 'Allocation Requests', 'path' => '/diocese/financial/allocations/requests'],
            ['title' => 'Allocation Analytics', 'path' => '/diocese/financial/allocations/analytics']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedDioceseIncomeTracking()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Income Tracking'],
            [
                'icon' => 'coins',
                'number' => 28,
                'description' => 'Track all income sources for diocese operations',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Income Dashboard', 'path' => '/diocese/financial/income/dashboard'],
            ['title' => 'Income Sources Management', 'path' => '/diocese/financial/income/sources'],
            ['title' => 'Income Recording', 'path' => '/diocese/financial/income/recording'],
            ['title' => 'Income Analytics', 'path' => '/diocese/financial/income/analytics'],
            ['title' => 'Income Reports', 'path' => '/diocese/financial/income/reports']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedDioceseExpenseTracking()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Expense Tracking'],
            [
                'icon' => 'receipt',
                'number' => 29,
                'description' => 'Record and monitor all diocese operational expenses',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Expense Dashboard', 'path' => '/diocese/financial/expenses/dashboard'],
            ['title' => 'Expense Categories', 'path' => '/diocese/financial/expenses/categories'],
            ['title' => 'Expense Recording', 'path' => '/diocese/financial/expenses/recording'],
            ['title' => 'Expense Approval', 'path' => '/diocese/financial/expenses/approval'],
            ['title' => 'Expense Analytics', 'path' => '/diocese/financial/expenses/analytics']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'approve', 'export']);
        }
    }

    private function seedFinancialReports()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Financial Reports'],
            [
                'icon' => 'file-invoice-dollar',
                'number' => 30,
                'description' => 'Generate comprehensive financial reports for diocese',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Report Templates', 'path' => '/diocese/financial/reports/templates'],
            ['title' => 'Generate Reports', 'path' => '/diocese/financial/reports/generate'],
            ['title' => 'Custom Report Builder', 'path' => '/diocese/financial/reports/builder'],
            ['title' => 'Reports Archive', 'path' => '/diocese/financial/reports/archive'],
            ['title' => 'Scheduled Reports', 'path' => '/diocese/financial/reports/scheduled']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'export', 'approve']);
        }
    }

    // === GROUP 4: PROGRAMS & ACTIVITIES ===

    private function seedDioceseInitiativesManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Initiatives Management'],
            [
                'icon' => 'graduation-cap',
                'number' => 31,
                'description' => 'Create and manage diocese-wide training, seminars, and programs',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Initiative Planning' => [
                'path' => '/diocese/initiatives/planning',
                'sub_submodules' => [
                    ['title' => 'Create Initiative', 'path' => '/diocese/initiatives/planning/create'],
                    ['title' => 'Initiative Types', 'path' => '/diocese/initiatives/planning/types'],
                    ['title' => 'Target Audience', 'path' => '/diocese/initiatives/planning/audience']
                ]
            ],
            'Initiative Management' => [
                'path' => '/diocese/initiatives/management',
                'sub_submodules' => [
                    ['title' => 'Registration Management', 'path' => '/diocese/initiatives/management/registration'],
                    ['title' => 'Participation Tracking', 'path' => '/diocese/initiatives/management/participation'],
                    ['title' => 'Communication', 'path' => '/diocese/initiatives/management/communication']
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

        // General initiatives submodules
        $generalSubmodules = [
            ['title' => 'Initiatives Analytics', 'path' => '/diocese/initiatives/analytics'],
            ['title' => 'Initiatives Calendar', 'path' => '/diocese/initiatives/calendar'],
            ['title' => 'Lower Level Initiatives', 'path' => '/diocese/initiatives/lower-level']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'export']);
        }
    }

    private function seedDioceseEventsManagement()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Events Management'],
            [
                'icon' => 'calendar-star',
                'number' => 32,
                'description' => 'Create and manage diocese-wide events, conferences, and gatherings',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Event Planning' => [
                'path' => '/diocese/events/planning',
                'sub_submodules' => [
                    ['title' => 'Create Event', 'path' => '/diocese/events/planning/create'],
                    ['title' => 'Event Types', 'path' => '/diocese/events/planning/types'],
                    ['title' => 'Venue Management', 'path' => '/diocese/events/planning/venues']
                ]
            ],
            'Event Execution' => [
                'path' => '/diocese/events/execution',
                'sub_submodules' => [
                    ['title' => 'Registration Management', 'path' => '/diocese/events/execution/registration'],
                    ['title' => 'Attendance Tracking', 'path' => '/diocese/events/execution/attendance'],
                    ['title' => 'Event Communication', 'path' => '/diocese/events/execution/communication']
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

        // General events submodules
        $generalSubmodules = [
            ['title' => 'Event Financials', 'path' => '/diocese/events/financials'],
            ['title' => 'Event Feedback', 'path' => '/diocese/events/feedback'],
            ['title' => 'Events Calendar', 'path' => '/diocese/events/calendar']
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

            $this->createPermissions($module->id, $submodule->id, null, ['create', 'read', 'update', 'export']);
        }
    }

    private function seedDioceseCalendar()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Calendar'],
            [
                'icon' => 'calendar-alt',
                'number' => 33,
                'description' => 'Unified calendar view of all diocese activities and deadlines',
                'is_active' => true
            ]
        );

        $submodules = [
            ['title' => 'Calendar Views', 'path' => '/diocese/calendar/views'],
            ['title' => 'Calendar Management', 'path' => '/diocese/calendar/management'],
            ['title' => 'Calendar Synchronization', 'path' => '/diocese/calendar/sync'],
            ['title' => 'Meeting Scheduler', 'path' => '/diocese/calendar/meetings'],
            ['title' => 'Calendar Reports', 'path' => '/diocese/calendar/reports']
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
    }

    // === GROUP 5: COMMUNICATION & ADMINISTRATION ===

    private function seedDioceseCommunicationsHub()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Communications Hub'],
            [
                'icon' => 'megaphone',
                'number' => 34,
                'description' => 'Centralized communication management for entire diocese',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'Message Management' => [
                'path' => '/diocese/communications/messaging',
                'sub_submodules' => [
                    ['title' => 'Compose Messages', 'path' => '/diocese/communications/messaging/compose'],
                    ['title' => 'Message Templates', 'path' => '/diocese/communications/messaging/templates'],
                    ['title' => 'Broadcast Messages', 'path' => '/diocese/communications/messaging/broadcast']
                ]
            ],
            'Communication Tracking' => [
                'path' => '/diocese/communications/tracking',
                'sub_submodules' => [
                    ['title' => 'Message Delivery', 'path' => '/diocese/communications/tracking/delivery'],
                    ['title' => 'Communications History', 'path' => '/diocese/communications/tracking/history'],
                    ['title' => 'Analytics', 'path' => '/diocese/communications/tracking/analytics']
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

        // General communication submodules
        $generalSubmodules = [
            ['title' => 'Hierarchical Messaging', 'path' => '/diocese/communications/hierarchical'],
            ['title' => 'Message Scheduling', 'path' => '/diocese/communications/scheduling'],
            ['title' => 'Inbox Management', 'path' => '/diocese/communications/inbox']
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

    private function seedDioceseSettings()
    {
        $module = Module::firstOrCreate(
            ['name' => 'Diocese Settings'],
            [
                'icon' => 'cogs',
                'number' => 35,
                'description' => 'Configure diocese-level system settings and preferences',
                'is_active' => true
            ]
        );

        $submodulesData = [
            'General Configuration' => [
                'path' => '/diocese/settings/general',
                'sub_submodules' => [
                    ['title' => 'Diocese Information', 'path' => '/diocese/settings/general/info'],
                    ['title' => 'Communication Settings', 'path' => '/diocese/settings/general/communication'],
                    ['title' => 'Financial Settings', 'path' => '/diocese/settings/general/financial']
                ]
            ],
            'System Administration' => [
                'path' => '/diocese/settings/admin',
                'sub_submodules' => [
                    ['title' => 'User Management', 'path' => '/diocese/settings/admin/users'],
                    ['title' => 'Security Settings', 'path' => '/diocese/settings/admin/security'],
                    ['title' => 'System Maintenance', 'path' => '/diocese/settings/admin/maintenance']
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

                $this->createPermissions($module->id, $submodule->id, $subSubmodule->id, ['read', 'update', 'approve']);
            }
        }

        // General settings submodules
        $generalSubmodules = [
            ['title' => 'Compliance Settings', 'path' => '/diocese/settings/compliance'],
            ['title' => 'Notification Preferences', 'path' => '/diocese/settings/notifications'],
            ['title' => 'Help and Support', 'path' => '/diocese/settings/support']
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

            $this->createPermissions($module->id, $submodule->id, null, ['read', 'update', 'approve']);
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
            $permissionName = preg_replace('/[^a-z0-9.]/', '', str_replace([' ', "'", '&'], ['', '', ''], $permissionName));

            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $moduleId,
                'submodule_id' => $submoduleId,
                'sub_submodule_id' => $subSubmoduleId,
                'action' => $action,
                'territory_scope' => 'diocese'
            ]);
        }
    }
}
