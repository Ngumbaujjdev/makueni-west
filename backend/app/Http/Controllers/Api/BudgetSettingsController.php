<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use App\Models\BudgetLineItem;
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
