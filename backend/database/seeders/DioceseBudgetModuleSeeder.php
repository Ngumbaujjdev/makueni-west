<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Models\Permission;
use App\Enums\TerritoryType;
use Illuminate\Support\Facades\DB;

class DioceseBudgetModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds the complete Budget Management module with all submodules and sub-submodules
     * for Diocese level based on UI requirements.
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('=================================================================');
        $this->command->info('DIOCESE BUDGET MANAGEMENT MODULE SEEDER');
        $this->command->info('=================================================================');
        $this->command->info('');

        DB::beginTransaction();

        try {
            // Get the Finance module group for diocese
            $financeGroup = ModuleGroup::where('slug', 'diocese-finance')
                ->where('territory_scope', TerritoryType::DIOCESE->value)
                ->first();

            if (!$financeGroup) {
                $this->command->error('Finance module group not found! Please run ModuleGroupSeeder first.');
                return;
            }

            // Create the main Budget Management module
            $budgetModule = Module::firstOrCreate(
                ['name' => 'Budget Management'],
                [
                    'module_group_id' => $financeGroup->id,
                    'icon' => 'ri-calculator-line',
                    'number' => 100,
                    'description' => 'Comprehensive budget management for diocese operations',
                    'is_active' => true
                ]
            );

            $this->command->info("Module: Budget Management (ID: {$budgetModule->id})");

            // Seed all submodules with their sub-submodules
            $this->seedBudgetOverview($budgetModule);
            $this->seedBudgetPlanning($budgetModule);
            $this->seedBudgetMonitoring($budgetModule);
            $this->seedBudgetApprovals($budgetModule);
            $this->seedBudgetReports($budgetModule);
            $this->seedResourceAllocation($budgetModule);
            $this->seedIncomeTracking($budgetModule);
            $this->seedExpenseTracking($budgetModule);

            DB::commit();

            $this->command->info('');
            $this->command->info('=================================================================');
            $this->command->info('Budget Management module seeded successfully!');
            $this->command->info('=================================================================');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Seeding failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Budget Overview submodule with sub-submodules
     */
    private function seedBudgetOverview(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Budget Overview'],
            [
                'path' => '/diocese/budget/overview',
                'description' => 'Overview of all budgets and quick access',
                'is_active' => true
            ]
        );

        $subSubmodules = [
            ['title' => 'All Budgets', 'path' => '/diocese/budget/overview/all'],
            ['title' => 'Budget Details', 'path' => '/diocese/budget/overview/details'],
            ['title' => 'Create Budget', 'path' => '/diocese/budget/overview/create'],
        ];

        foreach ($subSubmodules as $data) {
            SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $data['title']],
                [
                    'path' => $data['path'],
                    'description' => $data['title'],
                    'is_active' => true
                ]
            );
        }

        $this->command->info("   Submodule: Budget Overview (3 sub-submodules)");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'update', 'delete', 'export']);
    }

    /**
     * Budget Planning submodule with sub-submodules
     */
    private function seedBudgetPlanning(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Budget Planning'],
            [
                'path' => '/diocese/budget/planning',
                'description' => 'Budget creation and category management',
                'is_active' => true
            ]
        );

        $subSubmodules = [
            ['title' => 'Annual Budget Creation', 'path' => '/diocese/budget/planning/annual'],
            ['title' => 'Budget Categories Setup', 'path' => '/diocese/budget/planning/categories'],
            ['title' => 'Budget Approval Workflow', 'path' => '/diocese/budget/planning/workflow'],
        ];

        foreach ($subSubmodules as $data) {
            SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $data['title']],
                [
                    'path' => $data['path'],
                    'description' => $data['title'],
                    'is_active' => true
                ]
            );
        }

        $this->command->info("   Submodule: Budget Planning (3 sub-submodules)");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'update', 'approve', 'export']);
    }

    /**
     * Budget Monitoring submodule with sub-submodules
     */
    private function seedBudgetMonitoring(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Budget Monitoring'],
            [
                'path' => '/diocese/budget/monitoring',
                'description' => 'Track and monitor budget performance',
                'is_active' => true
            ]
        );

        $subSubmodules = [
            ['title' => 'Budget Tracking', 'path' => '/diocese/budget/monitoring/tracking'],
            ['title' => 'Variance Analysis', 'path' => '/diocese/budget/monitoring/variance'],
            ['title' => 'Budget Adjustments', 'path' => '/diocese/budget/monitoring/adjustments'],
        ];

        foreach ($subSubmodules as $data) {
            SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $data['title']],
                [
                    'path' => $data['path'],
                    'description' => $data['title'],
                    'is_active' => true
                ]
            );
        }

        $this->command->info("   Submodule: Budget Monitoring (3 sub-submodules)");
        $this->createPermissions($module->id, $submodule->id, ['read', 'update', 'approve', 'export']);
    }

    /**
     * Budget Approvals submodule with sub-submodules
     */
    private function seedBudgetApprovals(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Budget Approvals'],
            [
                'path' => '/diocese/budget/approvals',
                'description' => 'Budget approval management and history',
                'is_active' => true
            ]
        );

        $subSubmodules = [
            ['title' => 'Pending Approval', 'path' => '/diocese/budget/approvals/pending'],
            ['title' => 'Approval Workflow', 'path' => '/diocese/budget/approvals/workflow'],
            ['title' => 'Approval History', 'path' => '/diocese/budget/approvals/history'],
        ];

        foreach ($subSubmodules as $data) {
            SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $data['title']],
                [
                    'path' => $data['path'],
                    'description' => $data['title'],
                    'is_active' => true
                ]
            );
        }

        $this->command->info("   Submodule: Budget Approvals (3 sub-submodules)");
        $this->createPermissions($module->id, $submodule->id, ['read', 'approve', 'reject', 'export']);
    }

    /**
     * Budget Reports submodule with sub-submodules
     */
    private function seedBudgetReports(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Budget Reports'],
            [
                'path' => '/diocese/budget/reports',
                'description' => 'Budget reporting and analytics',
                'is_active' => true
            ]
        );

        $subSubmodules = [
            ['title' => 'Budget Summary', 'path' => '/diocese/budget/reports/summary'],
            ['title' => 'Performance Report', 'path' => '/diocese/budget/reports/performance'],
            ['title' => 'Export Reports', 'path' => '/diocese/budget/reports/export'],
        ];

        foreach ($subSubmodules as $data) {
            SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $data['title']],
                [
                    'path' => $data['path'],
                    'description' => $data['title'],
                    'is_active' => true
                ]
            );
        }

        $this->command->info("   Submodule: Budget Reports (3 sub-submodules)");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'export']);
    }

    /**
     * Resource Allocation submodule (no sub-submodules)
     */
    private function seedResourceAllocation(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Resource Allocation'],
            [
                'path' => '/diocese/budget/allocation',
                'description' => 'Manage resource allocation across departments',
                'is_active' => true
            ]
        );

        $this->command->info("   Submodule: Resource Allocation");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'update', 'approve', 'export']);
    }

    /**
     * Income Tracking submodule (no sub-submodules)
     */
    private function seedIncomeTracking(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Income Tracking'],
            [
                'path' => '/diocese/budget/income',
                'description' => 'Track income against budget',
                'is_active' => true
            ]
        );

        $this->command->info("   Submodule: Income Tracking");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'update', 'export']);
    }

    /**
     * Expense Tracking submodule (no sub-submodules)
     */
    private function seedExpenseTracking(Module $module): void
    {
        $submodule = Submodule::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Expense Tracking'],
            [
                'path' => '/diocese/budget/expenses',
                'description' => 'Track expenses against budget',
                'is_active' => true
            ]
        );

        $this->command->info("   Submodule: Expense Tracking");
        $this->createPermissions($module->id, $submodule->id, ['read', 'create', 'update', 'approve', 'export']);
    }

    /**
     * Helper method to create permissions
     */
    private function createPermissions(int $moduleId, int $submoduleId, array $actions): void
    {
        $module = Module::find($moduleId);
        $submodule = Submodule::find($submoduleId);

        foreach ($actions as $action) {
            $permissionName = strtolower($module->name) . '.' . strtolower($submodule->title) . '.' . strtolower($action);
            $permissionName = preg_replace('/[^a-z0-9.]/', '', str_replace([' ', "'", '&'], ['', '', ''], $permissionName));

            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $moduleId,
                'submodule_id' => $submoduleId,
                'sub_submodule_id' => null,
                'action' => $action,
                'territory_scope' => 'diocese'
            ]);
        }
    }
}
