<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Diocese;
use App\Models\Region;
use App\Models\Church;
use App\Models\UserTerritoryAssignment;
use App\Enums\AssignmentType;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SampleUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $diocese = Diocese::where('code', 'CCI-MWD')->first();
        $regions = Region::all();
        $sampleChurches = Church::take(5)->get();

        // Create Bishop for Makueni West Diocese
        $bishopRole = Role::where('name', 'Bishop')->first();
        $bishop = User::firstOrCreate(
            ['email' => 'bishop@makueniwestdiocese.or.ke'],
            [
                'firstname' => 'David',
                'lastname' => 'Mutua',
                'username' => 'bishop.mutua',
                'password' => Hash::make('password'),
                'phone' => '+254712345678',
                'position' => 'Bishop',
                'role_id' => $bishopRole->id,
                'status' => 'active',
                'employee_code' => '100001',
                'pin' => Hash::make('2468'),
            ]
        );

        $bishop->assignRole('Bishop');

        UserTerritoryAssignment::firstOrCreate(
            [
                'user_id' => $bishop->id,
                'territory_id' => $diocese->id,
                'role_id' => $bishopRole->id,
            ],
            [
                'assignment_type' => AssignmentType::PRIMARY,
                'can_see_children' => true,
                'can_see_siblings' => false,
                'can_manage_users' => true,
                'can_manage_finances' => true,
                'assignment_reason' => 'Diocese Bishop appointment',
                'assigned_by' => 1, // Super admin
                'approved_by' => 1,
                'approved_at' => now(),
            ]
        );

        // Create Regional Overseers - use different employee code range (500xxx)
        $employeeCode = 500001;
        $regionalOverseerRole = Role::where('name', 'Regional Overseer')->first();
        foreach ($regions->take(2) as $region) { // Just create for first 2 regions
            $email = strtolower(str_replace(' ', '', $region->name)) . '.overseer@makueniwestdiocese.or.ke';
            $overseer = User::firstOrCreate(
                ['email' => $email],
                [
                    'firstname' => 'Regional',
                    'lastname' => 'Overseer ' . $region->name,
                    'username' => 'overseer.' . strtolower(str_replace(' ', '', $region->name)),
                    'password' => Hash::make('password'),
                    'phone' => '+254' . rand(700000000, 799999999),
                    'position' => 'Regional Overseer',
                    'role_id' => $regionalOverseerRole->id,
                    'status' => 'active',
                    'employee_code' => (string)$employeeCode++,
                    'pin' => Hash::make(str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT)),
                ]
            );

            $overseer->assignRole('Regional Overseer');

            UserTerritoryAssignment::firstOrCreate(
                [
                    'user_id' => $overseer->id,
                    'territory_id' => $region->id,
                    'role_id' => $regionalOverseerRole->id,
                ],
                [
                    'assignment_type' => AssignmentType::PRIMARY,
                    'can_see_children' => true,
                    'can_see_siblings' => false,
                    'can_manage_users' => true,
                    'can_manage_finances' => false,
                    'assignment_reason' => 'Regional oversight assignment',
                    'assigned_by' => 1, // Super admin
                    'approved_by' => $bishop->id,
                    'approved_at' => now(),
                ]
            );
        }

        // Create Sample Pastors - use different employee code range (600xxx)
        $employeeCode = 600001;
        $seniorPastorRole = Role::where('name', 'Senior Pastor')->first();
        foreach ($sampleChurches as $church) {
            $email = 'pastor.church' . $church->id . '@makueniwestdiocese.or.ke';
            $pastor = User::firstOrCreate(
                ['email' => $email],
                [
                    'firstname' => 'Pastor',
                    'lastname' => 'Sample ' . $church->id,
                    'username' => 'pastor.' . $church->id,
                    'password' => Hash::make('password'),
                    'phone' => '+254' . rand(700000000, 799999999),
                    'position' => 'Senior Pastor',
                    'role_id' => $seniorPastorRole->id,
                    'status' => 'active',
                    'employee_code' => (string)$employeeCode++,
                    'pin' => Hash::make(str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT)),
                ]
            );

            $pastor->assignRole('Senior Pastor');

            UserTerritoryAssignment::firstOrCreate(
                [
                    'user_id' => $pastor->id,
                    'territory_id' => $church->id,
                    'role_id' => $seniorPastorRole->id,
                ],
                [
                    'assignment_type' => AssignmentType::PRIMARY,
                    'can_see_children' => false,
                    'can_see_siblings' => false,
                    'can_manage_users' => false,
                    'can_manage_finances' => false,
                    'assignment_reason' => 'Church pastoral assignment',
                    'assigned_by' => 1, // Super admin
                    'approved_by' => $bishop->id,
                    'approved_at' => now(),
                ]
            );
        }

        $this->command->info('✅ Sample users created:');
        $this->command->info("   - 1 Bishop");
        $this->command->info("   - 2 Regional Overseers");
        $this->command->info("   - 5 Sample Pastors");
    }
}
