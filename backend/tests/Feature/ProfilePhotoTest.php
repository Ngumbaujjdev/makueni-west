<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * My Profile: a person adds, changes and removes their own photo. It is
 * stored as a 400px square webp and shared through a public photo_url.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->me = User::create([
            'firstname' => 'Me', 'lastname' => 'Tester', 'username' => 'me.tester',
            'email' => 'me@example.test', 'password' => bcrypt('old-password-1'),
            'employee_code' => '483930', 'status' => 'active', 'phone' => '+254711000333',
        ]);
    }

    public function test_a_photo_is_saved_as_a_square_webp_and_shared_as_a_url(): void
    {
        Sanctum::actingAs($this->me);

        $res = $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 1200, 800)])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user = $this->me->fresh();
        $this->assertNotNull($user->photo_path);
        Storage::disk('local')->assertExists($user->photo_path);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get($user->photo_path));
        $this->assertSame([400, 400, IMAGETYPE_WEBP], [$w, $h, $type]);

        $this->assertSame($user->photo_url, $res->json('data.photo_url'));
        $this->assertStringContainsString("/api/users/{$user->id}/photo?v=", $user->photo_url);
        $this->assertArrayNotHasKey('photo_path', $user->toArray());
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        Sanctum::actingAs($this->me);
        Storage::disk('local')->put('photos/old.webp', 'x');
        $this->me->update(['photo_path' => 'photos/old.webp']);

        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->image('me.png', 500, 500)])->assertOk();

        Storage::disk('local')->assertMissing('photos/old.webp');
        Storage::disk('local')->assertExists($this->me->fresh()->photo_path);
    }

    public function test_a_file_that_is_too_big_or_not_a_photo_is_refused_clearly(): void
    {
        Sanctum::actingAs($this->me);

        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->image('big.jpg')->size(6000)])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', 'The photo must be 5 MB or smaller.');

        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->create('notes.txt', 3, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', 'That file is not a photo. Use a png, jpg or webp.');

        $this->assertNull($this->me->fresh()->photo_path);
    }

    public function test_removing_the_photo_clears_it_and_the_file(): void
    {
        Sanctum::actingAs($this->me);
        Storage::disk('local')->put('photos/mine.webp', 'x');
        $this->me->update(['photo_path' => 'photos/mine.webp']);

        $this->deleteJson('/api/auth/profile/photo')->assertOk()->assertJsonPath('data.photo_url', null);

        $this->assertNull($this->me->fresh()->photo_path);
        Storage::disk('local')->assertMissing('photos/mine.webp');
    }

    public function test_the_photo_is_public_and_missing_ones_are_not_found(): void
    {
        $this->getJson("/api/users/{$this->me->id}/photo")->assertNotFound();

        Storage::disk('local')->put('photos/mine.webp', 'x');
        $this->me->update(['photo_path' => 'photos/mine.webp']);

        $this->get("/api/users/{$this->me->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_signed_out_people_cannot_upload(): void
    {
        $this->postJson('/api/auth/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])->assertUnauthorized();
    }

    public function test_every_user_payload_carries_the_photo_url(): void
    {
        $this->assertNull($this->me->toArray()['photo_url']);

        $this->me->update(['photo_path' => 'photos/mine.webp']);
        $this->assertSame(config('app.url')."/api/users/{$this->me->id}/photo?v=mine", $this->me->fresh()->toArray()['photo_url']);
    }
}
