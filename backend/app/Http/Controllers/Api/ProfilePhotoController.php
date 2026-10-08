<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Images;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * My Profile: a person's own photo. Shown on the profile, the header and
 * the sidebar. Re-encoded to a 400px square webp, so whatever was uploaded
 * (a 12 MP phone photo, a png with metadata) never reaches anyone as-is.
 */
class ProfilePhotoController extends Controller
{
    private const SIZE = 400;

    /** POST /auth/profile/photo - png, jpg or webp up to 5 MB. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']], [
            'photo.required' => 'Choose a photo first.',
            'photo.image' => 'That file is not a photo. Use a png, jpg or webp.',
            'photo.mimes' => 'Use a png, jpg or webp photo.',
            'photo.max' => 'The photo must be 5 MB or smaller.',
        ]);

        // Upright first - a phone photo arrives sideways with an EXIF note.
        $image = Images::fromUpload($request->file('photo')->getRealPath());
        if (! $image) {
            throw ValidationException::withMessages(['photo' => "That file couldn't be read as a photo."]);
        }

        $user = $request->user();
        $old = $user->photo_path;
        // A new name each time, so the change is audited and browsers load the new photo.
        $path = "photos/{$user->id}-".now()->format('YmdHis').'.webp';
        Storage::disk('local')->put($path, Images::webp(Images::squareCrop($image, self::SIZE)));

        $user->photo_path = $path;
        $user->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return successResponse('Your photo is saved.', ['photo_url' => $user->photo_url]);
    }

    /** DELETE /auth/profile/photo */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->photo_path) {
            Storage::disk('local')->delete($user->photo_path);
            $user->photo_path = null;
            $user->save();
        }

        return successResponse('Your photo is removed.', ['photo_url' => null]);
    }

    /** GET /users/{user}/photo - public: <img> tags can't send the sign-in token. */
    public function show(User $user): Response
    {
        if (! $user->photo_path || ! Storage::disk('local')->exists($user->photo_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($user->photo_path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
