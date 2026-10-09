<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\DutyRota;
use App\Models\DutyTeamMember;
use App\Models\Equipment;
use App\Models\Territory;
use App\Services\Facilities\Facilities;
use App\Services\Settings\Settings;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings > Facilities (docs/specs/people-and-care-spec.md, P5 round 3): a
 * church's duties - and how many each service needs - and its kinds of
 * equipment. Kept in territories.metadata.facilities_setup (metadata.facilities
 * is the church's amenities), like Service times; who is on each duty's team
 * is the Teams page's (round 4, duty_team_members). Until a church saves, the
 * defaults are DutyRota::DUTIES and
 * Equipment::CATEGORIES. Rooms keep their own routes (/rooms), and the
 * section's other fields are the generic form (/settings/sections/facilities).
 */
class FacilitiesSetupController extends SettingsController
{
    private const SECTION = 'facilities';

    public const COLOURS = ['primary', 'success', 'purple', 'pink', 'warning', 'danger', 'info', 'secondary'];

    public function __construct(private Facilities $facilities) {}

    /** GET /settings/facilities-setup */
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

    /**
     * PUT /settings/facilities-setup {duties: [{key?, label, icon, colour,
     * active, needed}], kinds: [{key?, label, icon, colour}]} - replaces both
     * lists. A duty already on the rota is switched off rather than dropped;
     * a kind still in use can't go. A duty that goes takes its team with it.
     */
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
            'duties' => ['present', 'array', 'max:20'],
            'duties.*.key' => ['nullable', 'string', 'max:40'],
            'duties.*.label' => ['required', 'string', 'max:40'],
            'duties.*.icon' => ['required', Rule::in(Facilities::ICONS)],
            'duties.*.colour' => ['required', Rule::in(self::COLOURS)],
            'duties.*.active' => ['boolean'],
            'duties.*.needed' => ['required', 'integer', 'between:1,20'],
            'kinds' => ['required', 'array', 'min:1', 'max:24'],
            'kinds.*.key' => ['nullable', 'string', 'max:40'],
            'kinds.*.label' => ['required', 'string', 'max:40'],
            'kinds.*.icon' => ['required', Rule::in(Facilities::ICONS)],
            'kinds.*.colour' => ['required', Rule::in(self::COLOURS)],
        ], [
            'duties.*.label.required' => 'Give each duty a name, e.g. Ushering.',
            'kinds.*.label.required' => 'Give each kind a name, e.g. Sound.',
            'kinds.required' => 'Keep at least one kind of equipment.',
            'kinds.min' => 'Keep at least one kind of equipment.',
        ]);

        $old = $this->facilities->setup($place);
        $duties = $this->keyed($request->input('duties'), 'duty');
        $kinds = $this->keyed($request->input('kinds'), 'kind');

        // A duty on the rota stays, switched off, so the rota still reads.
        $used = DutyRota::where('territory_id', $place->id)->distinct()->pluck('duty')->all();
        foreach ($old['duties'] as $o) {
            if (in_array($o['key'], $used, true) && ! collect($duties)->contains('key', $o['key'])) {
                $duties[] = ['key' => $o['key'], 'label' => $o['label'], 'icon' => $o['icon'], 'colour' => $o['colour'], 'active' => false, 'needed' => (int) ($o['needed'] ?? 1)];
            }
        }
        // A kind with things in it can't go.
        $inUse = Equipment::where('territory_id', $place->id)->selectRaw('category, count(*) n')->groupBy('category')->pluck('n', 'category');
        foreach ($old['kinds'] as $o) {
            if (($inUse[$o['key']] ?? 0) > 0 && ! collect($kinds)->contains('key', $o['key'])) {
                $n = $inUse[$o['key']];

                return response()->json(['success' => false, 'status' => 422, 'message' => "{$o['label']} still has {$n} ".($n === 1 ? 'item' : 'items').' - move them to another kind first.', 'errors' => ['kinds' => ["{$o['label']} is still in use."]]], 422);
            }
        }

        $new = ['duties' => array_values($duties), 'kinds' => array_values($kinds)];
        $metadata = $place->metadata ?? [];
        $metadata['facilities_setup'] = $new;
        $place->metadata = $metadata;
        $place->updated_by = $request->user()->id;
        Territory::withoutAuditing(fn () => $place->save());
        DutyTeamMember::where('territory_id', $place->id)->whereNotIn('duty', array_column($duties, 'key') ?: [''])->get()->each->delete();
        $this->facilities->forgetSetup($place->id);
        $settings->audit($place, self::SECTION, [
            'duties' => ['old' => collect($old['duties'])->pluck('label')->implode(', '), 'new' => collect($duties)->pluck('label')->implode(', ')],
            'kinds' => ['old' => collect($old['kinds'])->pluck('label')->implode(', '), 'new' => collect($kinds)->pluck('label')->implode(', ')],
        ], $request->user());

        return $this->ok($this->payload($request, $place->fresh()), 'Duties and kinds saved.');
    }

    /** Each row keeps its key; a new one gets one from its name (unique, e.g. "media", "media-2"). */
    private function keyed(array $rows, string $what): array
    {
        $taken = [];
        $out = [];
        foreach ($rows as $r) {
            $key = trim((string) ($r['key'] ?? ''));
            if ($key === '' || isset($taken[$key])) {
                $base = Str::limit(Str::slug($r['label']) ?: $what, 34, '');
                $key = $base;
                for ($i = 2; isset($taken[$key]); $i++) {
                    $key = "{$base}-{$i}";
                }
            }
            $taken[$key] = true;
            $row = ['key' => $key, 'label' => trim($r['label']), 'icon' => $r['icon'], 'colour' => $r['colour']];
            if ($what === 'duty') {
                $row += ['active' => (bool) ($r['active'] ?? true), 'needed' => (int) $r['needed']];
            }
            $out[] = $row;
        }

        return $out;
    }

    private function denyHere(Request $request, Territory $place, string $action = 'read'): ?JsonResponse
    {
        if (SettingsAccess::level($place) !== 'church') {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Facilities are set up by each church.'], 404);
        }

        return $this->deny($request, $place, self::SECTION, $action);
    }

    private function payload(Request $request, Territory $place): array
    {
        $this->facilities->forgetSetup($place->id);
        $setup = $this->facilities->setup($place);
        $inUse = Equipment::where('territory_id', $place->id)->selectRaw('category, count(*) n')->groupBy('category')->pluck('n', 'category');
        $onRota = DutyRota::where('territory_id', $place->id)->selectRaw('duty, count(*) n')->groupBy('duty')->pluck('n', 'duty');
        $teams = $this->facilities->teams($place);

        return [
            'duties' => collect($setup['duties'])->map(fn ($d) => $d + ['on_rota' => (int) ($onRota[$d['key']] ?? 0), 'team_count' => ($teams[$d['key']] ?? collect())->count()])->values()->all(),
            'kinds' => collect($setup['kinds'])->map(fn ($k) => $k + ['items' => (int) ($inUse[$k['key']] ?? 0)])->values()->all(),
            'custom' => $setup['custom'],
            'icons' => Facilities::ICONS,
            'colours' => self::COLOURS,
            'can' => ['update' => SettingsAccess::can($request->user(), $place, self::SECTION, 'update')],
        ];
    }
}
