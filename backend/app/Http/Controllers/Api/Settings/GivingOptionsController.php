<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\GivingPurpose;
use App\Models\Territory;
use App\Services\Accounting\Funds;
use App\Services\Accounting\GivingPurposes;
use App\Services\Accounting\Paybill;
use App\Services\Settings\Settings;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings › Giving options & funds (docs/specs/accounting-spec.md, A11): what
 * this place's givers can give for, and its own funds - for itself or for every
 * place below it. The diocese also keeps the standard options every place
 * collects for itself. Options and funds from above show read-only, each with
 * "show on our giving page".
 */
class GivingOptionsController extends SettingsController
{
    private const SECTION = 'givingoptions';

    public function __construct(private GivingPurposes $purposes, private Funds $funds) {}

    /** GET /settings/giving-options */
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
     * PUT /settings/giving-options {purposes: [...], funds: [...], hidden: [ids],
     * standard?: [...], default?: key} - the place's own lists, replaced in one go.
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
        $data = $request->validate([
            'purposes' => ['present', 'array', 'max:'.GivingPurposes::LIMIT],
            'purposes.*.id' => ['nullable', 'integer'],
            'purposes.*.label' => ['required', 'string', 'max:40'],
            'purposes.*.suffix' => ['required', 'string', 'max:6'],
            'purposes.*.words' => ['nullable', 'array', 'max:8'],
            'purposes.*.words.*' => ['string', 'max:12'],
            'purposes.*.reach' => ['nullable', 'in:self,below'],
            'purposes.*.account_id' => ['required', 'integer'],
            'purposes.*.fund_id' => ['nullable', 'integer'],
            'purposes.*.fund_code' => ['nullable', 'string', 'max:10'],
            'purposes.*.icon' => ['nullable', 'string', 'max:40'],
            'purposes.*.colour' => ['nullable', 'string', 'max:20'],
            'purposes.*.is_active' => ['nullable', 'boolean'],
            'funds' => ['present', 'array', 'max:'.Funds::LIMIT],
            'funds.*.id' => ['nullable', 'integer'],
            'funds.*.code' => ['required', 'string', 'max:10'],
            'funds.*.name' => ['required', 'string', 'max:100'],
            'funds.*.is_restricted' => ['nullable', 'boolean'],
            'funds.*.reach' => ['nullable', 'in:self,below'],
            'funds.*.is_active' => ['nullable', 'boolean'],
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['integer'],
            'standard' => ['nullable', 'array', 'max:'.GivingPurposes::LIMIT],
            'default' => ['nullable', 'string', 'max:8'],
        ], ['purposes.*.label.required' => 'Give each option a name.', 'purposes.*.suffix.required' => 'Give each option a Pay Bill ending.',
            'purposes.*.account_id.required' => 'Pick the income account each option is booked to.', 'funds.*.name.required' => 'Give each fund a name.', 'funds.*.code.required' => 'Give each fund a short code.']);
        $isDiocese = SettingsAccess::level($place) === 'diocese';
        $before = $this->summary($place);
        DB::transaction(function () use ($data, $place, $request, $isDiocese) {
            $this->funds->save($place, $data['funds'], $request->user());
            // A new fund can be picked by its code in the same save.
            $byCode = AccountingFund::where('territory_id', $place->id)->pluck('id', 'code');
            $rows = array_map(fn ($r) => empty($r['fund_id']) && ! empty($r['fund_code']) ? array_merge($r, ['fund_id' => $byCode[strtoupper($r['fund_code'])] ?? null]) : $r, $data['purposes']);
            $this->purposes->save($place, $rows, $request->user());
            if ($isDiocese && isset($data['standard'])) {
                $this->purposes->save(null, $data['standard'], $request->user(), $data['default'] ?? $this->purposes->default()->key);
            }
            if (! $isDiocese) {
                $this->purposes->hide($place, $data['hidden'] ?? []);
            }
        });
        $this->purposes->forget();
        $after = $this->summary($place->fresh());
        $settings->audit($place, self::SECTION, collect($after)->filter(fn ($v, $k) => $before[$k] !== $v)->map(fn ($v, $k) => ['old' => $before[$k], 'new' => $v])->all(), $request->user());

