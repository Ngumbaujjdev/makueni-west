<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\GatheringType;
use App\Models\Territory;
use App\Services\Settings\Settings;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings > Service times (docs/specs/settings-spec.md). Kept in
 * territories.metadata.service_times, where Church::getServiceTimes()
 * already reads them: [{name, day 0-6, start HH:MM, end, gathering_type_id, language}].
 */
class ServiceTimesController extends SettingsController
{
    private const SECTION = 'servicetimes';

    public const MAX = 20;

    /**
     * The saved list, also reading the shape the seeders wrote before the
     * hub existed: {"sunday_morning": "09:00", "wednesday_prayer": "18:00"}
     * becomes [{name: "Sunday morning", day: 0, start: "09:00"}, ...].
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }
        if (array_is_list($raw)) {
            return array_values(array_filter($raw, fn ($t) => is_array($t) && isset($t['day'], $t['start'])));
        }
        $days = array_map('strtolower', HubController::WEEKDAYS);
        $times = [];
        foreach ($raw as $key => $start) {
            [$day, $rest] = array_pad(explode('_', strtolower((string) $key), 2), 2, '');
            $index = array_search($day, $days, true);
            if ($index === false || ! is_string($start) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $start, $m)) {
                continue;
            }
            $times[] = [
                'name' => ucfirst(trim($day.' '.str_replace('_', ' ', $rest))),
                'day' => $index,
                'start' => sprintf('%02d:%s', $m[1], $m[2]),
                'end' => null,
                'gathering_type_id' => null,
                'language' => null,
            ];
        }
        usort($times, fn ($a, $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        return $times;
    }

    /** GET /settings/service-times */
    public function show(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->denyHere($request, $place)) {
            return $deny;
        }

        return $this->ok($this->payload($request, $place));
    }

    /** PUT /settings/service-times - {times: [...]}, replaces the list. */
    public function update(Request $request, Settings $settings): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->denyHere($request, $place, 'update')) {
            return $deny;
        }

        $request->validate([
            'times' => ['present', 'array', 'max:'.self::MAX],
            'times.*.name' => ['required', 'string', 'max:100'],
            'times.*.day' => ['required', 'integer', 'between:0,6'],
            'times.*.start' => ['required', 'date_format:H:i'],
            'times.*.end' => ['nullable', 'date_format:H:i'],
            'times.*.gathering_type_id' => ['nullable', 'integer', Rule::exists('gathering_types', 'id')->where('territory_id', $place->id)],
            'times.*.language' => ['nullable', 'string', 'max:50'],
        ], [
            'times.max' => 'At most '.self::MAX.' service times.',
            'times.*.name.required' => 'Give each service a name, e.g. Main service.',
            'times.*.start.date_format' => 'Use a time like 09:30.',
            'times.*.end.date_format' => 'Use a time like 11:30.',
        ]);

        $times = collect($request->input('times'))->map(fn ($t) => [
            'name' => trim($t['name']),
            'day' => (int) $t['day'],
            'start' => $t['start'],
            'end' => ($t['end'] ?? null) ?: null,
            'gathering_type_id' => isset($t['gathering_type_id']) && $t['gathering_type_id'] !== '' ? (int) $t['gathering_type_id'] : null,
            'language' => isset($t['language']) && trim((string) $t['language']) !== '' ? trim($t['language']) : null,
        ]);
        $bad = $times->search(fn ($t) => $t['end'] !== null && $t['end'] <= $t['start']);
        if ($bad !== false) {
            return response()->json([
                'success' => false, 'status' => 422, 'message' => 'A service has to end after it starts.',
                'errors' => ["times.{$bad}.end" => ['A service has to end after it starts.']],
            ], 422);
        }
        $times = $times->sortBy(fn ($t) => sprintf('%d-%s', $t['day'], $t['start']))->values()->all();

        $metadata = $place->metadata ?? [];
        $old = self::normalize($metadata['service_times'] ?? []);
        if ($old !== $times) {
            $metadata['service_times'] = $times;
            $place->metadata = $metadata;
            $place->updated_by = $request->user()->id;
            Territory::withoutAuditing(fn () => $place->save());
            $settings->audit($place, self::SECTION, ['service_times' => ['old' => count($old).' times', 'new' => count($times).' times']], $request->user());
        }

        return $this->ok($this->payload($request, $place->fresh()), $old !== $times ? 'Service times saved.' : 'Nothing changed.');
    }

    private function denyHere(Request $request, Territory $place, string $action = 'read'): ?JsonResponse
    {
        if (! in_array(SettingsAccess::level($place), config('settings.sections.servicetimes.levels', []), true)) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'No service times here.'], 404);
        }

        return $this->deny($request, $place, self::SECTION, $action);
    }

    private function payload(Request $request, Territory $place): array
    {
        return [
            'times' => self::normalize($place->metadata['service_times'] ?? []),
            'gathering_types' => GatheringType::active()->forTerritory($place->id)->orderBy('display_order')->orderBy('name')->get(['id', 'name'])->all(),
            'max' => self::MAX,
            'can' => ['update' => SettingsAccess::can($request->user(), $place, self::SECTION, 'update')],
        ];
    }
}
