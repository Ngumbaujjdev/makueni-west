<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Territory;
use App\Services\Settings\Settings;
use App\Support\Images;
use App\Support\Kenya;
use App\Support\Settings\PlaceProfile;
use App\Support\SettingsAccess;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings > Profile: a church's, region's or diocese's own details
 * (docs/specs/settings-spec.md). Stored on territories; the code and the
 * place in the hierarchy can't be changed here.
 */
class ProfileController extends SettingsController
{
    private const SECTION = 'profile';

    private const FIELDS = [
        'name', 'description', 'established_date', 'phone', 'email', 'website', 'youtube_url',
        'address', 'town', 'sub_county', 'county', 'postal_code', 'latitude', 'longitude',
    ];

    /** GET /settings/profile */
    public function show(Request $request): JsonResponse
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

    /** PUT /settings/profile */
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }

        $website = trim((string) $request->input('website'));
        if ($website !== '' && ! preg_match('~^https?://~i', $website)) {
            $request->merge(['website' => "https://{$website}"]);
        }
        if ($request->filled('youtube_url')) {
            $request->merge(['youtube_url' => YouTube::normalise($request->input('youtube_url'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'established_date' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'youtube_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! YouTube::valid($value)) {
                    $fail('Use a YouTube link - your channel (youtube.com/@yourchurch) or a video.');
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'town' => ['nullable', 'string', 'max:100'],
            'sub_county' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', Rule::in(Kenya::COUNTIES)],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ], [
            'phone.regex' => 'Use digits, spaces and + ( ) - only, e.g. +254 712 345 678.',
            'county.in' => 'Pick a county from the list.',
            'latitude.required_with' => 'Drop the pin on the map, or clear both.',
            'longitude.required_with' => 'Drop the pin on the map, or clear both.',
        ]);

        $changes = [];
        foreach (self::FIELDS as $field) {
            $new = $data[$field] ?? null;
            $new = is_string($new) ? (trim($new) === '' ? null : trim($new)) : $new;
            $old = $field === 'established_date' ? $place->established_date?->toDateString() : $place->{$field};
            $same = in_array($field, ['latitude', 'longitude'], true)
                ? ($old === null && $new === null) || ($old !== null && $new !== null && abs((float) $old - (float) $new) < 0.0000001)
                : (string) $old === (string) $new;
            if (! $same) {
                $changes[$field] = ['old' => $old, 'new' => $new];
                $place->{$field} = $new;
            }
        }

        if ($changes) {
            $place->updated_by = $request->user()->id;
            Territory::withoutAuditing(fn () => $place->save()); // one settings audit row instead
            $settings->audit($place, self::SECTION, $changes, $request->user());
        }

        return $this->ok($this->payload($request, $place->fresh()), $changes ? 'Profile saved.' : 'Nothing changed.');
    }

    /** POST /settings/profile/logo - png, jpg or webp up to 2 MB; stored re-encoded as webp. */
    public function uploadLogo(Request $request, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']], [
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);

        $image = Images::fromUpload($request->file('logo')->getRealPath());
        if (! $image) {
            throw ValidationException::withMessages(['logo' => "That file couldn't be read as an image."]);
        }
        $bytes = Images::webp(Images::fitWithin($image, 512));

        $path = "logos/{$place->id}.webp";
        Storage::disk('local')->put($path, $bytes);
        $had = (bool) $place->logo_path;
        $place->logo_path = $path;
        $place->updated_by = $request->user()->id;
        Territory::withoutAuditing(fn () => $place->save());
        $settings->audit($place, self::SECTION, ['logo' => ['old' => $had ? 'a logo' : null, 'new' => 'a new logo']], $request->user());

        return $this->ok($this->payload($request, $place->fresh()), 'Logo saved.');
    }

    /** DELETE /settings/profile/logo */
    public function removeLogo(Request $request, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'update')) {
            return $deny;
        }
        if ($place->logo_path) {
            Storage::disk('local')->delete($place->logo_path);
            $place->logo_path = null;
            $place->updated_by = $request->user()->id;
            Territory::withoutAuditing(fn () => $place->save());
            $settings->audit($place, self::SECTION, ['logo' => ['old' => 'a logo', 'new' => null]], $request->user());
        }

        return $this->ok($this->payload($request, $place->fresh()), 'Logo removed.');
    }

    /** GET /settings/logo/{territory} - public: a place's logo is shown on its pages and documents. */
    public function logo(Territory $territory): Response
    {
        if (! $territory->logo_path || ! Storage::disk('local')->exists($territory->logo_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($territory->logo_path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function payload(Request $request, Territory $place): array
    {
        return [
            'profile' => PlaceProfile::present($place),
            'completeness' => PlaceProfile::completeness($place),
            'can' => ['update' => SettingsAccess::can($request->user(), $place, self::SECTION, 'update')],
        ];
    }
}
