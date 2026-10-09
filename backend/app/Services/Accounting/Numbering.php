<?php

namespace App\Services\Accounting;

use App\Models\Journal;
use App\Models\Territory;
use Illuminate\Support\Facades\DB;

/**
 * Document numbers per place, kind and year (docs/specs/accounting-spec.md):
 * SHR-001/RCT/2026/000012. Taken under a row lock, so two receipts written
 * at the same moment never share a number. Call inside a transaction.
 */
final class Numbering
{
    public function next(Territory $place, string $kind, int $year): string
    {
        DB::table('accounting_sequences')->insertOrIgnore([
            'territory_id' => $place->id, 'doc_type' => $kind, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('accounting_sequences')->where(['territory_id' => $place->id, 'doc_type' => $kind, 'year' => $year])->lockForUpdate()->first();
        $n = (int) $row->last_number + 1;
        DB::table('accounting_sequences')->where('id', $row->id)->update(['last_number' => $n, 'updated_at' => now()]);

        return self::short($place).'/'.(Journal::PREFIXES[$kind] ?? strtoupper($kind)).'/'.$year.'/'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** The place's short code: CCI-MWD-SHR-001 -> SHR-001, CCI-MWD -> MWD. */
    public static function short(Territory $place): string
    {
        $code = strtoupper((string) $place->code);
        if ($code === '') {
            return 'P'.$place->id;
        }
        $short = preg_replace('/^CCI-MWD-/', '', $code);

        return $short === 'CCI-MWD' ? 'MWD' : $short;
    }
}
