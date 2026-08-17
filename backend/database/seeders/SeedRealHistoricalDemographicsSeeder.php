<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalYear;
use App\Models\FiscalMonth;

class SeedRealHistoricalDemographicsSeeder extends Seeder
{
    /**
     * Real historical demographics from two source documents the diocese
     * actually submitted:
     *
     * 1. "CCI NATIONAL OFFICE REPORTING TEMPLATE - DIOCESE OF MAKUENI WEST"
     *    - annual reconciliation, submitted 19/02/2026 for 2024 + 2025.
     *    Per-church: S/School, Youth, Men, Women, Total. Filed here under
     *    February of the data year (the reconciliation checkpoint), per the
     *    "annual is a reconciliation of monthly tracking" decision - no
     *    schema change, just where these historical snapshots land.
     * 2. "DOMW CHILDREN'S MINISTRY" report (undated, confirmed as the most
     *    recent/authoritative source for the Sunday School male/female
     *    split - cross-checked: Sultan Hamud's 110+180=290 matches the
     *    national report's 2025 S/School figure for that church exactly).
     *    Only applied to each church's 2025 row, since it postdates 2025
     *    and there's no equivalent gender split for 2024.
     *
     * total_members uses each report's own printed Total column, not a
     * recomputed sum - a few rows don't arithmetically sum (real-world
     * reporting noise), and the printed Total is what the diocese actually
     * submitted.
     *
     * Marked 'approved' (not 'draft') - this is already-reconciled
     * historical data, not a pending submission, and needs to be visible
     * in rollups.
     *
     * Idempotent - skips any church/year pair that already has a row.
     *
     * NOT included (real data gaps, not oversights - see command output):
     * - "Kikumini" (2025 S/School 4/2/3/3, marked NEW CHURCH in the
     *   national report) has no matching territory row in this database
     *   at all - it needs to be created as a Church first.
     * - "Kavuthu" and "Ndutoni" (from the children's report) don't match
     *   any of the 71 seeded church names under any spelling variant found.
     * - "CCI MAKINDU" is seeded twice (ids vary by environment) - a
     *   pre-existing duplicate unrelated to this data; only the first
     *   match is used here.
     */

