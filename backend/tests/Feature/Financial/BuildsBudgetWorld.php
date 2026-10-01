<?php

namespace Tests\Feature\Financial;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use App\Models\BudgetType;
use App\Models\Church;
use App\Models\Diocese;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Services\Budgets\BudgetBook;

/**
 * A diocese with two regions; region A has two churches, region B one.
 * A pastor of church 1 (church budgets + budget lines, as the seeders
 * grant), an overseer of region A and a bishop - each with read, prepare
 * and (above church) viewing the places below. One budget type, the two
 * categories, and shared lines for churches, regions, the diocese and all.
 */
trait BuildsBudgetWorld
{
    protected Diocese $diocese;

    protected Region $region;

    protected Region $otherRegion;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected Church $farChurch;

    protected User $pastor;

    protected User $overseer;

    protected User $bishop;

    protected BudgetType $type;

    protected BudgetCategory $category;

    protected BudgetCategory $incomeCategory;

    protected BudgetLine $churchLine;

    protected BudgetLine $allLine;

    protected BudgetLine $dioceseLine;

    protected BudgetLine $incomeLine;

    protected function buildBudgetWorld(): void
    {
        $this->diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $this->region = Region::create(['name' => 'Region A', 'code' => 'T-RA', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->otherRegion = Region::create(['name' => 'Region B', 'code' => 'T-RB', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);
        $this->farChurch = Church::create(['name' => 'Far Church', 'code' => 'FAR-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->otherRegion->id]);

        $this->pastor = $this->userWithRole('pastor', 'Test Pastor', 'church', $this->myChurch->id, [
            ...array_map(fn ($a) => "church.budgets.budgets.{$a}", ['read', 'prepare', 'export']),
            'church.budgets.overview.read', 'church.budgets.spending.read', 'church.budgets.spending.record',
            ...array_map(fn ($a) => "church.settings.budgetsettings.budgetlines.{$a}", ['read', 'create', 'update', 'delete']),
        ]);
        $this->overseer = $this->userWithRole('overseer', 'Test Overseer', 'region', $this->region->id, [
            ...array_map(fn ($a) => "region.budgets.budgets.{$a}", ['read', 'prepare', 'export']),
            'region.budgets.overview.read', 'region.budgets.spending.read', 'region.budgets.spending.record',
            'region.budgets.below.read',
        ]);
        $this->bishop = $this->userWithRole('bishop', 'Test Bishop', 'diocese', $this->diocese->id, [
            ...array_map(fn ($a) => "diocese.budgets.budgets.{$a}", ['read', 'prepare', 'export']),
            'diocese.budgets.overview.read', 'diocese.budgets.spending.read', 'diocese.budgets.spending.record',
            'diocese.budgets.below.read',
        ]);

        $this->type = BudgetType::create(['name' => 'Annual', 'slug' => 'annual', 'duration_months' => 12, 'is_active' => true]);
        $this->incomeCategory = BudgetCategory::create(['name' => 'Income', 'slug' => 'income', 'is_active' => true]);
        $this->category = BudgetCategory::create(['name' => 'Expense', 'slug' => 'expense', 'is_active' => true]);
        $this->churchLine = $this->sharedLine('Church Rent', 'church');
        $this->allLine = $this->sharedLine('Utilities', 'all');
        $this->dioceseLine = $this->sharedLine('Diocese Office', 'diocese');
        $this->incomeLine = $this->sharedLine('Tithes', 'all', $this->incomeCategory);
    }

    protected function userWithRole(string $username, string $roleName, string $level, int $territoryId, array $permissions, string $assignment = 'primary'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['territory_level' => $level]);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['action' => substr(strrchr($name, '.'), 1), 'territory_scope' => $level],
            ));
        }
        $user = User::firstOrCreate(['username' => "test.{$username}"], [
            'firstname' => 'Test', 'lastname' => ucfirst($username),
            'email' => "test.{$username}@example.test", 'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => $assignment, 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    protected function sharedLine(string $name, string $scope, ?BudgetCategory $category = null): BudgetLine
    {
        return BudgetLine::create([
            'budget_category_id' => ($category ?? $this->category)->id, 'name' => $name, 'slug' => str($name)->slug(),
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

    /** A budget of a place, built the way the pages build it. */
    protected function budgetFor($place, string $status = 'draft', array $lines = [], int $year = 2026, ?int $month = 1): Budget
    {
        $type = $place->territory_type->value;
        $amounts = array_map(fn ($line) => ['budget_line_id' => $line->id, 'amount' => 1000], $lines ?: [$type === 'church' ? $this->churchLine : $this->allLine]);
        $book = app(BudgetBook::class);
        $budget = $book->save($this->pastor, $type, $place->id, ['year' => $year, 'month' => $month, 'lines' => $amounts]);
        if ($status !== 'draft') {
            $book->start($budget, $this->pastor);
        }
        if ($status === 'closed') {
            $book->close($budget->fresh(), $this->pastor);
        }

        return $budget->fresh();
    }
}
