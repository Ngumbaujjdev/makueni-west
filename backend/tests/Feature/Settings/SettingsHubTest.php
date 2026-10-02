<?php

namespace Tests\Feature\Settings;

use App\Models\GatheringType;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use Database\Seeders\SettingsHubSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Settings hub's rail, Overview, Profile and Service times
 * (docs/specs/settings-spec.md, S1).
 */
class SettingsHubTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        Storage::fake('local');
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Church', 'phone' => '+254 712 345 678', 'email' => 'mychurch@example.test',
            'address' => 'Sultan Hamud town', 'county' => 'Makueni', 'latitude' => -2.0213, 'longitude' => 37.3766,
        ], $overrides);
    }

    public function test_the_rail_shows_a_churchs_sections_with_what_this_role_can_do(): void
    {
        Sanctum::actingAs($this->pastor);
        $groups = $this->getJson('/api/settings/sections')->assertOk()
            ->assertJsonPath('data.level', 'church')
            ->assertJsonPath('data.place.name', 'My Church')
            ->json('data.groups');

        $sections = collect($groups)->flatMap(fn ($g) => $g['sections'])->keyBy('key');
        $this->assertSame(['overview', 'profile', 'servicetimes'], $sections->keys()->all());
        $this->assertTrue($sections['profile']['can']['update']);
        $this->assertTrue($sections['profile']['attention'], 'an empty profile needs attention');
        $this->assertSame('Our place', collect($groups)->firstWhere('key', 'our-place')['label']);

        Sanctum::actingAs($this->secretary);
        $sections = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->keyBy('key');
        $this->assertFalse($sections['profile']['can']['update']);
    }

    public function test_a_region_has_no_service_times(): void
    {
        Sanctum::actingAs($this->overseer);
        $keys = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->pluck('key')->all();

        $this->assertSame(['overview', 'profile'], $keys);
        $this->getJson('/api/settings/service-times')->assertNotFound();
    }

    public function test_someone_without_settings_permissions_is_refused(): void
    {
        $role = Role::create(['name' => 'Test Usher', 'guard_name' => 'web', 'territory_level' => 'church']);
        $usher = $this->settingsUser('usher', 'Test Usher', 'church', $this->myChurch->id, []);
        Sanctum::actingAs($usher);

        $this->getJson('/api/settings/sections')->assertForbidden();
        $this->getJson('/api/settings/profile')->assertForbidden();
    }

    public function test_only_a_global_admin_can_open_another_places_settings(): void
    {
        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/settings/profile?territory_id={$this->myChurch->id}")->assertForbidden();

        Sanctum::actingAs($this->globalAdmin);
        $this->getJson("/api/settings/profile?territory_id={$this->myChurch->id}")->assertOk()
            ->assertJsonPath('data.profile.name', 'My Church');
    }

    public function test_a_secretary_can_read_the_profile_but_not_change_it(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->getJson('/api/settings/profile')->assertOk()->assertJsonPath('data.can.update', false);
        $this->putJson('/api/settings/profile', $this->profile())->assertForbidden();
    }

    public function test_the_pastor_fills_in_the_profile_and_it_is_audited(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->putJson('/api/settings/profile', $this->profile(['website' => 'mychurch.or.ke']))
            ->assertOk()
            ->assertJsonPath('data.profile.county', 'Makueni')
            ->assertJsonPath('data.profile.website', 'https://mychurch.or.ke')
            ->assertJsonPath('data.completeness.percent', 83); // all but the logo

        $this->assertDatabaseHas('territories', ['id' => $this->myChurch->id, 'phone' => '+254 712 345 678', 'county' => 'Makueni']);
        $this->assertDatabaseHas('audits', ['event' => 'settings.updated', 'auditable_type' => 'territory', 'auditable_id' => $this->myChurch->id, 'tags' => 'settings,profile']);
        $this->assertSame(0, DB::table('audits')->where('event', 'updated')->where('auditable_id', $this->myChurch->id)->count(), 'one settings audit row, not a second model audit');
    }

    public function test_the_profile_refuses_bad_values(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->putJson('/api/settings/profile', $this->profile(['county' => 'Atlantis']))->assertStatus(422)->assertJsonValidationErrors(['county']);
        $this->putJson('/api/settings/profile', $this->profile(['longitude' => null]))->assertStatus(422)->assertJsonValidationErrors(['longitude']);
        $this->putJson('/api/settings/profile', $this->profile(['phone' => 'call me']))->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->putJson('/api/settings/profile', $this->profile(['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_a_logo_is_stored_as_webp_shown_publicly_and_can_be_removed(): void
    {
        Sanctum::actingAs($this->pastor);

        $url = $this->post('/api/settings/profile/logo', ['logo' => UploadedFile::fake()->image('logo.png', 1200, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.completeness.missing.logo', null)
            ->json('data.profile.logo_url');
        $this->assertNotNull($url);
        Storage::disk('local')->assertExists("logos/{$this->myChurch->id}.webp");
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get("logos/{$this->myChurch->id}.webp"));
        $this->assertSame([512, 256], [$w, $h], 'scaled to fit 512px');

        $this->get("/api/settings/logo/{$this->myChurch->id}")->assertOk()->assertHeader('Content-Type', 'image/webp');

        $this->deleteJson('/api/settings/profile/logo')->assertOk()->assertJsonPath('data.profile.logo_url', null);
        Storage::disk('local')->assertMissing("logos/{$this->myChurch->id}.webp");
        $this->get("/api/settings/logo/{$this->myChurch->id}")->assertNotFound();
    }

    public function test_a_logo_must_be_an_image_of_two_megabytes_or_less(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->post('/api/settings/profile/logo', ['logo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/settings/profile/logo', ['logo' => UploadedFile::fake()->image('big.png')->size(3000)], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_service_times_are_saved_in_order_and_checked(): void
    {
        $type = GatheringType::create(['territory_id' => $this->myChurch->id, 'gathering_category_id' => DB::table('gathering_categories')->value('id'), 'name' => 'Main Service', 'slug' => 'main-service', 'is_active' => true]);
        Sanctum::actingAs($this->pastor);

        $this->putJson('/api/settings/service-times', ['times' => [
            ['name' => 'Midweek prayer', 'day' => 3, 'start' => '17:30', 'end' => '19:00'],
            ['name' => 'Main service', 'day' => 0, 'start' => '10:00', 'end' => '12:30', 'gathering_type_id' => $type->id, 'language' => 'Kikamba'],
            ['name' => 'English service', 'day' => 0, 'start' => '08:00'],
        ]])->assertOk()
            ->assertJsonPath('data.times.0.name', 'English service')
            ->assertJsonPath('data.times.1.gathering_type_id', $type->id)
            ->assertJsonPath('data.times.2.day', 3);
        $this->assertCount(3, $this->myChurch->fresh()->getServiceTimes());

        $this->putJson('/api/settings/service-times', ['times' => [['name' => 'X', 'day' => 0, 'start' => '9am']]])->assertStatus(422);
        $this->putJson('/api/settings/service-times', ['times' => [['name' => 'X', 'day' => 0, 'start' => '10:00', 'end' => '09:00']]])->assertStatus(422);
        $this->putJson('/api/settings/service-times', ['times' => array_fill(0, 21, ['name' => 'X', 'day' => 0, 'start' => '10:00'])])->assertStatus(422);
        $this->putJson('/api/settings/service-times', ['times' => []])->assertOk()->assertJsonPath('data.times', []);
    }

    public function test_service_times_saved_by_the_old_seeders_are_read_as_a_list(): void
    {
        $this->myChurch->update(['metadata' => ['service_times' => ['wednesday_prayer' => '18:00', 'sunday_morning' => '9:00', 'sunday_evening' => '16:00']]]);
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/settings/service-times')->assertOk()
            ->assertJsonPath('data.times.0', ['name' => 'Sunday morning', 'day' => 0, 'start' => '09:00', 'end' => null, 'gathering_type_id' => null, 'language' => null])
            ->assertJsonPath('data.times.1.name', 'Sunday evening')
            ->assertJsonPath('data.times.2.name', 'Wednesday prayer');
        $this->assertSame(3, $this->getJson('/api/settings/overview')->json('data.service_times.count'));
    }

    public function test_the_overview_has_a_checklist_and_above_church_the_churches_missing_details(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/settings/profile', $this->profile());
        $checklist = collect($this->getJson('/api/settings/overview')->assertOk()->json('data.checklist'))->keyBy('key');
        $this->assertTrue($checklist['phone']['done']);
        $this->assertFalse($checklist['logo']['done']);
        $this->assertFalse($checklist['service_times']['done']);

        Sanctum::actingAs($this->overseer);
        $below = $this->getJson('/api/settings/overview')->assertOk()->json('data.below');
        $this->assertSame(2, $below['total']);
        $this->assertSame('Other Church', $below['missing'][0]['name'], 'least complete first');
    }

    public function test_the_seeder_builds_each_levels_settings_menu_and_grants(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-settings"], ['name' => 'Settings', 'territory_scope' => $level, 'is_active' => true]);
        }
        $pastorRole = Role::create(['name' => 'Senior Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);
        $secretaryRole = Role::create(['name' => 'Church Secretary', 'guard_name' => 'web', 'territory_level' => 'church']);

        $this->seed(SettingsHubSeeder::class);
        $this->seed(SettingsHubSeeder::class); // idempotent

        $module = Module::where('name', 'Settings')->whereHas('moduleGroup', fn ($q) => $q->where('slug', 'church-settings'))->firstOrFail();
        $this->assertSame(
            ['/church/settings/', '/church/settings/?section=profile', '/church/settings/?section=servicetimes'],
            Submodule::where('module_id', $module->id)->orderBy('id')->pluck('path')->all(),
        );
        $this->assertSame(1, Permission::where('name', 'church.settings.hub.profile.update')->count());
        $this->assertTrue($pastorRole->fresh()->hasPermissionTo('church.settings.hub.profile.update'));
        $this->assertTrue($secretaryRole->fresh()->hasPermissionTo('church.settings.hub.profile.read'));
        $this->assertFalse($secretaryRole->fresh()->hasPermissionTo('church.settings.hub.profile.update'));
        $this->assertTrue(Permission::where('name', 'region.settings.hub.overview.read')->exists());
        $this->assertFalse(Permission::where('name', 'region.settings.hub.servicetimes.read')->exists());
    }
}
