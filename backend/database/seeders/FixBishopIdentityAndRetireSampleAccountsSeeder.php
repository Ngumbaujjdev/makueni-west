<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FixBishopIdentityAndRetireSampleAccountsSeeder extends Seeder
{
    /**
     * SampleUsersSeeder used to run before DioceseLeadershipSeeder in
     * DatabaseSeeder's call list and firstOrCreate()'d a Bishop at
     * bishop@makueniwestdiocese.or.ke with placeholder data ("David Mutua").
     * DioceseLeadershipSeeder's own firstOrCreate() for the same email then
     * found the row already existed and left it untouched, so the real
     * Bishop - Peter Kilonzo, whose real phone/PIN are confirmed against the
     * CCI National Office reporting template - never got written.
     *
     * SampleUsersSeeder has now been removed from DatabaseSeeder's call list
     * (superseded by DioceseLeadershipSeeder + RegionalLeadershipSeeder), so
     * this collision can't happen on a fresh install. This seeder repairs
     * databases that were seeded before that fix: corrects the existing
     * Bishop row in place (same id/email, so nothing referencing user_id=2
     * breaks) and deactivates SampleUsersSeeder's two leftover duplicate
     * "Regional Overseer <region>" accounts, which are fully superseded by
     * the real, named Regional Overseer accounts DioceseLeadershipSeeder
     * already seeds correctly.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('👑 FIXING BISHOP IDENTITY & RETIRING SAMPLE ACCOUNTS');
        $this->command->info(str_repeat('=', 70));

        $this->fixBishopIdentity();
        $this->deactivateSampleOverseers();

        $this->command->info('');
        $this->command->info('✅ DONE');
    }

    private function fixBishopIdentity(): void
    {
        $bishop = User::where('email', 'bishop@makueniwestdiocese.or.ke')->first();

        if (! $bishop) {
            $this->command->error('   ❌ Bishop account not found - run DioceseLeadershipSeeder first');

            return;
        }

        if ($bishop->firstname === 'Peter' && $bishop->lastname === 'Kilonzo') {
            $this->command->warn('   ⚠️  Bishop identity already correct (Peter Kilonzo)');

            return;
        }

        $this->command->info("   Correcting Bishop identity: {$bishop->firstname} {$bishop->lastname} -> Peter Kilonzo");

        $bishop->update([
            'firstname' => 'Peter',
            'lastname' => 'Kilonzo',
            'username' => 'bishop.kilonzo',
            'phone' => '+254726285695',
            'pin' => Hash::make('1234'),
        ]);

        UserTerritoryAssignment::where('user_id', $bishop->id)
            ->update(['assignment_reason' => 'Makueni West Diocese Bishop']);

        $this->command->info('   ✅ Bishop corrected: Peter Kilonzo, PIN 1234');
    }

    private function deactivateSampleOverseers(): void
    {
        // Matches SampleUsersSeeder's exact naming ('Regional' / 'Overseer
        // <region name>') rather than an email-pattern guess - a broader
        // '%.overseer@%' match would also catch unrelated accounts like
        // SubregionalLeadershipSeeder's subregional.overseer@ test account.
        $sampleOverseers = User::where('firstname', 'Regional')
            ->where('lastname', 'like', 'Overseer %')
            ->where('status', 'active')
            ->get();

        if ($sampleOverseers->isEmpty()) {
            $this->command->warn('   ⚠️  No active SampleUsersSeeder overseer accounts found');

            return;
        }

        foreach ($sampleOverseers as $overseer) {
            $overseer->update(['status' => 'inactive']);
            $this->command->info("   ✅ Deactivated superseded account: {$overseer->firstname} {$overseer->lastname} ({$overseer->email})");
        }
    }
}
