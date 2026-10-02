<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Territory;
use App\Models\User;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;

/**
 * Settings > Audit log (docs/specs/settings-spec.md, S4b) - global admins
 * only: every settings change at every church, region and the diocese,
 * newest first. Each save is one audit row (Settings::audit()), with
 * secrets already masked.
 */
class AuditController extends SettingsController
{
    /** How many changes the list holds - the page filters and pages them in place. */
    public const LIMIT = 500;

    /** GET /settings/audit?section=&territory=&user=&from=&to= */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, 'audit')) {
            return $deny;
        }
        $filters = $request->validate([
            'section' => ['nullable', 'string', 'max:40'],
            'territory' => ['nullable', 'integer'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $audits = Audit::query()
            ->where('event', 'like', 'settings.%')
            ->when($filters['section'] ?? null, fn ($q, $s) => $q->where('tags', "settings,{$s}"))
            ->when($filters['territory'] ?? null, fn ($q, $t) => $q->where('auditable_type', 'territory')->where('auditable_id', $t))
            ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $people = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get(['id', 'firstname', 'lastname'])->keyBy('id');
        $places = Territory::whereIn('id', $audits->where('auditable_type', 'territory')->pluck('auditable_id')->unique())->get(['id', 'name', 'territory_type'])->keyBy('id');
        $sections = SettingsRegistry::sections();

        $rows = $audits->map(function (Audit $a) use ($people, $places, $sections) {
            $key = explode(',', (string) $a->tags)[1] ?? null;
            $section = $sections[$key] ?? null;
            $where = $a->auditable_type === 'territory' ? $places->get($a->auditable_id) : null;
            $who = $people->get($a->user_id);
            $old = (array) $a->old_values;
            $new = (array) $a->new_values;

            return [
                'id' => $a->id,
                'at' => $a->created_at?->toIso8601String(),
                'event' => Str::after($a->event, 'settings.'),
                'by' => $who ? ['id' => $who->id, 'name' => trim("{$who->firstname} {$who->lastname}")] : null,
                'place' => $where ? ['id' => $where->id, 'name' => $where->name, 'type' => $where->territory_type->value] : ['id' => null, 'name' => 'System', 'type' => 'system'],
                'section' => ['key' => $key, 'label' => $section['label'] ?? Str::headline((string) $key), 'icon' => $section['icon'] ?? 'ri-settings-3-line', 'colour' => $section['colour'] ?? 'primary'],
                'changes' => collect(array_unique([...array_keys($old), ...array_keys($new)]))->map(fn ($k) => [
                    'label' => SettingsRegistry::field($k)['label'] ?? (str_contains($k, ' ') ? $k : Str::headline($k)),
                    'old' => self::show($old[$k] ?? null),
                    'new' => self::show($new[$k] ?? null),
                ])->values()->all(),
            ];
        })->values();

        return $this->ok([
            'rows' => $rows,
            'limit' => self::LIMIT,
            'filters' => [
                'sections' => collect($sections)->filter(fn ($s) => ($s['kind'] ?? null) !== 'link')
                    ->map(fn ($s) => ['value' => $s['key'], 'label' => $s['label']])->values(),
                'people' => $people->map(fn ($u) => ['value' => $u->id, 'label' => trim("{$u->firstname} {$u->lastname}")])->sortBy('label')->values(),
                'places' => $places->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->sortBy('label')->values(),
            ],
        ]);
    }

    /** A stored value as one line of text (null stays null - the page says "empty"). */
    private static function show(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? 'On' : 'Off',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => mb_strimwidth((string) $value, 0, 200, '…'),
        };
    }
}
