<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetCategory;
use App\Models\BudgetDeduction;
use App\Models\BudgetLine;
use App\Models\BudgetLineItem;
use App\Services\Budgets\Deductions;
use App\Support\BudgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Budget Settings for one place - a church, a region or the diocese - in one
 * simple page (docs/specs/budgets-spec.md, phase 4).
 *
 * Lines: a place uses the shared lines meant for its level (set by the
 * diocese, locked for everyone else) and its own. The diocese also looks
 * after the shared lines and says who they're for. Every level is
 * independent: a place only ever changes its own lines.
 */
class BudgetSettingsController extends Controller
{
    /** Who a deduction applies to: applies_to_level => words. */
    private const APPLIES = ['own' => 'Our own budgets', 'church' => 'Every church below us', 'region' => 'Every region', 'all' => 'Our own and every place below'];

    /** Which "applies to" choices each level has. */
    private const APPLIES_FOR = ['church' => ['own'], 'region' => ['own', 'church'], 'diocese' => ['own', 'church', 'region', 'all']];

    public function __construct(private Deductions $deductions) {}

    /** Who a shared line is for: territory_scope => words. */
    private const SHARE = ['all' => 'Everyone', 'church' => 'Every church', 'region' => 'Every region', 'diocese' => 'The diocese'];

