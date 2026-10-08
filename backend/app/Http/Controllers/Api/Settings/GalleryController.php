<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\PlacePhoto;
use App\Models\Territory;
use App\Services\Images\ImageEngine;
use App\Services\Settings\Settings;
use App\Support\SettingsAccess;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings > Profile > Gallery (docs/specs/settings-spec.md): a place's
 * photos - the start of each church's own page. Uploads go through the image
 * engine (upright, at most 1920px, WebP, a 480px thumbnail; the original is
 * never kept). Changing them needs Profile's update permission, like the
 * logo. The photos and the gallery are public, like the logo.
 */
class GalleryController extends SettingsController
{
    private const SECTION = 'profile';

    private const MAX_SIDE = 1920;

    /** GET /settings/profile/photos */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION)) {
            return $deny;
        }

        return $this->ok($this->payload($request, $place));
    }

    /** POST /settings/profile/photos {photos[]} - 1 to 10 at a time, 30 in all. */
    public function store(Request $request, Settings $settings, ImageEngine $engine): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'photos.required' => 'Choose at least one photo.',
            'photos.max' => 'Add up to 10 photos at a time.',
            'photos.*.image' => 'Only photos can go in the gallery - png, jpg or webp.',
            'photos.*.mimes' => 'Only photos can go in the gallery - png, jpg or webp.',
            'photos.*.max' => 'Each photo must be 10 MB or smaller.',
        ]);

        $files = $request->file('photos');
        $have = PlacePhoto::where('territory_id', $place->id)->count();
        $room = PlacePhoto::MAX - $have;
        if (count($files) > $room) {
            throw ValidationException::withMessages(['photos' => $room > 0
                ? "You can add {$room} more ".($room === 1 ? 'photo' : 'photos').' - the gallery holds '.PlacePhoto::MAX.'.'
                : 'The gallery is full ('.PlacePhoto::MAX.' photos). Remove some to add more.']);
        }

        $position = (int) PlacePhoto::where('territory_id', $place->id)->max('position');
        foreach ($files as $file) {
            $img = $engine->store($file, "places/{$place->id}/gallery", self::MAX_SIDE, 82, true, 'photos');
            PlacePhoto::create([
                'territory_id' => $place->id, 'path' => $img->path, 'thumb_path' => $img->thumbPath,
                'width' => $img->width, 'height' => $img->height, 'bytes' => $img->bytes,
                'position' => ++$position, 'created_by' => $request->user()->id,
            ]);
        }
        $n = count($files);
        $settings->audit($place, self::SECTION, ['gallery' => ['old' => "{$have} photos", 'new' => 'added '.$n.' '.($n === 1 ? 'photo' : 'photos')]], $request->user());

        return $this->ok($this->payload($request, $place), $n === 1 ? 'Photo added.' : "{$n} photos added.", 201);
    }

    /** PATCH /settings/profile/photos/{id} {caption} */
    public function update(Request $request, Settings $settings, int $id): JsonResponse
    {
        [$place, $photo] = $this->mine($request, $id);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:160']]);
        $caption = trim((string) ($data['caption'] ?? '')) ?: null;
        if ($caption !== $photo->caption) {
            $settings->audit($place, self::SECTION, ['photo caption' => ['old' => $photo->caption, 'new' => $caption]], $request->user());
            $photo->update(['caption' => $caption]);
        }

        return $this->ok($photo->fresh()->present(), 'Caption saved.');
    }

    /** POST /settings/profile/photos/order {ids: [..]} - the gallery's order, first to last. */
    public function order(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }
        $ids = array_map('intval', (array) $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids']);
        $mine = PlacePhoto::where('territory_id', $place->id)->pluck('id')->all();
        if (array_diff($ids, $mine) || count(array_unique($ids)) !== count($mine)) {
            throw ValidationException::withMessages(['ids' => 'Send every photo in the gallery, once each.']);
        }
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                PlacePhoto::whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return $this->ok($this->payload($request, $place), 'Order saved.');
    }

    /** DELETE /settings/profile/photos/{id} */
    public function destroy(Request $request, Settings $settings, ImageEngine $engine, int $id): JsonResponse
    {
        [$place, $photo] = $this->mine($request, $id);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $engine->delete($photo->path, $photo->thumb_path);
        $photo->delete();
        $settings->audit($place, self::SECTION, ['gallery' => ['old' => 'a photo', 'new' => 'removed']], $request->user());

        return $this->ok($this->payload($request, $place), 'Photo removed.');
    }

    /** GET /places/{territory}/photos/{photo}[/thumb] - public, like the logo. */
    public function file(Territory $territory, PlacePhoto $photo, ?string $size = null): Response
    {
        $path = $size === 'thumb' ? ($photo->thumb_path ?: $photo->path) : $photo->path;
        if ($photo->territory_id !== $territory->id || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    /** GET /places/{territory}/gallery - public: the photos and the YouTube link, for the church's own page. */
    public function gallery(Territory $territory): JsonResponse
    {
        return $this->ok([
            'place' => ['id' => $territory->id, 'name' => $territory->name],
            'youtube_url' => $territory->youtube_url,
            'youtube_video' => YouTube::videoId($territory->youtube_url),
            'photos' => $this->photos($territory),
        ]);
    }

    /** @return array{0: Territory|JsonResponse, 1: ?PlacePhoto} */
    private function mine(Request $request, int $id): array
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return [$place, null];
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return [$deny, null];
        }
        $photo = PlacePhoto::where('territory_id', $place->id)->find($id);
        if (! $photo) {
            return [response()->json(['success' => false, 'status' => 404, 'message' => "That photo isn't in your gallery."], 404), null];
        }

        return [$place, $photo];
    }

    private function photos(Territory $place): array
    {
        return PlacePhoto::where('territory_id', $place->id)->orderBy('position')->orderBy('id')->get()->map->present()->values()->all();
    }

    private function payload(Request $request, Territory $place): array
    {
        return [
            'photos' => $this->photos($place),
            'max' => PlacePhoto::MAX,
            'can' => ['update' => SettingsAccess::can($request->user(), $place, self::SECTION, 'update')],
        ];
    }
}
