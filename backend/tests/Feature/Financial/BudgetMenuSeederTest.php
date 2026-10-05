<?php

namespace Tests\Feature\Financial;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use Database\Seeders\BudgetsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Budgets menu (docs/specs/budgets-spec.md): Income and Expenses are
 * their own pages - the same list, opened filtered - and the old combined
 * "Money in and out" entry leaves the menu.
 */
class BudgetMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_and_expenses_are_menu_pages_and_the_combined_entry_leaves_the_menu(): void
    {
        $module = Module::create(['name' => 'Finance', 'icon' => 'ri-wallet-3-line', 'number' => 5, 'is_active' => true]);
        Submodule::create(['module_id' => $module->id, 'title' => 'Budgets', 'path' => '/church/budget/budgets.php', 'is_active' => true]);
        $old = Submodule::create(['module_id' => $module->id, 'title' => 'Money in and out', 'path' => '/church/budget/spending.php', 'is_active' => true]);

        $this->seed(BudgetsAccessSeeder::class);

        $pages = Submodule::where('module_id', $module->id)->where('is_active', true)->pluck('title', 'path');
        $this->assertSame('Income', $pages['/church/budget/income.php']);
        $this->assertSame('Expenses', $pages['/church/budget/expenses.php']);
        $this->assertFalse($old->fresh()->is_active);

        $this->assertSame('/church/budget/income.php', Submodule::find(Permission::where('name', 'church.budgets.income.read')->value('submodule_id'))->path);
        $this->assertSame('/church/budget/expenses.php', Submodule::find(Permission::where('name', 'church.budgets.spending.read')->value('submodule_id'))->path);
    }
}
