<?php

namespace Tests\Feature\Financial;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use App\Models\BudgetType;
use App\Models\Church;
use App\Models\Diocese;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\BudgetAccess;

/**
 * Two churches, a pastor of the first (church budget + budget-lines
 * permissions, as ChurchBudgetAccessSeeder grants), a diocese approver, one
 * budget type and category, and shared lines for churches, the diocese and
 * all territories.
 */
trait BuildsBudgetWorld
{
    protected Church $myChurch;

    protected Church $otherChurch;

    protected Diocese $diocese;

    protected User $pastor;

    protected User $approver;

    protected BudgetType $type;

    protected BudgetCategory $category;

    protected BudgetLine $churchLine;

    protected BudgetLine $allLine;

    protected BudgetLine $dioceseLine;

    protected function buildBudgetWorld(): void
    {
        $this->diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        $this->pastor = $this->userWithRole('pastor', 'Test Pastor', 'church', $this->myChurch->id, [
            ...array_map(fn ($a) => BudgetAccess::CHURCH_PERMISSION_PREFIX.".{$a}", ['read', 'create', 'update', 'submit']),
            ...array_map(fn ($a) => "church.settings.budgetsettings.budgetlines.{$a}", ['read', 'create', 'update', 'delete']),
        ]);
        $this->approver = $this->userWithRole('bishop', 'Test Bishop', 'diocese', $this->diocese->id, [BudgetAccess::APPROVE_PERMISSION]);

        $this->type = BudgetType::create(['name' => 'Annual', 'slug' => 'annual', 'duration_months' => 12, 'is_active' => true]);
        $this->category = BudgetCategory::create(['name' => 'Operations', 'slug' => 'operations', 'is_active' => true]);
        $this->churchLine = $this->sharedLine('Church Rent', 'church');
        $this->allLine = $this->sharedLine('Utilities', 'all');
        $this->dioceseLine = $this->sharedLine('Diocese Office', 'diocese');
    }

    private function userWithRole(string $username, string $roleName, string $level, int $territoryId, array $permissions): User
    {
        $role = Role::create(['name' => $roleName, 'guard_name' => 'web', 'territory_level' => $level]);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['action' => substr(strrchr($name, '.'), 1), 'territory_scope' => $level],
            ));
        }
        $user = User::create([
            'firstname' => 'Test', 'lastname' => ucfirst($username), 'username' => "test.{$username}",
            'email' => "test.{$username}@example.test", 'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    protected function sharedLine(string $name, string $scope): BudgetLine
    {
        return BudgetLine::create([
            'budget_category_id' => $this->category->id, 'name' => $name, 'slug' => str($name)->slug(),
            'territory_scope' => $scope, 'is_active' => true,
        ]);
    }

    protected function ownLine(Church $church, string $name): BudgetLine
    {
        return BudgetLine::create([
            'budget_category_id' => $this->category->id, 'name' => $name, 'slug' => str($name)->slug(),
            'territory_scope' => 'church', 'territory_type' => 'church', 'territory_id' => $church->id, 'is_active' => true,
        ]);
    }

    protected function budgetFor(Church $church, string $status = 'draft', array $lines = []): Budget
    {
        $budget = Budget::create([
            'budget_type_id' => $this->type->id, 'territory_type' => 'church', 'territory_id' => $church->id,
            'name' => "{$church->name} 2026 ".uniqid(), 'slug' => 'b-'.uniqid(), 'fiscal_year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => $status,
        ]);
        foreach ($lines ?: [$this->churchLine] as $line) {
            $budget->budgetLineItems()->create([
                'budget_line_id' => $line->id, 'budget_category_id' => $line->budget_category_id, 'budgeted_amount' => 1000,
            ]);
        }

        return $budget;
    }
}
