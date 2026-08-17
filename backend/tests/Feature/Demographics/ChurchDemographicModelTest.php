<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChurchDemographicModelTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;
    protected FiscalYear $fiscalYear;
    protected FiscalMonth $fiscalMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::create([
            'name' => 'Test Church',
            'code' => 'TEST-CH-001',
            'territory_type' => 'church',
            'level' => 4,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->fiscalMonth = FiscalMonth::create([
            'number' => 8,
            'name' => 'August',
            'short_name' => 'Aug',
        ]);
    }

    public function test_it_can_be_created_with_expected_fillable_fields(): void
    {
        $demographic = ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 120,
            'male_count' => 55,
            'female_count' => 65,
            'youth_count' => 30,
            'sunday_school_male_count' => 12,
            'sunday_school_female_count' => 10,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('church_demographics', [
            'id' => $demographic->id,
            'territory_id' => $this->church->id,
            'total_members' => 120,
            'status' => 'draft',
        ]);
    }

    public function test_sunday_school_count_is_computed_from_male_and_female(): void
    {
        $demographic = ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'sunday_school_male_count' => 12,
            'sunday_school_female_count' => 10,
        ]);

        $this->assertEquals(22, $demographic->sunday_school_count);
    }

    public function test_territory_relationship_resolves_to_the_church(): void
    {
        $demographic = ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        $this->assertTrue($demographic->territory->is($this->church));
    }

    public function test_draft_and_changes_requested_are_editable_others_are_not(): void
    {
        $editableStatuses = ['draft', 'changes_requested'];
        $lockedStatuses = ['submitted', 'approved', 'flagged'];

        foreach ($editableStatuses as $status) {
            $demographic = ChurchDemographic::create([
                'territory_type' => 'church',
                'territory_id' => $this->church->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'fiscal_month_id' => $this->fiscalMonth->id,
                'status' => $status,
            ]);

            $this->assertTrue($demographic->is_editable, "Expected status '{$status}' to be editable");
        }

        foreach ($lockedStatuses as $status) {
            $demographic = ChurchDemographic::create([
                'territory_type' => 'church',
                'territory_id' => $this->church->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'fiscal_month_id' => $this->fiscalMonth->id,
                'status' => $status,
            ]);

            $this->assertFalse($demographic->is_editable, "Expected status '{$status}' to not be editable");
        }
    }

    public function test_it_records_the_reviewer_relationship(): void
    {
        $reviewer = User::create([
            'firstname' => 'Jane',
            'lastname' => 'Overseer',
            'username' => 'jane.overseer',
            'email' => 'jane.overseer@example.test',
            'password' => bcrypt('password'),
        ]);

        $demographic = ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'status' => 'flagged',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Youth count up 40% - please confirm',
        ]);

        $this->assertTrue($demographic->reviewer->is($reviewer));
        $this->assertEquals('Youth count up 40% - please confirm', $demographic->review_notes);
    }

    public function test_for_period_scope_filters_by_fiscal_year_and_month(): void
    {
        $otherMonth = FiscalMonth::create(['number' => 9, 'name' => 'September', 'short_name' => 'Sep']);

        ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $otherMonth->id,
        ]);

        $results = ChurchDemographic::forPeriod($this->fiscalYear->id, $this->fiscalMonth->id)->get();

        $this->assertCount(1, $results);
    }
}
