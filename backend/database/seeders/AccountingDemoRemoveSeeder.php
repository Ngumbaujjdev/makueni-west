<?php

namespace Database\Seeders;

use App\Models\Territory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Takes the Accounting demo away again (AccountingDemoSeeder): every row it
 * made - found by the id ranges it kept in the church's metadata
 * (accounting_demo), and only at the places it wrote to - children before
 * their parents. The sound mixer it added to Equipment goes too.
 *
 * php artisan db:seed --class=AccountingDemoRemoveSeeder
 */
class AccountingDemoRemoveSeeder extends Seeder
{
    public function run(): void
    {
        $removed = 0;
        foreach (Territory::whereNotNull('metadata')->where('metadata', 'like', '%"'.AccountingDemoSeeder::KEY.'"%')->get() as $church) {
            $removed += self::removeDemo($church);
        }
        $this->command?->info($removed ? "🧹 Accounting demo removed ({$removed} rows)." : 'No Accounting demo to remove.');
    }

    /** Remove one church's demo. Returns the rows deleted (children by cascade not counted). */
    public static function removeDemo(Territory $church): int
    {
        AccountingDemoSeeder::restoreRoles($church);
        $church = $church->fresh();
        $demo = ($church->metadata ?? [])[AccountingDemoSeeder::KEY] ?? null;
        if (! $demo) {
            return 0;
        }
        $places = array_map('intval', $demo['places'] ?? [$church->id]);
        $rows = function (string $table) use ($demo, $places) {
            [$from, $to] = $demo['ranges'][$table] ?? [0, -1];
            $q = DB::table($table)->whereBetween('id', [$from, $to]);
            $columns = AccountingDemoSeeder::TABLES[$table];

            return $q->where(fn ($w) => collect($columns)->each(fn ($c) => $w->orWhereIn($c, $places)));
        };
        $removed = 0;

        DB::transaction(function () use ($rows, &$removed) {
            // The equipment its deliveries made, found before the deliveries go.
            $equipment = DB::table('goods_received_lines')->whereIn('goods_received_id', $rows('goods_received')->pluck('id'))->whereNotNull('equipment_id')->pluck('equipment_id')->all();
            foreach (array_keys(AccountingDemoSeeder::TABLES) as $table) {
                if ($table === 'equipment') {
                    continue;
                }
                $ids = $rows($table)->pluck('id')->all();
                if (! $ids) {
                    continue;
                }
                // Lines that point at another demo row without a cascade.
                if ($table === 'journals') {
                    DB::table('journals')->whereIn('id', $ids)->update(['reverses_id' => null, 'reversed_by_id' => null]);
                }
                if ($table === 'accounting_accounts') {
                    DB::table('accounting_accounts')->whereIn('parent_id', $ids)->update(['parent_id' => null]);
                }
                $removed += DB::table($table)->whereIn('id', $ids)->delete();
            }
            if ($equipment) {
                $removed += DB::table('equipment')->whereIn('id', $equipment)->delete();
            }
        });

        $metadata = $church->metadata ?? [];
        unset($metadata[AccountingDemoSeeder::KEY]);
        Territory::withoutAuditing(fn () => Territory::whereKey($church->id)->update(['metadata' => json_encode($metadata)]));

        return $removed;
    }
}
