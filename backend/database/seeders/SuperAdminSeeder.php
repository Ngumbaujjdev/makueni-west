<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Territory;
use App\Models\Diocese;
use App\Models\UserTerritoryAssignment;
use App\Models\SuperAdminConfig;
use App\Enums\TerritoryType;
use App\Enums\AssignmentType;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // First create the roles for the diocese system
        $this->createDioceseRoles();

        // Get the Global Admin role and Diocese
        $globalAdminRole = Role::firstOrCreate(['name' => 'Global Administrator', 'guard_name' => 'web']);
        $global = Territory::where('territory_type', TerritoryType::GLOBAL)->first();
        $diocese = Territory::where('code', 'CCI-MWD')->first();

        if (!$diocese) {
            $this->command->error('Diocese not found! Please run DioceseSeeder first.');
            return;
        }

        // Create Super Admin User - Joshua John
        $superAdmin = User::create([
            'firstname' => 'Joshua',
            'lastname' => 'John',
            'username' => 'jjohn.admin',
            'email' => 'joshujohn03@gmail.com',
            'password' => Hash::make('password'),
            'phone' => '+254700000001',
            'position' => 'System Administrator',
            'role_id' => $globalAdminRole->id,
            'status' => 'active',
            'employee_code' => '000001', // 6-digit code for PIN login
            'pin' => Hash::make('123456'), // PIN as requested
            'pin_changed_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        // Assign Global Administrator role using Spatie
        $superAdmin->assignRole('Global Administrator');

        // Configure super admin settings
        SuperAdminConfig::create([
            'user_id' => $superAdmin->id,
            'primary_territory_id' => $diocese->id, // Based at Makueni West Diocese
            'global_access' => true,
            'default_territory_type' => TerritoryType::DIOCESE->value,
            'preferences' => [
                'dashboard_view' => 'diocese_overview',
                'default_reports' => ['financial_summary', 'membership_overview'],
                'notifications' => [
                    'email' => true,
                    'sms' => true,
                    'system' => true
                ],
                'ui_theme' => 'light',
                'items_per_page' => 20
            ]
        ]);

        // Create territory assignment for global access
        UserTerritoryAssignment::create([
            'user_id' => $superAdmin->id,
            'territory_id' => $global->id,
            'role_id' => $globalAdminRole->id,
            'assignment_type' => AssignmentType::PRIMARY,
            'can_see_children' => true,
            'can_see_siblings' => true,
            'can_manage_users' => true,
            'can_manage_finances' => true,
            'assignment_reason' => 'System Administrator with global access',
            'assigned_by' => $superAdmin->id,
            'approved_by' => $superAdmin->id,
            'approved_at' => now(),
        ]);

        $this->command->info('✅ Super Admin created successfully:');
        $this->command->info("   Name: Joshua John");
        $this->command->info("   Email: joshujohn03@gmail.com");
        $this->command->info("   Username: jjohn.admin");
        $this->command->info("   Employee Code: 000001");
        $this->command->info("   Primary Territory: {$diocese->name}");
        $this->command->info("   Global Access: ✅ Enabled");
    }

    /**
     * Create diocese-specific roles (without description field)
     */
    private function createDioceseRoles(): void
    {
        // territory_level drives both the Role Management admin UI's filter AND
        // real module/sidebar visibility (RoleController, ModuleController both key
        // off it) — every role needs one except Global Administrator, which bypasses
        // territory scoping entirely via SuperAdminConfig's global_access flag.
        $roles = [
            // Global Level
            'Global Administrator' => null,

            // Diocese Level
            'Bishop' => 'diocese',
            'Diocese Council Member' => 'diocese',
            'Diocese Secretary' => 'diocese',
            'Diocese Treasurer' => 'diocese',
            'Diocese Administrator' => 'diocese',

            // Regional Level
            'Regional Overseer' => 'region',
            'Regional Secretary' => 'region',

            // Subregional Level
            'Subregional Overseer' => 'subregion',

            // Church Level
            'Senior Pastor' => 'church',
            'Associate Pastor' => 'church',
            'Church Secretary' => 'church',
            'Church Treasurer' => 'church',
            'Church Committee Member' => 'church',
            'Elder' => 'church',
            'Deacon' => 'church',

            // Ministry Leaders
            'Youth Leader' => 'church',
            "Women's Ministry Leader" => 'church',
            "Men's Ministry Leader" => 'church',
            "Children's Ministry Leader" => 'church',
            'Music Director' => 'church',
        ];

        foreach ($roles as $roleName => $territoryLevel) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web'
            ]);

            // firstOrCreate only sets attributes on the INSERT path, so on a
            // database that already has these roles (created before this fix),
            // territory_level needs to be applied on every run, not just once.
            if ($role->territory_level !== $territoryLevel) {
                $role->territory_level = $territoryLevel;
                $role->save();
            }
        }

        $this->command->info('✅ Diocese roles created successfully');
    }
}