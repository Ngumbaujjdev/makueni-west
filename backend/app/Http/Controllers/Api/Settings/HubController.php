<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\GatheringType;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Services\DemographicsGrowthService;
use App\Services\Settings\Settings;
use App\Support\Kenya;
use App\Support\Settings\PlaceProfile;
use App\Support\Settings\SettingsRegistry;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Settings hub's rail, Overview and reference lists
 * (docs/specs/settings-spec.md).
 */
class HubController extends SettingsController
{
    public const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** GET /settings/sections - the rail: grouped sections this role may open. */
    public function sections(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, 'overview')) {
            return $deny;
        }
        $user = $request->user();
        $level = SettingsAccess::level($place);

        $byGroup = [];
        foreach (SettingsRegistry::sections($level) as $key => $section) {
            if (! SettingsAccess::can($user, $place, $key, 'read')) {
                continue;
            }
            $byGroup[$section['group'] ?? ''][] = [
                'key' => $key,
                'label' => $section['label'],
                'icon' => $section['icon'],
                'colour' => $section['colour'],
                'kind' => $section['kind'],
                'sentence' => $section['sentence'] ?? null,
                'url' => $section['url'][$level] ?? null,
                'can' => ['read' => true, 'update' => SettingsAccess::can($user, $place, $key, 'update')],
                'attention' => $this->attention($key, $place),
            ];
        }

        $groups = isset($byGroup['']) ? [['key' => null, 'label' => null, 'sections' => $byGroup['']]] : [];
        foreach (SettingsRegistry::groups() as $key => $label) {
            if (isset($byGroup[$key])) {
                $groups[] = ['key' => $key, 'label' => $label, 'sections' => $byGroup[$key]];
            }
        }

        return $this->ok([
            'place' => ['id' => $place->id, 'name' => $place->name, 'type' => $level, 'code' => $place->code, 'logo_url' => PlaceProfile::logoUrl($place)],
            'level' => $level,
            'groups' => $groups,
        ]);
    }

    /** GET /settings/overview - completeness, the setup checklist, and (above church) the churches below. */
    public function overview(Request $request, Settings $settings, DemographicsGrowthService $growth): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, 'overview')) {
            return $deny;
        }
        $level = SettingsAccess::level($place);
        $profile = PlaceProfile::completeness($place);
        $hasServiceTimes = isset(SettingsRegistry::sections($level)['servicetimes']);
        $times = count(ServiceTimesController::normalize($place->metadata['service_times'] ?? []));

        $checklist = [];
        foreach (PlaceProfile::CHECKS as $key => $label) {
            $checklist[] = ['key' => $key, 'label' => $label, 'done' => ! isset($profile['missing'][$key]), 'section' => 'profile'];
        }
        if ($hasServiceTimes) {
            $checklist[] = ['key' => 'service_times', 'label' => 'Service times', 'done' => $times > 0, 'section' => 'servicetimes'];
        }

        $below = null;
        if ($level !== 'church') {
            $churches = Territory::whereIn('id', $growth->descendantChurchIds($place))->orderBy('name')->get();
            $scored = $churches->map(fn (Territory $c) => ['id' => $c->id, 'name' => $c->name, 'percent' => PlaceProfile::completeness($c)['percent']]);
            $incomplete = $scored->filter(fn ($c) => $c['percent'] < 100)->sortBy('percent')->values();
            $below = [
                'total' => $scored->count(),
                'complete' => $scored->count() - $incomplete->count(),
                'average' => $scored->count() ? (int) round($scored->avg('percent')) : 0,
                'missing' => $incomplete->take(8)->all(),
            ];
        }

        return $this->ok([
            'profile' => $profile,
            'team' => [
                'people' => UserTerritoryAssignment::where('territory_id', $place->id)->where('is_active', true)->distinct()->count('user_id'),
            ],
            'service_times' => ['shown' => $hasServiceTimes, 'count' => $times],
            'last_change' => $settings->lastChange($place),
            'checklist' => $checklist,
            'below' => $below,
        ]);
    }

    /** GET /settings/reference - counties, weekdays and the place's gathering types. */
    public function reference(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, 'overview')) {
            return $deny;
        }

        return $this->ok([
            'counties' => array_values(Kenya::COUNTIES),
            'weekdays' => self::WEEKDAYS,
            'gathering_types' => GatheringType::active()->forTerritory($place->id)->orderBy('display_order')->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    /** Whether a section shows the rail's "needs attention" dot. */
    private function attention(string $section, Territory $place): bool
    {
        return match ($section) {
            'profile' => PlaceProfile::completeness($place)['percent'] < 100,
            'servicetimes' => ServiceTimesController::normalize($place->metadata['service_times'] ?? []) === [],
            default => false,
        };
    }
}
