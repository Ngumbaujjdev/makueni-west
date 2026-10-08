<?php

namespace Tests\Feature\Facilities;

use App\Models\BudgetEntry;
use App\Models\MessageLog;
use App\Models\Person;
use App\Models\RoomBooking;
use App\Models\User;
use App\Services\Calendar\LifeFeed;
use App\Services\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P5 (docs/specs/people-and-care-spec.md): facilities - a
 * clash is refused and named (weekly bookings on every date), only the
 * booker or a manager cancels, bookings and events share rooms, loans and
 * repairs, the duty rota and its reminders, and another church sees nothing.
 */
class FacilitiesTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $deacon;

    private $treasurer;

    private $otherPastor;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        $this->buildBudgetWorld();
        $f = ['church.facilities.facilities.read', 'church.facilities.facilities.book'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$f, 'church.facilities.facilities.manage', 'church.members.members.read']);
        $this->deacon = $this->userWithRole('deacon', 'Deacon', 'church', $this->myChurch->id, $f);
        $this->treasurer = $this->userWithRole('treasurer', 'Church Treasurer', 'church', $this->myChurch->id, ['church.facilities.facilities.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, [...$f, 'church.facilities.facilities.manage']);
        $this->monday = CarbonImmutable::now('Africa/Nairobi')->startOfWeek(CarbonImmutable::MONDAY)->addWeek();
    }

    private function room(string $name): int
    {
        Sanctum::actingAs($this->senior);

        return $this->postJson('/api/rooms', ['name' => $name, 'capacity' => 80])->assertCreated()->json('data.id');
    }

    private function day(int $offset): string
    {
        return $this->monday->addDays($offset)->toDateString();
    }

    public function test_a_clash_is_refused_and_named_and_only_the_booker_or_a_manager_cancels(): void
    {
        $hall = $this->room('Hall');
        $office = $this->room('Office');

        Sanctum::actingAs($this->deacon);
        $mine = $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(0), 'start' => '10:00', 'end' => '12:00', 'purpose' => 'Youth meeting'])->assertCreated()->json('data.id');
        $this->postJson('/api/rooms', ['name' => 'Kitchen'])->assertForbidden();

        Sanctum::actingAs($this->senior);
        $clash = $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(0), 'start' => '11:00', 'end' => '13:00', 'purpose' => 'Choir'])->assertStatus(409);
        $this->assertStringContainsString('Youth meeting', $clash->json('message'));
        $this->assertSame($mine, $clash->json('data.clash.id'));
        $this->postJson('/api/bookings', ['room_id' => $office, 'date' => $this->day(0), 'start' => '11:00', 'end' => '13:00', 'purpose' => 'Choir'])->assertCreated();
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(0), 'start' => '12:00', 'end' => '13:00', 'purpose' => 'Right after'])->assertCreated();
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(1), 'start' => '05:00', 'end' => '07:00', 'purpose' => 'Too early'])->assertStatus(422);

        // A weekly booking is checked on every date, and expands until its last one.
        Sanctum::actingAs($this->deacon);
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(21), 'start' => '15:00', 'end' => '16:00', 'purpose' => 'One-off'])->assertCreated();
        Sanctum::actingAs($this->senior);
        $weekly = ['room_id' => $hall, 'date' => $this->day(7), 'start' => '15:00', 'end' => '16:00', 'purpose' => 'Bible study', 'repeat' => 'weekly', 'repeat_until' => $this->day(35)];
        $this->assertSame($this->day(21), $this->postJson('/api/bookings', $weekly)->assertStatus(409)->json('data.clash.date'));
        $this->postJson('/api/bookings', ['start' => '16:00', 'end' => '17:00'] + $weekly)->assertCreated();
        $items = collect($this->getJson("/api/bookings?from={$this->day(0)}&to={$this->day(42)}&room={$hall}")->assertOk()->json('data.items'));
        $this->assertSame([$this->day(7), $this->day(14), $this->day(21), $this->day(28), $this->day(35)], $items->where('purpose', 'Bible study')->pluck('date')->values()->all());

        // Cancelling: the booker or a manager.
        $seniors = $items->firstWhere('purpose', 'Bible study')['id'];
        Sanctum::actingAs($this->deacon);
        $this->deleteJson("/api/bookings/{$seniors}")->assertForbidden();
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/bookings', ['room_id' => $office, 'date' => $this->day(2), 'start' => '09:00', 'end' => '10:00', 'purpose' => 'X'])->assertForbidden();
        $this->getJson('/api/facilities/overview')->assertOk();
        Sanctum::actingAs($this->senior);
        $this->deleteJson("/api/bookings/{$mine}")->assertOk();
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(0), 'start' => '11:00', 'end' => '12:00', 'purpose' => 'Now free'])->assertCreated();

        // Another church sees and changes none of it.
        Sanctum::actingAs($this->otherPastor);
        $this->assertSame([], $this->getJson("/api/bookings?from={$this->day(0)}&to={$this->day(42)}")->json('data.items'));
        $this->deleteJson("/api/bookings/{$seniors}")->assertNotFound();
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(3), 'start' => '09:00', 'end' => '10:00', 'purpose' => 'X'])->assertStatus(422);
        foreach (['/api/equipment', '/api/repairs', '/api/rota'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonMissing(['name' => 'Hall']);
        }
    }

    public function test_bookings_show_on_the_calendar_and_an_event_books_its_room(): void
    {
        $hall = $this->room('Hall');
        Sanctum::actingAs($this->senior);
        $this->postJson('/api/bookings', ['room_id' => $hall, 'date' => $this->day(0), 'start' => '10:00', 'end' => '12:00', 'purpose' => 'Youth meeting'])->assertCreated();
        $feed = app(LifeFeed::class)->occurrences($this->myChurch, $this->monday, $this->monday->addWeek(), [], ['ours'], ['bookings'], $this->senior);
        $this->assertSame(['Hall: Youth meeting'], array_column($feed, 'title'));
        $this->assertSame([], app(LifeFeed::class)->occurrences($this->otherChurch, $this->monday, $this->monday->addWeek(), [], ['ours'], ['bookings'], $this->otherPastor));

        // An event with a room books it, is refused when the room is taken, and frees it when cancelled.
        $this->give($this->senior, ['church.events.events.read', 'church.events.events.manage']);
        $at = fn (int $d, string $t) => CarbonImmutable::parse("{$this->day($d)} {$t}", 'Africa/Nairobi')->utc()->toIso8601String();
        $event = ['kind' => 'event', 'title' => 'Wedding', 'type' => 'wedding', 'open_to' => 'own', 'room_id' => $hall];
        $this->postJson('/api/activities', $event + ['starts_at' => $at(0, '11:00'), 'ends_at' => $at(0, '13:00')])->assertStatus(409)->assertJsonPath('data.clash.purpose', 'Youth meeting');
        $id = $this->postJson('/api/activities', $event + ['starts_at' => $at(1, '11:00'), 'ends_at' => $at(1, '13:00')])->assertCreated()->assertJsonPath('data.room.name', 'Hall')->json('data.id');
        $booking = RoomBooking::where('activity_id', $id)->first();
        $this->assertSame("{$this->day(1)} 11:00", $booking->starts_at->format('Y-m-d H:i'), 'Nairobi time');
        $this->deleteJson("/api/bookings/{$booking->id}")->assertStatus(422);
        $this->putJson("/api/activities/{$id}", $event + ['starts_at' => $at(2, '14:00'), 'ends_at' => $at(2, '15:00')])->assertOk();
        $this->assertSame("{$this->day(2)} 14:00", $booking->fresh()->starts_at->format('Y-m-d H:i'), 'it moves with the event');
        $this->postJson("/api/activities/{$id}/cancel")->assertOk();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_equipment_loans_and_repairs(): void
    {
        $hall = $this->room('Hall');
        Sanctum::actingAs($this->senior);
        $mic = $this->postJson('/api/equipment', ['name' => 'Wireless mic', 'category' => 'sound', 'quantity' => 2, 'room_id' => $hall])->assertCreated()->json('data.id');
        $mary = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Mary', 'last_name' => 'Mutua', 'phone' => '+254712345678', 'status' => 'member']);
        $this->postJson("/api/equipment/{$mic}/loans", ['to_person_id' => $mary->id])->assertCreated()->assertJsonPath('data.on_loan', 1);
        $this->postJson("/api/equipment/{$mic}/loans", ['to_name' => 'Choir', 'quantity' => 2])->assertStatus(422);
        $loan = $this->getJson("/api/equipment/{$mic}")->json('data.loans.0.id');
        $this->postJson("/api/loans/{$loan}/return")->assertOk()->assertJsonPath('data.available', 2);
        $this->assertContains('Test Senior lent it to Mary Mutua', array_column($this->getJson("/api/equipment/{$mic}")->json('data.history'), 'sentence'));

        // Anyone who sees the facilities reports a repair; those who manage them move it along.
        Sanctum::actingAs($this->treasurer);
        $job = $this->postJson('/api/repairs', ['title' => 'Mic crackles', 'equipment_id' => $mic, 'priority' => 'urgent'])->assertCreated()->json('data.id');
        $this->putJson("/api/repairs/{$job}", ['status' => 'done'])->assertForbidden();
        Sanctum::actingAs($this->senior);
        $this->putJson("/api/repairs/{$job}", ['status' => 'done', 'cost' => 1500])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.done_on', now('Africa/Nairobi')->toDateString());

        // The cost is recorded in Budgets; only our church's entry links.
        $ours = $this->budgetFor($this->myChurch, 'active');
        $theirs = $this->budgetFor($this->otherChurch, 'active');
        $entry = fn ($b) => BudgetEntry::create(['budget_id' => $b->id, 'budget_line_item_id' => DB::table('budget_line_items')->where('budget_id', $b->id)->value('id'), 'direction' => 'out', 'amount' => 1500, 'entry_date' => '2026-01-10', 'description' => 'Mic repair', 'recorded_by' => $this->senior->id]);
        $this->postJson("/api/repairs/{$job}/expense", ['budget_entry_id' => $entry($theirs)->id])->assertStatus(422);
        $this->postJson("/api/repairs/{$job}/expense", ['budget_entry_id' => $entry($ours)->id])->assertOk();

        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/equipment/{$mic}")->assertNotFound();
        $this->putJson("/api/repairs/{$job}", ['status' => 'reported'])->assertNotFound();
    }

    public function test_the_duty_rota_and_its_reminders(): void
    {
        $mary = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Mary', 'last_name' => 'Mutua', 'phone' => '+254712345678', 'status' => 'member']);
        $demo = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Demo', 'last_name' => 'Person', 'phone' => '+254700000123', 'status' => 'member']);
        $sunday = $this->monday->addDays(6);
        Sanctum::actingAs($this->senior);
        $this->putJson('/api/rota', ['on' => $sunday->toDateString(), 'service' => 'Sunday service', 'duty' => 'ushering', 'people' => [['person_id' => $mary->id], ['person_id' => $demo->id], ['name' => 'Uncle Joe']]])->assertOk();
        $rota = $this->getJson("/api/rota?from={$this->monday->toDateString()}&to={$sunday->toDateString()}")->assertOk()->json('data');
        $this->assertSame([['date' => $sunday->toDateString(), 'service' => 'Sunday service', 'start' => '09:00']], $rota['rows']);
        $this->assertSame(['Mary Mutua', 'Demo Person', 'Uncle Joe'], array_column($rota['cells']["{$sunday->toDateString()}|Sunday service|ushering"], 'name'));
        $this->postJson('/api/rota/copy', ['from' => $this->monday->toDateString(), 'to' => $this->monday->addWeek()->toDateString()])->assertOk()->assertJsonPath('data.copied', 3);
        Sanctum::actingAs($this->deacon);
        $this->putJson('/api/rota', ['on' => $sunday->toDateString(), 'service' => 'Sunday service', 'duty' => 'ushering', 'people' => []])->assertForbidden();

        // Reminders: off by default; when on, once, to real numbers only, after the chosen time.
        $eve = $sunday->subDay()->format('Y-m-d');
        Cache::flush();
        $this->artisan('facilities:duty-reminders', ['--at' => "{$eve} 18:30"])->assertSuccessful();
        $this->assertSame(0, MessageLog::where('kind', 'duty_reminder')->count(), 'off by default');
        app(Settings::class)->setMany($this->myChurch, 'church', 'facilities', ['facilities.duty_reminder' => true, 'facilities.duty_reminder_time' => '19:00']);
        $this->artisan('facilities:duty-reminders', ['--at' => "{$eve} 18:30"])->assertSuccessful();
        $this->assertSame(0, MessageLog::where('kind', 'duty_reminder')->count(), 'not before its time');
        $this->artisan('facilities:duty-reminders', ['--at' => "{$eve} 19:05"])->assertSuccessful();
        $this->artisan('facilities:duty-reminders', ['--at' => "{$eve} 20:00"])->assertSuccessful();
        $this->assertSame(1, MessageLog::where('kind', 'duty_reminder')->count(), 'Mary only, once - not the demo number or a typed name');

        $line = collect(app(LifeFeed::class)->occurrences($this->myChurch, $this->monday, $this->monday->addWeek(), [], ['ours'], ['due'], $this->senior))->firstWhere('key', "duty-{$sunday->toDateString()}");
        $this->assertSame('Duty: 3 people', $line['title']);
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            [$level] = explode('.', $name);
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level]));
        }
    }
}
