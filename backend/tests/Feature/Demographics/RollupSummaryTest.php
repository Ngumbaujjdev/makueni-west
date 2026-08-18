<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
use App\Models\Diocese;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\Subregion;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RollupSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Diocese $diocese;

    protected Region $region;

    protected Subregion $subregion;

    protected Church $churchA; // under subregion

    protected Church $churchB; // under subregion

    protected Church $churchC; // directly under region, no subregion

    protected FiscalYear $fiscalYear;

    protected FiscalMonth $august;

    protected FiscalMonth $july;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diocese = Diocese::create(['name' => 'My Diocese', 'code' => 'MY-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $this->region = Region::create(['name' => 'My Region', 'code' => 'MY-REG', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->subregion = Subregion::create(['name' => 'My Subregion', 'code' => 'MY-SR', 'territory_type' => 'subregion', 'level' => 3, 'parent_territory_id' => $this->region->id]);

        $this->churchA = Church::create(['name' => 'Church A', 'code' => 'CH-A', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->subregion->id]);
        $this->churchB = Church::create(['name' => 'Church B', 'code' => 'CH-B', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->subregion->id]);
        $this->churchC = Church::create(['name' => 'Church C', 'code' => 'CH-C', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);

        $this->fiscalYear = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->august = FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);
        $this->july = FiscalMonth::create(['number' => 7, 'name' => 'July', 'short_name' => 'Jul']);

        // Approved August submissions for all three churches
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->churchA->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'status' => 'approved', 'total_members' => 100, 'youth_count' => 20, 'baptisms_count' => 2,
        ]);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->churchB->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'status' => 'approved', 'total_members' => 150, 'youth_count' => 30, 'baptisms_count' => 1,
        ]);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->churchC->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'status' => 'approved', 'total_members' => 80, 'youth_count' => 10, 'baptisms_count' => 0,
        ]);
        // A submitted (not yet approved) row for churchA - should NOT count
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->churchA->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->july->id,
            'status' => 'submitted', 'total_members' => 999,
        ]);
        // Approved July baseline for growth-delta math
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->churchB->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->july->id,
            'status' => 'approved', 'total_members' => 140,
        ]);

        // Attendance: two Sunday services for churchA in August. Categories
        // are seeded directly in the gathering_categories migration, so
        // they already exist once RefreshDatabase has migrated.
        $sundayServiceCategoryId = GatheringCategory::where('slug', 'sunday_service')->value('id');

        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->churchA->id,
            'service_date' => '2026-08-02', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $sundayServiceCategoryId, 'adults_count' => 40, 'youth_count' => 10, 'children_male_count' => 5, 'children_female_count' => 5,
        ]);
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->churchA->id,
            'service_date' => '2026-08-09', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $sundayServiceCategoryId, 'adults_count' => 44, 'youth_count' => 12, 'children_male_count' => 4, 'children_female_count' => 6,
        ]);
        // churchB has no attendance rows at all - monthly_only mode, should not break the average
    }

    private function grantSummaryPermission(Role $role, string $permName, string $scope): void
    {
        $permission = Permission::create(['name' => $permName, 'guard_name' => 'web', 'action' => 'read', 'territory_scope' => $scope]);
        $role->givePermissionTo($permission);
    }

    private function assignUser(User $user, int $territoryId, Role $role): void
    {
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true, 'effective_from' => now()->subDay(),
            'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);
    }

    public function test_subregion_summary_sums_only_its_own_churches_and_only_approved_rows(): void
    {
        $role = Role::create(['name' => 'Test Subregion Reader', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        $this->grantSummaryPermission($role, 'subregiondemographicsreview.churchsubmissions.read', 'subregion');
        $user = User::create(['firstname' => 'Sub', 'lastname' => 'Reader', 'username' => 'sub.reader', 'email' => 'sub.reader@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->subregion->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->subregion->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        $response->assertStatus(200);
        // churchA (100) + churchB (150) = 250, NOT churchC (region-direct child, not in this subregion)
        $this->assertEquals(250, $response->json('data.membership.total_members'));
        $this->assertEquals(2, $response->json('data.membership.churches_reporting'));
        $this->assertEquals(3, $response->json('data.membership.baptisms_count'));
    }

    public function test_region_summary_includes_direct_and_subregion_descendant_churches(): void
    {
        $role = Role::create(['name' => 'Test Region Reader', 'guard_name' => 'web', 'territory_level' => 'region']);
        $this->grantSummaryPermission($role, 'regiondemographicsanalytics.summary.read', 'region');
        $user = User::create(['firstname' => 'Region', 'lastname' => 'Reader', 'username' => 'region.reader', 'email' => 'region.reader@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->region->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->region->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        $response->assertStatus(200);
        // churchA (100) + churchB (150) + churchC (80, direct region child) = 330
        $this->assertEquals(330, $response->json('data.membership.total_members'));
        $this->assertEquals(3, $response->json('data.membership.churches_reporting'));
    }

    public function test_diocese_summary_rolls_up_the_whole_tree(): void
    {
        $role = Role::where('name', 'Bishop')->first() ?? Role::create(['name' => 'Bishop', 'guard_name' => 'web', 'territory_level' => 'diocese']);
        // demographicsanalytics.demographicssummary.read already seeded and granted to Bishop by FixDemographicsPermissionScaffoldingSeeder in normal runs;
        // this test DB only has migrations, not seeders, so grant it explicitly here.
        $permission = Permission::firstOrCreate(
            ['name' => 'demographicsanalytics.demographicssummary.read'],
            ['guard_name' => 'web', 'action' => 'read', 'territory_scope' => 'diocese']
        );
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        $user = User::create(['firstname' => 'The', 'lastname' => 'Bishop', 'username' => 'the.bishop', 'email' => 'the.bishop@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->diocese->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->diocese->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        $response->assertStatus(200);
        $this->assertEquals(330, $response->json('data.membership.total_members'));
        $this->assertEquals(3, $response->json('data.membership.churches_reporting'));
    }

    public function test_growth_delta_compares_against_previous_month_approved_total(): void
    {
        $role = Role::create(['name' => 'Test Subregion Reader 2', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        $this->grantSummaryPermission($role, 'subregiondemographicsreview.churchsubmissions.read', 'subregion');
        $user = User::create(['firstname' => 'Sub2', 'lastname' => 'Reader', 'username' => 'sub2.reader', 'email' => 'sub2.reader@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->subregion->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->subregion->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        // July approved baseline for the subregion is only churchB=140 (churchA's July row is 'submitted', excluded)
        $this->assertEquals(140, $response->json('data.growth.previous_month_total_members'));
        $this->assertEquals(110, $response->json('data.growth.delta')); // 250 - 140
    }

    public function test_average_attendance_ignores_churches_with_no_logged_services(): void
    {
        $role = Role::create(['name' => 'Test Subregion Reader 3', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        $this->grantSummaryPermission($role, 'subregiondemographicsreview.churchsubmissions.read', 'subregion');
        $user = User::create(['firstname' => 'Sub3', 'lastname' => 'Reader', 'username' => 'sub3.reader', 'email' => 'sub3.reader@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->subregion->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->subregion->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        // churchA: (40+10+5+5)=60 and (44+12+4+6)=66 -> average 63.0; churchB contributes nothing (no rows), doesn't drag the average to 0
        $this->assertEquals(2, $response->json('data.attendance.services_logged'));
        $this->assertEquals(63.0, $response->json('data.attendance.average_attendance'));
    }

    public function test_reader_cannot_view_a_territory_they_are_not_assigned_to(): void
    {
        $otherSubregion = Subregion::create(['name' => 'Other Sub', 'code' => 'OTHER-SR2', 'territory_type' => 'subregion', 'level' => 3, 'parent_territory_id' => $this->region->id]);

        $role = Role::create(['name' => 'Test Subregion Reader 4', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        $this->grantSummaryPermission($role, 'subregiondemographicsreview.churchsubmissions.read', 'subregion');
        $user = User::create(['firstname' => 'Sub4', 'lastname' => 'Reader', 'username' => 'sub4.reader', 'email' => 'sub4.reader@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $otherSubregion->id, $role); // assigned elsewhere, not $this->subregion

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->subregion->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        $response->assertStatus(403);
    }

    public function test_reader_without_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'Test No Perm', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        $user = User::create(['firstname' => 'No', 'lastname' => 'Perm', 'username' => 'no.perm', 'email' => 'no.perm@example.test', 'password' => bcrypt('password')]);
        $user->assignRole($role);
        $this->assignUser($user, $this->subregion->id, $role);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/demographics/summary/{$this->subregion->id}?fiscal_year_id={$this->fiscalYear->id}&fiscal_month_id={$this->august->id}");

        $response->assertStatus(403);
    }
}