        return $this->ok($this->payload($request, $place->fresh()), 'Giving options and funds saved.');
    }

    private function denyHere(Request $request, Territory $place, string $action = 'read'): ?JsonResponse
    {
        if (! in_array(SettingsAccess::level($place), ['church', 'region', 'diocese'], true)) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Giving options are set up by a church, a region or the diocese.'], 404);
        }

        return $this->deny($request, $place, self::SECTION, $action);
    }

    /** One line per list, for the settings history. */
    private function summary(Territory $place): array
    {
        $this->purposes->forget();
        $all = $this->purposes->all();

        return [
            'options' => $all->where('territory_id', $place->id)->map(fn ($p) => $p->label.($p->is_active ? '' : ' (off)'))->implode(', '),
            'funds' => AccountingFund::where('territory_id', $place->id)->orderBy('display_order')->get()->map(fn ($f) => $f->name.($f->is_active ? '' : ' (off)'))->implode(', '),
            'hidden' => $all->whereIn('id', $this->purposes->hidden($place))->pluck('label')->implode(', '),
        ] + (SettingsAccess::level($place) === 'diocese' ? ['standard' => $all->whereNull('territory_id')->map(fn ($p) => $p->label.($p->is_active ? '' : ' (off)').($p->is_default ? ' (default)' : ''))->implode(', ')] : []);
    }

    private function payload(Request $request, Territory $place): array
    {
        $this->purposes->forget();
        $all = $this->purposes->all();
        $level = SettingsAccess::level($place);
        $code = Paybill::code($place);
        $hidden = $this->purposes->hidden($place);
        $row = fn (GivingPurpose $p, bool $mine) => [
            'id' => $p->id, 'key' => $p->key, 'label' => $p->label, 'suffix' => $p->suffix, 'words' => array_values((array) $p->words), 'reach' => $p->reach,
            'account_id' => $p->account_id, 'fund_id' => $p->fund_id, 'icon' => $p->icon, 'colour' => $p->colour, 'is_active' => (bool) $p->is_active, 'is_default' => (bool) $p->is_default,
            'owner' => $p->owner ? ['name' => $p->owner->name, 'level' => $p->owner->territory_type->value] : null,
            'example' => $code.$p->suffix,
        ] + ($mine ? ['in_use' => $this->purposes->inUse($p)] : ['shown' => ! in_array((int) $p->id, $hidden, true)]);
        $above = collect(\App\Support\PlaceAccess::ancestors($place))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inherited = $all->filter(fn ($p) => $p->is_active && $p->territory_id !== null && in_array((int) $p->territory_id, $above, true) && $p->reach === 'below');
        $funds = $this->funds->forPlace($place, false);

        return [
            'place' => ['id' => $place->id, 'name' => $place->name, 'level' => $level, 'code' => $code],
            'can' => ['update' => SettingsAccess::can($request->user(), $place, self::SECTION, 'update')],
            'own' => $all->where('territory_id', $place->id)->map(fn ($p) => $row($p, true))->values(),
            'standard' => $level === 'diocese' ? $all->whereNull('territory_id')->map(fn ($p) => $row($p, true))->values() : null,
            'inherited' => $level === 'diocese' ? [] : $all->filter(fn ($p) => $p->is_active && $p->territory_id === null)->concat($inherited)->map(fn ($p) => $row($p, false))->values(),
            'funds' => [
                'own' => $this->funds->present($funds->where('territory_id', $place->id), true),
                'inherited' => $this->funds->present($funds->filter(fn ($f) => (int) $f->territory_id !== (int) $place->id && $f->is_active)),
            ],
            'own_counts' => AccountingFund::where('territory_id', $place->id)->get()->mapWithKeys(fn ($f) => [$f->id => $this->funds->inUse($f)]),
            'accounts' => AccountingAccount::whereNull('territory_id')->where('type', 'income')->where('is_header', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'fund_choices' => $this->funds->present($this->funds->forPlace($place)),
            'icons' => GivingPurposes::ICONS,
            'colours' => GivingPurposes::COLOURS,
            'limits' => ['options' => GivingPurposes::LIMIT, 'funds' => Funds::LIMIT],
        ];
    }
}
