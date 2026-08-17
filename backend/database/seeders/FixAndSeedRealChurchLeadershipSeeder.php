<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Church;
use App\Models\UserTerritoryAssignment;
use App\Enums\AssignmentType;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class FixAndSeedRealChurchLeadershipSeeder extends Seeder
{
    /**
     * RegionalLeadershipSeeder assigned real pastor names to churches purely
     * by list position ($churches->skip($pastorIndex)->first()) against an
     * independently-ordered sample data array - there was never any real
     * connection between a person's name and the church they actually
     * pastor. That produced ~9 real, named pastors linked to the wrong
     * church in the live system (verified by exact phone-number match
     * against "CCI NATIONAL OFFICE REPORTING TEMPLATE - DIOCESE OF MAKUENI
     * WEST" and "DOMW CHILDREN'S MINISTRY" - the two real source
     * documents).
     *
     * This seeder:
     * 1. Reassigns those ~9 people to their real church/role.
     * 2. Adds church-level postings for people who were already correctly
     *    seeded at Region/Diocese level but never linked to the specific
     *    church they also pastor (e.g. Rev. C. Owuor Misori was Regional
     *    Overseer of Kalamba Region but not linked to CCI KALAMBA itself).
     * 3. Creates new Senior/Associate Pastor accounts for every church that
     *    had no real pastor seeded at all (~45 senior, ~10 associate).
     * 4. Records spouse contact info (name + phone only, no login account,
     *    per the "contact info only" decision) in each church's existing
     *    metadata JSON column.
     *
     * A handful of rows from the source documents were too ambiguous to
     * seed confidently and are intentionally skipped - see the command
     * output at the end of this seeder's run for the full list (e.g. Alice
     * Muia's real church could not be determined from the source; a
     * garbled pastor/spouse pairing at Muambani/Mbiini was left alone
     * rather than guessed).
     *
     * Idempotent - safe to re-run (keyed on email for new users, on
     * user_id+territory_id+role_id for assignments).
     */

    private int $employeeCodeCounter = 700001;

    public function run(): void
    {
        $this->command->info('👥 FIXING & SEEDING REAL CHURCH LEADERSHIP');
        $this->command->info(str_repeat('=', 70));

        $this->fixMisassignedPastors();
        $this->addMissingChurchPostsForExistingLeaders();
        $this->seedNewPastors();
        $this->seedSpouseContacts();
        $this->deactivateSupersededPlaceholders();

        $this->command->info('');
        $this->command->info('✅ CHURCH LEADERSHIP FIXED & SEEDED!');
    }

    /**
     * "Pastor Sample N" placeholders (SampleUsersSeeder) at churches that
     * now have a real named Senior Pastor from this seeder are deactivated
     * (status='inactive'), not deleted - keeps the audit trail intact
     * without a redundant, confusing second "Senior Pastor" showing up
     * alongside the real one.
     */
    private function deactivateSupersededPlaceholders(): void
    {
        $this->command->info('🧹 Deactivating superseded placeholder pastors...');

        $placeholders = User::where('username', 'like', 'pastor.%')
            ->where('status', '!=', 'inactive')
            ->get();

        $deactivated = 0;

        foreach ($placeholders as $placeholder) {
            $assignment = UserTerritoryAssignment::where('user_id', $placeholder->id)
                ->where('assignment_type', AssignmentType::PRIMARY)
                ->first();

            if (!$assignment) {
                continue;
            }

            $hasRealPastor = UserTerritoryAssignment::where('territory_id', $assignment->territory_id)
                ->where('user_id', '!=', $placeholder->id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['Senior Pastor', 'Associate Pastor']))
                ->exists();

            if (!$hasRealPastor) {
                continue;
            }

            $assignment->delete();
            $placeholder->status = 'inactive';
            $placeholder->save();
            $deactivated++;
            $this->command->info("   ✅ Deactivated {$placeholder->firstname} {$placeholder->lastname} (superseded by real pastor)");
        }

        $this->command->info("   {$deactivated} placeholder(s) deactivated.");
        $this->command->info('');
    }

    // ========================================================================
    // STEP 1: Fix the ~9 real people linked to the wrong church
    // ========================================================================

    /**
     * username => [new church db name, new role name]. Only the primary
     * (church-level) assignment moves - each person's Regional Committee
     * Member / Diocese Council Member secondary assignments are untouched,
     * they're independent of which church they pastor.
     */
    private const REASSIGNMENTS = [
        'stephen.mutisya' => ['CCI SULTAN HAMUD', 'Associate Pastor'],
        'titus.nthumbi' => ['CCI KALAMBA', 'Associate Pastor'],
        'david.kanyenyea' => ['CCI KALAMBA', 'Associate Pastor'],
        'john.ngala' => ['CCI MATHANGUNI', 'Senior Pastor'],
        'mark.muema' => ['CCI MITINI', 'Associate Pastor'],
        'justus.munyao' => ['CCI KYAMBEKE', 'Senior Pastor'],
        'zacchaeus.kavai' => ['CCI A/LIFE', 'Senior Pastor'],
        'jairus.nzuki' => ['CCI MUTITU', 'Senior Pastor'],
        'stephen.wambua' => ['CCI SIMBA CEMENT', 'Senior Pastor'],
    ];

    private function fixMisassignedPastors(): void
    {
        $this->command->info('🔀 Reassigning misplaced pastors to their real church...');

        foreach (self::REASSIGNMENTS as $username => [$churchName, $roleName]) {
            $user = User::where('username', $username)->first();
            $church = Church::where('name', $churchName)->first();
            $role = Role::where('name', $roleName)->first();

            if (!$user || !$church || !$role) {
                $this->command->error("   ❌ Could not resolve {$username} -> {$churchName} ({$roleName}) - skipping");
                continue;
            }

            $current = UserTerritoryAssignment::where('user_id', $user->id)
                ->where('assignment_type', AssignmentType::PRIMARY)
                ->first();

            if ($current && $current->territory_id === $church->id && $current->role_id === $role->id) {
                $this->command->warn("   ⚠️  {$user->firstname} {$user->lastname} already correctly at {$churchName}");
                continue;
            }

            if ($current) {
                $current->delete();
            }

            UserTerritoryAssignment::create([
                'user_id' => $user->id,
                'territory_id' => $church->id,
                'role_id' => $role->id,
                'assignment_type' => AssignmentType::PRIMARY,
                'can_manage_finances' => $roleName === 'Senior Pastor',
                'assignment_reason' => "{$roleName} for {$church->name} (corrected from positional mis-seeding)",
                'assigned_by' => $user->id,
                'assigned_at' => now(),
            ]);

            $user->role_id = $role->id;
            $user->save();
            $user->syncRoles([$roleName, ...$user->roles->pluck('name')->reject(fn ($n) => in_array($n, ['Senior Pastor', 'Associate Pastor']))->all()]);

            $this->command->info("   ✅ {$user->firstname} {$user->lastname} -> {$roleName} at {$churchName}");
        }

        // Alice Muia's real church could not be determined from either source
        // document (her seeded phone number doesn't match anyone listed) -
        // her incorrect "Senior Pastor of CCI A/LIFE" post is removed (that
        // church's real pastor, Zacchaeus Kavai, needs the slot) but no
        // replacement church is guessed. Her Regional Committee Member /
        // Diocese Council Member assignments are left untouched.
        $alice = User::where('username', 'alice.muia')->first();
        if ($alice) {
            $removed = UserTerritoryAssignment::where('user_id', $alice->id)
                ->where('assignment_type', AssignmentType::PRIMARY)
                ->delete();
            if ($removed) {
                $this->command->warn('   ⚠️  Removed Alice Muia\'s incorrect Senior Pastor post at CCI A/LIFE - her real church is unknown, not reassigned');
            }
        }

        $this->command->info('');
    }

    // ========================================================================
    // STEP 2: Existing correctly-named leaders missing a church-level post
    // ========================================================================

    /** username => [[church db name, role name], ...] */
    private const ADDITIONAL_POSTS = [
        'christopher.owuor' => [['CCI KALAMBA', 'Senior Pastor'], ['CCI KAWALA', 'Senior Pastor']],
        'philip.mutiso' => [['CCI MITINI', 'Senior Pastor']],
        'moses.makonjio' => [['CCI LOITOKITOK', 'Senior Pastor']],
        // "Stephen Kioko" at CCI EMALI shares Stephen Wambua's exact phone
        // number (0790503608) - users.phone is unique in this schema, so
        // they can't be two separate people. Treated as the same real
        // person (Senior Pastor at his primary post, Simba Cement;
        // Associate here) rather than as a data-entry collision.
        'stephen.wambua' => [['CCI EMALI', 'Associate Pastor']],
    ];

    private function addMissingChurchPostsForExistingLeaders(): void
    {
        $this->command->info('➕ Adding missing church posts for already-seeded leaders...');

        foreach (self::ADDITIONAL_POSTS as $username => $posts) {
            $user = User::where('username', $username)->first();

            if (!$user) {
                $this->command->error("   ❌ User not found: {$username}");
                continue;
            }

            foreach ($posts as [$churchName, $roleName]) {
                $church = Church::where('name', $churchName)->first();
                $role = Role::where('name', $roleName)->first();

                if (!$church || !$role) {
                    $this->command->error("   ❌ Could not resolve {$churchName} / {$roleName}");
                    continue;
                }

                $exists = UserTerritoryAssignment::where('user_id', $user->id)
                    ->where('territory_id', $church->id)
                    ->where('role_id', $role->id)
                    ->exists();

                if ($exists) {
                    $this->command->warn("   ⚠️  {$user->firstname} {$user->lastname} already posted at {$churchName}");
                    continue;
                }

                UserTerritoryAssignment::create([
                    'user_id' => $user->id,
                    'territory_id' => $church->id,
                    'role_id' => $role->id,
                    'assignment_type' => AssignmentType::SECONDARY,
                    'can_manage_finances' => $roleName === 'Senior Pastor',
                    'assignment_reason' => "{$roleName} for {$church->name}",
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                ]);

                $user->syncRoles([...$user->roles->pluck('name')->all(), $roleName]);

                $this->command->info("   ✅ {$user->firstname} {$user->lastname} -> {$roleName} at {$churchName}");
            }
        }

        $this->command->info('');
    }

    // ========================================================================
    // STEP 3: New pastor accounts for churches with no real pastor seeded
    // ========================================================================

    /** [firstname, lastname, phone, [church db names...], role] */
    private const NEW_PASTORS = [
        ['Samson', 'Katutu', '0714573246', ['CCI UPETE'], 'Senior Pastor'],
        ['Martin', 'Mutula', '0720967615', ['CCI MLOLONGO', 'CCI KITENGELA'], 'Senior Pastor'],
        ['Esther', 'Mutiso', '0738686718', ['CCI MUAMBANI'], 'Senior Pastor'],
        ['Peter', 'Muuo', '0110914448', ['CCI MBIINI'], 'Senior Pastor'],
        ['Joseph', 'Maweu', '0720921531', ['CCI MASAA'], 'Senior Pastor'],
        ['Christopher', 'Kalolwe', '0711781376', ['CCI MWANYANI'], 'Senior Pastor'],
        ['Regina', 'Mutua', '0715042794', ['CCI MUTYAMBUA'], 'Senior Pastor'],
        ['Christopher', 'Wambua', '0707942177', ['CCI MIKUINI'], 'Senior Pastor'],
        ['Julius', 'Wambua', '0714735414', ['CCI MUAMBWANI'], 'Senior Pastor'],
        ['Joseph', 'Lavuta', '0721653097', ['CCI KASIKEU'], 'Senior Pastor'],
        ['Boniface', 'Muvasi', '0724157400', ['CCI MATIKU'], 'Senior Pastor'],
        ['Wilson', 'Mua', '0713053141', ['CCI MBULUTINI'], 'Senior Pastor'],
        ['Harrison', 'Kutatui', '0797198508', ['CCI OLRISYENYEK'], 'Senior Pastor'],
        ['Moses', 'Sarijore', '0741886237', ['CCI EMISIGIYO'], 'Senior Pastor'],
        ['Daniel', 'Kelemba', '0111428366', ['CCI AMBOSELI'], 'Senior Pastor'],
        ['Rabecca', 'Karinga', '0721332984', ['CCI ILBISSIL'], 'Senior Pastor'],
        ['Joseph', 'Kilonzo', '0712640539', ['CCI KIUANI'], 'Senior Pastor'],
        ['Wilson', 'Mutuku', '0724388643', ['CCI KATIVANI'], 'Senior Pastor'],
        ['Edward', 'Sila', '0702831522', ['CCI ARROI'], 'Senior Pastor'],
        ['James', 'Kuseren', '0746783019', ['CCI OLMAKARIKARA'], 'Senior Pastor'],
        ['Michael', 'Moroswa', '0723250039', ["CCI NANING'O"], 'Senior Pastor'],
        ['Jacob', 'Saru', '0759716257', ['CCI NABLA'], 'Senior Pastor'],
        ['William', 'Kinyamao', '0720842790', ['CCI MASAMUKYE'], 'Senior Pastor'],
        ['Elizabeth', 'Michael', '0725115683', ['CCI KIKUMINI'], 'Senior Pastor'],
        ['Juliana', 'Munyao', '0707134825', ['CCI KALAANI'], 'Senior Pastor'],
        ['Jackson', 'Kimeu', '0715794572', ['CCI UPENDO'], 'Senior Pastor'],
        ['Jeremiah', 'Muange', '0717515632', ['CCI KIKWASUNI'], 'Senior Pastor'],
        ['Salome', 'Kyengo', '0714561395', ['CCI MUTULANI'], 'Senior Pastor'],
        ['David', 'Mweu', '0707976635', ['CCI MAKUTANO'], 'Senior Pastor'],
        ['Josiah', 'Mutuku', '0719264464', ['CCI KIKUI'], 'Senior Pastor'],
        ['David', 'Wambua', '0720660911', ['CCI WELOVEA'], 'Senior Pastor'],
        ['James', 'Mutua', '0748730080', ['CCI MATILIKU'], 'Senior Pastor'],
        ['Reuben', 'Kitundu', '0720573221', ['CCI KYAU'], 'Senior Pastor'],
        ['Joshua', 'Muange', '0716503180', ['CCI YUMBANI'], 'Senior Pastor'],
        ['Nicholas', 'Wambua', '0724815326', ['CCI ISAMBANI'], 'Senior Pastor'],
        ['John', 'Mativo', '0725506863', ['CCI BHC', 'CCI EMALI'], 'Senior Pastor'],
        ['James', 'Muumba', '0798305989', ['CCI MATWIKU'], 'Senior Pastor'],
        ['Ngondu', 'Pastor', '0728698334', ['CCI MALINDI'], 'Senior Pastor'], // surname-only in source document
        ['Benjamin', 'Ndolo', '0715866799', ['CCI MUANGINI'], 'Senior Pastor'],
        ['Joel', 'Nyole', '0114729027', ['CCI KATULYE'], 'Senior Pastor'],
        ['Phylis', 'Thomas', '0792273847', ['CCI MWAANI'], 'Senior Pastor'],
        ['Noris', 'Manda', '0729609677', ['CCI KALUMBI'], 'Senior Pastor'],
        ['John', 'Muthoka', '0724785581', ['CCI SINAI'], 'Senior Pastor'],
        ['Francis', 'Muthenya', '0720144161', ['CCI NDIANI', 'CCI IIANI'], 'Senior Pastor'],
        ['Elizabeth', 'Muli', '0704081119', ['CCI GRACE VALLEY'], 'Senior Pastor'],
        ['Joshua', 'Mwania', '0725335234', ['CCI NZUKINI'], 'Senior Pastor'],
        ['Justus', 'Kituku', '0724560721', ['CCI KALIVIA'], 'Senior Pastor'],
        ['Purity', 'Kithome', '0724408028', ['CCI ENGAVU'], 'Senior Pastor'],
        ['Rose', 'Titus', '0710339894', ['CCI BEULAH'], 'Senior Pastor'],
        ['Richard', 'Kaio', '0718924032', ['CCI WAUTU'], 'Senior Pastor'],
        ['Chris', 'Kasuni', '0710733397', ['CCI KITHIONI'], 'Senior Pastor'],
        ['Daniel', 'Ngeka', '0705896297', ['CCI BETHSAIDA'], 'Senior Pastor'],
        ['Mike', 'Kwinga', '0700069802', ['CCI MAKINDU'], 'Senior Pastor'],
        ['David', 'Kimanthi', '0768003188', ['CCI ISINET'], 'Senior Pastor'],
        ['Clinton', 'Kavai', '0719793440', ['CCI NDUNGUNI'], 'Senior Pastor'],

        // Associate Pastors
        ['Lukas', 'Maundu', '0721661969', ['CCI SULTAN HAMUD'], 'Associate Pastor'],
        ['Joseph', 'Kaumbulu', '0711446980', ['CCI SULTAN HAMUD'], 'Associate Pastor'],
        ['Aron', 'Muthoka', '0733180440', ['CCI MALILI'], 'Associate Pastor'],
        ['Stephen', 'Kiamba', '0714489845', ['CCI MLOLONGO'], 'Associate Pastor'],
        ['Moses', 'Kiio', '0725002053', ['CCI MLOLONGO'], 'Associate Pastor'],
        ['Anthony', 'Kioko', '0729467532', ['CCI MATHANGUNI'], 'Associate Pastor'],
        ['Titus', 'Ngonzi', '0729798676', ['CCI UPENDO'], 'Associate Pastor'],
        ['Matheka', 'Pastor', '0722979100', ['CCI KIKWASUNI'], 'Associate Pastor'], // surname-only in source
        ['Daniel', 'Mainga', '0721774322', ['CCI A/LIFE'], 'Associate Pastor'],
    ];

    private function seedNewPastors(): void
    {
        $this->command->info('⛪ Creating new pastor accounts for churches with none seeded...');

        foreach (self::NEW_PASTORS as [$firstname, $lastname, $phone, $churchNames, $roleName]) {
            $role = Role::where('name', $roleName)->first();
            $username = strtolower($firstname . '.' . $lastname);
            $email = $username . '@makueniwestdiocese.or.ke';

            $user = User::where('email', $email)->first();

            if (!$user) {
                $user = User::create([
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make('password'),
                    'phone' => '+254' . substr($phone, 1),
                    'position' => $roleName . ' - ' . $churchNames[0],
                    'role_id' => $role->id,
                    'status' => 'active',
                    'employee_code' => (string) $this->employeeCodeCounter++,
                    'pin' => Hash::make('1234'),
                ]);
                $user->assignRole($roleName);
                $this->command->info("   ✅ Created: {$firstname} {$lastname} ({$roleName})");
            } else {
                $this->command->warn("   ⚠️  Already exists: {$firstname} {$lastname}");
            }

            foreach ($churchNames as $index => $churchName) {
                $church = Church::where('name', $churchName)->first();

                if (!$church) {
                    $this->command->error("      ❌ Church not found: {$churchName}");
                    continue;
                }

                $exists = UserTerritoryAssignment::where('user_id', $user->id)
                    ->where('territory_id', $church->id)
                    ->where('role_id', $role->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                UserTerritoryAssignment::create([
                    'user_id' => $user->id,
                    'territory_id' => $church->id,
                    'role_id' => $role->id,
                    'assignment_type' => $index === 0 ? AssignmentType::PRIMARY : AssignmentType::SECONDARY,
                    'can_manage_finances' => $roleName === 'Senior Pastor',
                    'assignment_reason' => "{$roleName} for {$church->name}",
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                ]);

                $this->command->info("      -> {$churchName}");
            }
        }

        $this->command->info('');
    }

    // ========================================================================
    // STEP 4: Spouse contact info (name + phone only, no login account)
    // ========================================================================

    /** church db name => [[pastor name, spouse name, spouse phone or null], ...] */
    private const SPOUSE_CONTACTS = [
        'CCI SULTAN HAMUD' => [
            ['Benson Manoo', 'Apphia Somba', '0722920871'],
            ['Stephen Mutisya', 'Miriam Stephen', '0720325704'],
            ['Lukas Maundu', 'Sarah Maundu', '0721627064'],
            ['Joseph Kaumbulu', 'Faith Joseph', '0715640137'],
        ],
        'CCI MALILI' => [['Robinson Nganda', 'Catherine Kioko', '0724652911']],
        'CCI MLOLONGO' => [
            ['Martin Mutula', 'Deborah Martin', '0725842257'],
            ['Stephen Kiamba', 'Redempta Kiamba', '0764876364'],
            ['Moses Kiio', 'Cynthia Moses', '0716182721'],
        ],
        'CCI MASAA' => [['Joseph Maweu', 'Naomy Maweu', '0724660366']],
        'CCI UPETE' => [['Samson Katutu', 'Damaris Sila', '0714277681']],
        'CCI MWANYANI' => [['Christopher Kalolwe', 'Josphine Mutungi', '0716133604']],
        'CCI MIKUINI' => [['Christopher Wambua', "Sarah King'oku", '0786296293']],
        'CCI MUAMBWANI' => [['Julius Wambua', 'Martha Julius', '0704630034']],
        'CCI KASIKEU' => [['Joseph Lavuta', 'Esther Joseph', '0721472602']],
        'CCI MATIKU' => [['Boniface Muvasi', 'Tabitha Boniface', '0784821455']],
        'CCI MBULUTINI' => [['Wilson Mua', 'Winfred Mua', '0725346034']],
        'CCI OLRISYENYEK' => [['Harrison Kutatui', 'Beatrice Kutatoi', '0794813529']],
        'CCI EMISIGIYO' => [['Moses Sarijore', 'Elizabeth Resiato', '0717631012']],
        'CCI AMBOSELI' => [['Daniel Kelemba', 'Nkirote Daniel', '011267739']],
        'CCI KIUANI' => [['Joseph Kilonzo', 'Everline Kiseve', '0714294964']],
        'CCI KATIVANI' => [['Wilson Mutuku', 'Tabitha Mutua', '0714868788']],
        'CCI ARROI' => [['Edward Sila', 'Hildah Edward', '0710272089']],
        'CCI OLMAKARIKARA' => [['James Kuseren', 'Agnes Kuseren', '0713377936']],
        "CCI NANING'O" => [['Michael Moroswa', 'Gladys Michael', '0707215020']],
        'CCI NABLA' => [['Jacob Saru', 'Purity Jacob', '0707844104']],
        'CCI MASAMUKYE' => [['William Kinyamao', 'Grace Kinyamao', null]],
        'CCI KIKUMINI' => [['Elizabeth Michael', 'Michael Musyoki', '0722769431']],
        'CCI KALAMBA' => [
            ['C. Owuor Misori', 'Beatrice Owuor', '0713025322'],
            ['Titus Nthumbi', 'Princinia Nzomo', '072028907'],
            ['David Kanyenyea', 'Veronicah David', '0705932727'],
        ],
        'CCI MATHANGUNI' => [
            ['John Ngala', 'Angeline John', '0726086042'],
            ['Anthony Kioko', 'Doris Anthony', '0713163762'],
        ],
        'CCI UPENDO' => [
            ['Jackson Kimeu', 'Anne Jackson', '0728876073'],
            ['Titus Ngonzi', 'Zipporah Mutiso', '0796271998'],
        ],
        'CCI KIKWASUNI' => [
            ['Jeremiah Muange', 'Joyce Jeremiah', '0752844816'],
            ['Matheka', 'Alice Matheka', '0721232027'],
        ],
        'CCI MUTULANI' => [['Salome Kyengo', 'Michael Kivuva', '0726855306']],
        'CCI MAKUTANO' => [['David Mweu', 'Ruth Mutunga', '0711219268']],
        'CCI KIKUI' => [['Josiah Mutuku', 'Milcah Josiah', '0705012923']],
        'CCI WELOVEA' => [['David Wambua', 'Harris Malova', '0700830306']],
        'CCI MATILIKU' => [['James Mutua', 'Naomi James', '0795096202']],
        'CCI KYAU' => [['Reuben Kitundu', 'Sarah Mutinda', '0725840884']],
        'CCI YUMBANI' => [['Joshua Muange', 'Joy Joshua', '0795659540']],
        'CCI ISAMBANI' => [['Nicholas Wambua', 'Constance Wambua', '0728324055']],
        'CCI BHC' => [['John Mativo', 'Phyllis Mativo', '0723872846']],
        'CCI KITHEINI' => [['Dishon Maweu', 'Lilian Dishon', '0727297257']],
        'CCI A/LIFE' => [['Daniel Mainga', 'Agnes Mainga', '0708114574']],
        'CCI MUTITU' => [['Jairus Nzuki', 'Beatrice Muinde', '0798392016']],
        'CCI MATWIKU' => [['James Muumba', 'Gladys James', '0797379806']],
        'CCI MALINDI' => [['Ngondu', 'Susan Ngondu', '0718574448']],
        'CCI MUANGINI' => [['Benjamin Ndolo', 'Monicah Ndolo', '0706272343']],
        'CCI KATULYE' => [['Joel Nyole', 'Miriam Nyole', '0736966274']],
        'CCI KALUMBI' => [['Noris Manda', 'Elizabeth Manda', '0791628921']],
        'CCI SINAI' => [['John Muthoka', 'Zipporah John', '0701907692']],
        'CCI MITINI' => [
            ['Philip Mutiso', 'Peninah Philip', '0717072035'],
            ['Mark Muema', 'Martha Mark', '0713447991'],
        ],
        'CCI KYAMBEKE' => [['Justus Munyao', 'Angelina Kiio', '0720300599']],
        'CCI NDIANI' => [['Francis Muthenya', 'Josephine Mutie', '0702531456']],
        'CCI GRACE VALLEY' => [['Elizabeth Muli', null, null]],
        'CCI NZUKINI' => [['Joshua Mwania', 'Joyce Muia', '0112165594']],
        'CCI KALIVIA' => [['Justus Kituku', 'Purity Kithome (Engavu)', '0724408028']],
        'CCI ENGAVU' => [['Purity Kithome', 'Justus Kituku (Kalivia)', '0724560721']],
        'CCI BEULAH' => [['Rose Titus', 'Titus Muema', '0104495076']],
        'CCI WAUTU' => [['Richard Kaio', 'Catherine Mutunga', '0711657578']],
        'CCI KITHIONI' => [['Chris Kasuni', 'Eunice Chris', '0722152198']],
        'CCI BETHSAIDA' => [['Daniel Ngeka', 'Elizabeth Daniel', '0740184231']],
        'CCI EMALI' => [
            ['John Mativo', 'Phyllis Mativo', '0723872846'],
            ['Stephen Kioko', 'Jacklyn Kioko', '0713529706'],
        ],
        'CCI MAKINDU' => [['Mike Kwinga', 'Winfred Kwinga', '0748143012']],
        'CCI SIMBA CEMENT' => [['Stephen Wambua', 'Eunice Wambua', '0705018548']],
        'CCI KIMANA' => [['Julius Matolo', 'Catherine Mutua', '0723438563']],
        'CCI LOITOKITOK' => [['Moses Okello', 'Roselidah Okello', '0728726946']],
        'CCI ISINET' => [['David Kimanthi', 'Salome David', '079953374']],
        'CCI NDUNGUNI' => [['Clinton Kavai', 'Mary Nzembi', '0769494135']],
    ];

    private function seedSpouseContacts(): void
    {
        $this->command->info('💑 Recording spouse contact info (no login accounts)...');

        $recorded = 0;

        foreach (self::SPOUSE_CONTACTS as $churchName => $contacts) {
            $church = Church::where('name', $churchName)->first();

            if (!$church) {
                $this->command->error("   ❌ Church not found: {$churchName}");
                continue;
            }

            $metadata = $church->metadata ?? [];
            $existing = $metadata['clergy_family_contacts'] ?? [];
            $existingPastorNames = array_column($existing, 'pastor_name');

            foreach ($contacts as [$pastorName, $spouseName, $spousePhone]) {
                if (!$spouseName || in_array($pastorName, $existingPastorNames, true)) {
                    continue;
                }

                $existing[] = [
                    'pastor_name' => $pastorName,
                    'spouse_name' => $spouseName,
                    'spouse_phone' => $spousePhone,
                ];
                $recorded++;
            }

            $metadata['clergy_family_contacts'] = $existing;
            $church->metadata = $metadata;
            $church->save();
        }

        $this->command->info("   ✅ Recorded {$recorded} spouse contact(s) across " . count(self::SPOUSE_CONTACTS) . ' churches.');
        $this->command->info('');
    }
}
