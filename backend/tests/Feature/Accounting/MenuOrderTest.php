<?php

namespace Tests\Feature\Accounting;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Submodule;
use Database\Seeders\AccountingAccessSeeder;
use Database\Seeders\MenuOrderSeeder;
use Database\Seeders\PeopleCareAccessSeeder;
use Database\Seeders\SettingsHubSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A module's pages show on the menu (sidebar and tab bar) in the order its
 * access seeder lists them - Accounting as money moves - not A-Z. A module
 * nobody ordered stays A-Z.
 */
class MenuOrderTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    private function group(string $slug, string $level): void
    {
        ModuleGroup::firstOrCreate(['slug' => $slug], ['name' => ucfirst(str_replace('-', ' ', $slug)), 'territory_scope' => $level, 'order' => 3, 'icon' => 'ri-folder-line', 'is_active' => true]);
    }

    public function test_accounting_pages_follow_how_money_moves(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            $this->group("{$level}-finance", $level);
        }
        $this->seed(AccountingAccessSeeder::class);

        $church = Submodule::where('path', 'like', '/church/accounting/%')->where('is_active', true)->orderBy('order')->orderBy('title')->pluck('title')->all();
        $this->assertSame(['Overview', 'Cash & bank', 'Collections', 'Receipts', 'Requisitions', 'Approvals', 'Procurement', 'Payment vouchers', 'Payroll', 'Remittances', 'Cashbook', 'Journals', 'Reconciliation', 'Month-end close', 'All documents'], $church);

        // The menu the treasurer gets back from the API is in that order too.
        Sanctum::actingAs($this->treasurer);
        $groups = $this->getJson('/api/modules/for-role')->assertOk()->json('data.module_groups');
        $accounting = collect($groups)->flatMap(fn ($g) => $g['modules'] ?? [])->firstWhere('name', 'Accounting');
        $this->assertNotNull($accounting, 'the treasurer sees Accounting');
        $titles = array_column($accounting['submodules'], 'title');
        $this->assertNotEmpty($titles);
        $this->assertSame(array_values(array_intersect($church, $titles)), $titles, 'the API keeps the seeder\'s order');
        $this->assertSame('Overview', $titles[0]);
    }

    public function test_people_and_care_pages_are_in_their_listed_order(): void
    {
        foreach (['church-members' => 'church', 'church-programs' => 'church', 'region-churches' => 'region', 'diocese-churches' => 'diocese'] as $slug => $level) {
            $this->group($slug, $level);
        }
        $this->seed(PeopleCareAccessSeeder::class);

        $pages = fn (string $name) => Module::where('name', $name)->firstOrFail()->submodules()->where('is_active', true)->orderBy('order')->orderBy('title')->pluck('title')->all();
        $this->assertSame(['Members', 'Add member', 'Transfers', 'Insights'], $pages('Members'));
        $this->assertSame(['Facilities', 'Bookings', 'Equipment', 'What we own', 'Repairs', 'Teams', 'Duty rota', 'Reports'], $pages('Facilities'));

        // A module whose pages have no order stays A-Z.
        $other = Module::create(['module_group_id' => ModuleGroup::where('slug', 'church-members')->value('id'), 'name' => 'Unordered', 'number' => 99, 'is_active' => true]);
        foreach (['Zebra', 'Apple', 'Mango'] as $t) {
            Submodule::create(['module_id' => $other->id, 'title' => $t, 'path' => '/church/x/'.strtolower($t), 'is_active' => true]);
        }
        $this->assertSame(['Apple', 'Mango', 'Zebra'], $pages('Unordered'));
    }

    public function test_the_other_modules_get_their_order_and_new_pages_go_after(): void
    {
        $this->group('church-finance', 'church');
        $budgets = Module::create(['module_group_id' => ModuleGroup::where('slug', 'church-finance')->value('id'), 'name' => 'Budgets', 'number' => 1, 'is_active' => true]);
        foreach (['Reports', 'Income', 'Budgets', 'Overview', 'New budget', 'Expenses', 'Contributions'] as $t) {
            Submodule::create(['module_id' => $budgets->id, 'title' => $t, 'path' => '/church/budget/'.str_replace(' ', '-', strtolower($t)).'.php', 'is_active' => true]);
        }
        $this->seed(MenuOrderSeeder::class);
        $this->seed(MenuOrderSeeder::class);
        $pages = fn () => $budgets->submodules()->active()->menuOrder()->pluck('title')->all();
        $this->assertSame(['Overview', 'Budgets', 'New budget', 'Income', 'Expenses', 'Contributions', 'Reports'], $pages());

        // A page added later, with no place set, goes after the ordered ones.
        Submodule::create(['module_id' => $budgets->id, 'title' => 'Archive', 'path' => '/church/budget/archive.php', 'is_active' => true]);
        $this->assertSame('Archive', last($pages()));
    }

    public function test_settings_pages_follow_the_hubs_rail(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            $this->group("{$level}-settings", $level);
        }
        $this->seed(SettingsHubSeeder::class);

        $module = Module::where('name', 'Settings')->whereHas('moduleGroup', fn ($q) => $q->where('slug', 'church-settings'))->firstOrFail();
        $titles = $module->submodules()->active()->menuOrder()->pluck('title')->all();
        $this->assertSame(['Overview', 'View profile', 'Profile', 'Service times', 'Leadership & team'], array_slice($titles, 0, 5));
        $this->assertLessThan(array_search('Facilities', $titles, true), array_search('Communication', $titles, true));
    }
}
