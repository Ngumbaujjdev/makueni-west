<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\ActivitySession;
use App\Models\CalendarEvent;
use App\Models\MessageBatch;
use App\Models\MessageRecipient;
use App\Models\MessageReply;
use App\Models\MessageTemplate;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportComment;
use App\Models\Territory;
use App\Models\User;
use App\Notifications\PlaceNotification;
use App\Services\Activities\Activities;
use App\Services\Activities\Sessions;
use App\Services\Reports\MonthlyFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample Church life content - events, initiatives and their sessions,
 * calendar dates, monthly reports with comments, messages with replies,
 * saved messages and a few bell notifications - so the pages can be seen
 * full. Centred on CCI SULTAN HAMUD (Senior Pastor Benson Manoo), Sultan
 * Hamud Region (Titus Kenzi), the diocese (the Bishop) and CCI Kenya.
 *
 * NOT part of DatabaseSeeder: a real diocese never gets made-up data.
 *
 *   php artisan db:seed --class=ChurchLifeDemoSeeder             add / refresh it
 *   DEMO=remove php artisan db:seed --class=ChurchLifeDemoSeeder  take it all out
 *
 * Every row it makes carries a uid starting "de300000-" (or, for saved
 * messages and bell notifications, one of its own names), so removal takes
 * out exactly what it made and never anything a person wrote.
 */
class ChurchLifeDemoSeeder extends Seeder
{
    public const UID_PREFIX = 'de300000-';

    private const TZ = 'Africa/Nairobi';

    private const TEMPLATES = ['Sunday reminder', 'Meeting reminder', 'Report reminder'];

    private const NOTIFICATION_TITLES = [
        'You are invited: Diocese Youth Convention 2026',
        'Titus Kenzi saw your September report',
        'New comment on your September report',
        'New message from Makueni West Diocese',
    ];

    private int $n = 0;

    private const AUDITED = [Activity::class, CalendarEvent::class, MonthlyReport::class, MessageBatch::class];

    public function run(): void
    {
        // Sample rows aren't anyone's changes - keep them out of the audit
        // log, and switch auditing back on afterwards (the flag is static).
        foreach (self::AUDITED as $model) {
            $model::disableAuditing();
        }
        try {
            $this->fill();
        } finally {
            foreach (self::AUDITED as $model) {
                $model::enableAuditing();
            }
        }
    }

    private function fill(): void
    {
        $this->remove();
        if (env('DEMO') === 'remove') {
            $this->command?->info('Church life sample data removed.');

            return;
        }

        $church = Territory::where('name', 'CCI SULTAN HAMUD')->firstOrFail();
        $region = Territory::findOrFail($church->parent_territory_id);
        $diocese = Territory::findOrFail($region->parent_territory_id);
        $cci = Territory::find($diocese->parent_territory_id);

        $benson = $this->leader($church, 'Senior Pastor') ?? $this->anyoneAt($church);
        $titus = $this->leader($region, 'Regional Overseer') ?? $this->anyoneAt($region);
        $bishop = $this->leader($diocese, 'Bishop') ?? $this->anyoneAt($diocese);
        $neighbours = Territory::where('parent_territory_id', $region->id)->where('territory_type', 'church')
            ->where('id', '!=', $church->id)->orderBy('id')->limit(4)->get();

        DB::transaction(function () use ($church, $region, $diocese, $cci, $benson, $titus, $bishop, $neighbours) {
            $this->calendar($cci, $diocese, $region, $church, $bishop, $titus, $benson);
            $this->activities($diocese, $region, $church, $neighbours, $bishop, $titus, $benson);
            $this->reports($church, $region, $neighbours, $titus, $benson);
            $this->messages($diocese, $region, $church, $bishop, $titus, $benson);
        });
        $this->notifications($benson, $church, $region, $diocese);

        $this->command?->info('Church life sample data added for '.$church->name.' and around it.');
    }

    // ------------------------------------------------------------- helpers

    private function uid(): string
    {
        return self::UID_PREFIX.sprintf('0000-4000-8000-%012d', ++$this->n);
    }

