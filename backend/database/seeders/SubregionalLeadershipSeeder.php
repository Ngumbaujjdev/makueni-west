<?php

namespace Database\Seeders;

use App\Enums\AssignmentType;
use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SubregionalLeadershipSeeder extends Seeder
{
    /**
     * The "Subregional Overseer" role has existed since RoleSeeder but no
     * seeder ever assigned it to a user - there was no way to test
     * subregion-scoped read access (the Demographics review workflow, in
     * particular) without manually assigning the role in Tinker.
     *
     * No source document names a real Subregional Overseer, so this is a
     * dev/test account only (mirrors the Super Admin seed account in that
     * sense) - named descriptively rather than with an invented real name,
     * so it's never mistaken for real diocese data.
     *
     * Idempotent - safe to re-run.
     */
    public function run(): void
    {
        $this->command->info('🏘️  SUBREGIONAL LEADERSHIP SEEDER (test account)');
        $this->command->info(str_repeat('=', 70));

        $subregion = Territory::where('territory_type', TerritoryType::SUBREGION)
            ->orderBy('code')
            ->first();

        if (! $subregion) {
            $this->command->error('❌ No Subregion territories found - run SubregionsSeeder first.');

            return;
        }

        $role = Role::where('name', 'Subregional Overseer')->first();

        if (! $role) {
            $this->command->error('❌ Subregional Overseer role not found - run RoleSeeder first.');

            return;
        }

        $bishop = User::where('email', 'bishop@makueniwestdiocese.or.ke')->first();

        $overseer = User::firstOrCreate(
            ['email' => 'subregional.overseer@makueniwestdiocese.or.ke'],
            [
                'firstname' => 'Subregional',
                'lastname' => 'Overseer',
                'username' => 'subregional.overseer',
                'password' => Hash::make('password'),
                'phone' => '+254799999901',
                'position' => 'Subregional Overseer - '.$subregion->name,
                'role_id' => $role->id,
                'status' => 'active',
                'employee_code' => '800001',
                'pin' => Hash::make('1234'),
            ]
        );

        $overseer->assignRole('Subregional Overseer');

        UserTerritoryAssignment::firstOrCreate(
            [
                'user_id' => $overseer->id,
                'territory_id' => $subregion->id,
                'role_id' => $role->id,
            ],
            [
                'assignment_type' => AssignmentType::PRIMARY,
                'can_see_children' => true,
                'can_see_siblings' => false,
                'can_manage_users' => false,
                'can_manage_finances' => false,
                'assignment_reason' => "Subregional Overseer for {$subregion->name} (test/dev account, no real person named in source documents)",
                'assigned_by' => $bishop?->id ?? 1,
                'approved_by' => $bishop?->id ?? 1,
                'approved_at' => now(),
            ]
        );

        $this->command->info("✅ Subregional Overseer: {$overseer->firstname} {$overseer->lastname}");
        $this->command->line("   - Subregion: {$subregion->name}");
        $this->command->line('   - Email: subregional.overseer@makueniwestdiocese.or.ke');
        $this->command->line('   - Employee Code: 800001, PIN: 1234');
    }
}
