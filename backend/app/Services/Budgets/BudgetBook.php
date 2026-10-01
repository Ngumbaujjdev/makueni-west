<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Models\BudgetLine;
use App\Models\BudgetLineItem;
use App\Models\BudgetLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes a budget goes through here, so a budget is saved
 * as one piece: its period, its lines and amounts, its totals (worked out
 * once), and one plain History entry saying what changed
 * (docs/specs/budgets-spec.md).
 *
 * A budget covers a month or a whole year of one place (church, region or
 * diocese); a place has either a whole-year budget or month budgets in a
 * year, one per month.
 */
final class BudgetBook
{
    /** The lines a place can use, grouped by Money in / Money out. */
    public function linesFor(string $type, int $id): Collection
    {
        return BudgetLine::with('budgetCategory')
            ->forPlace($type, $id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /** The budgets that already use a year of this place (for greying out periods). */
    public function takenPeriods(string $type, int $id, int $year, ?int $ignoreId = null): array
    {
        $budgets = Budget::where('territory_type', $type)->where('territory_id', $id)
            ->where('fiscal_year', $year)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'period_month', 'status']);

        return [
            'year' => $budgets->firstWhere('period_month', null)?->id,
            'months' => $budgets->whereNotNull('period_month')->mapWithKeys(fn ($b) => [$b->period_month => $b->id])->all(),
        ];
    }

    /** The place's latest budget that ends before this period starts - what "Copy last budget" copies. */
    public function previous(string $type, int $id, int $year, ?int $month): ?Budget
    {
        [$start] = $this->dates($year, $month);

        return Budget::with('budgetLineItems')
            ->where('territory_type', $type)->where('territory_id', $id)
            ->where('end_date', '<', $start->toDateString())
            ->orderByDesc('end_date')
            ->first();
    }

    /**
     * Create or update a budget with all its lines in one go.
     *
     * @param  array{year: int, month: ?int, notes: ?string, lines: array<int, array{budget_line_id: int, amount: numeric}>, start?: bool}  $data
     */
    public function save(User $user, string $type, int $id, array $data, ?Budget $budget = null): Budget
    {
        $year = (int) $data['year'];
        $month = isset($data['month']) && $data['month'] !== null && $data['month'] !== '' ? (int) $data['month'] : null;
        $this->assertPeriodFree($type, $id, $year, $month, $budget?->id);

        $usable = $this->linesFor($type, $id)->keyBy('id');
        $amounts = [];
        foreach ($data['lines'] ?? [] as $line) {
            $lineId = (int) $line['budget_line_id'];
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            // A line this place may no longer use can stay only if the budget already had it.
            if (! $usable->has($lineId) && ! $budget?->budgetLineItems->contains('budget_line_id', $lineId)) {
                throw ValidationException::withMessages(['lines' => ['One of the lines can\'t be used in this budget.']]);
            }
            $amounts[$lineId] = ($amounts[$lineId] ?? 0) + $amount;
        }

        return DB::transaction(function () use ($user, $type, $id, $data, $budget, $year, $month, $amounts) {
            [$start, $end] = $this->dates($year, $month);
            $isNew = $budget === null;
            $before = $isNew ? [] : $this->amountsOf($budget);
            $periodBefore = $isNew ? null : $budget->period_label;

            $budget ??= new Budget([
                'territory_type' => $type,
                'territory_id' => $id,
                'status' => 'draft',
                'created_by' => $user->id,
            ]);
            $budget->fill([
                'fiscal_year' => $year,
                'period_month' => $month,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'name' => ($month ? Budget::periodLabelFor($year, $month) : (string) $year).' budget',
                'description' => $data['notes'] ?? null,
                'updated_by' => $user->id,
            ]);
            if ($isNew || $budget->isDirty(['fiscal_year', 'period_month'])) {
                $budget->slug = $this->slug($type, $id, $year, $month, $budget->id);
            }
            $budget->save();

            $this->writeLines($budget, $amounts, $user);
            $this->recalculate($budget);

            if ($isNew) {
                $this->log($budget, $user, 'created', "Prepared the {$budget->period_label} budget", [], $this->amountsOf($budget));
            } else {
                $after = $this->amountsOf($budget);
                $changes = $this->describeChanges($before, $after);
                if ($periodBefore !== $budget->period_label) {
                    array_unshift($changes, "period {$periodBefore} → {$budget->period_label}");
                }
                if ($changes) {
                    $this->log($budget, $user, 'updated', 'Changed '.implode('; ', $changes), $before, $after);
                }
            }

            if (! empty($data['start']) && $budget->status === 'draft') {
                $this->start($budget, $user);
            }

            return $budget->fresh();
        });
    }

