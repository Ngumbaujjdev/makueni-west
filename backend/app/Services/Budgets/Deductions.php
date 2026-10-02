<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetDeduction;
use App\Models\BudgetDeductionItem;
use App\Models\BudgetLine;
use App\Models\BudgetLineItem;
use App\Models\Territory;
use Illuminate\Support\Collection;

/**
 * Deductions: a share sent up out of money in, worked out for you
 * (docs/specs/budgets-spec.md, phase 4) - e.g. "Diocese share: 10% of money
 * in, for every church". Each is paid through a money-out line, so what was
 * sent is just money recorded on that line and nothing is counted twice.
 *
 * Who it applies to: the place that set it (own, all) and, when it says so,
 * every church / region below it. A fixed amount is per month - twelve
 * times that on a whole-year budget.
 */
final class Deductions
{
    /** Deductions that apply to a place: its own and those set above it for its level. */
    public function applicable(string $type, int $id, bool $activeOnly = true): Collection
    {
        $above = $this->above($id);

        return BudgetDeduction::with('budgetLine')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($type, $id, $above) {
                $q->where(fn ($own) => $own->where('territory_type', $type)->where('territory_id', $id)->whereIn('applies_to_level', ['own', 'all']));
                foreach ($above as $place) {
                    $q->orWhere(fn ($up) => $up->where('territory_type', $place['type'])->where('territory_id', $place['id'])->whereIn('applies_to_level', [$type, 'all']));
                }
            })
            ->orderBy('display_order')->orderBy('name')
            ->get();
    }

    /** The places above one (region, diocese), nearest first. */
    public function above(int $id): array
    {
        $out = [];
        $territory = Territory::find($id);
        for ($depth = 0; $territory && $territory->parent_territory_id && $depth < 6; $depth++) {
            $territory = Territory::find($territory->parent_territory_id);
            if ($territory && in_array($territory->territory_type?->value, ['region', 'diocese'], true)) {
                $out[] = ['type' => $territory->territory_type->value, 'id' => (int) $territory->id];
            }
        }

        return $out;
    }

    /**
     * What each deduction comes to on a budget's planned amounts:
     * [deduction id => ['deduction', 'line_id', 'base', 'amount']].
     *
     * @param  array<int, float>  $amounts  line id => planned amount
     */
    public function plan(Collection $deductions, array $amounts, ?int $month): array
    {
        $income = BudgetLine::whereIn('id', array_keys($amounts))
            ->whereHas('budgetCategory', fn ($c) => $c->where('slug', 'income'))
            ->pluck('id')->all();
        $out = [];
        foreach ($deductions as $d) {
            if (! $d->budget_line_id) {
                continue; // not paid through a line yet - nothing to fill in
            }
            $on = $d->basis === 'lines' ? array_map('intval', $d->basis_line_ids ?? []) : $income;
            $base = round(array_sum(array_intersect_key($amounts, array_flip(array_intersect($on, $income)))), 2);
            $out[$d->id] = [
                'deduction' => $d,
                'line_id' => (int) $d->budget_line_id,
                'base' => $base,
                'amount' => $this->amountOn($d, $base, $month),
            ];
        }

        return $out;
    }

    /** One deduction on a base: a % of it, or the fixed monthly amount (x12 for a year). */
    public function amountOn(BudgetDeduction $d, float $base, ?int $month): float
    {
        return $d->deduction_type === 'percentage'
            ? round($base * (float) $d->deduction_value / 100, 2)
            : round((float) $d->deduction_value * ($month === null ? 12 : 1), 2);
    }

    /**
     * After a budget's lines are written: mark the lines the deductions filled
     * in, and keep a snapshot of each rule on the budget. Deductions that no
     * longer apply let go of their line (its amount stays, now typed).
     */
    public function remember(Budget $budget, array $planned, int $userId): void
    {
        $items = BudgetLineItem::where('budget_id', $budget->id)->get()->keyBy('budget_line_id');
        $keep = [];
        foreach ($planned as $deductionId => $p) {
            $item = $items->get($p['line_id']);
            if ($item) {
                $item->budget_deduction_id = $deductionId;
                $item->saveQuietly();
            }
            $d = $p['deduction'];
            $snapshot = BudgetDeductionItem::withTrashed()->firstOrNew(['budget_id' => $budget->id, 'budget_deduction_id' => $deductionId], ['created_by' => $userId]);
            if ($snapshot->trashed()) {
                $snapshot->restore();
            }
            $snapshot->fill([
                'deduction_amount' => $p['amount'],
                'rate_type' => $d->deduction_type,
                'rate_value' => $d->deduction_value,
                'base_amount' => $p['base'],
                'is_applied' => true,
                'applied_at' => now(),
                'updated_by' => $userId,
            ])->save();
            $keep[] = $deductionId;
        }
        BudgetLineItem::where('budget_id', $budget->id)->whereNotNull('budget_deduction_id')->whereNotIn('budget_deduction_id', $keep ?: [0])->update(['budget_deduction_id' => null]);
        BudgetDeductionItem::where('budget_id', $budget->id)->whereNotIn('budget_deduction_id', $keep ?: [0])->delete();
    }

    /**
     * A budget's deductions: planned (from the snapshot), due on what has
     * actually come in, sent (spent on the paid-through line), still owed.
     */
    public function status(Budget $budget): array
    {
        $snapshots = BudgetDeductionItem::with('budgetDeduction.budgetLine')->where('budget_id', $budget->id)->get();
        if ($snapshots->isEmpty()) {
            return [];
        }
        $items = BudgetLineItem::with('budgetCategory')->where('budget_id', $budget->id)->get();
        $received = $items->filter(fn ($i) => $i->budgetCategory?->slug === 'income')->mapWithKeys(fn ($i) => [$i->budget_line_id => (float) $i->actual_amount])->all();

        return $snapshots->map(function (BudgetDeductionItem $s) use ($items, $received, $budget) {
            $d = $s->budgetDeduction;
            $percent = $s->rate_type === 'percentage';
            $on = $d && $d->basis === 'lines' ? array_map('intval', $d->basis_line_ids ?? []) : array_keys($received);
            $base = array_sum(array_intersect_key($received, array_flip($on)));
            $due = $percent ? round($base * (float) $s->rate_value / 100, 2) : (float) $s->deduction_amount;
            $sent = (float) ($items->firstWhere('budget_line_id', $d?->budget_line_id)?->actual_amount ?? 0);

            return [
                'id' => $d?->id,
                'name' => $d?->name ?? 'Deduction',
                'rule' => $this->ruleText($s->rate_type, (float) $s->rate_value, $d?->basis ?? 'all', $budget->period_month === null, $d?->basis_line_ids ?? []),
                // What it's worked out on: all money in, or these money-in lines.
                'basis' => $d?->basis ?? 'all',
                'basis_line_ids' => array_map('intval', $d?->basis_line_ids ?? []),
                'line_id' => $d?->budget_line_id,
                'line' => $d?->budgetLine?->name,
                'set_by' => $this->setBy($d, $budget),
                // Who set it - who the money is owed to.
                'owner_type' => $d?->territory_type,
                'owner_id' => $d?->territory_id ? (int) $d->territory_id : null,
                'rate_type' => $s->rate_type,
                'rate_value' => (float) $s->rate_value,
                'base_planned' => (float) $s->base_amount,
                'base_received' => round($base, 2),
                'planned' => (float) $s->deduction_amount,
                'due' => $due,
                'sent' => $sent,
                'owed' => round(max($due - $sent, 0), 2),
            ];
        })->values()->all();
    }

    /**
     * "10% of Tithes received", "10% of Tithes and Offerings received",
     * "10% of all money received", "KES 5,000.00 each month (KES 60,000.00
     * for the year)". A % is always of the money actually received - what
     * is recorded - on the lines it names.
     */
    public function ruleText(?string $type, float $value, string $basis, bool $year = false, array $lineIds = []): string
    {
        if ($type === 'percentage') {
            $names = $basis === 'lines' ? $this->lineNames($lineIds) : [];
            $on = $names === [] ? ($basis === 'lines' ? 'some money in' : 'all money') : $this->listOf($names);

            return rtrim(rtrim(number_format($value, 2), '0'), '.')."% of {$on} received";
        }

        return 'KES '.number_format($value, 2).' each month'.($year ? ' (KES '.number_format($value * 12, 2).' for the year)' : '');
    }

    /** @var array<int, string> line names already looked up */
    private array $names = [];

    /** @return string[] the lines' names, in the order given */
    private function lineNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $missing = array_diff($ids, array_keys($this->names));
        if ($missing !== []) {
            $this->names += BudgetLine::whereIn('id', $missing)->pluck('name', 'id')->all();
        }

        return array_values(array_filter(array_map(fn ($id) => $this->names[$id] ?? null, $ids)));
    }

    /** "Tithes", "Tithes and Offerings", "Tithes, Offerings and Donations". */
    private function listOf(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names).' and '.$last;
    }

    /** Who set it, from the budget's place: "Set by the diocese", "Our own". */
    public function setBy(?BudgetDeduction $d, Budget $budget): string
    {
        if (! $d) {
            return '';
        }
        if ($d->territory_type === $budget->territory_type && (int) $d->territory_id === (int) $budget->territory_id) {
            return 'Our own';
        }

        return 'Set by the '.($d->territory_type === 'diocese' ? 'diocese' : 'region');
    }
}