    /** A time in Nairobi, stored as the app stores it (UTC). */
    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ)->utc();
    }

    private function leader(Territory $place, string $role): ?User
    {
        $id = DB::table('user_territory_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.territory_id', $place->id)->where('a.is_active', 1)->where('r.name', $role)->value('a.user_id');

        return $id ? User::find($id) : null;
    }

    /** Whoever holds a role at the place - the leader roles are named differently in tests. */
    private function anyoneAt(Territory $place): User
    {
        $id = DB::table('user_territory_assignments')->where('territory_id', $place->id)->where('is_active', 1)->orderBy('id')->value('user_id');

        return User::findOrFail($id);
    }

    /** People with an active role at these places, optionally only these roles. */
    private function peopleAt(array $placeIds, array $roles = []): \Illuminate\Support\Collection
    {
        return DB::table('user_territory_assignments as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.territory_id', $placeIds)->where('a.is_active', 1)
            ->when($roles, fn ($q) => $q->whereIn('r.name', $roles))
            ->get(['u.id', 'u.firstname', 'u.lastname', 'u.phone', 'u.email', 'a.territory_id', 'r.name as role'])
            ->unique('id')->values();
    }

    // --------------------------------------------------------------- remove

    private function remove(): void
    {
        $like = self::UID_PREFIX.'%';
        Activity::withTrashed()->where('uid', 'like', $like)->get()->each->forceDelete();
        CalendarEvent::withTrashed()->where('uid', 'like', $like)->get()->each->forceDelete();
        MonthlyReport::where('uid', 'like', $like)->delete();
        MessageBatch::where('uid', 'like', $like)->delete();
        MessageTemplate::whereIn('name', self::TEMPLATES)->whereNull('created_by')->delete();
        DB::table('notifications')->where('type', PlaceNotification::class)->get()
            ->filter(fn ($n) => in_array(json_decode($n->data, true)['title'] ?? null, self::NOTIFICATION_TITLES, true))
            ->each(fn ($n) => DB::table('notifications')->where('id', $n->id)->delete());
    }

    // ------------------------------------------------------------- calendar

    private function calendar(?Territory $cci, Territory $diocese, Territory $region, Territory $church, User $bishop, User $titus, User $benson): void
    {
        $rows = [];
        if ($cci) {
            $rows[] = [$cci, null, 'CCI Day of Prayer', 'fasting_prayer', '2026-10-24', '2026-10-24', true, null, null, 'Every CCI church', 'none', 'One day of prayer for the nation, in every congregation.'];
            $rows[] = [$cci, null, "National Pastors' Conference", 'conference', '2026-11-24', '2026-11-26', true, null, null, 'CCI Headquarters, Nairobi', 'none', 'Three days for every pastor in CCI Kenya. Dioceses send their delegates.'];
            $rows[] = [$cci, null, 'Christmas Day', 'holiday', '2026-12-25', '2026-12-25', true, null, null, null, 'none', null];
            $rows[] = [$cci, null, 'Watch Night Service', 'service', '2026-12-31', '2026-12-31', false, '21:00', '23:59', 'Every CCI church', 'none', 'Seeing the new year in together.'];
            $rows[] = [$cci, null, 'National Fasting & Prayer Week', 'fasting_prayer', '2027-01-04', '2027-01-10', true, null, null, null, 'none', 'The first week of the year in fasting and prayer.'];
        }
        $rows[] = [$diocese, $bishop, 'Diocese AGM', 'meeting', '2026-11-07', '2026-11-07', false, '09:00', '15:00', 'Diocese office, Wote', 'none', 'The annual general meeting - reports from every region.'];
        $rows[] = [$diocese, $bishop, "Bishop's visit to Sultan Hamud Region", 'service', '2026-10-25', '2026-10-25', false, '09:00', '13:00', 'CCI SULTAN HAMUD', 'none', 'Combined Sunday service with the Bishop.'];
        $rows[] = [$region, $titus, "Regional pastors' meeting", 'meeting', '2026-09-10', '2026-09-10', false, '10:00', '13:00', 'CCI SULTAN HAMUD', 'monthly', 'Every month: reports, prayer and planning.'];
        $rows[] = [$church, $benson, 'Church Council meeting', 'meeting', '2026-09-13', '2026-09-13', false, '14:00', '16:00', 'Church office', 'monthly', 'After the Sunday service.'];
        $rows[] = [$church, $benson, 'Pastoral visits - Kima village', 'other', '2026-10-10', '2026-10-10', false, '10:00', '16:00', 'Kima', 'none', null];

        foreach ($rows as [$place, $by, $title, $kind, $from, $to, $allDay, $start, $end, $where, $repeats, $about]) {
            CalendarEvent::create([
                'uid' => $this->uid(), 'territory_id' => $place->id, 'title' => $title, 'description' => $about, 'kind' => $kind,
                'starts_on' => $from, 'ends_on' => $to, 'all_day' => $allDay, 'start_time' => $start, 'end_time' => $end,
                'location' => $where, 'repeats' => $repeats, 'repeat_until' => $repeats === 'none' ? null : '2027-06-30',
                'shared_below' => $place->id !== $church->id, 'source' => 'manual', 'created_by' => $by?->id, 'updated_by' => $by?->id,
            ]);
        }
    }

    // ----------------------------------------------------------- activities

    private function activity(array $data, User $by): Activity
    {
        return Activity::create(['uid' => $this->uid(), 'created_by' => $by->id, 'updated_by' => $by->id] + $data);
    }

    private function register(Activity $a, Territory $place, array $counts, User $by, array $extra = []): void
    {
        $counts += ['youth' => 0, 'adults' => 0, 'children' => 0, 'leaders' => 0];
        ActivityRegistration::create([
            'activity_id' => $a->id, 'territory_id' => $place->id, 'status' => 'registered',
            'fee_due' => Activities::feeDue($a, $counts), 'registered_by' => $by->id, 'updated_by' => $by->id,
        ] + $counts + $extra);
    }

    /** Generate the sessions, then fill in the ones already held. */
    private function sessions(Activity $a, User $by, array $topics, callable $counts): void
    {
        Sessions::sync($a, $by->id);
        $today = CarbonImmutable::now(self::TZ)->toDateString();
        foreach (ActivitySession::where('activity_id', $a->id)->orderBy('held_on')->get() as $i => $s) {
            $s->topic = $topics[$i % count($topics)];
            if ($s->held_on->toDateString() < $today) {
                $s->fill(['status' => 'held', 'updated_by' => $by->id] + $counts($i));
            }
            $s->save();
        }
    }

    private function activities(Territory $diocese, Territory $region, Territory $church, $neighbours, User $bishop, User $titus, User $benson): void
    {
        // The diocese
        $convention = $this->activity([
            'kind' => 'event', 'territory_id' => $diocese->id, 'title' => 'Diocese Youth Convention 2026', 'type' => 'youth_convention', 'audience' => 'youth',
            'description' => 'Three days of worship, teaching and fellowship for the youth of every church in the diocese.',
            'starts_at' => $this->at('2026-12-04 09:00'), 'ends_at' => $this->at('2026-12-06 16:00'), 'venue' => 'Makueni Boys High School, Wote',
            'capacity' => 600, 'coordinator' => 'Rev. Daniel Musyoka', 'speakers' => 'Bishop Peter Kilonzo, Pst. Grace Wambua',
            'agenda' => "Fri: arrival and opening night\nSat: teaching, sports and worship night\nSun: combined service and send-off",
            'open_to' => 'below', 'registration' => true, 'register_by' => '2026-11-20', 'fee_per_person' => 500,
            'planned_income' => 250000, 'planned_spend' => 180000, 'status' => 'published', 'published_at' => $this->at('2026-09-20 10:00'),
        ], $bishop);
        $this->register($convention, $church, ['youth' => 20, 'adults' => 5, 'leaders' => 2], $benson, ['fee_paid' => 5000, 'names' => 'Youth leader: Faith Mwende']);
        foreach ($neighbours->take(2) as $i => $n) {
            $this->register($convention, $n, $i ? ['youth' => 10, 'adults' => 2, 'leaders' => 1] : ['youth' => 15, 'adults' => 3, 'leaders' => 1], $bishop, ['fee_paid' => $i ? 0 : 9500]);
        }

        $training = $this->activity([
            'kind' => 'initiative', 'territory_id' => $diocese->id, 'title' => "Pastors' Leadership Training", 'type' => 'pastors_training', 'audience' => 'pastors',
            'description' => 'A monthly Saturday for every pastor: leading a church, caring for people and keeping good records.',
            'starts_at' => $this->at('2026-09-05 09:00'), 'ends_at' => $this->at('2026-12-05 15:00'), 'venue' => 'Diocese office, Wote',
            'frequency' => 'monthly', 'meeting_day' => 6, 'meeting_time' => '09:00', 'certificate' => true, 'coordinator' => 'Rev. Daniel Musyoka',
            'open_to' => 'below', 'registration' => true, 'status' => 'published', 'published_at' => $this->at('2026-08-25 10:00'),
        ], $bishop);
        $this->sessions($training, $bishop, ['Leading with a servant heart', 'Pastoral care and visits', 'Church records and reports', 'Planning the new year'], fn ($i) => ['adults' => 0, 'leaders' => 54 + $i * 3]);
        $this->register($training, $church, ['leaders' => 2], $benson);
        foreach ($neighbours->take(2) as $n) {
            $this->register($training, $n, ['leaders' => 1], $bishop);
        }

        // The region
        $crusade = $this->activity([
            'kind' => 'event', 'territory_id' => $region->id, 'title' => 'Sultan Hamud Region Crusade', 'type' => 'outreach', 'audience' => 'everyone',
            'description' => 'Three evenings of open-air preaching and prayer for Sultan Hamud town, with every church in the region.',
            'starts_at' => $this->at('2026-11-13 15:00'), 'ends_at' => $this->at('2026-11-15 18:00'), 'venue' => 'Sultan Hamud Stadium',
            'coordinator' => 'Pst. Titus Kenzi', 'open_to' => 'below', 'registration' => true, 'register_by' => '2026-11-06',
            'planned_spend' => 60000, 'status' => 'published', 'published_at' => $this->at('2026-09-28 10:00'),
        ], $titus);
        $this->register($crusade, $church, ['youth' => 30, 'adults' => 40, 'leaders' => 4], $benson);
        if ($neighbours->first()) {
            $this->register($crusade, $neighbours->first(), ['youth' => 18, 'adults' => 25, 'leaders' => 3], $titus);
        }

        $discipleship = $this->activity([
            'kind' => 'initiative', 'territory_id' => $region->id, 'title' => 'Regional Youth Discipleship', 'type' => 'youth_programme', 'audience' => 'youth',
            'description' => 'Every other Saturday afternoon: Bible, mentoring and sport for the youth of the region.',
            'starts_at' => $this->at('2026-08-22 14:00'), 'ends_at' => $this->at('2026-12-12 17:00'), 'venue' => 'CCI SULTAN HAMUD',
            'frequency' => 'fortnightly', 'meeting_day' => 6, 'meeting_time' => '14:00', 'open_to' => 'below', 'registration' => true,
            'status' => 'published', 'published_at' => $this->at('2026-08-10 10:00'),
        ], $titus);
        $this->sessions($discipleship, $titus, ['Who am I in Christ?', 'Friendships and choices', 'Prayer that works', 'Serving in church', 'Money and work'], fn ($i) => ['youth' => 22 + ($i % 3) * 4, 'leaders' => 3]);
        $this->register($discipleship, $church, ['youth' => 12], $benson);
        if ($neighbours->first()) {
            $this->register($discipleship, $neighbours->first(), ['youth' => 8], $titus);
        }

        // The church
        $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Harvest Thanksgiving Sunday', 'type' => 'special_service', 'audience' => 'everyone',
            'description' => 'Bringing the first of the harvest to the Lord, with a thanksgiving offering.',
            'starts_at' => $this->at('2026-09-27 09:00'), 'ends_at' => $this->at('2026-09-27 13:00'), 'venue' => 'CCI SULTAN HAMUD',
            'open_to' => 'own', 'planned_income' => 40000, 'status' => 'completed', 'published_at' => $this->at('2026-09-01 10:00'),
            'report_back' => 'A full church - 184 people. The thanksgiving offering came to KES 48,500, above the KES 40,000 we hoped for, and three families gave their lives to Christ.',
        ], $benson);
        $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Youth Kesha', 'type' => 'youth_kesha', 'audience' => 'youth',
            'description' => 'An all-night prayer and worship for the youth. Neighbouring churches welcome.',
            'starts_at' => $this->at('2026-10-16 21:00'), 'ends_at' => $this->at('2026-10-17 05:00'), 'venue' => 'CCI SULTAN HAMUD',
            'coordinator' => 'Faith Mwende', 'open_to' => 'region', 'status' => 'published', 'published_at' => $this->at('2026-10-01 10:00'),
        ], $benson);
        $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Roof Fundraiser', 'type' => 'fundraising', 'audience' => 'everyone',
            'description' => 'Raising the money to replace the church roof before the long rains.',
            'starts_at' => $this->at('2026-11-22 11:00'), 'ends_at' => $this->at('2026-11-22 16:00'), 'venue' => 'CCI SULTAN HAMUD',
            'open_to' => 'region', 'planned_income' => 350000, 'planned_spend' => 20000, 'status' => 'published', 'published_at' => $this->at('2026-10-02 10:00'),
        ], $benson);

        $bible = $this->activity([
            'kind' => 'initiative', 'territory_id' => $church->id, 'title' => 'Wednesday Bible Study', 'type' => 'bible_study', 'audience' => 'everyone',
            'description' => 'Going through the Book of James, a chapter or two at a time.',
            'starts_at' => $this->at('2026-08-05 18:00'), 'ends_at' => $this->at('2026-12-16 19:30'), 'venue' => 'Church hall',
            'frequency' => 'weekly', 'meeting_day' => 3, 'meeting_time' => '18:00', 'coordinator' => 'Pst. Benson Manoo',
            'open_to' => 'own', 'status' => 'published', 'published_at' => $this->at('2026-08-01 10:00'),
        ], $benson);
        $this->sessions($bible, $benson, ['James 1 - Trials and wisdom', 'James 2 - Faith that works', 'James 3 - Taming the tongue', 'James 4 - Drawing near to God', 'James 5 - Patience and prayer'], fn ($i) => ['adults' => 18 + ($i % 4) * 2, 'youth' => 6 + ($i % 3)]);

        $women = $this->activity([
            'kind' => 'initiative', 'territory_id' => $church->id, 'title' => "Women's Prayer Group", 'type' => 'womens', 'audience' => 'women',
            'description' => 'Every other Friday morning, praying for the church, families and the town.',
            'starts_at' => $this->at('2026-09-04 10:00'), 'ends_at' => $this->at('2026-12-11 12:00'), 'venue' => 'Church hall',
            'frequency' => 'fortnightly', 'meeting_day' => 5, 'meeting_time' => '10:00', 'coordinator' => 'Mama Ruth Manoo',
            'open_to' => 'own', 'status' => 'published', 'published_at' => $this->at('2026-09-01 10:00'),
        ], $benson);
        $this->sessions($women, $benson, ['Praying for our families', 'Praying for the town', 'Praying for the youth'], fn ($i) => ['adults' => 14 + $i % 3]);

        $this->moreEvents($diocese, $region, $church, $neighbours, $bishop, $titus, $benson);
    }

    /**
     * More kinds of event at every level (2026-10-08): conferences, a worship
     * night, a leadership meeting, a revival week, a wedding, a harambee - some
     * already happened, with who came, fees paid, ratings and a report back, so
     * an event's page has something to show. No money entries: those belong to
     * a budget, and the demo leaves budgets alone.
     */
    private function moreEvents(Territory $diocese, Territory $region, Territory $church, $neighbours, User $bishop, User $titus, User $benson): void
    {
        $two = $neighbours->take(2)->values();

        // The diocese
        $conference = $this->activity([
            'kind' => 'event', 'territory_id' => $diocese->id, 'title' => "Diocese Pastors' Conference 2026", 'type' => 'conference', 'audience' => 'pastors',
            'description' => "Three days for every pastor and their spouse: the word, rest and planning the diocese's year together.",
            'starts_at' => $this->at('2026-08-20 09:00'), 'ends_at' => $this->at('2026-08-22 15:00'), 'venue' => 'Makueni Boys High School, Wote',
            'capacity' => 250, 'coordinator' => 'Rev. Daniel Musyoka', 'speakers' => 'Bishop Peter Kilonzo, Rev. Dr. Joseph Muli',
            'agenda' => "Thu: arrival, opening service and the Bishop's charge\nFri: teaching - shepherding in hard times\nFri evening: spouses' session\nSat: planning 2027 by region, Holy Communion and send-off",
            'open_to' => 'below', 'registration' => true, 'register_by' => '2026-08-10', 'fee_per_person' => 1500,
            'planned_income' => 300000, 'planned_spend' => 260000, 'status' => 'completed', 'published_at' => $this->at('2026-07-01 10:00'),
            'report_back' => '212 pastors and spouses came from every region. The Bishop charged us to visit every member before Christmas; each region left with its 2027 plan.',
        ], $bishop);
        $this->register($conference, $church, ['adults' => 2, 'leaders' => 2], $benson, ['fee_paid' => 6000, 'came_adults' => 2, 'came_leaders' => 2, 'rating' => 5, 'comment' => 'The best conference in years - the planning day was very practical.']);
        foreach ($two as $i => $n) {
            $this->register($conference, $n, ['adults' => 1, 'leaders' => 2 - $i], $bishop, ['fee_paid' => $i ? 1500 : 4500, 'came_adults' => 1, 'came_leaders' => 1, 'rating' => 4 + ($i ? 0 : 1) - $i, 'comment' => $i ? 'Good teaching. Food could have been better.' : 'We came back refreshed. Thank you, Bishop.']);
        }

        $prayer = $this->activity([
            'kind' => 'event', 'territory_id' => $diocese->id, 'title' => 'Diocese Prayer Conference 2027', 'type' => 'prayer_conference', 'audience' => 'everyone',
            'description' => 'Opening the new year in prayer and fasting - three days for every church of the diocese.',
            'starts_at' => $this->at('2027-01-15 09:00'), 'ends_at' => $this->at('2027-01-17 13:00'), 'venue' => 'CCI Wote church grounds',
            'capacity' => 800, 'coordinator' => 'Pst. Grace Wambua', 'speakers' => 'Bishop Peter Kilonzo',
            'agenda' => "Fri: opening night of prayer\nSat: prayer for families, the nation and the youth\nSun: thanksgiving service",
            'open_to' => 'below', 'registration' => true, 'register_by' => '2027-01-08', 'fee_per_person' => 300,
            'planned_income' => 120000, 'planned_spend' => 90000, 'status' => 'published', 'published_at' => $this->at('2026-10-01 10:00'),
        ], $bishop);
        $this->register($prayer, $church, ['youth' => 10, 'adults' => 25, 'leaders' => 3], $benson);

        $meeting = $this->activity([
            'kind' => 'event', 'territory_id' => $diocese->id, 'title' => "Bishop's Leadership Meeting", 'type' => 'leadership_meeting', 'audience' => 'pastors',
            'description' => 'The Bishop meets the senior pastors and regional overseers: the year so far, monthly reports, and the convention.',
            'starts_at' => $this->at('2026-10-24 10:00'), 'ends_at' => $this->at('2026-10-24 14:00'), 'venue' => 'Diocese office, Wote',
            'coordinator' => 'Diocese Secretary', 'agenda' => "Opening prayer\nThe year so far - each region\nMonthly reports: what we learn\nYouth Convention: final plans\nAny other business",
            'open_to' => 'below', 'registration' => true, 'register_by' => '2026-10-20', 'status' => 'published', 'published_at' => $this->at('2026-10-03 10:00'),
        ], $bishop);
        $this->register($meeting, $church, ['leaders' => 1], $benson);
        foreach ($two as $n) {
            $this->register($meeting, $n, ['leaders' => 1], $bishop);
        }

        // The region
        $women = $this->activity([
            'kind' => 'event', 'territory_id' => $region->id, 'title' => "Regional Women's Conference", 'type' => 'womens', 'audience' => 'women',
            'description' => 'A day for the women of every church in the region: worship, teaching and a shared lunch.',
            'starts_at' => $this->at('2026-11-28 09:00'), 'ends_at' => $this->at('2026-11-28 16:00'), 'venue' => 'CCI SULTAN HAMUD',
            'capacity' => 300, 'coordinator' => 'Mama Ruth Manoo', 'speakers' => 'Pst. Grace Wambua',
            'open_to' => 'below', 'registration' => true, 'register_by' => '2026-11-20', 'fee_per_person' => 200,
            'planned_income' => 40000, 'planned_spend' => 35000, 'status' => 'published', 'published_at' => $this->at('2026-10-04 10:00'),
        ], $titus);
        $this->register($women, $church, ['adults' => 35], $benson, ['fee_paid' => 3000]);
        if ($two->first()) {
            $this->register($women, $two->first(), ['adults' => 22], $titus);
        }

        $this->activity([
            'kind' => 'event', 'territory_id' => $region->id, 'title' => "Regional Men's Conference", 'type' => 'mens', 'audience' => 'men',
            'description' => 'Men of the region: faith at home, at work and in the church.',
            'starts_at' => $this->at('2027-02-13 09:00'), 'ends_at' => $this->at('2027-02-13 15:00'), 'venue' => 'Sultan Hamud Stadium',
            'coordinator' => 'Pst. Titus Kenzi', 'open_to' => 'below', 'registration' => true, 'register_by' => '2027-02-06',
            'status' => 'published', 'published_at' => $this->at('2026-10-05 10:00'),
        ], $titus);

        $worship = $this->activity([
            'kind' => 'event', 'territory_id' => $region->id, 'title' => 'Regional Worship Night', 'type' => 'worship_night', 'audience' => 'everyone',
            'description' => "An evening of worship with every church's choir and praise team.",
            'starts_at' => $this->at('2026-09-12 18:00'), 'ends_at' => $this->at('2026-09-12 22:00'), 'venue' => 'CCI SULTAN HAMUD',
            'coordinator' => 'Pst. Titus Kenzi', 'agenda' => "18:00 Opening prayer\n18:15 Choirs, one church at a time\n20:30 Combined praise\n21:30 Word and closing prayer",
            'open_to' => 'below', 'registration' => true, 'status' => 'completed', 'published_at' => $this->at('2026-08-20 10:00'),
            'report_back' => 'Five choirs and about 340 people. We will make it a yearly night - next time with more seats outside.',
        ], $titus);
        $this->register($worship, $church, ['youth' => 40, 'adults' => 60, 'leaders' => 5], $benson, ['came_youth' => 46, 'came_adults' => 72, 'came_leaders' => 5, 'rating' => 5, 'comment' => 'Our choir loved it. Please do it again.']);
        if ($two->first()) {
            $this->register($worship, $two->first(), ['youth' => 25, 'adults' => 30, 'leaders' => 3], $titus, ['came_youth' => 20, 'came_adults' => 33, 'came_leaders' => 3, 'rating' => 4, 'comment' => 'Lovely night; the sound system struggled at the start.']);
        }

        // The church
        $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Revival Week', 'type' => 'revival', 'audience' => 'everyone',
            'description' => 'Seven evenings of preaching and prayer, Sunday to Saturday. Bring a neighbour.',
            'starts_at' => $this->at('2026-10-25 17:30'), 'ends_at' => $this->at('2026-10-31 20:00'), 'venue' => 'CCI SULTAN HAMUD',
            'coordinator' => 'Pst. Benson Manoo', 'speakers' => 'Evangelist Peter Mutua', 'agenda' => "Sun: the call\nMon-Fri: evening preaching and prayer\nSat: baptism and thanksgiving",
            'open_to' => 'region', 'planned_spend' => 25000, 'status' => 'published', 'published_at' => $this->at('2026-10-06 10:00'),
        ], $benson);
        $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Wedding: Daniel & Mary', 'type' => 'wedding', 'audience' => 'everyone',
            'description' => 'The wedding of Daniel Kioko and Mary Ndinda. The whole church is invited.',
            'starts_at' => $this->at('2026-12-12 10:00'), 'ends_at' => $this->at('2026-12-12 15:00'), 'venue' => 'CCI SULTAN HAMUD',
            'coordinator' => 'Pst. Benson Manoo', 'open_to' => 'own', 'status' => 'published', 'published_at' => $this->at('2026-10-02 10:00'),
        ], $benson);
        $harambee = $this->activity([
            'kind' => 'event', 'territory_id' => $church->id, 'title' => 'Harambee for the Church Van', 'type' => 'fundraising', 'audience' => 'everyone',
            'description' => 'Raising the money for a church van, so the elderly and the youth can get to services and outreach.',
            'starts_at' => $this->at('2026-08-30 11:00'), 'ends_at' => $this->at('2026-08-30 16:00'), 'venue' => 'CCI SULTAN HAMUD',
            'coordinator' => 'Church Committee', 'speakers' => 'Hon. guest of honour: the area MCA', 'open_to' => 'region', 'registration' => true,
            'planned_income' => 200000, 'planned_spend' => 15000, 'status' => 'completed', 'published_at' => $this->at('2026-08-01 10:00'),
            'report_back' => 'KES 236,400 raised - above the KES 200,000 target. Thank you to the churches of the region who came. The van is ordered.',
        ], $benson);
        if ($two->first()) {
            $this->register($harambee, $two->first(), ['adults' => 15, 'leaders' => 2], $titus, ['came_adults' => 18, 'came_leaders' => 2, 'rating' => 5, 'comment' => 'A joyful day. Glad to stand with you.']);
        }
    }

    // -------------------------------------------------------------- reports

    private function reports(Territory $church, Territory $region, $neighbours, User $titus, User $benson): void
    {
        $figures = app(MonthlyFigures::class);

        $september = MonthlyReport::create([
            'uid' => $this->uid(), 'territory_id' => $church->id, 'year' => 2026, 'month' => 9, 'status' => 'seen',
            'figures' => $figures->for($church, 2026, 9),
            'achievements' => "Harvest Thanksgiving filled the church - 184 people, and an offering of KES 48,500.\nThe Wednesday Bible Study grew to over 25 every week.",
            'challenges' => 'Sunday attendance dips at month end, when many members travel to Nairobi for work.',
            'prayer_requests' => 'For rain before planting, and for the youth facing the national exams in November.',
            'support_needed' => 'Advice on fundraising for the new church roof.',
            'testimonies' => 'Three families gave their lives to Christ at Harvest Thanksgiving.',
            'next_month' => 'Youth Kesha on 16 October, pastoral visits to Kima village, and starting the roof fundraiser.',
            'pastoral_visits' => 12, 'outreach' => 'Open-air meeting at Sultan Hamud market on 19 September.',
            'sent_at' => $this->at('2026-10-03 18:20'), 'sent_by' => $benson->id, 'seen_at' => $this->at('2026-10-04 08:45'), 'seen_by' => $titus->id,
            'created_by' => $benson->id, 'updated_by' => $benson->id,
        ]);
        MonthlyReportComment::create(['monthly_report_id' => $september->id, 'user_id' => $titus->id, 'territory_id' => $region->id,
            'body' => 'Thank you Pastor Benson - a full report. Praise God for the three families. For the roof, talk to CCI Upete, who did theirs last year.']);
        MonthlyReportComment::create(['monthly_report_id' => $september->id, 'user_id' => $benson->id, 'territory_id' => $church->id,
            'body' => 'Thank you, Overseer. I will call Pastor Stephen this week.']);

        MonthlyReport::create([
            'uid' => $this->uid(), 'territory_id' => $church->id, 'year' => 2026, 'month' => 10, 'status' => 'draft',
            'achievements' => 'The Bishop\'s visit is planned for 25 October.',
            'created_by' => $benson->id, 'updated_by' => $benson->id,
        ]);

        // One neighbour sent September, another hasn't (so it shows late).
        if ($first = $neighbours->first()) {
            $pastor = $this->leader($first, 'Senior Pastor') ?? $benson;
            MonthlyReport::create([
                'uid' => $this->uid(), 'territory_id' => $first->id, 'year' => 2026, 'month' => 9, 'status' => 'sent',
                'figures' => $figures->for($first, 2026, 9),
                'achievements' => 'Baptised nine new believers on 20 September.',
                'challenges' => 'Two families moved away for work.',
                'prayer_requests' => 'For the sick in the congregation.',
                'pastoral_visits' => 7, 'sent_at' => $this->at('2026-10-04 20:05'), 'sent_by' => $pastor->id,
                'created_by' => $pastor->id, 'updated_by' => $pastor->id,
            ]);
        }

        MonthlyReport::create([
            'uid' => $this->uid(), 'territory_id' => $region->id, 'year' => 2026, 'month' => 9, 'status' => 'sent',
            'figures' => $figures->for($region, 2026, 9),
            'achievements' => 'The churches of the region planned the November crusade together.',
            'challenges' => 'Several churches still record attendance late.',
            'prayer_requests' => 'For the crusade and for rain.',
            'sent_at' => $this->at('2026-10-05 09:30'), 'sent_by' => $titus->id, 'created_by' => $titus->id, 'updated_by' => $titus->id,
        ]);
    }

    // ------------------------------------------------------------- messages

    private function batch(Territory $from, User $by, string $subject, string $body, string $summary, $people, string $sentAt, array $readBy = [], string $channel = 'app'): MessageBatch
    {
        $batch = MessageBatch::create([
            'uid' => $this->uid(), 'territory_id' => $from->id, 'channel' => $channel, 'subject' => $subject, 'body' => $body,
            'audience' => ['demo' => true], 'summary' => $summary, 'recipient_count' => $people->count(), 'sent_count' => $people->count(),
            'failed_count' => 0, 'status' => 'sent', 'sent_at' => $this->at($sentAt), 'created_by' => $by->id,
        ]);
        foreach ($people as $p) {
            MessageRecipient::create([
                'message_batch_id' => $batch->id, 'user_id' => $p->id, 'name' => trim("{$p->firstname} {$p->lastname}"),
                'phone' => $p->phone, 'email' => $p->email, 'place_id' => $p->territory_id, 'role' => $p->role,
                'sms_status' => $channel === 'app' ? null : 'logged', 'notified_at' => $this->at($sentAt),
                'read_at' => in_array($p->id, $readBy, true) ? $this->at($sentAt)->addHours(2) : null,
            ]);
        }

        return $batch;
    }

    private function reply(MessageBatch $batch, User $user, Territory $place, string $body): void
    {
        $recipient = MessageRecipient::where('message_batch_id', $batch->id)->where('user_id', $user->id)->first();
        MessageReply::create(['message_batch_id' => $batch->id, 'message_recipient_id' => $recipient?->id, 'user_id' => $user->id, 'territory_id' => $place->id, 'body' => $body]);
    }

    private function messages(Territory $diocese, Territory $region, Territory $church, User $bishop, User $titus, User $benson): void
    {
        $churchIds = Territory::where('territory_type', 'church')->pluck('id')->all();
        $regionChurches = Territory::where('parent_territory_id', $region->id)->where('territory_type', 'church')->pluck('id')->all();

        $pastors = $this->peopleAt($churchIds, ['Senior Pastor']);
        $this->batch($diocese, $bishop, 'Youth Convention registration is open',
            "Dear Pastor,\n\nRegistration for the Diocese Youth Convention (4-6 December, Wote) is now open. Please register how many youth are coming from your church by 20 November. The fee is KES 500 per person.\n\nGod bless,\nBishop Peter Kilonzo",
            'Senior Pastors of every church', $pastors, '2026-10-02 10:00', $pastors->pluck('id')->reject(fn ($id) => $id === $benson->id)->take(40)->values()->all(), 'both');

        $regionPastors = $this->peopleAt($regionChurches, ['Senior Pastor', 'Associate Pastor']);
        $meeting = $this->batch($region, $titus, "Regional pastors' meeting this Thursday",
            "Dear Pastors,\n\nOur monthly meeting is this Thursday, 8 October, 10:00 at CCI SULTAN HAMUD. Please bring your September figures and your plans for the crusade.\n\nTitus Kenzi, Regional Overseer",
            "Pastors of Sultan Hamud Region's churches", $regionPastors, '2026-10-04 16:30', $regionPastors->pluck('id')->all(), 'both');
        $this->reply($meeting, $benson, $church, 'Thank you Overseer - I will be there, with our secretary.');
        if ($other = $regionPastors->first(fn ($p) => $p->id !== $benson->id)) {
            $this->reply($meeting, User::find($other->id), Territory::find($other->territory_id), 'Noted, I will come.');
        }

        $reminder = $this->peopleAt($regionChurches, ['Senior Pastor']);
        $this->batch($region, $titus, 'Reminder: September reports are due on 5 October',
            'A reminder that September\'s monthly report is due on Monday 5 October. Thank you to those who have already sent theirs.',
            "Senior Pastors of Sultan Hamud Region's churches", $reminder, '2026-10-05 07:30', []);

        $council = $this->peopleAt([$church->id])->reject(fn ($p) => $p->id === $benson->id)->values();
        $council = $council->isEmpty() ? $this->peopleAt([$church->id]) : $council;
        $after = $this->batch($church, $benson, 'Council meeting after service on Sunday',
            "Dear Council,\n\nWe meet after the service this Sunday, 11 October, in the church office. Agenda: the roof fundraiser and the Bishop's visit.\n\nPastor Benson",
            "CCI SULTAN HAMUD's leaders", $council, '2026-10-05 12:00', $council->pluck('id')->take(1)->all());
        if ($first = $council->first()) {
            $this->reply($after, User::find($first->id), $church, 'Thank you Pastor. I will bring the roof quotations.');
        }

        foreach ([
            ['Sunday reminder', 'sms', null, 'Dear {name}, we look forward to seeing you at church this Sunday at 9am. God bless - {place}'],
            ['Meeting reminder', 'both', 'Meeting reminder', "Dear {name},\n\nA reminder of our meeting on [day] at [time] in [place].\n\n{sender}"],
            ['Report reminder', 'app', 'Your monthly report', 'Dear {name}, a reminder that this month\'s report is due on the 5th. Thank you - {sender}'],
        ] as [$name, $channel, $subject, $body]) {
            MessageTemplate::create(['territory_id' => $church->id, 'name' => $name, 'channel' => $channel, 'subject' => $subject, 'body' => $body]);
        }
    }

    // --------------------------------------------------------- notifications

    private function notifications(User $benson, Territory $church, Territory $region, Territory $diocese): void
    {
        $convention = Activity::where('uid', 'like', self::UID_PREFIX.'%')->where('title', 'Diocese Youth Convention 2026')->first();
        $inbox = MessageRecipient::whereHas('batch', fn ($q) => $q->where('uid', 'like', self::UID_PREFIX.'%')->where('territory_id', $diocese->id))
            ->where('user_id', $benson->id)->first();
        $notes = [
            new PlaceNotification('invitation', self::NOTIFICATION_TITLES[0], 'Register how many are coming by 20 November.', $convention ? "/church/events/event?id={$convention->id}" : null, $diocese, 'ri-calendar-event-line'),
            new PlaceNotification('report', self::NOTIFICATION_TITLES[1], 'Your September 2026 report was marked as seen.', '/church/monthly-reports/report?year=2026&month=9', $region, 'ri-eye-line'),
            new PlaceNotification('report', self::NOTIFICATION_TITLES[2], 'Titus Kenzi: "Thank you Pastor Benson - a full report…"', '/church/monthly-reports/report?year=2026&month=9', $region, 'ri-chat-3-line'),
            new PlaceNotification('message', self::NOTIFICATION_TITLES[3], 'Youth Convention registration is open', $inbox ? "/church/messages/?open={$inbox->id}" : '/church/messages/', $diocese, 'ri-mail-line'),
        ];
        foreach ($notes as $note) {
            $benson->notify($note);
        }
    }
}
