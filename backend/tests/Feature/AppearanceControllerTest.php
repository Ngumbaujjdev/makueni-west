<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers GET/PUT/DELETE /api/appearance - see
 * docs/specs/appearance-settings-spec.md for the full contract. Not
 * under tests/Feature/Diocese|Financial|Demographics since Appearance
 * is self-scoped per-user, not territory-scoped - it belongs to the
 * generic "Feature" suite, same as ExampleTest.php.
 */
class AppearanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'firstname' => 'Test', 'lastname' => 'User', 'username' => 'test.user',
            'email' => 'test.user@example.test', 'password' => bcrypt('password'),
        ]);

        $this->otherUser = User::create([
            'firstname' => 'Other', 'lastname' => 'User', 'username' => 'other.user',
            'email' => 'other.user@example.test', 'password' => bcrypt('password'),
        ]);
    }

    public function test_show_returns_all_defaults_when_nothing_saved(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/appearance');

        $response->assertStatus(200)
            ->assertJsonPath('data.density', 'comfortable')
            ->assertJsonPath('data.text_size', 'medium')
            ->assertJsonPath('data.reduce_motion', false)
            ->assertJsonPath('data.high_contrast', false)
            ->assertJsonPath('data.focus_outlines', false)
            ->assertJsonPath('data.underline_links', false)
            ->assertJsonPath('data.big_targets', false)
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.accent', 'teal')
            ->assertJsonPath('data.accent_rgb', '44, 164, 191')
            ->assertJsonPath('data.classes', []);
    }

    public function test_update_persists_theme_and_accent_without_adding_classes(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/appearance', ['theme' => 'dark', 'accent' => 'purple']);

        $response->assertStatus(200)
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.accent', 'purple')
            ->assertJsonPath('data.accent_rgb', '124, 58, 237')
            ->assertJsonPath('data.classes', []);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $this->user->id, 'key' => 'theme', 'value' => 'dark']);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $this->user->id, 'key' => 'accent', 'value' => 'purple']);
    }

    public function test_system_theme_is_accepted_and_default_theme_deletes_row(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/appearance', ['theme' => 'system'])->assertJsonPath('data.theme', 'system');
        $this->putJson('/api/appearance', ['theme' => 'light'])->assertJsonPath('data.theme', 'light');

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->user->id, 'key' => 'theme']);
    }

    public function test_invalid_theme_or_accent_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/appearance', ['theme' => 'sepia'])->assertStatus(422);
        $this->putJson('/api/appearance', ['accent' => '#ff0000'])->assertStatus(422);

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->user->id]);
    }

    public function test_update_persists_a_non_default_value(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/appearance', ['density' => 'compact']);

        $response->assertStatus(200)->assertJsonPath('data.density', 'compact');
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id, 'key' => 'density', 'value' => 'compact',
        ]);

        $getResponse = $this->getJson('/api/appearance');
        $getResponse->assertJsonPath('data.density', 'compact');
        $getResponse->assertJsonPath('data.classes', ['app-density-compact']);
    }

    public function test_update_with_a_default_value_deletes_any_existing_row(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/appearance', ['density' => 'compact']);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $this->user->id, 'key' => 'density']);

        $response = $this->putJson('/api/appearance', ['density' => 'comfortable']);

        $response->assertStatus(200)->assertJsonPath('data.density', 'comfortable');
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->user->id, 'key' => 'density']);
    }

    public function test_update_rejects_an_invalid_density_value(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/appearance', ['density' => 'huge']);

        $response->assertStatus(422)->assertJsonValidationErrors('density');
    }

    public function test_update_toggles_a_boolean_accessibility_setting(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/appearance', ['reduce_motion' => true]);

        $response->assertStatus(200)
            ->assertJsonPath('data.reduce_motion', true)
            ->assertJsonPath('data.classes', ['app-reduce-motion']);
    }

    public function test_destroy_resets_all_settings_to_defaults(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/appearance', ['density' => 'spacious', 'text_size' => 'large', 'high_contrast' => true]);
        $this->assertDatabaseCount('user_preferences', 3);

        $response = $this->deleteJson('/api/appearance');

        $response->assertStatus(200)
            ->assertJsonPath('data.density', 'comfortable')
            ->assertJsonPath('data.text_size', 'medium')
            ->assertJsonPath('data.high_contrast', false);
        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_one_users_preferences_never_affect_another_users_response(): void
    {
        Sanctum::actingAs($this->user);
        $this->putJson('/api/appearance', ['density' => 'compact']);

        Sanctum::actingAs($this->otherUser);
        $response = $this->getJson('/api/appearance');

        $response->assertJsonPath('data.density', 'comfortable');
    }
}