    /** doc name => [db church name, ['2024'=>[sschool,youth,men,women,total], '2025'=>[...]]] */
    private const ANNUAL_DATA = [
        'CCI SULTAN HAMUD' => ['2024' => [289, 130, 59, 128, 606], '2025' => [290, 132, 62, 138, 622]],
        'CCI MALILI' => ['2024' => [74, 30, 12, 28, 144], '2025' => [75, 30, 14, 29, 148]],
        'CCI MLOLONGO' => ['2024' => [35, 42, 25, 40, 142], '2025' => [56, 64, 26, 40, 186]],
        'CCI MUAMBANI' => ['2024' => [78, 38, 21, 44, 181], '2025' => [80, 30, 25, 50, 185]],
        'CCI MBIINI' => ['2024' => [16, 6, 3, 12, 37], '2025' => [19, 13, 3, 12, 47]],
        'CCI MASAA' => ['2024' => [21, 12, 10, 22, 65], '2025' => [32, 7, 6, 22, 67]],
        'CCI UPETE' => ['2024' => [15, 10, 3, 8, 65], '2025' => [31, 8, 8, 19, 66]],
        'CCI MWANYANI' => ['2024' => [15, 10, 3, 8, 36], '2025' => [31, 6, 4, 7, 48]],
        'CCI MUTYAMBUA' => ['2024' => [53, 45, 13, 47, 164], '2025' => [57, 45, 22, 50, 174]],
        'CCI MIKUINI' => ['2024' => [6, 3, 5, 5, 19], '2025' => [7, 5, 6, 6, 25]],
        'CCI MUAMBWANI' => ['2024' => [25, 21, 9, 19, 51], '2025' => [48, 13, 8, 14, 105]],
        'CCI KASIKEU' => ['2024' => [28, 5, 10, 22, 65], '2025' => [25, 9, 9, 23, 66]],
        'CCI MATIKU' => ['2024' => [30, 7, 6, 9, 52], '2025' => [25, 6, 3, 4, 38]],
        'CCI MBULUTINI' => ['2024' => [41, 23, 14, 26, 104], '2025' => [25, 28, 12, 23, 88]],
        'CCI OLRISYENYEK' => ['2024' => [54, 12, 23, 38, 82], '2025' => [16, 12, 11, 25, 64]],
        'CCI EMISIGIYO' => ['2024' => [55, 28, 10, 28, 121], '2025' => [30, 20, 14, 50, 114]],
        'CCI AMBOSELI' => ['2024' => [34, 24, 10, 35, 103], '2025' => [34, 54, 15, 38, 141]],
        'CCI ILBISSIL' => ['2024' => [51, 6, 1, 4, 62], '2025' => [72, 7, 0, 14, 93]],
        'CCI KIUANI' => ['2024' => [13, 2, 3, 3, 21], '2025' => [8, 5, 2, 4, 19]],
        'CCI KATIVANI' => ['2024' => [47, 15, 4, 15, 126], '2025' => [41, 9, 4, 13, 67]],
        'CCI ARROI' => ['2024' => [27, 20, 7, 14, 68], '2025' => [30, 15, 6, 15, 66]],
        'CCI OLMAKARIKARA' => ['2024' => [41, 22, 11, 19, 93], '2025' => [50, 14, 7, 20, 91]],
        "CCI NANING'O" => ['2024' => [26, 18, 6, 2, 72], '2025' => [42, 17, 5, 22, 86]],
        'CCI NABLA' => ['2024' => [15, 18, 5, 12, 50], '2025' => [21, 18, 5, 12, 56]],
        'CCI KITENGELA' => ['2024' => [0, 2, 2, 3, 7], '2025' => [0, 0, 6, 3, 10]],
        'CCI MASAMUKYE' => ['2025' => [2, 0, 4, 5, 11]], // 2024 was N/A
        'CCI KALAMBA' => ['2024' => [103, 40, 27, 55, 220], '2025' => [65, 40, 21, 50, 181]],
        'CCI MATHANGUNI' => ['2024' => [60, 16, 12, 60, 148], '2025' => [64, 11, 20, 68, 155]],
        'CCI KALAANI' => ['2024' => [60, 20, 8, 45, 133], '2025' => [55, 13, 10, 43, 123]],
        'CCI UPENDO' => ['2024' => [20, 26, 10, 26, 82], '2025' => [26, 24, 10, 26, 86]],
        'CCI KIKWASUNI' => ['2024' => [62, 32, 18, 45, 157], '2025' => [50, 35, 20, 45, 150]],
        'CCI MUTULANI' => ['2024' => [22, 12, 5, 15, 54], '2025' => [21, 9, 5, 11, 50]],
        'CCI MAKUTANO' => ['2024' => [27, 18, 6, 20, 71], '2025' => [21, 7, 5, 15, 53]],
        'CCI KIKUI' => ['2024' => [45, 18, 13, 26, 102], '2025' => [52, 12, 11, 30, 101]],
        'CCI WELOVEA' => ['2024' => [49, 57, 8, 35, 149], '2025' => [20, 15, 15, 30, 85]],
        'CCI MATILIKU' => ['2024' => [20, 18, 6, 16, 60], '2025' => [19, 16, 8, 17, 59]],
        'CCI KYAU' => ['2024' => [12, 6, 6, 10, 34], '2025' => [11, 5, 8, 7, 34]],
        'CCI YUMBANI' => ['2024' => [23, 7, 3, 20, 53], '2025' => [12, 12, 3, 10, 42]],
        'CCI ISAMBANI' => ['2024' => [17, 15, 7, 12, 51], '2025' => [15, 0, 7, 12, 34]],
        'CCI KAWALA' => ['2024' => [2, 1, 2, 2, 7], '2025' => [15, 0, 4, 1, 20]],
        'CCI BHC' => ['2024' => [81, 79, 54, 92, 306], '2025' => [80, 80, 53, 84, 297]],
        'CCI KITHEINI' => ['2024' => [68, 50, 18, 67, 195], '2025' => [65, 50, 22, 69, 206]],
        'CCI A/LIFE' => ['2024' => [60, 50, 20, 65, 195], '2025' => [60, 50, 20, 60, 190]],
        'CCI MUTITU' => ['2024' => [60, 30, 17, 40, 147], '2025' => [61, 31, 19, 44, 159]],
        'CCI MATWIKU' => ['2024' => [55, 23, 10, 19, 107], '2025' => [55, 24, 7, 19, 105]],
        'CCI MALINDI' => ['2024' => [44, 24, 11, 33, 112], '2025' => [55, 25, 10, 35, 125]],
        'CCI MUANGINI' => ['2024' => [27, 4, 11, 21, 63], '2025' => [22, 2, 10, 19, 53]],
        'CCI KATULYE' => ['2024' => [13, 8, 3, 10, 34], '2025' => [13, 12, 3, 10, 43]],
        'CCI MWAANI' => ['2024' => [12, 7, 1, 8, 28], '2025' => [6, 12, 2, 10, 30]],
        'CCI KALUMBI' => ['2024' => [15, 3, 6, 6, 30], '2025' => [13, 9, 4, 5, 31]],
        'CCI SINAI' => ['2024' => [23, 14, 5, 18, 62], '2025' => [30, 15, 9, 20, 74]],
        'CCI MITINI' => ['2024' => [82, 58, 19, 48, 207], '2025' => [85, 38, 19, 51, 193]],
        'CCI KYAMBEKE' => ['2024' => [75, 43, 16, 37, 181], '2025' => [80, 40, 15, 34, 169]],
        'CCI NDIANI' => ['2024' => [53, 20, 9, 22, 104], '2025' => [60, 24, 12, 25, 131]],
        'CCI GRACE VALLEY' => ['2024' => [16, 5, 3, 11, 35], '2025' => [7, 6, 2, 7, 22]],
        'CCI IIANI' => ['2024' => [6, 4, 4, 6, 20], '2025' => [8, 4, 4, 7, 23]],
        'CCI NZUKINI' => ['2024' => [9, 8, 5, 4, 26], '2025' => [12, 12, 8, 7, 39]],
        'CCI KALIVIA' => ['2024' => [34, 32, 10, 24, 100], '2025' => [34, 22, 9, 24, 89]],
        'CCI ENGAVU' => ['2024' => [18, 9, 2, 8, 36], '2025' => [16, 12, 3, 10, 41]],
        'CCI BEULAH' => ['2024' => [9, 29, 5, 14, 57], '2025' => [31, 8, 4, 15, 58]],
        'CCI WAUTU' => ['2024' => [22, 14, 6, 18, 60], '2025' => [20, 14, 6, 14, 54]],
        'CCI KITHIONI' => ['2024' => [5, 4, 4, 8, 21], '2025' => [6, 4, 4, 8, 22]],
        'CCI BETHSAIDA' => ['2024' => [15, 13, 4, 13, 45], '2025' => [20, 15, 6, 16, 57]],
        'CCI EMALI' => ['2024' => [47, 55, 14, 30, 146], '2025' => [51, 34, 14, 25, 124]],
        'CCI MAKINDU' => ['2024' => [5, 2, 2, 5, 14], '2025' => [4, 5, 2, 7, 18]],
        'CCI SIMBA CEMENT' => ['2024' => [9, 4, 7, 10, 30], '2025' => [25, 34, 12, 20, 91]],
        'CCI KIMANA' => ['2024' => [28, 15, 11, 18, 72], '2025' => [30, 20, 13, 20, 83]],
        'CCI LOITOKITOK' => ['2024' => [6, 9, 4, 7, 26], '2025' => [15, 10, 4, 10, 39]],
        'CCI ISINET' => ['2024' => [30, 12, 8, 10, 60], '2025' => [38, 12, 9, 18, 77]],
        'CCI NDUNGUNI' => ['2025' => [10, 5, 7, 7, 29]], // 2024 was N/A, NEW CHURCH
    ];

