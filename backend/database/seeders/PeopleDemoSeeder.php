<?php

namespace Database\Seeders;

use App\Models\GatheringType;
use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\Territory;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use App\Services\Activities\Activities;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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
        foreach ($others as $i => $church) {
            $this->church($church, mt_rand(5, 15), mt_rand(2, 6), $this->areasFor($church), collect([$main]));
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
