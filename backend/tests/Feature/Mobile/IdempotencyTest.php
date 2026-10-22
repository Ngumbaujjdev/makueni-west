<?php

namespace Tests\Feature\Mobile;

use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Settings\BuildsSettingsWorld;
use Tests\TestCase;

/**
 * The phone app's outbox resends a counting form with the same
 * Idempotency-Key (docs/specs/mobile-app-spec.md, M11): the count is recorded
 * once, another person's key is their own, no header changes nothing, and a
 * refused send isn't kept.
 */
class IdempotencyTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    private string $sunday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        FiscalYear::firstOrCreate(['year' => (int) now()->year], ['start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $sunday = now()->previous('Sunday');
        FiscalYear::firstOrCreate(['year' => (int) $sunday->year], ['start_date' => $sunday->copy()->startOfYear(), 'end_date' => $sunday->copy()->endOfYear()]);
        foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $m) {
            FiscalMonth::firstOrCreate(['number' => $i + 1], ['name' => $m, 'short_name' => substr($m, 0, 3)]);
        }
        $this->sunday = $sunday->toDateString();
        foreach ([$this->pastor, $this->otherPastor] as $u) {
            $u->roles()->first()->givePermissionTo(Permission::firstOrCreate(
                ['name' => 'attendancemanagement.serviceattendance.create', 'guard_name' => 'web'], ['action' => 'create', 'territory_scope' => 'church'],
            ));
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function sendCount(int $churchId, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/attendance', [
            'territory_id' => $churchId, 'service_date' => $this->sunday,
            'gathering_category_id' => GatheringCategory::where('slug', 'sunday_service')->value('id'),
            'men_count' => 30, 'women_count' => 40,
        ]);
    }

    public function test_the_same_key_twice_records_once_and_answers_the_same(): void
    {
        Sanctum::actingAs($this->pastor);
        $first = $this->sendCount($this->myChurch->id, ['Idempotency-Key' => 'abc-1'])->assertJsonPath('success', true);
        $again = $this->sendCount($this->myChurch->id, ['Idempotency-Key' => 'abc-1'])->assertJsonPath('success', true)->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, ChurchAttendanceRecord::where('territory_id', $this->myChurch->id)->count());
    }

    public function test_another_persons_key_is_their_own(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->sendCount($this->myChurch->id, ['Idempotency-Key' => 'same'])->assertJsonPath('success', true);
        Sanctum::actingAs($this->otherPastor);
        $this->sendCount($this->otherChurch->id, ['Idempotency-Key' => 'same'])->assertJsonPath('success', true);

        $this->assertSame(1, ChurchAttendanceRecord::where('territory_id', $this->otherChurch->id)->count());
    }

    public function test_without_the_header_nothing_changes(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->sendCount($this->myChurch->id)->assertJsonPath('success', true);
        // A second count for the same Sunday is refused as always.
        $this->sendCount($this->myChurch->id)->assertJsonPath('success', false);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_a_refused_send_is_not_kept_so_a_retry_can_go_through(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->withHeaders(['Idempotency-Key' => 'k-2'])->postJson('/api/attendance', ['territory_id' => $this->myChurch->id])
            ->assertJsonPath('success', false);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->sendCount($this->myChurch->id, ['Idempotency-Key' => 'k-2'])->assertJsonPath('success', true);
    }

    public function test_old_keys_are_pruned(): void
    {
        DB::table('idempotency_keys')->insert([
            ['key' => 'old', 'user_id' => $this->pastor->id, 'method' => 'POST', 'route' => 'api/attendance', 'status_code' => 200, 'response_body' => '{}', 'created_at' => now()->subDays(8)],
            ['key' => 'new', 'user_id' => $this->pastor->id, 'method' => 'POST', 'route' => 'api/attendance', 'status_code' => 200, 'response_body' => '{}', 'created_at' => now()],
        ]);
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'idempotency-keys:prune');
        $this->assertNotNull($event);
        $event->run(app());
        $this->assertSame(['new'], DB::table('idempotency_keys')->pluck('key')->all());
    }
}
