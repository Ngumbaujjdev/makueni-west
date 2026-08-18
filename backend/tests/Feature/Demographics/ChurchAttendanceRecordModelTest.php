<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChurchAttendanceRecordModelTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected FiscalYear $fiscalYear;

    protected FiscalMonth $fiscalMonth;

    protected int $sundayServiceCategoryId;

    protected int $specialEventCategoryId;

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

        // Categories are seeded directly in the gathering_categories
        // migration (not a Database\Seeders class), so they already exist
        // once RefreshDatabase has migrated the test database.
        $this->sundayServiceCategoryId = GatheringCategory::where('slug', 'sunday_service')->value('id');
        $this->specialEventCategoryId = GatheringCategory::where('slug', 'special_event')->value('id');
    }

    private function makeRecord(array $overrides = []): ChurchAttendanceRecord
    {
        return ChurchAttendanceRecord::create(array_merge([
            'territory_type' => 'church',
            'territory_id' => $this->church->id,
            'service_date' => '2026-08-16',
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 40,
            'youth_count' => 15,
            'children_male_count' => 8,
            'children_female_count' => 7,
        ], $overrides));
    }

    public function test_it_can_be_created_with_expected_fillable_fields(): void
    {
        $record = $this->makeRecord();

        $this->assertDatabaseHas('church_attendance_records', [
            'id' => $record->id,
            'territory_id' => $this->church->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 40,
        ]);
    }

    public function test_children_count_is_computed_from_male_and_female(): void
    {
        $record = $this->makeRecord(['children_male_count' => 8, 'children_female_count' => 7]);

        $this->assertEquals(15, $record->children_count);
    }

    public function test_total_count_sums_all_categories(): void
    {
        $record = $this->makeRecord([
            'adults_count' => 40,
            'youth_count' => 15,
            'children_male_count' => 8,
            'children_female_count' => 7,
        ]);

        $this->assertEquals(70, $record->total_count);
    }

    public function test_territory_relationship_resolves_to_the_church(): void
    {
        $record = $this->makeRecord();

        $this->assertTrue($record->territory->is($this->church));
    }

    public function test_service_date_is_cast_to_a_date(): void
    {
        $record = $this->makeRecord(['service_date' => '2026-08-16']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $record->service_date);
        $this->assertEquals('2026-08-16', $record->service_date->toDateString());
    }

    public function test_sunday_services_scope_excludes_other_gathering_categories(): void
    {
        $this->makeRecord(['gathering_category_id' => $this->sundayServiceCategoryId]);
        $this->makeRecord([
            'gathering_category_id' => $this->specialEventCategoryId,
            'event_name' => 'Youth Kesha',
            'service_date' => '2026-08-15',
        ]);

        $results = ChurchAttendanceRecord::sundayServices()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($this->sundayServiceCategoryId, $results->first()->gathering_category_id);
    }

    public function test_for_period_scope_filters_by_fiscal_year_and_month(): void
    {
        $otherMonth = FiscalMonth::create(['number' => 9, 'name' => 'September', 'short_name' => 'Sep']);

        $this->makeRecord(['fiscal_month_id' => $this->fiscalMonth->id]);
        $this->makeRecord(['fiscal_month_id' => $otherMonth->id, 'service_date' => '2026-09-06']);

        $results = ChurchAttendanceRecord::forPeriod($this->fiscalYear->id, $this->fiscalMonth->id)->get();

        $this->assertCount(1, $results);
    }
}
