<?php

namespace Tests\Feature\Activities;

use App\Notifications\PlaceNotification;
use App\Support\BudgetAccess;
use App\Support\PlaceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * The Church life foundation (docs/specs/events-initiatives-spec.md, L1a):
 * PlaceAccess answers as Budgets does, and in-app notifications are each
 * person's own.
 */
class FoundationTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    public function test_place_access_follows_the_budget_rules(): void
    {
        $this->actingAs($this->pastor);
        $this->assertSame($this->myChurch->id, PlaceAccess::place($this->pastor)->id);
        $this->assertNull(PlaceAccess::place($this->pastor, $this->otherChurch->id), 'never sideways');
        $this->assertNull(PlaceAccess::place($this->pastor, $this->region->id), 'never upwards');
        $this->assertTrue(PlaceAccess::isOwn($this->pastor, $this->myChurch));

        $this->actingAs($this->overseer);
        $this->assertSame($this->myChurch->id, PlaceAccess::place($this->overseer, $this->myChurch->id)->id, 'a place below, to view');
        $this->assertNull(PlaceAccess::place($this->overseer, $this->farChurch->id), "another region's church");
        $this->assertSame(BudgetAccess::isBelow($this->overseer, $this->myChurch->id), PlaceAccess::isBelow($this->overseer, $this->myChurch));
        $this->assertFalse(PlaceAccess::isBelow($this->overseer, $this->farChurch));

        $this->assertSame([$this->region->id, $this->diocese->id], array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($this->myChurch)));
        $this->assertEqualsCanonicalizing([$this->myChurch->id, $this->otherChurch->id], PlaceAccess::descendantIds($this->region));
        $this->assertTrue(PlaceAccess::can($this->pastor, $this->myChurch, ['read' => 'budgets.budgets.read'], 'read'));
        $this->assertFalse(PlaceAccess::can($this->pastor, $this->myChurch, ['read' => 'events.events.read'], 'read'));
    }

    public function test_notifications_are_each_persons_own(): void
    {
        $this->pastor->notify(new PlaceNotification('invitation', 'Youth Convention', 'You are invited.', '/church/events/event?id=1', $this->diocese));
        $this->travel(1)->minutes();
        $this->pastor->notify(new PlaceNotification('report', 'April report seen', 'The region saw it.'));
        $this->overseer->notify(new PlaceNotification('message', 'Hello', 'From the diocese'));
        $theirs = $this->overseer->notifications()->first()->id;

        Sanctum::actingAs($this->pastor);
        $d = $this->getJson('/api/notifications')->assertOk()->json('data');
        $this->assertSame([2, 2], [count($d['items']), $d['unread']]);
        $this->assertSame(['report', 'invitation'], array_column($d['items'], 'kind'), 'newest first');
        $this->assertSame(['id' => $this->diocese->id, 'name' => $this->diocese->name], $d['items'][1]['place']);
        $this->assertSame(1, $d['by_kind']['invitation']['unread']);
        $this->assertCount(1, $this->getJson('/api/notifications?kind=invitation')->json('data.items'));

        $this->postJson("/api/notifications/{$theirs}/read")->assertNotFound();
        $this->postJson('/api/notifications/'.$d['items'][0]['id'].'/read')->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertCount(1, $this->getJson('/api/notifications?unread=1')->json('data.items'));
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notifications')->json('data.unread'));
        $this->assertSame(1, $this->overseer->unreadNotifications()->count(), "someone else's stay unread");
    }
}
