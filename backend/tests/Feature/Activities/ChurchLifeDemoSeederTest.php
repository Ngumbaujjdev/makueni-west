<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\CalendarEvent;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Models\MonthlyReport;
use Database\Seeders\ChurchLifeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * The Church life sample data: it fills the pages, seeding twice doubles
 * nothing, and DEMO=remove takes out only what it made.
 */
class ChurchLifeDemoSeederTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->myChurch->update(['name' => 'CCI SULTAN HAMUD']);
    }

    private function counts(): array
    {
        return [Activity::withTrashed()->count(), CalendarEvent::withTrashed()->count(), MonthlyReport::count(), MessageBatch::count(), MessageTemplate::count()];
    }

    public function test_it_fills_the_pages_twice_without_doubling_and_removes_only_its_own(): void
    {
        $mine = Activity::create(['kind' => 'event', 'territory_id' => $this->myChurch->id, 'title' => 'Our own wedding', 'type' => 'wedding',
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(4), 'status' => 'draft', 'created_by' => $this->pastor->id]);
        MessageTemplate::create(['territory_id' => $this->myChurch->id, 'name' => 'Sunday reminder', 'channel' => 'sms', 'body' => 'Mine', 'created_by' => $this->pastor->id]);

        $this->seed(ChurchLifeDemoSeeder::class);
        $once = $this->counts();
        $this->assertGreaterThan(5, $once[0], 'events and initiatives');
        $this->assertGreaterThan(0, $once[2], 'monthly reports');
        $this->assertGreaterThan(0, $once[3], 'messages');
        $this->assertSame(1, MonthlyReport::where('territory_id', $this->myChurch->id)->where('month', 9)->where('status', 'seen')->count());

        $this->seed(ChurchLifeDemoSeeder::class);
        $this->assertSame($once, $this->counts());

        putenv('DEMO=remove');
        try {
            $this->seed(ChurchLifeDemoSeeder::class);
        } finally {
            putenv('DEMO');
        }
        $this->assertSame([1, 0, 0, 0, 1], $this->counts());
        $this->assertTrue($mine->fresh()->exists);
        $this->assertSame('Mine', MessageTemplate::sole()->body);
    }
}
