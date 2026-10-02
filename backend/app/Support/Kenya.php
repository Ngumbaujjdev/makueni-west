<?php

namespace App\Support;

/** Kenya reference lists for profile forms (docs/specs/settings-spec.md). */
final class Kenya
{
    /** The 47 counties, by their official code. */
    public const COUNTIES = [
        1 => 'Mombasa', 2 => 'Kwale', 3 => 'Kilifi', 4 => 'Tana River', 5 => 'Lamu',
        6 => 'Taita-Taveta', 7 => 'Garissa', 8 => 'Wajir', 9 => 'Mandera', 10 => 'Marsabit',
        11 => 'Isiolo', 12 => 'Meru', 13 => 'Tharaka-Nithi', 14 => 'Embu', 15 => 'Kitui',
        16 => 'Machakos', 17 => 'Makueni', 18 => 'Nyandarua', 19 => 'Nyeri', 20 => 'Kirinyaga',
        21 => "Murang'a", 22 => 'Kiambu', 23 => 'Turkana', 24 => 'West Pokot', 25 => 'Samburu',
        26 => 'Trans Nzoia', 27 => 'Uasin Gishu', 28 => 'Elgeyo-Marakwet', 29 => 'Nandi', 30 => 'Baringo',
        31 => 'Laikipia', 32 => 'Nakuru', 33 => 'Narok', 34 => 'Kajiado', 35 => 'Kericho',
        36 => 'Bomet', 37 => 'Kakamega', 38 => 'Vihiga', 39 => 'Bungoma', 40 => 'Busia',
        41 => 'Siaya', 42 => 'Kisumu', 43 => 'Homa Bay', 44 => 'Migori', 45 => 'Kisii',
        46 => 'Nyamira', 47 => 'Nairobi',
    ];

    /** The listed county matching free text ("makueni county" → "Makueni"), or null. */
    public static function county(?string $text): ?string
    {
        $clean = strtolower(trim(preg_replace('/\s+county$/i', '', trim((string) $text))));
        foreach (self::COUNTIES as $name) {
            if (strtolower($name) === $clean || str_replace('-', ' ', strtolower($name)) === str_replace('-', ' ', $clean)) {
                return $name;
            }
        }

        return null;
    }
}
