<?php

namespace Tests\Feature\Settings;

use App\Models\PlacePhoto;
use App\Services\Images\ImageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings > Profile > Gallery and the YouTube link (docs/specs/settings-spec.md):
 * photos go through the image engine (upright, WebP, at most 1920px, a
 * thumbnail, nothing of the original kept), need Profile's update permission,
 * and are public for the church's own page.
 */
class GalleryTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        Storage::fake('local');
    }

    /** A JPEG as a phone writes it: landscape pixels, with EXIF Orientation 6 ("turn me 90 degrees"). */
    private function sidewaysPhone(int $w = 400, int $h = 200): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 40, 160, 190));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $tiff = "II\x2A\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00";
        $app1 = "\xFF\xE1".pack('n', strlen("Exif\x00\x00".$tiff) + 2)."Exif\x00\x00".$tiff;
        $path = tempnam(sys_get_temp_dir(), 'phone').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true);
    }

    public function test_the_engine_stands_a_phone_photo_up_and_keeps_only_a_webp(): void
    {
        $img = app(ImageEngine::class)->store($this->sidewaysPhone(), 'places/1/gallery', 1920, 82, true);

        $this->assertSame([200, 400], [$img->width, $img->height], 'turned upright');
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get($img->path));
        $this->assertSame([200, 400, IMAGETYPE_WEBP], [$w, $h, $type]);
        Storage::disk('local')->assertExists($img->thumbPath);
        $this->assertCount(2, Storage::disk('local')->allFiles('places/1/gallery'), 'the WebP and its thumbnail - no original');
    }

    public function test_photos_are_added_as_webp_capped_at_1920_with_a_thumbnail_and_shown_publicly(): void
    {
        Sanctum::actingAs($this->pastor);
        $photos = $this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->image('big.jpg', 4000, 3000), UploadedFile::fake()->image('b.png', 800, 600)]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.max', 30)->json('data.photos');

        $this->assertCount(2, $photos);
        $first = PlacePhoto::find($photos[0]['id']);
        $this->assertSame([1920, 1440], [$first->width, $first->height]);
        [$tw] = getimagesizefromstring(Storage::disk('local')->get($first->thumb_path));
        $this->assertSame(480, $tw);

        $this->get("/api/places/{$this->myChurch->id}/photos/{$first->id}")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("/api/places/{$this->myChurch->id}/photos/{$first->id}/thumb")->assertOk();
        $this->get("/api/places/{$this->otherChurch->id}/photos/{$first->id}")->assertNotFound();
        $this->getJson("/api/places/{$this->myChurch->id}/gallery")->assertOk()->assertJsonCount(2, 'data.photos');
    }

    public function test_captions_order_and_removal(): void
    {
        Sanctum::actingAs($this->pastor);
        $ids = collect($this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')]], ['Accept' => 'application/json'])->json('data.photos'))->pluck('id')->all();

        $this->patchJson("/api/settings/profile/photos/{$ids[1]}", ['caption' => 'Easter Sunday'])->assertOk()->assertJsonPath('data.caption', 'Easter Sunday');
        $order = $this->postJson('/api/settings/profile/photos/order', ['ids' => array_reverse($ids)])->assertOk()->json('data.photos');
        $this->assertSame(array_reverse($ids), array_column($order, 'id'));
        $this->postJson('/api/settings/profile/photos/order', ['ids' => [$ids[0]]])->assertStatus(422);

        $gone = PlacePhoto::find($ids[2]);
        $this->deleteJson("/api/settings/profile/photos/{$ids[2]}")->assertOk()->assertJsonCount(2, 'data.photos');
        Storage::disk('local')->assertMissing($gone->path);
        Storage::disk('local')->assertMissing($gone->thumb_path);
        $this->getJson('/api/settings/profile')->assertJsonPath('data.profile.photo_count', 2);
    }

    public function test_only_profile_editors_change_the_gallery_and_the_limits_hold(): void
    {
        Sanctum::actingAs($this->secretary);
        $this->getJson('/api/settings/profile/photos')->assertOk();
        $this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->pastor);
        $this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->image('big.jpg')->size(11000)]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['photos.0' => 'Each photo must be 10 MB or smaller.']);
        $this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertStatus(422);

        foreach (range(1, 29) as $i) {
            PlacePhoto::create(['territory_id' => $this->myChurch->id, 'path' => "x/{$i}.webp", 'position' => $i]);
        }
        $this->post('/api/settings/profile/photos', ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.photos.0', 'You can add 1 more photo - the gallery holds 30.');
    }

    public function test_a_youtube_link_is_saved_and_anything_else_is_refused(): void
    {
        Sanctum::actingAs($this->pastor);
        $base = ['name' => 'My Church'];

        $this->putJson('/api/settings/profile', $base + ['youtube_url' => 'youtube.com/@mychurch'])->assertOk()
            ->assertJsonPath('data.profile.youtube_url', 'https://youtube.com/@mychurch')->assertJsonPath('data.profile.youtube_video', null);
        $this->putJson('/api/settings/profile', $base + ['youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])->assertOk()
            ->assertJsonPath('data.profile.youtube_video', 'dQw4w9WgXcQ');
        $this->putJson('/api/settings/profile', $base + ['youtube_url' => 'https://vimeo.com/123'])->assertStatus(422)->assertJsonValidationErrors(['youtube_url']);
    }
}
