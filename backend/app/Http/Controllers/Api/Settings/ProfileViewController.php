<?php

namespace App\Http\Controllers\Api\Settings;

use App\Actions\Users\AddPersonToPlace;
use App\Models\GatheringType;
use App\Models\Ministry;
use App\Models\Person;
use App\Models\PlacePhoto;
use App\Models\Room;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Support\Settings\PlaceProfile;
use App\Support\Settings\SettingsRegistry;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings > View profile (docs/specs/settings-spec.md): everything a place
 * set up - who it is, how to reach it, the map pin, photos, services online,
 * when it meets and who leads it - in one read-only page. Leaders show by name
 * and role only; the church's counts are totals, never names.
 */
class ProfileViewController extends SettingsController
{
    private const SECTION = 'view';

    /** GET /settings/view */
    public function show(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION)) {
            return $deny;
        }
        $level = SettingsAccess::level($place);
        $user = $request->user();
        $hasTimes = in_array($level, SettingsRegistry::section('servicetimes')['levels'] ?? [], true);

        return $this->ok([
            'profile' => PlaceProfile::present($place),
            'photos' => PlacePhoto::where('territory_id', $place->id)->orderBy('position')->orderBy('id')->get()->map->present()->values()->all(),
            'service_times' => $hasTimes ? $this->times($place) : null,
            'leaders' => $this->leaders($place),
            'glance' => $level === 'church' ? $this->glance($place) : null,
            'can' => [
                'profile' => SettingsAccess::can($user, $place, 'profile', 'update'),
                'servicetimes' => $hasTimes && SettingsAccess::can($user, $place, 'servicetimes', 'update'),
                'team' => SettingsAccess::can($user, $place, 'team', 'read'),
            ],
        ]);
    }

    /** The week's services, by day and time, with the gathering's name. */
    private function times(Territory $place): array
    {
        $times = ServiceTimesController::normalize(($place->metadata ?? [])['service_times'] ?? null);
        $types = GatheringType::whereIn('id', array_filter(array_column($times, 'gathering_type_id')) ?: [0])->pluck('name', 'id');
        usort($times, fn ($a, $b) => [(int) $a['day'], $a['start']] <=> [(int) $b['day'], $b['start']]);

        return array_map(fn ($t) => [
            'name' => $t['name'] ?? 'Service', 'day' => (int) $t['day'], 'start' => $t['start'], 'end' => $t['end'] ?? null,
            'language' => $t['language'] ?? null, 'gathering' => isset($t['gathering_type_id']) ? ($types[$t['gathering_type_id']] ?? null) : null,
        ], $times);
    }

    /** Who leads here - name and role only, in the team's order. */
    private function leaders(Territory $place): array
    {
        $order = SettingsAccess::TEAM_ROLES[SettingsAccess::level($place)] ?? [];

        return UserTerritoryAssignment::with(['user', 'role'])->where('territory_id', $place->id)->where('is_active', true)->get()
            ->filter(fn ($a) => $a->user)
            ->sortBy(fn ($a) => sprintf('%03d-%s', ($i = array_search($a->role?->name, $order, true)) === false ? 999 : $i, $a->user->firstname))
            ->map(fn ($a) => ['name' => trim("{$a->user->firstname} {$a->user->lastname}"), 'initials' => AddPersonToPlace::initials($a->user), 'role' => $a->role?->name])
            ->values()->all();
    }

    /** The church at a glance: totals only. */
    private function glance(Territory $place): array
    {
        return [
            'members' => Person::where('territory_id', $place->id)->where('status', 'member')->whereNull('archived_at')->whereNull('anonymised_at')->count(),
            'ministries' => Ministry::where('territory_id', $place->id)->where('active', true)->count(),
            'rooms' => Room::where('territory_id', $place->id)->where('active', true)->count(),
            'services' => count(ServiceTimesController::normalize(($place->metadata ?? [])['service_times'] ?? null)),
        ];
    }
}
