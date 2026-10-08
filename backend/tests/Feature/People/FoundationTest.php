<?php

namespace Tests\Feature\People;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use App\Support\PeopleAccess;
use Database\Seeders\PeopleCareAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P0 (docs/specs/people-and-care-spec.md): named records stay
 * with their church, the region and diocese get totals, and the old
 * placeholder modules are reused without duplicates.
 */
class FoundationTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    public function test_names_stay_with_their_church_and_totals_go_up(): void
    {
        $pastor = $this->userWithRole('carer', 'Care Pastor', 'church', $this->myChurch->id, ['church.members.members.manage', 'church.pastoral.care.read']);
        $overseer = $this->userWithRole('regioncare', 'Region Care', 'region', $this->region->id, ['region.members.below.read']);
        $this->actingAs($pastor);

        $this->assertTrue(PeopleAccess::canNamed($pastor, $this->myChurch, 'members'), 'manage implies read');
        $this->assertTrue(PeopleAccess::canNamed($pastor, $this->myChurch, 'members', 'manage'));
        $this->assertFalse(PeopleAccess::canNamed($pastor, $this->myChurch, 'members', 'export'));
        $this->assertTrue(PeopleAccess::canNamed($pastor, $this->myChurch, 'pastoral'));
        $this->assertFalse(PeopleAccess::canNamed($pastor, $this->myChurch, 'pastoral', 'confidential'));
        $this->assertFalse(PeopleAccess::canNamed($pastor, $this->otherChurch, 'members'), 'never sideways');
        $this->assertFalse(PeopleAccess::canNamed($pastor, $this->myChurch, 'visitors'), 'no visitors permission');

        $this->actingAs($overseer);
        $this->assertFalse(PeopleAccess::canNamed($overseer, $this->myChurch, 'members'), 'the region never sees names');
        $this->assertFalse(PeopleAccess::canNamed($overseer, $this->region, 'members'), 'named records live at churches');
        $this->assertTrue(PeopleAccess::canTotals($overseer, $this->region, 'members'));
        $this->assertFalse(PeopleAccess::canTotals($overseer, $this->region, 'visitors'));
        $this->assertFalse(PeopleAccess::canTotals($overseer, $this->region, 'facilities'), 'facilities have no totals');
        $this->assertFalse(PeopleAccess::canTotals($pastor, $this->myChurch, 'members'), 'totals are for those above');
    }

    public function test_the_seeder_reuses_the_placeholders_and_retires_their_permissions(): void
    {
        $groups = [];
        foreach (['church-members', 'church-programs', 'church-settings', 'region-churches', 'diocese-churches'] as $slug) {
            $groups[$slug] = ModuleGroup::firstOrCreate(['slug' => $slug], ['name' => $slug, 'territory_scope' => explode('-', $slug)[0], 'is_active' => true]);
        }
        $old = Module::create(['module_group_id' => $groups['church-members']->id, 'name' => 'Member Management', 'icon' => 'x', 'number' => 1, 'is_active' => true]);
        $oldPage = Submodule::create(['module_id' => $old->id, 'title' => 'Member Registration', 'path' => '/members/registration', 'is_active' => true]);
        $oldPermission = Permission::create(['name' => 'membermanagement.memberregistration.create', 'guard_name' => 'web', 'module_id' => $old->id, 'submodule_id' => $oldPage->id, 'action' => 'create', 'territory_scope' => 'church']);
        $facility = Module::create(['module_group_id' => $groups['church-settings']->id, 'name' => 'Facility Management', 'icon' => 'x', 'number' => 8, 'is_active' => true]);
        $senior = Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $senior->givePermissionTo($oldPermission);
        $bishop = Role::firstOrCreate(['name' => 'Bishop', 'guard_name' => 'web'], ['territory_level' => 'diocese']);

        $this->seed(PeopleCareAccessSeeder::class);
        $this->seed(PeopleCareAccessSeeder::class);

        $this->assertSame('Members', $old->fresh()->name);
        $this->assertSame(PeopleCareAccessSeeder::LIVE['members'], (bool) $old->fresh()->is_active, 'on once its pages are built');
        $this->assertSame(PeopleCareAccessSeeder::LIVE['facilities'], (bool) $facility->fresh()->is_active, 'switched off until its pages are built');
        $this->assertNull(Submodule::find($oldPage->id), 'the old page is gone');
        $this->assertSame(1, Submodule::where('path', '/church/members/')->count());
        $this->assertSame('Facilities', $facility->fresh()->name);
        $this->assertSame($groups['church-programs']->id, (int) $facility->fresh()->module_group_id, 'moved to Programs');
        $this->assertSame(0, Permission::where('name', 'like', 'membermanagement.%')->count());
        $this->assertSame(1, Module::where('name', 'Church care')->where('module_group_id', $groups['region-churches']->id)->count());
        $this->assertSame(1, Submodule::where('path', '/diocese/people/members.php')->count());

        $senior = $senior->fresh();
        $this->assertTrue($senior->hasPermissionTo('church.members.members.export'));
        $this->assertTrue($senior->hasPermissionTo('church.members.transfers.read'), 'readers get the Transfers page');
        $this->assertSame(1, Submodule::where('path', '/church/members/insights.php')->count());
        $this->assertTrue($senior->hasPermissionTo('church.visitors.insights.read'), 'visitor readers get its Insights page');
        $this->assertSame(1, Submodule::where('path', '/church/visitors/insights.php')->count());
        $this->assertTrue($senior->hasPermissionTo('church.pastoral.care.confidential'));
        $this->assertTrue($bishop->fresh()->hasPermissionTo('diocese.visitors.below.read'));
        $this->assertSame('church', Permission::where('name', 'church.members.members.read')->value('territory_scope'));
    }
}
