<?php

namespace Tests\Feature\Settings;

use App\Models\Ministry;
use App\Models\Person;
use App\Models\PlacePhoto;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings > View profile (docs/specs/settings-spec.md): everything a place
 * set up in one read-only page - leaders by name and role only, the church's
 * counts as totals - for its own people, and Change links only for those
 * who can change each part.
 */
class ProfileViewTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        Storage::fake('local');
    }

    private function profileView(int $placeId): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/settings/view?territory_id='.$placeId);
    }

    public function test_a_church_sees_its_whole_profile(): void
    {
        $this->myChurch->forceFill([
            'description' => 'A church on the hill.', 'phone' => '+254700000001', 'email' => 'mychurch@example.test',
            'latitude' => -2.1, 'longitude' => 37.6, 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'metadata' => ['service_times' => [['name' => 'Evening prayer', 'day' => 3, 'start' => '18:00'], ['name' => 'Main service', 'day' => 0, 'start' => '09:00', 'language' => 'Kikamba']]],
        ])->saveQuietly();
        PlacePhoto::create(['territory_id' => $this->myChurch->id, 'path' => 'p/2.webp', 'thumb_path' => 'p/2t.webp', 'caption' => 'Second', 'position' => 2]);
        PlacePhoto::create(['territory_id' => $this->myChurch->id, 'path' => 'p/1.webp', 'thumb_path' => 'p/1t.webp', 'caption' => 'First', 'position' => 1]);
        Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Ruth', 'last_name' => 'Mwende', 'status' => 'member']);
        Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Gone', 'last_name' => 'Away', 'status' => 'transferred_out']);
        Person::create(['territory_id' => $this->otherChurch->id, 'first_name' => 'Not', 'last_name' => 'Ours', 'status' => 'member']);
        Ministry::create(['territory_id' => $this->myChurch->id, 'name' => 'Youth', 'kind' => 'youth', 'active' => true]);
        Room::create(['territory_id' => $this->myChurch->id, 'name' => 'Hall', 'active' => true]);

        Sanctum::actingAs($this->pastor);
        $d = $this->profileView($this->myChurch->id)->assertOk()->json('data');

        $this->assertSame('A church on the hill.', $d['profile']['description']);
        $this->assertSame('dQw4w9WgXcQ', $d['profile']['youtube_video']);
        $this->assertSame('Region A', $d['profile']['parent']['name']);
        $this->assertSame(['First', 'Second'], array_column($d['photos'], 'caption'));
        $this->assertSame(['Main service', 'Evening prayer'], array_column($d['service_times'], 'name'));
        $this->assertSame([0, '09:00', 'Kikamba'], [$d['service_times'][0]['day'], $d['service_times'][0]['start'], $d['service_times'][0]['language']]);
        $this->assertSame(['members' => 1, 'ministries' => 1, 'rooms' => 1, 'services' => 2], $d['glance']);
        $this->assertTrue($d['can']['profile']);

        // Leaders: name and role, nothing to reach them by.
        $this->assertContains('Test Pastor', array_column($d['leaders'], 'name'));
        $this->assertSame(['name', 'initials', 'role'], array_keys($d['leaders'][0]));
        $this->assertStringNotContainsString('@example.test', json_encode($d['leaders']));

        // Someone who only reads Settings sees it, with no Change links.
        Sanctum::actingAs($this->secretary);
        $can = $this->profileView($this->myChurch->id)->assertOk()->json('data.can');
        $this->assertFalse($can['profile']);
        $this->assertFalse($can['servicetimes']);
    }

    public function test_a_region_sees_its_profile_and_leaders_without_church_counts(): void
    {
        Sanctum::actingAs($this->overseer);
        $d = $this->profileView($this->region->id)->assertOk()->json('data');
        $this->assertSame('Region A', $d['profile']['name']);
        $this->assertNull($d['service_times']);
        $this->assertNull($d['glance']);
        $this->assertContains('Test Overseer', array_column($d['leaders'], 'name'));
    }

    public function test_only_its_own_people_see_it(): void
    {
        Sanctum::actingAs($this->otherPastor);
        $this->profileView($this->myChurch->id)->assertForbidden();

        $nobody = $this->settingsUser('nobody', 'Test Member', 'church', $this->myChurch->id, []);
        Sanctum::actingAs($nobody);
        $this->profileView($this->myChurch->id)->assertForbidden();
    }
}
