<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes Damaris and Benson Manoo to have Diocese Council Member as PRIMARY
     */
    public function up(): void
    {
        // Get role IDs
        $dioceseCouncilRoleId = DB::table('roles')->where('name', 'Diocese Council Member')->value('id');
        $associatePastorRoleId = DB::table('roles')->where('name', 'Associate Pastor')->value('id');
        $seniorPastorRoleId = DB::table('roles')->where('name', 'Senior Pastor')->value('id');

        if (!$dioceseCouncilRoleId) {
            echo "\n❌ Diocese Council Member role not found!\n";
            return;
        }

        // Get diocese ID
        $dioceseId = DB::table('territories')
            ->where('territory_type', 'diocese')
            ->where('code', 'CCI-MWD')
            ->value('id');

        if (!$dioceseId) {
            echo "\n❌ Diocese not found!\n";
            return;
        }

        // === FIX DAMARIS MAKAU (Bishop Spouse) ===
        echo "\n✅ Fixing Damaris Makau (Bishop Spouse)...\n";

        $damaris = DB::table('users')->where('employee_code', '100002')->first();

        if ($damaris) {
            // Delete old assignments
            DB::table('user_territory_assignments')
                ->where('user_id', $damaris->id)
                ->delete();

            // Create PRIMARY assignment: Diocese Council Member at diocese level
            DB::table('user_territory_assignments')->insert([
                'user_id' => $damaris->id,
                'territory_id' => $dioceseId,
                'role_id' => $dioceseCouncilRoleId,
                'assignment_type' => 'primary',
                'can_see_children' => true,
                'can_see_siblings' => true,
                'can_manage_users' => false,
                'can_manage_finances' => false,
                'assignment_reason' => 'Diocese Council Member (Bishop Spouse)',
                'assigned_by' => 1,
                'approved_by' => 1,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create SECONDARY assignment: Associate Pastor at diocese level
            if ($associatePastorRoleId) {
                DB::table('user_territory_assignments')->insert([
                    'user_id' => $damaris->id,
                    'territory_id' => $dioceseId,
                    'role_id' => $associatePastorRoleId,
                    'assignment_type' => 'secondary',
                    'can_see_children' => true,
                    'can_see_siblings' => false,
                    'can_manage_users' => false,
                    'can_manage_finances' => false,
                    'assignment_reason' => 'Bishop Spouse - Reverend (Secondary Assignment)',
                    'assigned_by' => 1,
                    'approved_by' => 1,
                    'approved_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update her user record
            DB::table('users')
                ->where('id', $damaris->id)
                ->update([
                    'role_id' => $dioceseCouncilRoleId,
                    'position' => 'Diocese Council Member (Bishop Spouse)',
                    'updated_at' => now(),
                ]);

            // Update Spatie role
            DB::table('model_has_roles')
                ->where('model_id', $damaris->id)
                ->where('model_type', 'App\\Models\\User')
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id' => $dioceseCouncilRoleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $damaris->id,
            ]);

            echo "   ✅ Damaris Makau updated successfully\n";
            echo "      - PRIMARY: Diocese Council Member (diocese level)\n";
            echo "      - SECONDARY: Associate Pastor (diocese level)\n";
        } else {
            echo "   ❌ Damaris Makau not found!\n";
        }

        // === FIX BENSON MANOO ===
        echo "\n✅ Fixing Benson Manoo...\n";

        $benson = DB::table('users')->where('employee_code', '300000')->first();

        if ($benson && $seniorPastorRoleId) {
            // Get his church assignment using JOIN instead of whereHas
            $bensonChurchAssignment = DB::table('user_territory_assignments as uta')
                ->join('territories as t', 'uta.territory_id', '=', 't.id')
                ->where('uta.user_id', $benson->id)
                ->where('t.territory_type', 'church')
                ->select('uta.*')
                ->first();

            if (!$bensonChurchAssignment) {
                // Find first church in Shariani region (where Benson should be)
                $bensonChurch = DB::table('territories as t1')
                    ->join('territories as t2', 't1.parent_territory_id', '=', 't2.id')
                    ->where('t1.territory_type', 'church')
                    ->where('t2.code', 'CCI-MWD-SHR')
                    ->select('t1.*')
                    ->first();

                if (!$bensonChurch) {
                    echo "   ❌ Could not find Benson's church!\n";
                    return;
                }

                $churchId = $bensonChurch->id;
            } else {
                $churchId = $bensonChurchAssignment->territory_id;
            }

            // Delete old assignments
            DB::table('user_territory_assignments')
                ->where('user_id', $benson->id)
                ->delete();

            // Create PRIMARY assignment: Diocese Council Member at diocese level
            DB::table('user_territory_assignments')->insert([
                'user_id' => $benson->id,
                'territory_id' => $dioceseId,
                'role_id' => $dioceseCouncilRoleId,
                'assignment_type' => 'primary',
                'can_see_children' => true,
                'can_see_siblings' => true,
                'can_manage_users' => false,
                'can_manage_finances' => false,
                'assignment_reason' => 'Diocese Council Member (ex-officio Pastor)',
                'assigned_by' => 1,
                'approved_by' => 1,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create SECONDARY assignment: Senior Pastor at church level
            DB::table('user_territory_assignments')->insert([
                'user_id' => $benson->id,
                'territory_id' => $churchId,
                'role_id' => $seniorPastorRoleId,
                'assignment_type' => 'secondary',
                'can_see_children' => false,
                'can_see_siblings' => false,
                'can_manage_users' => false,
                'can_manage_finances' => true,
                'assignment_reason' => 'Senior Pastor (Secondary Assignment)',
                'assigned_by' => 1,
                'approved_by' => 1,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update his user record
            DB::table('users')
                ->where('id', $benson->id)
                ->update([
                    'role_id' => $dioceseCouncilRoleId,
                    'position' => 'Diocese Council Member (Pastor)',
                    'updated_at' => now(),
                ]);

            // Update Spatie role
            DB::table('model_has_roles')
                ->where('model_id', $benson->id)
                ->where('model_type', 'App\\Models\\User')
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id' => $dioceseCouncilRoleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $benson->id,
            ]);

            echo "   ✅ Benson Manoo updated successfully\n";
            echo "      - PRIMARY: Diocese Council Member (diocese level)\n";
            echo "      - SECONDARY: Senior Pastor (church level)\n";
        } else {
            echo "   ❌ Benson Manoo not found or Senior Pastor role missing!\n";
        }

        echo "\n✅ Committee member role assignments fixed successfully!\n\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert changes if needed
        $damaris = DB::table('users')->where('employee_code', '100002')->first();
        $benson = DB::table('users')->where('employee_code', '300000')->first();

        if ($damaris) {
            $associatePastorRoleId = DB::table('roles')->where('name', 'Associate Pastor')->value('id');

            DB::table('users')->where('id', $damaris->id)->update([
                'role_id' => $associatePastorRoleId,
                'position' => 'Reverend (Bishop Spouse)',
            ]);
        }

        if ($benson) {
            $seniorPastorRoleId = DB::table('roles')->where('name', 'Senior Pastor')->value('id');

            DB::table('users')->where('id', $benson->id)->update([
                'role_id' => $seniorPastorRoleId,
                'position' => 'Senior Pastor',
            ]);
        }
    }
};
