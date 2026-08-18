<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatheringTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected User $pastor;

    protected Role $pastorRole;

    protected int $ministryGatheringCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        // Seeded directly in the gathering_categories migration.
        $this->ministryGatheringCategoryId = GatheringCategory::where('slug', 'ministry_gathering')->value('id');

        $this->pastorRole = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        foreach ([
            'church.attendance.gatheringtypes.read',
            'church.attendance.gatheringtypes.create',
            'church.attendance.gatheringtypes.update',
        ] as $permName) {
            $permission = Permission::create(['name' => $permName, 'guard_name' => 'web', 'action' => 'create', 'territory_scope' => 'church']);
            $this->pastorRole->givePermissionTo($permission);
        }

        $this->pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
        ]);
        $this->pastor->assignRole($this->pastorRole);

        UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $this->pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $this->pastor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }

    public function test_pastor_can_create_a_gathering_type_for_their_own_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/gathering-types', [
            'territory_id' => $this->myChurch->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'name' => 'Kesha (All-Night Prayer)',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Kesha (All-Night Prayer)')
            ->assertJsonPath('data.territory_id', $this->myChurch->id);

        $this->assertDatabaseHas('gathering_types', [
            'territory_id' => $this->myChurch->id,
            'slug' => 'kesha-all-night-prayer',
        ]);
    }

    public function test_pastor_cannot_create_a_gathering_type_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/gathering-types', [
            'territory_id' => $this->otherChurch->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'name' => 'Kesha (All-Night Prayer)',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('gathering_types', ['territory_id' => $this->otherChurch->id]);
    }

    public function test_user_without_permission_cannot_create_a_gathering_type(): void
    {
        $noPermRole = Role::create(['name' => 'No Perm Role', 'guard_name' => 'web', 'territory_level' => 'church']);
        $user = User::create([
            'firstname' => 'No', 'lastname' => 'Perm', 'username' => 'no.perm',
            'email' => 'no.perm@example.test', 'password' => bcrypt('password'),
        ]);
        $user->assignRole($noPermRole);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $this->myChurch->id, 'role_id' => $noPermRole->id,
            'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/gathering-types', [
            'territory_id' => $this->myChurch->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'name' => 'Kesha (All-Night Prayer)',
        ]);

        $response->assertStatus(403);
    }

    public function test_index_lists_only_the_requested_churchs_gathering_types(): void
    {
        Sanctum::actingAs($this->pastor);

        GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Kesha (All-Night Prayer)', 'slug' => 'kesha-all-night-prayer',
        ]);
        GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->otherChurch->id,
            'name' => 'Tuesday Fellowship', 'slug' => 'tuesday-fellowship',
        ]);

        $response = $this->getJson('/api/gathering-types?territory_id='.$this->myChurch->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Kesha (All-Night Prayer)', $response->json('data.0.name'));
    }

    public function test_pastor_cannot_list_another_churchs_gathering_types(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/gathering-types?territory_id='.$this->otherChurch->id);

        $response->assertStatus(403);
    }

    public function test_pastor_can_deactivate_their_own_churchs_gathering_type(): void
    {
        Sanctum::actingAs($this->pastor);

        $type = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Kesha (All-Night Prayer)', 'slug' => 'kesha-all-night-prayer',
        ]);

        $response = $this->putJson("/api/gathering-types/{$type->id}", ['is_active' => false]);

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);
    }

    public function test_pastor_cannot_update_another_churchs_gathering_type(): void
    {
        Sanctum::actingAs($this->pastor);

        $type = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->otherChurch->id,
            'name' => 'Tuesday Fellowship', 'slug' => 'tuesday-fellowship',
        ]);

        $response = $this->putJson("/api/gathering-types/{$type->id}", ['is_active' => false]);

        $response->assertStatus(403);
    }
}
