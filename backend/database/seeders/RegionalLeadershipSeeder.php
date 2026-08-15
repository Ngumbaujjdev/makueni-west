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

class RegionalLeadershipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🏛️  REGIONAL LEADERSHIP SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Seeding Regional Committees (Overseer + Spouse + All Pastors)...');
        $this->command->info('');

        $diocese = Territory::where('territory_type', TerritoryType::DIOCESE)->first();

        // Get all 5 regions
        $regions = Territory::where('territory_type', TerritoryType::REGION)
            ->orderBy('code')
            ->get();

        if ($regions->count() !== 5) {
            $this->command->error("❌ Expected 5 regions but found {$regions->count()}!");
            return;
        }

        $this->command->info("📍 Found {$regions->count()} regions");
        $this->command->info('');

        $totalPastorsCreated = 0;
        $totalCommitteeMembers = 0;

        // === PROCESS EACH REGION ===
        foreach ($regions as $index => $region) {
            $regionNumber = $index + 1;

            $this->command->info('');
            $this->command->info(str_repeat('=', 70));
            $this->command->info("📍 REGION {$regionNumber}/5: {$region->name}");
            $this->command->info(str_repeat('=', 70));

            // Get Regional Overseer for this region
            $overseer = User::whereHas('territoryAssignments', function($query) use ($region) {
                $query->where('territory_id', $region->id)
                      ->whereHas('role', function($q) {
                          $q->where('name', 'Regional Overseer');
                      });
            })->first();

            if (!$overseer) {
                $this->command->error("   ❌ Regional Overseer not found for {$region->name}!");
                continue;
            }

            $this->command->info("✅ Regional Overseer: {$overseer->firstname} {$overseer->lastname}");

            // Get churches in this region
            $churches = Territory::where('territory_type', TerritoryType::CHURCH)
                ->where('parent_territory_id', $region->id)
                ->get();

            $this->command->info("   Churches in region: {$churches->count()}");
            $this->command->info('');

            // Seed sample pastors for this region
            $pastorsData = $this->getPastorsForRegion($region->code);

            $regionCommitteeMembers = [];
            $employeeCodeBase = $this->getEmployeeCodeBase($region->code);

            foreach ($pastorsData as $pastorIndex => $pastorData) {
                // Get a church for this pastor
                $church = $churches->skip($pastorIndex)->first();

                if (!$church) {
                    break;
                }

                $employeeCode = $employeeCodeBase + $pastorIndex;
                $pastor = $this->createPastor($pastorData, $church, $region, $diocese, $overseer, $employeeCode);

                if ($pastor) {
                    $regionCommitteeMembers[] = $pastor;
                    $totalPastorsCreated++;
                }
            }

            // Count committee members for this region
            $committeeCount = 2 + count($regionCommitteeMembers);
            $totalCommitteeMembers += $committeeCount;

            $this->command->info('');
            $this->command->info("✅ {$region->name} Committee:");
            $this->command->line("   - Regional Overseer: 1");
            $this->command->line("   - Overseer Spouse: 1");
            $this->command->line("   - Pastors (Committee Members): " . count($regionCommitteeMembers));
            $this->command->line("   - Total Committee Size: {$committeeCount}");
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ REGIONAL LEADERSHIP SEEDED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Regions Processed: {$regions->count()}");
        $this->command->info("   - Pastors Created: {$totalPastorsCreated}");
        $this->command->info("   - Total Regional Committee Members: ~{$totalCommitteeMembers}");
        $this->command->info('');
        $this->command->info('🏛️  Regional Committee Structure:');
        $this->command->info('   Each region has: Overseer + Spouse + All Pastors in region');
        $this->command->info('');
        $this->command->info('🎯 Next Steps:');
        $this->command->info('   - Assign church-level permissions to pastor roles');
        $this->command->info('   - Seed remaining pastors for all churches');
    }

    private function getPastorsForRegion($regionCode)
    {
        $pastorsData = [
            'CCI-MWD-SHR' => [
                [
                    'firstname' => 'Benson',
                    'lastname' => 'Manoo',
                    'title' => 'Rev.',
                    'phone' => '0722911402',
                    'spouse_name' => 'Apphia Somba',
                    'spouse_phone' => '0722920871',
                ],
                [
                    'firstname' => 'Stephen',
                    'lastname' => 'Mutisya',
                    'title' => 'Pst.',
                    'phone' => '0720428202',
                    'spouse_name' => 'Miriam Stephen',
                    'spouse_phone' => '0720325704',
                ],
                [
                    'firstname' => 'Robinson',
                    'lastname' => 'Nganda',
                    'title' => 'Rev.',
                    'phone' => '0720644512',
                    'spouse_name' => 'Catherine Kioko',
                    'spouse_phone' => '0724652911',
                ],
            ],
            'CCI-MWD-KLG' => [
                [
                    'firstname' => 'Mark',
                    'lastname' => 'Muema',
                    'title' => 'Pst.',
                    'phone' => '0712433882',
                    'spouse_name' => 'Martha Mark',
                    'spouse_phone' => '0713447991',
                ],
                [
                    'firstname' => 'Justus',
                    'lastname' => 'Munyao',
                    'title' => 'Rev.',
                    'phone' => '0726773340',
                    'spouse_name' => 'Angeline Kiio',
                    'spouse_phone' => '0720300599',
                ],
            ],
            'CCI-MWD-KLB' => [
                [
                    'firstname' => 'Titus',
                    'lastname' => 'Nthumbi',
                    'title' => 'Pst.',
                    'phone' => '0726377157',
                    'spouse_name' => 'Princinia Nzomo',
                    'spouse_phone' => '072028907',
                ],
                [
                    'firstname' => 'John',
                    'lastname' => 'Ngala',
                    'title' => 'Rev.',
                    'phone' => '0710910813',
                    'spouse_name' => 'Angeline John',
                    'spouse_phone' => '0726086042',
                ],
                [
                    'firstname' => 'David',
                    'lastname' => 'Kanyenyea',
                    'title' => 'Pst.',
                    'phone' => '0714895963',
                    'spouse_name' => 'Veronicah David',
                    'spouse_phone' => '0705932727',
                ],
            ],
            'CCI-MWD-KJS' => [
                [
                    'firstname' => 'Stephen',
                    'lastname' => 'Wambua',
                    'title' => 'Ev.',
                    'phone' => '0790503608',
                    'spouse_name' => 'Eunice Wambua',
                    'spouse_phone' => '0705018548',
                ],
                [
                    'firstname' => 'Julius',
                    'lastname' => 'Mutua',
                    'title' => 'Pst.',
                    'phone' => '0724243254',
                    'spouse_name' => 'Catherine Mutua',
                    'spouse_phone' => '0794835188',
                ],
            ],
            'CCI-MWD-MML' => [
                [
                    'firstname' => 'Alice',
                    'lastname' => 'Muia',
                    'title' => 'Pst.',
                    'phone' => '0715315690',
                    'spouse_name' => 'Philip Kimeu',
                    'spouse_phone' => '0736446985',
                ],
                [
                    'firstname' => 'Zacchaeus',
                    'lastname' => 'Kavai',
                    'title' => 'Rev.',
                    'phone' => '0711882906',
                    'spouse_name' => 'Deceased',
                    'spouse_phone' => null,
                ],
                [
                    'firstname' => 'Jairus',
                    'lastname' => 'Nzuki',
                    'title' => 'Pst.',
                    'phone' => '0707083042',
                    'spouse_name' => 'Beatrice Muinde',
                    'spouse_phone' => '0798392016',
                ],
            ],
        ];

        return $pastorsData[$regionCode] ?? [];
    }

    private function getEmployeeCodeBase($regionCode)
    {
        $bases = [
            'CCI-MWD-SHR' => 300000,
            'CCI-MWD-KLG' => 301000,
            'CCI-MWD-KLB' => 302000,
            'CCI-MWD-KJS' => 303000,
            'CCI-MWD-MML' => 304000,
        ];

        return $bases[$regionCode] ?? 399000;
    }

    private function createPastor($data, $church, $region, $diocese, $overseer, $employeeCode)
    {
        $seniorPastorRole = Role::where('name', 'Senior Pastor')->first();
        $regionalCommitteeMemberRole = Role::where('name', 'Regional Committee Member')->first();
        $dioceseCouncilMemberRole = Role::where('name', 'Diocese Council Member')->first();

        $lastnameParts = explode(' ', $data['lastname']);
        $firstLastname = $lastnameParts[0];

        $username = strtolower($data['firstname'] . '.' . $firstLastname);
        $email = strtolower($data['firstname'] . '.' . $firstLastname) . '@makueniwestdiocese.or.ke';

        try {
            $pastor = User::firstOrCreate(
                ['email' => $email],
                [
                    'firstname' => $data['firstname'],
                    'lastname' => $data['lastname'],
                    'username' => $username,
                    'password' => Hash::make('password'),
                    'phone' => '+254' . substr($data['phone'], 1),
                    'position' => 'Senior Pastor - ' . $church->name,
                    'role_id' => $seniorPastorRole->id,
                    'status' => 'active',
                    'employee_code' => (string)$employeeCode,
                    'pin' => Hash::make('1234'),
                ]
            );

            $pastor->assignRole('Senior Pastor');

            // Self-heal: remove the old, incorrectly-scoped secondary assignment
            // (Senior Pastor role at the Region territory) from before this fix —
            // firstOrCreate below won't touch it since it's a different role_id at
            // the same territory, which would otherwise leave a stale duplicate.
            UserTerritoryAssignment::where('user_id', $pastor->id)
                ->where('territory_id', $region->id)
                ->where('role_id', $seniorPastorRole->id)
                ->where('assignment_type', AssignmentType::SECONDARY)
                ->delete();

            // Primary assignment: Church level
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
                    'can_manage_finances' => true,
                    'assignment_reason' => "Senior Pastor for {$church->name}",
                    'assigned_by' => $overseer->id,
                    'approved_by' => $overseer->id,
                    'approved_at' => now(),
                ]
            );

            // Secondary assignment: Regional Committee Member — was wrongly reusing
            // the Senior Pastor role_id (church-scoped permissions) at a region
            // territory, which resolves to zero usable permissions once switch-role
            // actually respects which assignment is active. Regional Committee
            // Member is the real, distinct, region-scoped role for this seat.
            $pastor->assignRole('Regional Committee Member');
            UserTerritoryAssignment::firstOrCreate(
                [
                    'user_id' => $pastor->id,
                    'territory_id' => $region->id,
                    'role_id' => $regionalCommitteeMemberRole->id,
                ],
                [
                    'assignment_type' => AssignmentType::SECONDARY,
                    'can_see_children' => true,
                    'can_see_siblings' => true,
                    'can_manage_users' => false,
                    'can_manage_finances' => false,
                    'assignment_reason' => "Regional Committee Member for {$region->name}",
                    'assigned_by' => $overseer->id,
                    'approved_by' => $overseer->id,
                    'approved_at' => now(),
                ]
            );

            // Tertiary assignment: Diocese Council Member — every pastor also sits
            // on the diocese council ex-officio, same role Regional Overseers hold
            // for their diocese seat (see DioceseLeadershipSeeder).
            $pastor->assignRole('Diocese Council Member');
            UserTerritoryAssignment::firstOrCreate(
                [
                    'user_id' => $pastor->id,
                    'territory_id' => $diocese->id,
                    'role_id' => $dioceseCouncilMemberRole->id,
                ],
                [
                    'assignment_type' => AssignmentType::SECONDARY,
                    'can_see_children' => true,
                    'can_see_siblings' => true,
                    'can_manage_users' => false,
                    'can_manage_finances' => false,
                    'assignment_reason' => "Diocese Council Member (ex-officio as pastor)",
                    'assigned_by' => $overseer->id,
                    'approved_by' => $overseer->id,
                    'approved_at' => now(),
                ]
            );

            $this->command->info("   ✅ {$data['title']} {$data['firstname']} {$data['lastname']}");
            $this->command->line("      - Church: {$church->name}");
            $this->command->line("      - Employee Code: {$employeeCode}");
            $this->command->line("      - Email: {$email}");

            return $pastor;

        } catch (\Exception $e) {
            $this->command->error("   ❌ Failed to create {$data['firstname']} {$data['lastname']}: " . $e->getMessage());
            return null;
        }
    }
}
