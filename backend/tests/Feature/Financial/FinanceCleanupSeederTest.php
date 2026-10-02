<?php

namespace Tests\Feature\Financial;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use Database\Seeders\BudgetsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance clean-up (docs/specs/budgets-spec.md → F5): the finance
 * placeholder modules whose pages were never built are switched off and
 * their permissions retired; a real module with the same kind of name is
 * left alone.
 */
class FinanceCleanupSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_finance_modules_are_switched_off_and_their_permissions_retired(): void
    {
        $placeholder = Module::create(['name' => 'Tithe Management', 'icon' => 'ri-hand-coin-line', 'number' => 25, 'is_active' => true]);
        $page = Submodule::create(['module_id' => $placeholder->id, 'title' => 'Overview', 'path' => '/diocese/financial/tithe/overview', 'is_active' => true]);
        $perm = Permission::create(['name' => 'tithemanagement.titheoverview.read', 'guard_name' => 'web', 'module_id' => $placeholder->id, 'action' => 'read', 'territory_scope' => 'diocese']);
        $bishop = Role::create(['name' => 'Bishop', 'guard_name' => 'web', 'territory_level' => 'diocese']);
        $bishop->givePermissionTo($perm);

        // Same name, but real pages elsewhere - not a placeholder, so it stays.
        $real = Module::create(['name' => 'Financial Reports', 'icon' => 'ri-file-chart-line', 'number' => 26, 'is_active' => true]);
        Submodule::create(['module_id' => $real->id, 'title' => 'Reports', 'path' => '/diocese/budgets/reports.php', 'is_active' => true]);

        $this->seed(BudgetsAccessSeeder::class);

        $this->assertFalse($placeholder->fresh()->is_active);
        $this->assertFalse($page->fresh()->is_active);
        $this->assertNull(Permission::find($perm->id));
        $this->assertFalse($bishop->fresh()->permissions->contains('id', $perm->id));
        $this->assertTrue($real->fresh()->is_active);
    }
}
