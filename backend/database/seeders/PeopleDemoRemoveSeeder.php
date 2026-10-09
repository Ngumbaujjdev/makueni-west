<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\CareContact;
use App\Models\CareRecord;
use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Takes PeopleDemoSeeder's demo people away again - only them, found by
 * their demo numbers (+254 700 000 xxx) - with their visits, follow-ups,
 * pastoral care, places in ministries, transfers and audits - and the
 * attendance, demo gathering types and events it added for the ministries,
 * and its rooms, bookings, equipment, loans, repairs and duty rota.
 *
 *   php artisan db:seed --class=PeopleDemoRemoveSeeder
 */
class PeopleDemoRemoveSeeder extends Seeder
{
    public function run(): void
    {
        $n = self::removeDemo();
        $this->command?->info("   🧹 Removed {$n} demo people.");
    }

    /** The demo people's ids - by their demo numbers. */
    public static function ids(): array
    {
        return Person::withTrashed()->where('phone', 'like', '+254700000%')->pluck('id')->all();
    }

    public static function removeDemo(): int
    {
        // What the demo added outside the register (P4): attendance, demo gathering types, events.
        DB::table('church_attendance_records')->where('notes', PeopleDemoSeeder::DEMO_NOTE)->delete();
        $types = DB::table('gathering_types')->where('slug', 'like', 'demo-%')->pluck('id')->all();
        if ($types && ! DB::table('church_attendance_records')->whereIn('gathering_type_id', $types)->exists()) {
            DB::table('ministries')->whereIn('gathering_type_id', $types)->update(['gathering_type_id' => null]);
            DB::table('gathering_types')->whereIn('id', $types)->delete();
        }
        Activity::withTrashed()->where('description', PeopleDemoSeeder::DEMO_ACTIVITY)->get()->each->forceDelete();
        // Facilities (P5): the demo's rooms and equipment, with their bookings, loans and repairs.
        $rooms = DB::table('rooms')->where('notes', PeopleDemoSeeder::DEMO_ROOM)->pluck('id')->all();
        $items = DB::table('equipment')->where('serial', 'like', PeopleDemoSeeder::DEMO_SERIAL.'%')->pluck('id')->all();
        DB::table('maintenance_jobs')->where(fn ($q) => $q->whereIn('equipment_id', $items ?: [0])->orWhereIn('room_id', $rooms ?: [0]))->delete();
        DB::table('equipment_loans')->whereIn('equipment_id', $items ?: [0])->delete();
        // Their photos and receipts (round 2) - the files too.
        foreach (DB::table('equipment_photos')->whereIn('equipment_id', $items ?: [0])->get() as $p) {
            app(\App\Services\Images\ImageEngine::class)->delete($p->path, $p->thumb_path);
        }
        \Spatie\MediaLibrary\MediaCollections\Models\Media::where('model_type', 'equipment')->whereIn('model_id', $items ?: [0])->get()->each->delete();
        DB::table('equipment')->whereIn('id', $items ?: [0])->delete();
        DB::table('room_bookings')->whereIn('room_id', $rooms ?: [0])->delete();
        DB::table('rooms')->whereIn('id', $rooms ?: [0])->delete();
        $ids = self::ids();
        DB::table('duty_rota')->whereIn('person_id', $ids ?: [0])->delete();
        // The duty teams lose the demo people (the Teams page).
        DB::table('duty_team_members')->whereIn('person_id', $ids ?: [0])->delete();
        if (! $ids) {
            return 0;
        }
        $care = CareRecord::withTrashed()->whereIn('person_id', $ids)->pluck('id')->all();
        $children = [
            'care_record' => $care,
            'care_contact' => CareContact::whereIn('care_record_id', $care ?: [0])->pluck('id')->all(),
            'person_transfer' => PersonTransfer::whereIn('person_id', $ids)->pluck('id')->all(),
            'visitor_visit' => VisitorVisit::whereIn('person_id', $ids)->pluck('id')->all(),
            'visitor_followup' => VisitorFollowup::whereIn('person_id', $ids)->pluck('id')->all(),
            'ministry_member' => DB::table('ministry_members')->whereIn('person_id', $ids)->pluck('id')->all(),
            'ministry_leader' => DB::table('ministry_leaders')->whereIn('person_id', $ids)->pluck('id')->all(),
        ];
        DB::table('audits')->where(function ($q) use ($ids, $children) {
            $q->where(fn ($w) => $w->where('auditable_type', 'person')->whereIn('auditable_id', $ids));
            foreach ($children as $type => $childIds) {
                $q->orWhere(fn ($w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $childIds ?: [0]));
            }
        })->delete();
        CareContact::whereIn('care_record_id', $care ?: [0])->delete();
        CareRecord::withTrashed()->whereIn('id', $care ?: [0])->forceDelete();
        // Their places in ministries (the ministries themselves are the church's own and stay).
        DB::table('ministry_members')->whereIn('person_id', $ids)->delete();
        DB::table('ministry_leaders')->whereIn('person_id', $ids)->delete();
        PersonTransfer::whereIn('person_id', $ids)->delete();
        VisitorVisit::whereIn('person_id', $ids)->delete();
        VisitorFollowup::whereIn('person_id', $ids)->delete();
        Person::withTrashed()->whereIn('id', $ids)->forceDelete();

        return count($ids);
    }
}
