<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalYear;
use App\Models\FiscalMonth;

class FixTerritoryDataGapsSeeder extends Seeder
{
    /**
     * Resolves the three real data gaps flagged while seeding
     * SeedRealHistoricalDemographicsSeeder:
     *
     * 1. "Kikumini" - a real church (marked NEW CHURCH in the 2025 national
     *    report: S/School 4, Youth 2, Men 3, Women 3, Total 12) that was
     *    never added as a Church territory. Grouped under Sultan Hamud in
     *    both source documents.
     * 2. "Kavuthu" and "Ndutoni" - real churches in the DOMW Children's
     *    Ministry report (grouped under Sultan Hamud and Mumela
     *    respectively) with no matching territory and no entry in the
     *    national annual report at all - only their children's-ministry
     *    counts are known. Seeded with total_members left at its honest
     *    default (0, unknown) rather than guessed; the existing soft
     *    validation warning (sunday_school_count > total_members) will
     *    correctly flag these as incomplete records when read.
     * 3. "CCI MAKINDU" is seeded twice (a pre-existing duplicate, unrelated
     *    to either source document). Verified before writing this: the
     *    duplicate has zero references anywhere (no UserTerritoryAssignment,
     *    ChurchDemographic, or ChurchAttendanceRecord rows) - safe to
     *    soft-delete. The original (with its 2024/2025 demographics
     *    already seeded) is left untouched.
     *
     * Idempotent - safe to re-run.
     */

    /** name => [parent_territory_id, code, ChurchDemographic 2025 data or null] */
    private const NEW_CHURCHES = [
        'CCI KIKUMINI' => [
            'parent_territory_id' => 3, // Sultan Hamud Region
            'code' => 'CCI-MWD-SHR-027',
            'demographics_2025' => [
                'total_members' => 12, 'youth_count' => 2, 'mens_fellowship_count' => 3,
                'womens_fellowship_count' => 3, 'sunday_school_male_count' => 4, 'sunday_school_female_count' => 3,
            ],
        ],
        'CCI KAVUTHU' => [
            'parent_territory_id' => 3, // Sultan Hamud Region
            'code' => 'CCI-MWD-SHR-028',
            'demographics_2025' => [
                // Only the children's-ministry count is known for this
                // church - total_members/youth/men/women stay at their
                // honest default (0/unknown), not guessed.
                'sunday_school_male_count' => 4, 'sunday_school_female_count' => 9,
            ],
        ],
        'CCI NDUTONI' => [
            'parent_territory_id' => 7, // Mumela Region
            'code' => 'CCI-MWD-MML-012',
            'demographics_2025' => [
                'sunday_school_male_count' => 12, 'sunday_school_female_count' => 13,
            ],
        ],
    ];

    public function run(): void
    {
        $this->command->info('🔧 FIXING TERRITORY DATA GAPS');
        $this->command->info(str_repeat('=', 70));

        $this->createMissingChurches();
        $this->removeOrphanedDuplicateMakindu();

        $this->command->info('');
        $this->command->info('✅ TERRITORY DATA GAPS FIXED!');
    }

    private function createMissingChurches(): void
    {
        $this->command->info('⛪ Creating missing church territories...');

        $fiscalYear = FiscalYear::where('year', 2025)->first();
        $february = FiscalMonth::where('number', 2)->first();

        foreach (self::NEW_CHURCHES as $name => $config) {
            $church = Church::where('name', $name)->first();

            if ($church) {
                $this->command->warn("   ⚠️  Already exists: {$name} (ID: {$church->id})");
            } else {
                $church = Church::create([
                    'name' => $name,
                    'code' => $config['code'],
                    'territory_type' => 'church',
                    'level' => 4,
                    'parent_territory_id' => $config['parent_territory_id'],
                    'is_active' => true,
                ]);
                $this->command->info("   ✅ Created: {$name} (ID: {$church->id}, code: {$config['code']})");
            }

            if (!$fiscalYear || !$february) {
                $this->command->error('   ❌ FY2025/February not found - skipping demographics for ' . $name);
                continue;
            }

            $existingDemographic = ChurchDemographic::where('territory_id', $church->id)
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('fiscal_month_id', $february->id)
                ->first();

            if ($existingDemographic) {
                $this->command->warn("      ⚠️  Demographics already seeded for {$name}");
                continue;
            }

            ChurchDemographic::create(array_merge([
                'territory_type' => 'church',
                'territory_id' => $church->id,
                'fiscal_year_id' => $fiscalYear->id,
                'fiscal_month_id' => $february->id,
                'status' => 'approved',
            ], $config['demographics_2025']));

            $this->command->info("      ✅ Seeded 2025 demographics for {$name}");
        }

        $this->command->info('');
    }

    private function removeOrphanedDuplicateMakindu(): void
    {
        $this->command->info('🔍 Checking for the duplicate CCI MAKINDU...');

        $duplicates = Church::where('name', 'CCI MAKINDU')->orderBy('id')->get();

        if ($duplicates->count() <= 1) {
            $this->command->warn('   ⚠️  No duplicate found (already resolved)');
            return;
        }

        $keep = $duplicates->first();
        $this->command->info("   Keeping: ID {$keep->id} ({$keep->code})");

        foreach ($duplicates->skip(1) as $extra) {
            $hasAssignments = \App\Models\UserTerritoryAssignment::where('territory_id', $extra->id)->exists();
            $hasDemographics = ChurchDemographic::where('territory_id', $extra->id)->exists();
            $hasAttendance = \App\Models\ChurchAttendanceRecord::where('territory_id', $extra->id)->exists();

            if ($hasAssignments || $hasDemographics || $hasAttendance) {
                $this->command->error("   ❌ ID {$extra->id} ({$extra->code}) has real references - NOT deleting, needs manual review");
                continue;
            }

            $extra->delete();
            $this->command->info("   ✅ Removed orphaned duplicate: ID {$extra->id} ({$extra->code})");
        }
    }
}