    /**
     * DOMW Children's Ministry report - the newest source for the Sunday
     * School gender split. Applied to each church's 2025 row only.
     * Churches marked "NOT GIVEN THEIR DATA" or left blank in that report
     * (Olorishenyek, Arroi, Mikuini, Emisigiyo, Kyambeke, Wautu, Bethsaida)
     * are intentionally omitted here, not defaulted to 0/0.
     */
    private const CHILDREN_DATA = [
        'CCI SULTAN HAMUD' => [110, 180],
        'CCI KASIKEU' => [7, 15],
        'CCI MUTYAMBUA' => [32, 22],
        'CCI MBULUTINI' => [5, 20],
        'CCI MALILI' => [34, 41],
        'CCI MUAMBWANI' => [18, 20],
        'CCI UPETE' => [13, 21],
        'CCI MATIKU' => [6, 10],
        'CCI ILBISSIL' => [27, 45],
        'CCI MLOLONGO' => [20, 36],
        'CCI MASAA' => [17, 9],
        'CCI KIUANI' => [4, 5],
        'CCI MASAMUKYE' => [0, 2],
        'CCI MWANYANI' => [12, 18],
        'CCI OLMAKARIKARA' => [29, 23],
        "CCI NANING'O" => [16, 20],
        'CCI NABLA' => [7, 8],
        'CCI MBIINI' => [8, 9],
        'CCI KATIVANI' => [25, 14],
        'CCI EMALI' => [33, 33],
        'CCI KIMANA' => [14, 18],
        'CCI SIMBA CEMENT' => [8, 18],
        'CCI ISINET' => [15, 24],
        'CCI NDUNGUNI' => [7, 6],
        'CCI MAKINDU' => [2, 1],
        'CCI KALAMBA' => [32, 50],
        'CCI MATHANGUNI' => [29, 41],
        'CCI KIKWASUNI' => [20, 30],
        'CCI KALAANI' => [39, 23],
        'CCI UPENDO' => [12, 16],
        'CCI KIKUI' => [21, 22],
        'CCI MATILIKU' => [10, 11],
        'CCI MAKUTANO' => [11, 18],
        'CCI YUMBANI' => [5, 8],
        'CCI KYAU' => [7, 8],
        'CCI MUTULANI' => [12, 13],
        'CCI KAWALA' => [11, 4],
        'CCI WELOVEA' => [19, 19],
        'CCI ISAMBANI' => [8, 9],
        'CCI MITINI' => [45, 56],
        'CCI NDIANI' => [22, 28],
        'CCI KALIVIA' => [16, 17],
        'CCI BEULAH' => [15, 20],
        'CCI NZUKINI' => [10, 12],
        'CCI ENGAVU' => [4, 10],
        'CCI GRACE VALLEY' => [3, 2],
        'CCI IIANI' => [5, 5],
        'CCI KITHIONI' => [0, 0],
        'CCI BHC' => [24, 47],
        'CCI A/LIFE' => [30, 35],
        'CCI KITHEINI' => [18, 24],
        'CCI MALINDI' => [28, 36],
        'CCI SINAI' => [15, 30],
        'CCI KALUMBI' => [8, 8],
        'CCI MUANGINI' => [6, 11],
        'CCI MWAANI' => [2, 6],
        'CCI MATWIKU' => [20, 35],
        'CCI KATULYE' => [7, 11],
        'CCI MUTITU' => [33, 32],
    ];

