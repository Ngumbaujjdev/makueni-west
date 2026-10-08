<?php

namespace App\Support\Settings;

use App\Models\PlacePhoto;
use App\Models\Territory;
use App\Support\Kenya;
use App\Support\YouTube;
use Illuminate\Support\Facades\Storage;

/**
 * A place's profile as Settings > Profile, the Overview checklist and the
 * rail's attention dot see it (docs/specs/settings-spec.md).
 */
final class PlaceProfile
{
    /** What "complete" counts: key => label. */
    public const CHECKS = [
        'phone' => 'Phone number',
        'email' => 'Email address',
        'address' => 'Physical address',
        'county' => 'County',
        'location' => 'Map pin',
        'logo' => 'Logo or photo',
        'photos' => 'At least 3 photos',
    ];

    /** @return array{percent: int, done: int, total: int, missing: array<string, string>} */
    public static function completeness(Territory $place): array
    {
        $done = [
            'phone' => filled($place->phone),
            'email' => filled($place->email),
            'address' => filled($place->address),
            'county' => filled($place->county),
            'location' => $place->latitude !== null && $place->longitude !== null,
            'logo' => filled($place->logo_path),
            'photos' => PlacePhoto::where('territory_id', $place->id)->count() >= 3,
        ];
        $count = count(array_filter($done));

        return [
            'percent' => (int) round($count / count(self::CHECKS) * 100),
            'done' => $count,
            'total' => count(self::CHECKS),
            'missing' => array_intersect_key(self::CHECKS, array_filter($done, fn ($d) => ! $d)),
        ];
    }

    public static function logoUrl(Territory $place): ?string
    {
        if (! $place->logo_path || ! Storage::disk('local')->exists($place->logo_path)) {
            return null;
        }

        return url("/api/settings/logo/{$place->id}").'?v='.Storage::disk('local')->lastModified($place->logo_path);
    }

    public static function present(Territory $place): array
    {
        $parent = $place->parent_territory_id ? Territory::find($place->parent_territory_id) : null;

        return [
            'id' => $place->id,
            'name' => $place->name,
            'code' => $place->code,
            'type' => $place->territory_type?->value,
            'description' => $place->description,
            'established_date' => $place->established_date?->toDateString(),
            'phone' => $place->phone,
            'email' => $place->email,
            'website' => $place->website,
            'address' => $place->address,
            'town' => $place->town,
            'sub_county' => $place->sub_county,
            'county' => $place->county,
            'county_listed' => $place->county === null || in_array($place->county, Kenya::COUNTIES, true),
            'postal_code' => $place->postal_code,
            'latitude' => $place->latitude !== null ? (float) $place->latitude : null,
            'longitude' => $place->longitude !== null ? (float) $place->longitude : null,
            'logo_url' => self::logoUrl($place),
            'youtube_url' => $place->youtube_url,
            'youtube_video' => YouTube::videoId($place->youtube_url),
            'photo_count' => PlacePhoto::where('territory_id', $place->id)->count(),
            'parent' => $parent ? ['name' => $parent->name, 'type' => $parent->territory_type?->value] : null,
        ];
    }
}