    public function start(Budget $budget, User $user): void
    {
        $this->assertStatus($budget, ['draft'], 'Only a draft can be started.');
        $budget->update(['status' => 'active', 'started_at' => now(), 'started_by' => $user->id, 'updated_by' => $user->id]);
        $this->log($budget, $user, 'started', "Started using the {$budget->period_label} budget");
    }

    public function close(Budget $budget, User $user): void
    {
        $this->assertStatus($budget, ['active'], 'Only a budget in use can be closed.');
        $budget->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id, 'updated_by' => $user->id]);
        $this->log($budget, $user, 'closed', "Closed the {$budget->period_label} budget");
    }

    public function reopen(Budget $budget, User $user): void
    {
        $this->assertStatus($budget, ['closed'], 'Only a closed budget can be reopened.');
        $budget->update(['status' => 'active', 'closed_at' => null, 'closed_by' => null, 'updated_by' => $user->id]);
        $this->log($budget, $user, 'reopened', "Reopened the {$budget->period_label} budget");
    }

    public function delete(Budget $budget, User $user): void
    {
        $this->assertStatus($budget, ['draft'], 'Only a draft can be deleted. Close a budget in use instead.');
        DB::transaction(function () use ($budget, $user) {
            $this->log($budget, $user, 'deleted', "Deleted the draft {$budget->period_label} budget");
            $budget->budgetLineItems()->delete();
            $budget->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Money in and out ("Spending")
    |--------------------------------------------------------------------------
    | Recorded against a line of a budget that is In use, on a date inside its
    | period. A line the budget didn't plan for is added as "unplanned".
    */

    /** The place's budget in use on a date (its month's, or the whole year's). */
    public function budgetInUseOn(string $type, int $id, string $date): ?Budget
    {
        return Budget::where('territory_type', $type)->where('territory_id', $id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->orderByRaw('period_month IS NULL') // a month's budget before the whole year's
            ->first();
    }

    /**
     * @param  array{budget_line_id: int, amount: numeric, entry_date: string, description: string, counterparty?: ?string, method?: ?string, reference?: ?string}  $data
     */
    public function record(User $user, Budget $budget, array $data): BudgetEntry
    {
        $this->assertCanRecord($budget, $data['entry_date']);

        return DB::transaction(function () use ($user, $budget, $data) {
            $item = $this->itemFor($budget, (int) $data['budget_line_id'], $user);
            $entry = BudgetEntry::create([
                'budget_id' => $budget->id,
                'budget_line_item_id' => $item->id,
                'direction' => $item->budgetCategory?->slug === 'income' ? 'in' : 'out',
                'amount' => round((float) $data['amount'], 2),
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'counterparty' => $data['counterparty'] ?? null,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $user->id,
            ]);
            $this->refreshLine($item);
            $this->log($budget, $user, 'entry_recorded', $this->entrySentence('Recorded', $entry, $item));

            return $entry->fresh(['lineItem.budgetLine', 'recorder']);
        });
    }

    public function changeEntry(User $user, BudgetEntry $entry, array $data): BudgetEntry
    {
        $budget = $entry->budget;
        $this->assertCanRecord($budget, $data['entry_date'] ?? $entry->entry_date->toDateString());

        return DB::transaction(function () use ($user, $entry, $data, $budget) {
            $oldItem = $entry->lineItem;
            $item = isset($data['budget_line_id']) ? $this->itemFor($budget, (int) $data['budget_line_id'], $user) : $oldItem;
            $before = $this->entrySentence('', $entry, $oldItem);
            $entry->fill([
                'budget_line_item_id' => $item->id,
                'direction' => $item->budgetCategory?->slug === 'income' ? 'in' : 'out',
                'amount' => isset($data['amount']) ? round((float) $data['amount'], 2) : $entry->amount,
                'entry_date' => $data['entry_date'] ?? $entry->entry_date,
                'description' => $data['description'] ?? $entry->description,
                'counterparty' => array_key_exists('counterparty', $data) ? $data['counterparty'] : $entry->counterparty,
                'method' => array_key_exists('method', $data) ? $data['method'] : $entry->method,
                'reference' => array_key_exists('reference', $data) ? $data['reference'] : $entry->reference,
                'updated_by' => $user->id,
            ])->save();
            $this->refreshLine($item);
            if ($oldItem && $oldItem->id !== $item->id) {
                $this->refreshLine($oldItem);
            }
            $this->log($budget, $user, 'entry_changed', 'Changed an entry: '.trim($before).' → '.trim($this->entrySentence('', $entry, $item)));

            return $entry->fresh(['lineItem.budgetLine', 'recorder']);
        });
    }

    public function removeEntry(User $user, BudgetEntry $entry): void
    {
        $this->assertOpen($entry->budget);
        DB::transaction(function () use ($user, $entry) {
            $item = $entry->lineItem;
            $entry->update(['updated_by' => $user->id]);
            $entry->delete();
            $this->refreshLine($item);
            $this->log($entry->budget, $user, 'entry_removed', $this->entrySentence('Removed', $entry, $item));
        });
    }

    public function restoreEntry(User $user, BudgetEntry $entry): BudgetEntry
    {
        $this->assertOpen($entry->budget);

        return DB::transaction(function () use ($user, $entry) {
            $item = BudgetLineItem::withTrashed()->find($entry->budget_line_item_id);
            if ($item?->trashed()) {
                $item->restore();
            }
            $entry->restore();
            $this->refreshLine($item);
            $this->log($entry->budget, $user, 'entry_restored', $this->entrySentence('Brought back', $entry, $item));

            return $entry->fresh(['lineItem.budgetLine', 'recorder']);
        });
    }

    /** A line's received/spent amount is the sum of its entries; then the budget's totals follow. */
    public function refreshLine(?BudgetLineItem $item): void
    {
        if (! $item) {
            return;
        }
        $item->actual_amount = BudgetEntry::where('budget_line_item_id', $item->id)->sum('amount');
        $item->saveQuietly();
        $this->recalculate(Budget::find($item->budget_id));
    }

    private function assertCanRecord(Budget $budget, string $date): void
    {
        if ($budget->status === 'draft') {
            throw ValidationException::withMessages(['budget_id' => ["The {$budget->period_label} budget is still a draft. Start using it first."]]);
        }
        $this->assertOpen($budget);
        $day = CarbonImmutable::parse($date)->startOfDay();
        if ($day->lt($budget->start_date) || $day->gt($budget->end_date)) {
            throw ValidationException::withMessages(['entry_date' => ["The date must be within {$budget->period_label}."]]);
        }
    }

    private function assertOpen(Budget $budget): void
    {
        if ($budget->status === 'closed') {
            throw ValidationException::withMessages(['budget_id' => ["The {$budget->period_label} budget is closed. Reopen it to change its money."]]);
        }
    }

    /** The budget's line for this budget line - added as "unplanned" when the budget didn't plan it. */
    private function itemFor(Budget $budget, int $lineId, User $user): BudgetLineItem
    {
        $item = BudgetLineItem::withTrashed()->with('budgetCategory')->where('budget_id', $budget->id)->where('budget_line_id', $lineId)->first();
        if ($item) {
            if ($item->trashed()) {
                $item->restore();
            }

            return $item;
        }
        $line = $this->linesFor($budget->territory_type, (int) $budget->territory_id)->firstWhere('id', $lineId);
        if (! $line) {
            throw ValidationException::withMessages(['budget_line_id' => ['That line can\'t be used here.']]);
        }
        $item = BudgetLineItem::make([
            'budget_id' => $budget->id,
            'budget_line_id' => $line->id,
            'budgeted_amount' => 0,
            'actual_amount' => 0,
            'is_unplanned' => true,
            'created_by' => $user->id,
        ]);
        $item->budget_category_id = $line->budget_category_id;
        $item->saveQuietly();
        $this->log($budget, $user, 'updated', "Added {$line->name} as an unplanned line");

        return $item->load('budgetCategory');
    }

    private function entrySentence(string $verb, BudgetEntry $entry, ?BudgetLineItem $item): string
    {
        $what = $entry->direction === 'in' ? 'received' : 'spent';
        $line = $item?->budgetLine?->name ?? 'a line';

        return trim("{$verb} KES ".number_format((float) $entry->amount, 2)." {$what} on {$line} ({$entry->description}, ".CarbonImmutable::parse($entry->entry_date)->format('j M').')');
    }

    /** Totals, worked out once from the lines: planned and actual, money in and out, money left. */
    public function recalculate(Budget $budget): void
    {
        $sums = DB::table('budget_line_items as i')
            ->join('budget_categories as c', 'c.id', '=', 'i.budget_category_id')
            ->where('i.budget_id', $budget->id)
            ->whereNull('i.deleted_at')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN c.slug = 'income' THEN i.budgeted_amount END), 0) AS in_planned,
                COALESCE(SUM(CASE WHEN c.slug = 'income' THEN i.actual_amount END), 0) AS in_actual,
                COALESCE(SUM(CASE WHEN c.slug = 'expense' THEN i.budgeted_amount END), 0) AS out_planned,
                COALESCE(SUM(CASE WHEN c.slug = 'expense' THEN i.actual_amount END), 0) AS out_actual
            ")->first();

        DB::table('budgets')->where('id', $budget->id)->update([
            'total_income_budgeted' => $sums->in_planned,
            'total_income_actual' => $sums->in_actual,
            'total_expense_budgeted' => $sums->out_planned,
            'total_expense_actual' => $sums->out_actual,
            'net_income_budgeted' => $sums->in_planned - $sums->out_planned,
            'net_income_actual' => $sums->in_actual - $sums->out_actual,
        ]);
        $budget->refresh();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function dates(int $year, ?int $month): array
    {
        $start = CarbonImmutable::create($year, $month ?? 1, 1);

        return [$start, $month ? $start->endOfMonth()->startOfDay() : $start->endOfYear()->startOfDay()];
    }

    private function assertPeriodFree(string $type, int $id, int $year, ?int $month, ?int $ignoreId): void
    {
        $taken = $this->takenPeriods($type, $id, $year, $ignoreId);
        $message = match (true) {
            $taken['year'] !== null => "There's already a budget for the whole of {$year}.",
            $month === null && $taken['months'] !== [] => "There are already month budgets in {$year}. Use those, or delete them first.",
            $month !== null && isset($taken['months'][$month]) => 'There\'s already a budget for '.Budget::periodLabelFor($year, $month).'.',
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages(['month' => [$message]]);
        }
    }

    private function assertStatus(Budget $budget, array $allowed, string $message): void
    {
        if (! in_array($budget->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    /** Write the planned amounts; a line dropped from the budget is removed unless money was recorded on it. */
    private function writeLines(Budget $budget, array $amounts, User $user): void
    {
        $items = BudgetLineItem::withTrashed()->where('budget_id', $budget->id)->get()->keyBy('budget_line_id');
        $lines = BudgetLine::withTrashed()->whereIn('id', array_keys($amounts))->get()->keyBy('id');

        foreach ($amounts as $lineId => $amount) {
            $item = $items->get($lineId) ?? new BudgetLineItem(['budget_id' => $budget->id, 'budget_line_id' => $lineId, 'created_by' => $user->id]);
            if ($item->trashed()) {
                $item->restore();
            }
            $item->budget_category_id = $lines[$lineId]->budget_category_id;
            $item->budgeted_amount = $amount;
            $item->is_unplanned = false; // it's planned now
            $item->updated_by = $user->id;
            $item->saveQuietly();
        }

        foreach ($items as $lineId => $item) {
            if (isset($amounts[$lineId]) || $item->trashed()) {
                continue;
            }
            if ((float) $item->actual_amount > 0) {
                $item->budgeted_amount = 0;
                $item->saveQuietly();
            } else {
                $item->deleteQuietly();
            }
        }
    }

    /** name => planned amount, for History. */
    private function amountsOf(Budget $budget): array
    {
        return $budget->budgetLineItems()->with('budgetLine:id,name')->get()
            ->mapWithKeys(fn ($i) => [$i->budgetLine?->name ?? "Line {$i->budget_line_id}" => (float) $i->budgeted_amount])
            ->filter(fn ($amount) => $amount > 0)
            ->all();
    }

    private function describeChanges(array $before, array $after): array
    {
        $money = fn ($v) => number_format($v, 2);
        $changes = [];
        foreach ($after as $name => $amount) {
            if (! array_key_exists($name, $before)) {
                $changes[] = "added {$name} {$money($amount)}";
            } elseif (abs($before[$name] - $amount) >= 0.005) {
                $changes[] = "{$name} {$money($before[$name])} → {$money($amount)}";
            }
        }
        foreach (array_diff_key($before, $after) as $name => $amount) {
            $changes[] = "removed {$name}";
        }

        return $changes;
    }

    private function slug(string $type, int $id, int $year, ?int $month, ?int $ignoreId): string
    {
        $base = Str::slug("{$type}-{$id}-{$year}".($month ? '-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT) : ''));
        $slug = $base;
        for ($n = 2; Budget::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function log(Budget $budget, User $user, string $action, string $description, array $old = [], array $new = []): void
    {
        BudgetLog::create([
            'budget_id' => $budget->id,
            'action' => $action,
            'description' => $description,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'performed_by' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
