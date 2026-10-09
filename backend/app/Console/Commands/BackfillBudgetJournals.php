<?php

namespace App\Console\Commands;

use App\Models\BudgetEntry;
use App\Services\Accounting\BudgetBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Give every budget entry recorded before Accounting its journal
 * (docs/specs/accounting-spec.md), so the books hold all the money already
 * recorded in Budgets. Entries that already have one are left alone - safe
 * to run again.
 */
class BackfillBudgetJournals extends Command
{
    protected $signature = 'accounting:backfill-budget-entries {--dry-run : Count what would be posted, post nothing}';

    protected $description = 'Post a journal for every budget entry that has none yet';

    public function handle(BudgetBridge $bridge): int
    {
        $query = BudgetEntry::with(['budget', 'lineItem.budgetLine'])->whereNull('journal_id')->orderBy('entry_date')->orderBy('id');
        $total = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("{$total} budget entries have no journal yet.");

            return self::SUCCESS;
        }
        $done = $failed = 0;
        foreach ($query->cursor() as $entry) {
            try {
                DB::transaction(fn () => $bridge->entryRecorded($entry, null));
                $done++;
            } catch (ValidationException $e) {
                $failed++;
                $this->warn("Entry {$entry->id}: ".collect($e->errors())->flatten()->first());
            }
        }
        $this->info("Posted {$done} of {$total} budget entries to the books".($failed ? ", {$failed} could not be" : '').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