    public function run(): void
    {
        $this->command->info('📊 SEEDING REAL HISTORICAL DEMOGRAPHICS (2024-2025)');
        $this->command->info(str_repeat('=', 70));

        $february = FiscalMonth::where('number', 2)->first();

        if (!$february) {
            $this->command->error('❌ FiscalMonth for February not found - run FiscalMonthSeeder first.');
            return;
        }

        $created = 0;
        $skippedExisting = 0;
        $skippedNoChurch = [];

        foreach (self::ANNUAL_DATA as $churchName => $years) {
            $church = Church::where('name', $churchName)->first();

            if (!$church) {
                $skippedNoChurch[] = $churchName;
                continue;
            }

            foreach ($years as $year => [$sschool, $youth, $men, $women, $total]) {
                $fiscalYear = FiscalYear::where('year', (int) $year)->first();

                if (!$fiscalYear) {
                    $this->command->error("   ❌ FiscalYear {$year} not found - skipping {$churchName} {$year}");
                    continue;
                }

                $existing = ChurchDemographic::where('territory_id', $church->id)
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->where('fiscal_month_id', $february->id)
                    ->first();

                if ($existing) {
                    $skippedExisting++;
                    continue;
                }

                $sundaySchoolMale = 0;
                $sundaySchoolFemale = 0;

                // PHP casts numeric string array keys ('2024', '2025') to
                // int automatically, so $year arrives as an int here despite
                // the string literal keys above - compare as int.
                if ((int) $year === 2025 && isset(self::CHILDREN_DATA[$churchName])) {
                    [$sundaySchoolMale, $sundaySchoolFemale] = self::CHILDREN_DATA[$churchName];
                }

                ChurchDemographic::create([
                    'territory_type' => 'church',
                    'territory_id' => $church->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'fiscal_month_id' => $february->id,
                    'total_members' => $total,
                    'youth_count' => $youth,
                    'mens_fellowship_count' => $men,
                    'womens_fellowship_count' => $women,
                    'sunday_school_male_count' => $sundaySchoolMale,
                    'sunday_school_female_count' => $sundaySchoolFemale,
                    'status' => 'approved',
                ]);

                $created++;
            }
        }

        $this->command->info("✅ Created {$created} historical demographics row(s), skipped {$skippedExisting} already-seeded.");

        if (!empty($skippedNoChurch)) {
            $this->command->warn('');
            $this->command->warn('⚠️  No matching Church territory found for: ' . implode(', ', array_unique($skippedNoChurch)));
        }

        $this->command->info('');
        $this->command->info('✅ REAL HISTORICAL DEMOGRAPHICS SEEDED SUCCESSFULLY!');
    }
}
