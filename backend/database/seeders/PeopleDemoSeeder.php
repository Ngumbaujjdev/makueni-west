<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\CareContact;
use App\Models\CareRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Ministry;
use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\Territory;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use App\Services\Activities\Activities;
use App\Services\People\Ministries;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo members and visitors, so the People & care pages can be seen filled in
 * (docs/specs/people-and-care-spec.md). Run by hand - it is not part of
 * DatabaseSeeder and refuses to run in production:
 *
 *   php artisan db:seed --class=PeopleDemoSeeder         (re-run: replaces the demo)
 *   php artisan db:seed --class=PeopleDemoRemoveSeeder   (takes it away)
 *
 * Every demo person has a number +254 700 000 xxx (a child's is "a parent's")
 * - Phone::isDemo() - which is how they are removed again, and they
 * are never texted (the SMS button, the welcome SMS and Messages' "Our
 * register" all skip them), so a demo copy can't reach a stranger.
 */
class PeopleDemoSeeder extends Seeder
{
    /** The church the demo is centred on (Benson Manoo's - see docs/TEST-LOGINS.md). */
    public const MAIN_CHURCH = 'CCI SULTAN HAMUD';

    private const MEN = ['John', 'Peter', 'James', 'Daniel', 'Joseph', 'Samuel', 'David', 'Stephen', 'Paul', 'Francis', 'Moses', 'Simon', 'Patrick', 'Benjamin', 'Kennedy', 'Dennis', 'Jackson', 'Philip'];

    private const WOMEN = ['Mary', 'Grace', 'Faith', 'Esther', 'Ruth', 'Agnes', 'Mercy', 'Jane', 'Ann', 'Catherine', 'Elizabeth', 'Lucy', 'Rose', 'Beatrice', 'Florence', 'Josephine', 'Purity', 'Caroline'];

    private const BOYS = ['Brian', 'Kelvin', 'Ian', 'Emmanuel', 'Victor', 'Collins', 'Elijah', 'Joshua'];

    private const GIRLS = ['Joy', 'Blessing', 'Precious', 'Gift', 'Sharon', 'Abigail', 'Naomi', 'Tabitha'];

    private const SURNAMES = ['Mutua', 'Musyoka', 'Kioko', 'Mwende', 'Muthoka', 'Nzioka', 'Kilonzo', 'Mutiso', 'Wambua', 'Kyalo', 'Ndunda', 'Mumo', 'Nthenge', 'Kimeu', 'Mwangangi', 'Mbithi', 'Musau', 'Kitheka', 'Nzomo', 'Mulwa'];

    private const MAIN_AREAS = ['Sultan Hamud Town', 'Kasikeu', 'Mbuvo', 'Kalembwani', 'Kiboko', 'Ngokolani', 'Ilatu'];

    private const OTHER_AREAS = ['Town Centre', 'Mbumbuni', 'Kyumbi', 'Kwa Kavisi', 'Nzaui'];

    private int $phone = 0;

    private CarbonImmutable $today;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('   ❌ Demo people are never seeded in production.');

            return;
        }
        $main = Territory::where('territory_type', 'church')->where('name', self::MAIN_CHURCH)->first()
            ?? Territory::where('territory_type', 'church')->orderBy('id')->first();
        if (! $main) {
            $this->command?->error('   ❌ No churches yet - seed the territories first.');

            return;
        }
        PeopleDemoRemoveSeeder::removeDemo();
        mt_srand(2026);
        $this->today = CarbonImmutable::now('Africa/Nairobi')->startOfDay();

        $region = collect(PlaceAccess::ancestors($main))->first(fn ($t) => PlaceAccess::level($t) === 'region');
        $others = $region ? Territory::whereIn('id', PlaceAccess::descendantIds($region))->where('territory_type', 'church')
            ->where('id', '!=', $main->id)->orderBy('name')->limit(8)->get() : collect();

        $this->church($main, 40, 15, self::MAIN_AREAS, $others);
        $this->care($main, 25);
        $this->ministries($main, true);
        foreach ($others as $i => $church) {
            $this->church($church, mt_rand(5, 15), mt_rand(2, 6), $this->areasFor($church), collect([$main]));
            $this->care($church, mt_rand(2, 6));
            $this->ministries($church);
        }
        $this->command?->info("   ✅ Demo people: {$main->name} (40 members, 15 visitors) and ".$others->count().' other churches');
        $this->command?->warn('   ℹ️  Demo numbers are +254 700 000 xxx and are never texted. Remove with --class=PeopleDemoRemoveSeeder');
    }

    /** One church: members (some Sunday school, a few who left) and visitors at every stage. */
    private function church(Territory $church, int $members, int $visitors, array $areas, Collection $nearby): void
    {
        $leaders = app(Activities::class)->leadersWith([$church->id], PeopleAccess::permission('visitors', 'manage'))->pluck('id')->all();
        $gathering = GatheringType::active()->forTerritory($church->id)->orderBy('display_order')->value('id');

        // Members: about 30% Sunday school; joined over the last two years, a couple this month.
        for ($i = 0; $i < $members; $i++) {
            $school = $i % 10 < 3;
            $male = mt_rand(0, 1) === 1;
            $joined = $i < 2 ? $this->today->subDays(mt_rand(1, max(1, $this->today->day - 1))) : $this->today->subDays(mt_rand(30, 730));
            $status = match (true) {
                $i === $members - 1 && $members > 10 => 'inactive',
                $i === $members - 2 && $members > 20 => 'inactive',
                $i === $members - 3 && $members > 20 => 'transferred_out',
                $i === $members - 4 && $members > 20 => 'transferred_out',
                default => 'member',
            };
            $person = Person::create([
                'territory_id' => $church->id, 'first_name' => $this->first($male, $school), 'last_name' => $this->pick(self::SURNAMES),
                'gender' => $male ? 'male' : 'female', 'phone' => $this->nextPhone(), 'area' => $this->pick($areas),
                'congregation' => $school ? 'sunday_school' : 'main_church', 'status' => $status, 'consent_contact' => ! $school,
                'joined_on' => $joined->toDateString(), 'how_joined' => $this->pick(['conversion', 'conversion', 'baptism', 'birth', 'transfer']),
                'baptised_on' => ! $school && mt_rand(1, 10) <= 6 ? $joined->addDays(mt_rand(20, 200))->min($this->today)->toDateString() : null,
            ]);
            if ($status === 'transferred_out' && $nearby->isNotEmpty()) {
                PersonTransfer::create(['person_id' => $person->id, 'territory_id' => $church->id, 'direction' => 'out', 'other_church_id' => $nearby->first()->id, 'on' => $this->today->subDays(mt_rand(10, 120))->toDateString(), 'reason' => 'Moved for work']);
            }
            if ($i === 5 && $members > 20) {
                $person->forceFill(['how_joined' => 'transfer', 'previous_church' => 'AIC Emali'])->save();
                PersonTransfer::create(['person_id' => $person->id, 'territory_id' => $church->id, 'direction' => 'in', 'other_church_name' => 'AIC Emali', 'on' => $joined->toDateString()]);
            }
        }

        // Visitors: first visits over the last eight Sundays, at every stage.
        $plan = $visitors >= 15
            ? ['new', 'new', 'new', 'new', 'contacted', 'contacted', 'contacted', 'contacted', 'returning', 'returning', 'returning', 'regular', 'member', 'member', 'member']
            : array_slice(['new', 'contacted', 'returning', 'new', 'member', 'regular'], 0, $visitors);
        $lastSunday = $this->today->subDays($this->today->dayOfWeek ?: 7);
        foreach ($plan as $n => $stage) {
            $male = mt_rand(0, 1) === 1;
            $visits = ['new' => 1, 'contacted' => 1, 'returning' => mt_rand(2, 3), 'regular' => 5, 'member' => 6][$stage];
            $firstWeek = $stage === 'new' ? $n % 3 : min(7, $visits + mt_rand(0, 2));
            $sundays = collect(range($firstWeek, max(0, $firstWeek - $visits + 1)))->map(fn ($w) => $lastSunday->subWeeks($w))->unique()->values();
            $assigned = $leaders && $n % 3 !== 0 ? $leaders[$n % count($leaders)] : null;
            $became = $stage === 'member' ? $sundays->last()->addDays(3)->min($this->today) : null;
            $person = Person::create([
                'territory_id' => $church->id, 'first_name' => $this->first($male, false), 'last_name' => $this->pick(self::SURNAMES),
                'gender' => $stage === 'member' ? ($male ? 'male' : 'female') : null, 'phone' => $this->nextPhone(), 'area' => $this->pick($areas),
                'congregation' => $stage === 'member' ? 'main_church' : null, 'status' => $stage === 'member' ? 'member' : 'visitor', 'stage' => $stage,
                'first_visit_on' => $sundays->first()->toDateString(), 'last_visit_on' => $sundays->last()->toDateString(), 'visit_count' => $sundays->count(),
                'consent_contact' => $n !== 2, 'assigned_to' => $assigned, 'became_member_on' => $became?->toDateString(),
                'joined_on' => $became?->toDateString(), 'how_joined' => $became ? 'conversion' : null,
            ]);
            foreach ($sundays as $k => $day) {
                VisitorVisit::create(['person_id' => $person->id, 'territory_id' => $church->id, 'gathering_type_id' => $gathering, 'on' => $day->toDateString(), 'first_time' => $k === 0]);
            }
            $this->followups($person, $church, $stage, $sundays, $leaders, $n);
        }
    }

    /**
     * Pastoral care (P3) for the demo people: every kind, some confidential,
     * two in hospital, prayers open and answered (one testimony shared),
     * and next steps this week.
     */
    private function care(Territory $church, int $count): void
    {
        $people = Person::where('territory_id', $church->id)->where('phone', 'like', '+254700000%')->where('status', 'member')->inRandomOrder()->limit($count)->get();
        if ($people->isEmpty()) {
            return;
        }
        $carers = app(Activities::class)->leadersWith([$church->id], PeopleAccess::permission('pastoral', 'manage'))->pluck('id')->all();
        $plan = ['home_visit', 'home_visit', 'hospital', 'counselling', 'prayer', 'prayer', 'phone_call', 'bereavement', 'concern', 'home_visit', 'prayer', 'hospital', 'phone_call', 'home_visit', 'counselling'];
        $notes = [
            'home_visit' => 'Visited the family; prayed together.', 'hospital' => 'Admitted after a fall - doing better.', 'counselling' => 'Talked through a hard season at home.',
            'prayer' => 'Asked for prayer for a job.', 'phone_call' => 'Called to check in after missing two Sundays.', 'bereavement' => 'Lost her mother - the family needs support.',
            'concern' => 'Has not been in church for a month.',
        ];
        foreach ($people as $i => $p) {
            $type = $plan[$i % count($plan)];
            $on = $this->today->subDays(mt_rand(1, 170));
            $open = in_array($type, ['hospital', 'prayer', 'counselling', 'concern'], true) && ($type !== 'hospital' || $i < 3) && $i % 4 !== 3;
            if ($type === 'hospital' && $open) {
                $on = $this->today->subDays(mt_rand(2, 12));
            }
            $answered = $type === 'prayer' && ! $open;
            $record = CareRecord::create([
                'territory_id' => $church->id, 'person_id' => $p->id, 'type' => $type, 'priority' => $i % 6 === 0 ? 'high' : 'normal',
                'status' => $answered ? 'answered' : ($open ? 'open' : 'closed'), 'on' => $on->toDateString(), 'closed_on' => $open ? null : $on->addDays(mt_rand(0, 20))->min($this->today)->toDateString(),
                'hospital' => $type === 'hospital' ? $this->pick(['Makueni County Referral', 'Sultan Hamud Health Centre', 'Machakos Level 5']) : null,
                'discharged_on' => $type === 'hospital' && ! $open ? $on->addDays(4)->min($this->today)->toDateString() : null,
                'note' => $notes[$type], 'confidential' => $type === 'counselling' || $i % 9 === 0,
                'next_on' => $open && $i % 2 === 0 ? $this->today->addDays(mt_rand(-2, 6))->toDateString() : null,
                'testimony' => $answered ? 'She found work two weeks later - God answered.' : null, 'share_testimony' => $answered && $i % 2 === 0,
                'created_by' => $carers ? $carers[$i % count($carers)] : null,
            ]);
            if ($carers) {
                $record->carers()->sync([$carers[$i % count($carers)]]);
            }
            if ($open && $type === 'hospital') {
                CareContact::create(['care_record_id' => $record->id, 'territory_id' => $church->id, 'on' => $on->addDay()->min($this->today)->toDateString(), 'type' => 'visit', 'note' => 'Visited and prayed with them.', 'done_by' => $carers[0] ?? null]);
            }
        }
    }

    /** Marks what the demo adds outside the register, so PeopleDemoRemoveSeeder finds it again. */
    public const DEMO_NOTE = 'Demo attendance';

    public const DEMO_ACTIVITY = 'Demo activity (PeopleDemoSeeder).';

    /** kind => [the gathering it meets as, the day, the time, how many come for each member, events for it [title, type, audience]] */
    private const MINISTRY_PLAN = [
        'youth' => ['Youth Service', 6, '15:00', 1.6, [['Youth kesha', 'youth_kesha', 'youth'], ['Youth sports day', 'special_service', 'youth']]],
        'women' => ["Women's Fellowship", 2, '14:00', 1.3, [["Women's fellowship day", 'special_service', 'women']]],
        'men' => ["Men's Fellowship", 0, '14:30', 1.2, [["Men's breakfast", 'special_service', 'men']]],
        'children' => ['Sunday School', 0, '09:00', 1.4, [["Children's fun day", 'special_service', 'children']]],
        'music' => ['Choir Practice', 4, '17:00', 1.1, []],
        'prayer' => ['Prayer Meeting', 3, '18:00', 2.2, [['Prayer and fasting week', 'prayer_meeting', 'everyone']]],
    ];

    /**
     * Ministries (P4): the standard six, each with a day it meets, demo
     * members (Sunday school children are put in by the register itself),
     * two demo leaders, its gathering - a demo type when the church has none -
     * six months of weekly attendance, and an event or two for it. Everything
     * outside the register is marked (DEMO_NOTE, demo- slugs, DEMO_ACTIVITY)
     * and taken away by PeopleDemoRemoveSeeder.
     */
    private function ministries(Territory $church, bool $main = false): void
    {
        app(Ministries::class)->ensure($church);
        $ministries = Ministry::where('territory_id', $church->id)->whereNotNull('standard')->get()->keyBy('standard');
        $people = Person::where('territory_id', $church->id)->where('phone', 'like', '+254700000%')->where('status', 'member')->orderBy('id')->get();
        $adults = $people->where('congregation', 'main_church')->values();
        $plan = [
            'women' => $adults->where('gender', 'female')->filter(fn () => mt_rand(1, 10) <= 7),
            'men' => $adults->where('gender', 'male')->filter(fn () => mt_rand(1, 10) <= 6),
            'youth' => $adults->filter(fn () => mt_rand(1, 10) <= 4),
            'music' => $adults->filter(fn () => mt_rand(1, 10) <= 3),
            'prayer' => $adults->filter(fn () => mt_rand(1, 10) <= 3),
        ];
        $now = now();
        $category = GatheringCategory::where('slug', 'ministry_gathering')->value('id');
        foreach (self::MINISTRY_PLAN as $kind => [$gathering, $day, $time, $per, $events]) {
            $m = $ministries[$kind] ?? null;
            if (! $m) {
                continue;
            }
            $members = $kind === 'children' ? $people->where('congregation', 'sunday_school') : ($plan[$kind] ?? collect());
            if ($kind !== 'children' && $members->isNotEmpty()) {
                DB::table('ministry_members')->insertOrIgnore($members->map(fn (Person $p) => [
                    'ministry_id' => $m->id, 'person_id' => $p->id, 'joined_on' => $this->today->subDays(mt_rand(10, 500))->toDateString(), 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }
            $lead = $members->values();
            foreach ([['leader', 0], ['assistant', 1]] as [$role, $i]) {
                $who = $kind === 'children' ? $adults->get(($m->id + $i) % max(1, $adults->count())) : $lead->get($i);
                if ($who) {
                    DB::table('ministry_leaders')->insert(['ministry_id' => $m->id, 'person_id' => $who->id, 'role' => $role, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            // Its gathering: the church's own type when it has one, or a demo one.
            $type = $m->gathering_type_id ? GatheringType::find($m->gathering_type_id) : null;
            if (! $type && $category) {
                $type = GatheringType::firstOrCreate(
                    ['territory_id' => $church->id, 'slug' => 'demo-'.Str::slug($gathering)],
                    ['gathering_category_id' => $category, 'name' => $gathering, 'icon' => Ministry::KINDS[$kind][1], 'display_order' => 90, 'is_active' => true],
                );
            }
            $m->forceFill(['gathering_type_id' => $type?->id, 'meets_day' => $m->meets_day ?? $day, 'meets_time' => $m->meets_time ?? $time])->saveQuietly();
            if ($type) {
                $this->attendance($church, $type, $kind, $day, max(6, (int) round(max(1, $members->count()) * $per * ($main ? 1 : 0.8))));
            }
            if ($main || $kind === 'youth') {
                foreach ($events as $n => [$title, $eventType, $audience]) {
                    $start = $this->today->addDays($n % 2 === 0 ? mt_rand(5, 40) : -mt_rand(10, 80))->setTime(14, 0);
                    Activity::create([
                        'kind' => 'event', 'territory_id' => $church->id, 'title' => $title, 'description' => self::DEMO_ACTIVITY, 'type' => $eventType, 'audience' => $audience,
                        'starts_at' => $start, 'ends_at' => $start->addHours(4), 'venue' => 'Church grounds', 'open_to' => 'own', 'registration' => false,
                        'status' => $start->isPast() ? 'completed' : 'published', 'published_at' => $now,
                    ]);
                }
            }
        }
    }

    /** Weekly attendance for a ministry's gathering over the last six months - around its size, now and then missed. */
    private function attendance(Territory $church, GatheringType $type, string $kind, int $day, int $size): void
    {
        $years = FiscalYear::pluck('id', 'year');
        $months = FiscalMonth::pluck('id', 'number');
        $first = $this->today->subMonthsNoOverflow(6)->startOfMonth();
        $date = $first->addDays(($day - $first->dayOfWeek + 7) % 7);
        $rows = [];
        for ($w = 0; $date->lte($this->today); $w++, $date = $date->addWeek()) {
            $year = $years[$date->year] ?? null;
            $month = $months[$date->month] ?? null;
            if (! $year || ! $month || mt_rand(1, 10) === 1) {
                continue; // no fiscal year set up, or it didn't meet that week
            }
            $total = max(3, (int) round($size * (0.8 + $w * 0.012) * mt_rand(85, 115) / 100));
            [$adults, $youth, $boys, $girls] = match ($kind) {
                'youth' => [(int) round($total * 0.1), $total - (int) round($total * 0.1), 0, 0],
                'children' => [(int) round($total * 0.1), 0, intdiv($total - (int) round($total * 0.1), 2), $total - (int) round($total * 0.1) - intdiv($total - (int) round($total * 0.1), 2)],
                default => [$total - (int) round($total * 0.15), (int) round($total * 0.15), 0, 0],
            };
            $rows[] = [
                'territory_type' => 'church', 'territory_id' => $church->id, 'service_date' => $date->toDateString(), 'fiscal_year_id' => $year, 'fiscal_month_id' => $month,
                'gathering_category_id' => $type->gathering_category_id, 'gathering_type_id' => $type->id, 'adults_count' => $adults, 'youth_count' => $youth,
                'children_male_count' => $boys, 'children_female_count' => $girls, 'notes' => self::DEMO_NOTE, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('church_attendance_records')->insert($rows);
        }
    }

    /** Follow-ups that fit the stage: none yet for some new ones (they show as due or late), next steps this week for others. */
    private function followups(Person $p, Territory $church, string $stage, Collection $sundays, array $leaders, int $n): void
    {
        if ($stage === 'new') {
            return;
        }
        $by = $leaders ? $leaders[$n % count($leaders)] : null;
        $first = $sundays->first()->addDays(mt_rand(1, 3))->min($this->today);
        $log = fn (CarbonImmutable $on, string $type, string $outcome, ?string $note, ?CarbonImmutable $next) => VisitorFollowup::create([
            'person_id' => $p->id, 'territory_id' => $church->id, 'type' => $type, 'outcome' => $outcome, 'note' => $note,
            'done_by' => $by, 'done_on' => $on->toDateString(), 'next_on' => $next?->toDateString(),
        ]);
        $log($first, 'call', 'reached', $this->pick(['Glad to have come - will bring her sister.', 'Asked about the youth group.', 'New in the area, looking for a church home.', 'Enjoyed the choir.']), null);
        $last = $sundays->last();
        if ($stage === 'contacted') {
            // Half are due a second call this week, one is late.
            $next = $n % 2 === 0 ? $this->today->addDays(mt_rand(0, 5)) : $this->today->subDays(2);
            $log($first->addDay()->min($this->today), 'sms', 'sent', 'Thank you for visiting.', $next);
        } elseif (in_array($stage, ['returning', 'regular'], true)) {
            $log($last->addDays(2)->min($this->today), 'visit', 'will_come', 'Visited at home; prayed with the family.', $stage === 'returning' ? $this->today->addDays(mt_rand(1, 6)) : null);
        } else {
            $log($last->min($this->today), 'met', 'reached', 'Ready to join - spoke with the pastor.', null);
        }
    }

    private function first(bool $male, bool $child): string
    {
        return $this->pick($child ? ($male ? self::BOYS : self::GIRLS) : ($male ? self::MEN : self::WOMEN));
    }

    private function pick(array $list): string
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    private function nextPhone(): string
    {
        return sprintf('+254700000%03d', ++$this->phone);
    }

    private function areasFor(Territory $church): array
    {
        $town = ucwords(strtolower(trim(preg_replace('/^CCI\s+/i', '', $church->name))));

        return [$town, ...array_slice(self::OTHER_AREAS, 1)];
    }
}
