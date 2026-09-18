<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers GET /attendance-reports/export-pdf - see
 * docs/specs/attendance-pdf-reports-spec.md for the full contract. Mirrors
 * AttendanceControllerTest's setup pattern (manual Church/FiscalYear/Role/
 * User/UserTerritoryAssignment creation - RefreshDatabase runs migrations
 * only, not the ChurchSystemSeeder permission rows, so this doesn't rely
 * on the already-seeded attendancemanagement.attendancereports.export
 * permission existing in the test database).
 */
class AttendanceReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected User $pastor;

    protected int $sundayServiceCategoryId;

    protected int $ministryGatheringCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        // Categories are seeded directly in the gathering_categories
        // migration, so they already exist once RefreshDatabase has migrated.
        $this->sundayServiceCategoryId = GatheringCategory::where('slug', 'sunday_service')->value('id');
        $this->ministryGatheringCategoryId = GatheringCategory::where('slug', 'ministry_gathering')->value('id');

        $pastorRole = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        $this->pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
        ]);
        $this->pastor->assignRole($pastorRole);

        UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $this->pastor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }

    public function test_pastor_can_export_a_pdf_for_a_weekly_category_at_their_own_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->get('/api/attendance-reports/export-pdf?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => FiscalYear::first()->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pastor_can_export_a_pdf_for_a_breakdown_category_at_their_own_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->get('/api/attendance-reports/export-pdf?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => FiscalYear::first()->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pastor_cannot_export_a_pdf_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->get('/api/attendance-reports/export-pdf?'.http_build_query([
            'territory_id' => $this->otherChurch->id,
            'fiscal_year_id' => FiscalYear::first()->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(403);
    }

    public function test_export_pdf_requires_a_gathering_category(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->get('/api/attendance-reports/export-pdf?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => FiscalYear::first()->id,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('gathering_category_id');
    }

    public function test_export_pdf_requires_a_fiscal_year(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->get('/api/attendance-reports/export-pdf?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('fiscal_year_id');
    }
}
