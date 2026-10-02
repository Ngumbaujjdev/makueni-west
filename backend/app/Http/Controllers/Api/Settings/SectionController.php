<?php

namespace App\Http\Controllers\Api\Settings;

use App\Services\Settings\Settings;
use App\Support\Settings\SettingsRegistry;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A generic "form" section of the Settings hub, built from the registry's
 * fields (docs/specs/settings-spec.md): each value with where it comes
 * from, whether it's locked from above, and secrets only as set / not set.
 */
class SectionController extends SettingsController
{
    /** GET /settings/sections/{section} */
    public function show(Request $request, string $section, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $level = SettingsAccess::level($place);
        if (! isset(SettingsRegistry::sections($level)[$section])) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'No such part of Settings here.'], 404);
        }
        if ($deny = $this->deny($request, $place, $section)) {
            return $deny;
        }

        return $this->ok($this->payload($request, $section, $settings, $place));
    }

    /** PUT /settings/sections/{section} - {values: {key: value}, locks: {key: bool}, reset: [key]} */
    public function update(Request $request, string $section, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $level = SettingsAccess::level($place);
        if (! isset(SettingsRegistry::sections($level)[$section])) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'No such part of Settings here.'], 404);
        }
        if ($deny = $this->deny($request, $place, $section, 'update')) {
            return $deny;
        }
        $request->validate([
            'values' => ['sometimes', 'array'],
            'locks' => ['sometimes', 'array'],
            'locks.*' => ['boolean'],
            'reset' => ['sometimes', 'array'],
            'reset.*' => ['string'],
        ]);

        $changes = $settings->setMany(
            $place, $level, $section,
            (array) $request->input('values', []),
            (array) $request->input('locks', []),
            (array) $request->input('reset', []),
            $request->user(),
        );

        return $this->ok(
            $this->payload($request, $section, $settings, $place),
            $changes ? 'Saved.' : 'Nothing changed.',
        );
    }

    private function payload(Request $request, string $section, Settings $settings, $place): array
    {
        return [
            'section' => SettingsRegistry::section($section),
            'cards' => $settings->present($section, $place, SettingsAccess::level($place)),
            'can' => ['update' => SettingsAccess::can($request->user(), $place, $section, 'update')],
        ];
    }
}
