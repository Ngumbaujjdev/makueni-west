<?php

namespace Tests\Feature\Settings;

use App\Models\Church;
use App\Models\Diocese;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\SuperAdminConfig;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\Settings\SettingsRegistry;

/**
 * A diocese with one region holding two churches, plus: the church's
 * pastor (changes Settings), its secretary (looks), the other church's
 * pastor, the region's overseer, the bishop and a global admin - with the
 * {level}.settings.hub.* permissions SettingsHubSeeder hands out, for every
 * section in config/settings.php.
 */
trait BuildsSettingsWorld
{
    protected Diocese $diocese;

    protected Region $region;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected User $pastor;

    protected User $secretary;

    protected User $otherPastor;

    protected User $overseer;

    protected User $bishop;

    protected User $globalAdmin;

    protected function buildSettingsWorld(): void
    {
        $this->diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $this->region = Region::create(['name' => 'Region A', 'code' => 'T-RA', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);

        $this->pastor = $this->settingsUser('pastor', 'Test Senior Pastor', 'church', $this->myChurch->id, ['read', 'update']);
        $this->secretary = $this->settingsUser('secretary', 'Test Church Secretary', 'church', $this->myChurch->id, ['read']);
        $this->otherPastor = $this->settingsUser('otherpastor', 'Test Senior Pastor', 'church', $this->otherChurch->id, ['read', 'update']);
        $this->overseer = $this->settingsUser('overseer', 'Test Regional Overseer', 'region', $this->region->id, ['read', 'update']);
        $this->bishop = $this->settingsUser('bishop', 'Test Bishop', 'diocese', $this->diocese->id, ['read', 'update']);
        $this->globalAdmin = $this->settingsUser('admin', 'Test Global Administrator', 'diocese', $this->diocese->id, []);
        SuperAdminConfig::create(['user_id' => $this->globalAdmin->id, 'primary_territory_id' => $this->diocese->id, 'global_access' => true]);
    }

    /** A user whose role holds the given actions on every section of its level. */
    protected function settingsUser(string $username, string $roleName, string $level, int $territoryId, array $actions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['territory_level' => $level]);
        foreach (array_keys(SettingsRegistry::sections($level)) as $section) {
            foreach ($actions as $action) {
                $role->givePermissionTo(Permission::firstOrCreate(
                    ['name' => SettingsRegistry::permission($level, $section, $action), 'guard_name' => 'web'],
                    ['action' => $action, 'territory_scope' => $level],
                ));
            }
        }
        $user = User::create([
            'firstname' => 'Test', 'lastname' => ucfirst($username), 'username' => "test.{$username}",
            'email' => "test.{$username}@example.test", 'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }
}
