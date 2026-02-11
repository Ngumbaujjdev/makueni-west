<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Enums\TerritoryType;
use App\Enums\AssignmentType;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DioceseLeadershipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('👑 DIOCESE LEADERSHIP SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Seeding Bishop, Spouse, Regional Overseers & Diocese Council...');
        $this->command->info('');

        // Get Diocese
        $diocese = Territory::where('code', 'CCI-MWD')->first();

        if (!$diocese) {
            $this->command->error('❌ Diocese not found! Please run DioceseSeeder first.');
            return;
        }

        $this->command->info("📍 Diocese: {$diocese->name} (ID: {$diocese->id})");
        $this->command->info('');

        // Get all 5 regions
        $regions = Territory::where('territory_type', TerritoryType::REGION)
            ->where('parent_territory_id', $diocese->id)
            ->get();

        if ($regions->count() !== 5) {
            $this->command->error("❌ Expected 5 regions but found {$regions->count()}! Please run RegionsSeeder first.");
            return;
        }

        $this->command->info("📍 Found {$regions->count()} regions");
        $this->command->info('');

        // === STEP 1: CREATE BISHOP ===
        $this->command->info('👑 Creating Bishop & Spouse...');
        $this->command->info(str_repeat('=', 70));

        $bishop = $this->createBishop($diocese);
        $bishopSpouse = $this->createBishopSpouse($diocese, $bishop);

        $this->command->info('');

        // === STEP 2: CREATE REGIONAL OVERSEERS ===
        $this->command->info('👥 Creating Regional Overseers (Diocese Council Members)...');
        $this->command->info(str_repeat('=', 70));

        $regionalOverseers = [];
        $overseersData = [
            [
                'region_code' => 'CCI-MWD-SHR',
                'firstname' => 'Titus',
                'lastname' => 'Kenzi',
                'title' => 'Rev.',
                'phone' => '0797806292',
                'spouse_name' => 'Esther Mutiso',
                'spouse_phone' => '0729159342',
            ],
            [
                'region_code' => 'CCI-MWD-KLG',
                'firstname' => 'Philip',
                'lastname' => 'Mutiso',
                'title' => 'Rev.',
                'phone' => '0734892294',
                'spouse_name' => 'Peninah Philip',
                'spouse_phone' => '0736648842',
            ],
            [
                'region_code' => 'CCI-MWD-KLB',
                'firstname' => 'Christopher',
                'lastname' => 'Owuor Misori',
                'title' => 'Rev.',
                'phone' => '0723711634',
                'spouse_name' => 'Beatrice Owuor',
                'spouse_phone' => '0713025322',
            ],
            [
                'region_code' => 'CCI-MWD-KJS',
                'firstname' => 'Moses',
                'lastname' => 'Makonjio Okello',
                'title' => 'Prof. Rev.',
                'phone' => '0718800398',
                'spouse_name' => 'Roselidah Okello',
                'spouse_phone' => '0728726946',
            ],
            [
                'region_code' => 'CCI-MWD-MML',
                'firstname' => 'Dishon',
                'lastname' => 'Maweu',
                'title' => 'Rev.',
                'phone' => '0725973875',
                'spouse_name' => 'Lilian Dishon',
                'spouse_phone' => '0727297257',
            ],
        ];

        foreach ($overseersData as $index => $data) {
            $region = $regions->where('code', $data['region_code'])->first();

            if (!$region) {
                $this->command->error("   ❌ Region {$data['region_code']} not found!");
                continue;
            }

            $overseer = $this->createRegionalOverseer($data, $region, $diocese, $bishop, $index);
            $regionalOverseers[] = $overseer;

            $this->command->info('');
        }

        // === STEP 3: ASSIGN DIOCESE COUNCIL MEMBERSHIP ===
        $this->command->info('');
        $this->command->info('🏛️  Assigning Diocese Council Membership...');
        $this->command->info(str_repeat('=', 70));

        $dioceseCouncilRole = Role::where('name', 'Diocese Council Member')->first();

        if (!$dioceseCouncilRole) {
            $this->command->error('❌ Diocese Council Member role not found!');
        } else {
            // Assign Diocese Council role to all Regional Overseers
            foreach ($regionalOverseers as $overseer) {
                $overseer->assignRole('Diocese Council Member');

                // Create secondary territory assignment for diocese level
                UserTerritoryAssignment::firstOrCreate(
                    [
                        'user_id' => $overseer->id,
                        'territory_id' => $diocese->id,
                        'role_id' => $dioceseCouncilRole->id,
                    ],
                    [
                        'assignment_type' => AssignmentType::SECONDARY,
                        'can_see_children' => true,
                        'can_see_siblings' => true,
                        'can_manage_users' => false,
                        'can_manage_finances' => false,
                        'assignment_reason' => 'Diocese Council Member (ex-officio as Regional Overseer)',
                        'assigned_by' => $bishop->id,
                        'approved_by' => $bishop->id,
                        'approved_at' => now(),
                    ]
                );
            }

            $this->command->info("✅ Assigned Diocese Council membership to {$regions->count()} Regional Overseers");
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ DIOCESE LEADERSHIP SEEDED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('👥 Created Users:');
        $this->command->info('   - 1 Bishop (Peter Kilonzo)');
        $this->command->info('   - 1 Bishop Spouse (Rev. Damaris Makau)');
        $this->command->info('   - 5 Regional Overseers');
        $this->command->info('');
        $this->command->info('🏛️  Diocese Council Composition:');
        $this->command->info('   - Bishop Peter Kilonzo (Chairman)');
        $this->command->info('   - 5 Regional Overseers (Members)');
        $this->command->info('   Total: 6 Council Members');
        $this->command->info('');
        $this->command->info('🎯 Next Steps:');
        $this->command->info('   - Seed sample pastors for churches');
        $this->command->info('   - Assign permissions to regional and church roles');
    }

    private function createBishop($diocese)
    {
        $bishopRole = Role::where('name', 'Bishop')->first();

        $bishop = User::firstOrCreate(
            ['email' => 'bishop@makueniwestdiocese.or.ke'],
            [
                'firstname' => 'Peter',
                'lastname' => 'Kilonzo',
                'username' => 'bishop.kilonzo',
                'password' => Hash::make('password'),
                'phone' => '+254726285695',
                'position' => 'Bishop',
                'role_id' => $bishopRole->id,
                'status' => 'active',
                'employee_code' => '100001',
                'pin' => Hash::make('1234'),
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
                'can_see_siblings' => true,
                'can_manage_users' => true,
                'can_manage_finances' => true,
                'assignment_reason' => 'Makueni West Diocese Bishop',
                'assigned_by' => 1, // Super admin
                'approved_by' => 1,
                'approved_at' => now(),
            ]
        );

        $this->command->info("✅ Bishop: Peter Kilonzo");
        $this->command->line("   - Email: bishop@makueniwestdiocese.or.ke");
        $this->command->line("   - Employee Code: 100001");
        $this->command->line("   - Username: bishop.kilonzo");

        return $bishop;
    }

    private function createBishopSpouse($diocese, $bishop)
    {
        $associatePastorRole = Role::where('name', 'Associate Pastor')->first();

        $spouse = User::firstOrCreate(
            ['email' => 'damaris.makau@makueniwestdiocese.or.ke'],
            [
                'firstname' => 'Damaris',
                'lastname' => 'Makau',
                'username' => 'rev.damaris',
                'password' => Hash::make('password'),
                'phone' => '+254724117533',
                'position' => 'Reverend (Bishop Spouse)',
                'role_id' => $associatePastorRole->id,
                'status' => 'active',
                'employee_code' => '100002',
                'pin' => Hash::make('1234'),
            ]
        );

        $spouse->assignRole('Associate Pastor');

        UserTerritoryAssignment::firstOrCreate(
            [
                'user_id' => $spouse->id,
                'territory_id' => $diocese->id,
                'role_id' => $associatePastorRole->id,
            ],
            [
                'assignment_type' => AssignmentType::SECONDARY,
                'can_see_children' => true,
                'can_see_siblings' => false,
                'can_manage_users' => false,
                'can_manage_finances' => false,
                'assignment_reason' => 'Bishop Spouse - Reverend',
                'assigned_by' => $bishop->id,
                'approved_by' => $bishop->id,
                'approved_at' => now(),
            ]
        );

        $this->command->info("✅ Bishop Spouse: Rev. Damaris Makau");
        $this->command->line("   - Email: damaris.makau@makueniwestdiocese.or.ke");
        $this->command->line("   - Employee Code: 100002");

        return $spouse;
    }

    private function createRegionalOverseer($data, $region, $diocese, $bishop, $index)
    {
        $regionalOverseerRole = Role::where('name', 'Regional Overseer')->first();
        $employeeCode = 200001 + $index;

        $username = strtolower($data['firstname'] . '.' . explode(' ', $data['lastname'])[0]);
        $email = strtolower($data['firstname'] . '.' . explode(' ', $data['lastname'])[0]) . '@makueniwestdiocese.or.ke';

        $overseer = User::firstOrCreate(
            ['email' => $email],
            [
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'username' => $username,
                'password' => Hash::make('password'),
                'phone' => '+254' . substr($data['phone'], 1),
                'position' => 'Regional Overseer - ' . $region->name,
                'role_id' => $regionalOverseerRole->id,
                'status' => 'active',
                'employee_code' => (string)$employeeCode,
                'pin' => Hash::make('1234'),
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
                'can_manage_finances' => true,
                'assignment_reason' => "Regional Overseer for {$region->name}",
                'assigned_by' => $bishop->id,
                'approved_by' => $bishop->id,
                'approved_at' => now(),
            ]
        );

        $this->command->info("✅ {$data['title']} {$data['firstname']} {$data['lastname']}");
        $this->command->line("   - Region: {$region->name}");
        $this->command->line("   - Email: {$email}");
        $this->command->line("   - Employee Code: {$employeeCode}");

        return $overseer;
    }
}
