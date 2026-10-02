<?php

namespace App\Support\Settings;

/**
 * Every section and field of the Settings hub, read from config/settings.php
 * (docs/specs/settings-spec.md). The rail, the forms, validation and the
 * permission names all come from here, so adding a setting is one entry.
 */
final class SettingsRegistry
{
    public const LEVELS = ['church', 'region', 'diocese'];

    /** @return array<string, string> group key => label, in rail order */
    public static function groups(): array
    {
        return config('settings.groups', []);
    }

    /** @return array<string, array> sections shown at a level (all when null), in rail order */
    public static function sections(?string $level = null): array
    {
        $sections = [];
        foreach (config('settings.sections', []) as $key => $section) {
            if ($level === null || in_array($level, $section['levels'] ?? [], true)) {
                $sections[$key] = ['key' => $key] + $section;
            }
        }

        return $sections;
    }

    public static function section(string $key): ?array
    {
        $section = config("settings.sections.{$key}");

        return $section ? ['key' => $key] + $section : null;
    }

    /** @return array<string, array> fields, optionally of one section */
    public static function fields(?string $section = null): array
    {
        $fields = [];
        foreach (config('settings.fields', []) as $key => $field) {
            if ($section === null || ($field['section'] ?? null) === $section) {
                $fields[$key] = ['key' => $key] + $field + [
                    'type' => 'text', 'rules' => [], 'default' => null, 'levels' => self::LEVELS,
                    'lockable' => false, 'secret' => false, 'help' => null, 'used_by' => null, 'span' => 6,
                    'options' => null, 'card' => 'General',
                ];
            }
        }

        return $fields;
    }

    public static function field(string $key): ?array
    {
        return self::fields()[$key] ?? null;
    }

    /** The permission behind a section action: "{level}.settings.hub.{section}.{action}". */
    public static function permission(string $level, string $section, string $action): string
    {
        return "{$level}.settings.hub.{$section}.{$action}";
    }
}