    /** GET /budget-settings - the place's lines (and, later, deductions). */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request, 'settings.read');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $isDiocese = $place['type'] === 'diocese';
        $lines = BudgetLine::with('budgetCategory')
            ->when(
                $isDiocese,
                // The diocese sees every shared line (it looks after them) and its own.
                fn ($q) => $q->where(fn ($w) => $w->whereNull('territory_id')->orWhere(fn ($o) => $o->where('territory_type', 'diocese')->where('territory_id', $place['id']))),
                fn ($q) => $q->forPlace($place['type'], $place['id']),
            )
            ->orderBy('display_order')->orderBy('name')
            ->get();

        // How many of this place's budgets use each line, and how many budgets anywhere.
        $ours = BudgetLineItem::query()
            ->whereHas('budget', fn ($b) => $b->where('territory_type', $place['type'])->where('territory_id', $place['id']))
            ->selectRaw('budget_line_id, count(distinct budget_id) as n')->groupBy('budget_line_id')->pluck('n', 'budget_line_id');
        $anywhere = BudgetLineItem::query()->whereIn('budget_line_id', $lines->pluck('id'))
            ->selectRaw('budget_line_id, count(distinct budget_id) as n')->groupBy('budget_line_id')->pluck('n', 'budget_line_id');

        $canUpdate = $this->can($request, 'settings');

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => [
                'place' => [...$place, 'name' => \App\Models\Territory::find($place['id'])?->name],
                'can' => ['update' => $canUpdate],
                'lines' => $lines->map(fn (BudgetLine $l) => $this->lineRow($l, $place, $ours, $anywhere, $canUpdate))->values(),
                'deductions' => $this->deductionRows($place, $canUpdate),
                'applies_choices' => collect(self::APPLIES_FOR[$place['type']])->map(fn ($k) => ['value' => $k, 'label' => self::APPLIES[$k]])->values(),
                'paid_through' => $this->paidThroughChoices($place),
            ],
        ]);
    }

    /** POST /budget-settings/lines - a new line: the place's own, or (the diocese) a shared one. */
    public function storeLine(Request $request): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'side' => 'required|in:in,out',
            'description' => 'nullable|string|max:1000',
            'share_with' => 'nullable|in:own,all,church,region,diocese',
        ], ['name.required' => 'Give the line a name.']);
        $category = $this->category($data['side']);
        $shared = $place['type'] === 'diocese' && ! empty($data['share_with']) && $data['share_with'] !== 'own';
        $owner = $shared ? [null, null] : [$place['type'], $place['id']];

        $line = BudgetLine::create([
            'budget_category_id' => $category->id,
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['name'], ...$owner),
            'description' => $data['description'] ?? null,
            'territory_scope' => $shared ? $data['share_with'] : $place['type'],
            'territory_type' => $owner[0],
            'territory_id' => $owner[1],
            'is_system_default' => false,
            'is_active' => true,
            'display_order' => (int) BudgetLine::where('budget_category_id', $category->id)->max('display_order') + 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'status' => 201, 'message' => "Added {$line->name}", 'data' => ['id' => $line->id]], 201);
    }

    /** PUT /budget-settings/lines/{line} - rename, describe, switch on/off, (diocese) who it's shared with. */
    public function updateLine(Request $request, BudgetLine $line): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! $this->editable($line, $place)) {
            return $this->forbidden('This line is set by the diocese - only the diocese can change it.');
        }
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'side' => 'sometimes|required|in:in,out',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
            'share_with' => 'nullable|in:all,church,region,diocese',
        ]);
        $changes = ['updated_by' => $request->user()->id];
        if (isset($data['name'])) {
            $changes['name'] = trim($data['name']);
            $changes['slug'] = $this->uniqueSlug($data['name'], $line->territory_type, $line->territory_id, $line->id);
        }
        if (array_key_exists('description', $data)) {
            $changes['description'] = $data['description'];
        }
        if (isset($data['is_active'])) {
            $changes['is_active'] = $data['is_active'];
        }
        if (isset($data['side'])) {
            $category = $this->category($data['side']);
            if ($category->id !== $line->budget_category_id && $line->budgetLineItems()->exists()) {
                return response()->json(['success' => false, 'status' => 422, 'message' => 'This line is already used in a budget, so it can\'t move between money in and money out.'], 422);
            }
            $changes['budget_category_id'] = $category->id;
        }
        if (! empty($data['share_with']) && $line->territory_id === null) {
            $changes['territory_scope'] = $data['share_with'];
        }
        $line->update($changes);

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Saved '.$line->name]);
    }

    /** DELETE /budget-settings/lines/{line} - only a line no budget has used. */
    public function destroyLine(Request $request, BudgetLine $line): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! $this->editable($line, $place) || $line->is_system_default) {
            return $this->forbidden('This line is set by the diocese - only the diocese can change it.');
        }
        $used = $line->budgetLineItems()->distinct('budget_id')->count('budget_id');
        if ($used > 0) {
            return response()->json(['success' => false, 'status' => 422, 'message' => "{$line->name} is used in {$used} ".($used === 1 ? 'budget' : 'budgets').'. Switch it off instead - it then isn\'t offered for new budgets.'], 422);
        }
        $line->delete();

        return response()->json(['success' => true, 'status' => 200, 'message' => "Deleted {$line->name}"]);
    }

    // ---------------------------------------------------------------- deductions

    /** POST /budget-settings/deductions */
    public function storeDeduction(Request $request): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $this->deductionData($request, $place);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $deduction = BudgetDeduction::create([
            ...$data,
            'slug' => $this->uniqueDeductionSlug($data['name'], $place),
            'territory_type' => $place['type'],
            'territory_id' => $place['id'],
            'territory_scope' => $data['applies_to_level'] === 'own' ? $place['type'] : ($data['applies_to_level'] === 'all' ? 'all' : $data['applies_to_level']),
            'applies_to' => 'income',
            'is_active' => true,
            'display_order' => (int) BudgetDeduction::where('territory_type', $place['type'])->where('territory_id', $place['id'])->max('display_order') + 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'status' => 201, 'message' => "Added {$deduction->name}. Budgets saved from now on work it out.", 'data' => ['id' => $deduction->id]], 201);
    }

    /** PUT /budget-settings/deductions/{deduction} - change the rule, or just switch it on/off. */
    public function updateDeduction(Request $request, BudgetDeduction $deduction): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! $this->ownsDeduction($deduction, $place)) {
            return $this->forbidden('This deduction is set by a level above - only they can change it.');
        }
        if ($request->has('is_active') && count($request->except(['is_active', 'territory_id'])) === 0) {
            $deduction->update(['is_active' => $request->boolean('is_active'), 'updated_by' => $request->user()->id]);

            return response()->json(['success' => true, 'status' => 200, 'message' => $deduction->is_active ? "{$deduction->name} is on" : "{$deduction->name} is off - budgets saved from now on leave it out"]);
        }
        $data = $this->deductionData($request, $place);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $deduction->update([
            ...$data,
            'slug' => $this->uniqueDeductionSlug($data['name'], $place, $deduction->id),
            'territory_scope' => $data['applies_to_level'] === 'own' ? $place['type'] : ($data['applies_to_level'] === 'all' ? 'all' : $data['applies_to_level']),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'status' => 200, 'message' => "Saved {$deduction->name}. Budgets saved from now on use the new rule."]);
    }

    /** DELETE /budget-settings/deductions/{deduction} - only one no budget has used. */
    public function destroyDeduction(Request $request, BudgetDeduction $deduction): JsonResponse
    {
        $place = $this->place($request, 'settings');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if (! $this->ownsDeduction($deduction, $place)) {
            return $this->forbidden('This deduction is set by a level above - only they can change it.');
        }
        $used = $deduction->budgetDeductionItems()->count();
        if ($used > 0) {
            return response()->json(['success' => false, 'status' => 422, 'message' => "{$deduction->name} was worked out in {$used} ".($used === 1 ? 'budget' : 'budgets').'. Switch it off instead.'], 422);
        }
        $deduction->delete();

        return response()->json(['success' => true, 'status' => 200, 'message' => "Deleted {$deduction->name}"]);
    }

    /** The checked fields of a deduction, creating its paid-through line when asked. */
    private function deductionData(Request $request, array $place): array|JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'deduction_type' => 'required|in:percentage,fixed_amount',
            'deduction_value' => 'required|numeric|min:0.01|max:9999999999',
            'basis' => 'required|in:all,lines',
            'basis_line_ids' => 'array|required_if:basis,lines',
            'basis_line_ids.*' => 'integer|exists:budget_lines,id',
            'applies_to_level' => 'required|in:'.implode(',', self::APPLIES_FOR[$place['type']]),
            'budget_line_id' => 'nullable|integer|exists:budget_lines,id',
            'new_line_name' => 'nullable|string|max:255',
        ], [
            'name.required' => 'Give the deduction a name.',
            'deduction_value.min' => 'Type the % or the amount.',
            'basis_line_ids.required_if' => 'Choose the money in lines it is worked out on.',
        ]);
        if ($data['deduction_type'] === 'percentage' && $data['deduction_value'] > 100) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'A % can\'t be more than 100.', 'errors' => ['deduction_value' => ['At most 100%.']]], 422);
        }
        $level = $data['applies_to_level'];
        if (empty($data['budget_line_id'])) {
            if (empty($data['new_line_name'])) {
                return response()->json(['success' => false, 'status' => 422, 'message' => 'Choose the money out line it is paid through.', 'errors' => ['budget_line_id' => ['Required.']]], 422);
            }
            if ($level !== 'own' && $place['type'] !== 'diocese') {
                return response()->json(['success' => false, 'status' => 422, 'message' => 'Choose one of the shared lines - only the diocese adds lines for others.'], 422);
            }
            $shared = $level !== 'own';
            $expense = BudgetCategory::where('slug', 'expense')->firstOrFail();
            $line = BudgetLine::create([
                'budget_category_id' => $expense->id,
                'name' => trim($data['new_line_name']),
                'slug' => $this->uniqueSlug($data['new_line_name'], $shared ? null : $place['type'], $shared ? null : $place['id']),
                'description' => 'Paid through: '.trim($data['name']),
                'territory_scope' => $shared ? $level : $place['type'],
                'territory_type' => $shared ? null : $place['type'],
                'territory_id' => $shared ? null : $place['id'],
                'is_system_default' => false,
                'is_active' => true,
                'display_order' => (int) BudgetLine::where('budget_category_id', $expense->id)->max('display_order') + 1,
                'created_by' => $request->user()->id,
            ]);
            $data['budget_line_id'] = $line->id;
        } elseif (! collect($this->paidThroughChoices($place))->firstWhere('id', (int) $data['budget_line_id'])) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'That line can\'t carry this deduction.', 'errors' => ['budget_line_id' => ['Choose another line.']]], 422);
        }
        unset($data['new_line_name']);
        $data['basis_line_ids'] = $data['basis'] === 'lines' ? array_values(array_map('intval', $data['basis_line_ids'] ?? [])) : null;

        return $data;
    }

    /** The place's own deductions (all) and the ones set above that apply to it (locked). */
    private function deductionRows(array $place, bool $canUpdate): array
    {
        $own = BudgetDeduction::with('budgetLine')->where('territory_type', $place['type'])->where('territory_id', $place['id'])->orderBy('display_order')->orderBy('name')->get();
        $inherited = $this->deductions->applicable($place['type'], $place['id'])->reject(fn ($d) => $this->ownsDeduction($d, $place));
        $lineNames = BudgetLine::whereIn('id', $own->merge($inherited)->flatMap(fn ($d) => $d->basis_line_ids ?? [])->unique())->pluck('name', 'id');

        return $own->merge($inherited)->map(function (BudgetDeduction $d) use ($place, $canUpdate, $lineNames) {
            $isOwn = $this->ownsDeduction($d, $place);
            $from = $d->territory_type === 'diocese' ? 'the diocese' : 'the region';

            return [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'deduction_type' => $d->deduction_type,
                'deduction_value' => (float) $d->deduction_value,
                'basis' => $d->basis,
                'basis_line_ids' => $d->basis_line_ids ?? [],
                'basis_lines' => collect($d->basis_line_ids ?? [])->map(fn ($id) => $lineNames[$id] ?? null)->filter()->values(),
                'budget_line_id' => $d->budget_line_id,
                'line' => $d->budgetLine?->name,
                'applies_to_level' => $d->applies_to_level,
                'applies_label' => self::APPLIES[$d->applies_to_level] ?? '',
                'rule' => $this->deductions->ruleText($d->deduction_type, (float) $d->deduction_value, $d->basis),
                'example' => $d->deduction_type === 'percentage' ? round(100000 * (float) $d->deduction_value / 100, 2) : (float) $d->deduction_value,
                'is_active' => (bool) $d->is_active,
                'is_own' => $isOwn,
                'set_by' => $isOwn ? 'Ours' : "Set by {$from}",
                'editable' => $canUpdate && $isOwn,
                'used' => $d->budgetDeductionItems()->count(),
            ];
        })->values()->all();
    }

    /**
     * Money-out lines a deduction can be paid through, with who can use each:
     * the place's own usable lines (for "our own budgets"), and the shared
     * lines of each level below (for deductions set for them).
     */
    private function paidThroughChoices(array $place): array
    {
        $out = BudgetLine::with('budgetCategory')->where('is_active', true)
            ->whereHas('budgetCategory', fn ($c) => $c->where('slug', 'expense'))
            ->where(fn ($q) => $q->whereNull('territory_id')->orWhere(fn ($o) => $o->where('territory_type', $place['type'])->where('territory_id', $place['id'])))
            ->orderBy('name')->get();

        return $out->map(fn (BudgetLine $l) => [
            'id' => $l->id,
            'name' => $l->name,
            // which "applies to" choices this line works for
            'for' => array_values(array_filter(self::APPLIES_FOR[$place['type']], fn ($level) => $level === 'own'
                ? ($l->territory_id !== null || in_array($l->territory_scope, [$place['type'], 'all'], true))
                : ($l->territory_id === null && ($level === 'all' ? $l->territory_scope === 'all' : in_array($l->territory_scope, [$level, 'all'], true))))),
        ])->filter(fn ($l) => $l['for'] !== [])->values()->all();
    }

    private function ownsDeduction(BudgetDeduction $d, array $place): bool
    {
        return $d->territory_type === $place['type'] && (int) $d->territory_id === $place['id'];
    }

    private function uniqueDeductionSlug(string $name, array $place, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'deduction';
        $slug = $base;
        for ($i = 2; ; $i++) {
            $taken = BudgetDeduction::withTrashed()->where('slug', $slug)->where('territory_type', $place['type'])->where('territory_id', $place['id'])
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
            if (! $taken) {
                return $slug;
            }
            $slug = "{$base}-{$i}";
        }
    }

    /* ---------------------------------------------------------------- */

    private function lineRow(BudgetLine $l, array $place, $ours, $anywhere, bool $canUpdate): array
    {
        $own = $l->territory_id !== null && $l->territory_type === $place['type'] && (int) $l->territory_id === $place['id'];

        return [
            'id' => $l->id,
            'name' => $l->name,
            'description' => $l->description,
            'side' => $l->budgetCategory?->slug === 'income' ? 'in' : 'out',
            'is_active' => (bool) $l->is_active,
            'is_own' => $own,
            'shared_with' => $l->territory_id === null ? ($l->territory_scope ?? 'all') : null,
            'shared_label' => $l->territory_id === null ? (self::SHARE[$l->territory_scope] ?? 'Everyone') : null,
            'editable' => $canUpdate && $this->editable($l, $place),
            'used_here' => (int) ($ours[$l->id] ?? 0),
            'used_anywhere' => (int) ($anywhere[$l->id] ?? 0),
            'is_system_default' => (bool) $l->is_system_default,
        ];
    }

    /** A place changes its own lines; the diocese also looks after the shared ones. */
    private function editable(BudgetLine $line, array $place): bool
    {
        if ($line->territory_id === null) {
            return $place['type'] === 'diocese';
        }

        return $line->territory_type === $place['type'] && (int) $line->territory_id === $place['id'];
    }

    /**
     * The place whose settings these are - the acting one (a global admin
     * names it with territory_id) - or a 403 without the ability.
     */
    private function place(Request $request, string $ability): array|JsonResponse
    {
        $user = $request->user();
        $place = $user->hasGlobalAccess()
            ? BudgetAccess::place($user, $request->integer('territory_id') ?: null)
            : BudgetAccess::acting($user);
        if (! $place) {
            return $this->forbidden('Budget Settings are for a church, a region or the diocese.');
        }
        if (! $this->can($request, $ability)) {
            return $this->forbidden($ability === 'settings' ? 'Your role can look at Budget Settings, but not change them.' : 'Your role cannot open Budget Settings.');
        }

        return $place;
    }

    private function can(Request $request, string $ability): bool
    {
        return BudgetAccess::can($request->user(), $ability);
    }

    private function category(string $side): BudgetCategory
    {
        return BudgetCategory::where('slug', $side === 'in' ? 'income' : 'expense')->firstOrFail();
    }

    /** A slug not yet used by the same owner (shared lines, or one place's lines). */
    private function uniqueSlug(string $name, ?string $type, ?int $id, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'line';
        $slug = $base;
        for ($i = 2; ; $i++) {
            $taken = BudgetLine::withTrashed()->where('slug', $slug)
                ->when($id, fn ($q) => $q->where('territory_type', $type)->where('territory_id', $id), fn ($q) => $q->whereNull('territory_id'))
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();
            if (! $taken) {
                return $slug;
            }
            $slug = "{$base}-{$i}";
        }
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
