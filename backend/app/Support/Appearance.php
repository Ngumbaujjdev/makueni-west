<?php

namespace App\Support;

/**
 * Single source of truth for Appearance settings: which keys exist,
 * their allowed values/defaults, and the CSS class each value maps to.
 * Ported from the pattern in the sibling project v1-events-backend
 * (App\Support\Appearance there) - see
 * docs/specs/appearance-settings-spec.md for the full contract and for
 * why this ships without a `theme` (light/dark/system) key.
 *
 * Not tied to Eloquent/a User instance on purpose - every method here
 * is a pure function over plain arrays, so AppearanceController and the
 * frontend's live-preview JS can both reason about "given these raw
 * values, what's the effective/resolved state" without needing a
 * database round-trip to test it.
 */
class Appearance
{
    public const OPTIONS = [
        'density' => [
            'choices' => ['compact', 'comfortable', 'spacious'],
            'default' => 'comfortable',
            'classes' => [
                'compact' => 'app-density-compact',
                'comfortable' => null,
                'spacious' => 'app-density-spacious',
            ],
        ],
        'text_size' => [
            'choices' => ['small', 'medium', 'large'],
            'default' => 'medium',
            'classes' => [
                'small' => 'app-text-sm',
                'medium' => null,
                'large' => 'app-text-lg',
            ],
        ],
        'reduce_motion' => ['boolean' => true, 'default' => false, 'class' => 'app-reduce-motion'],
        'high_contrast' => ['boolean' => true, 'default' => false, 'class' => 'app-high-contrast'],
        'focus_outlines' => ['boolean' => true, 'default' => false, 'class' => 'app-focus-outlines'],
        'underline_links' => ['boolean' => true, 'default' => false, 'class' => 'app-underline-links'],
        'big_targets' => ['boolean' => true, 'default' => false, 'class' => 'app-big-targets'],
    ];

    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::OPTIONS as $key => $option) {
            $defaults[$key] = $option['default'];
        }

        return $defaults;
    }

    /**
     * Defaults overlaid with any saved rows. $savedRows is key => raw
     * string value, the shape UserPreference::pluck('value', 'key')
     * returns directly.
     */
    public static function effectiveFor(array $savedRows): array
    {
        $effective = self::defaults();

        foreach ($savedRows as $key => $rawValue) {
            if (! array_key_exists($key, self::OPTIONS)) {
                continue;
            }

            $option = self::OPTIONS[$key];
            $effective[$key] = ! empty($option['boolean'])
                ? filter_var($rawValue, FILTER_VALIDATE_BOOLEAN)
                : $rawValue;
        }

        return $effective;
    }

    /**
     * The resolved CSS class list for a set of effective settings -
     * printed server-side on <html class="..."> on every page load, so
     * there's no flash of unstyled content.
     */
    public static function classesFor(array $effective): array
    {
        $classes = [];

        foreach (self::OPTIONS as $key => $option) {
            $value = $effective[$key] ?? $option['default'];

            if (! empty($option['boolean'])) {
                if ($value) {
                    $classes[] = $option['class'];
                }

                continue;
            }

            $class = $option['classes'][$value] ?? null;
            if ($class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * A value equal to its key's default doesn't get a stored row - see
     * the spec's "sparse rows" data-model decision.
     */
    public static function isDefault(string $key, $value): bool
    {
        if (! array_key_exists($key, self::OPTIONS)) {
            return false;
        }

        $option = self::OPTIONS[$key];

        if (! empty($option['boolean'])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) === $option['default'];
        }

        return $value === $option['default'];
    }

    /** Laravel validation rules for a PUT payload - every key optional, unknown keys simply ignored by the caller. */
    public static function validationRules(): array
    {
        $rules = [];

        foreach (self::OPTIONS as $key => $option) {
            $rules[$key] = ! empty($option['boolean'])
                ? 'sometimes|boolean'
                : 'sometimes|in:'.implode(',', $option['choices']);
        }

        return $rules;
    }
}
