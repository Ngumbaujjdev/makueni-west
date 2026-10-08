<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Takes PeopleDemoSeeder's demo people away again - only them, found by
 * their demo numbers (+254 700 000 xxx) - with their visits, follow-ups,
 * transfers and audits.
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
        $ids = self::ids();
        if (! $ids) {
            return 0;
        }
        $children = [
            'person_transfer' => PersonTransfer::whereIn('person_id', $ids)->pluck('id')->all(),
            'visitor_visit' => VisitorVisit::whereIn('person_id', $ids)->pluck('id')->all(),
            'visitor_followup' => VisitorFollowup::whereIn('person_id', $ids)->pluck('id')->all(),
        ];
        DB::table('audits')->where(function ($q) use ($ids, $children) {
            $q->where(fn ($w) => $w->where('auditable_type', 'person')->whereIn('auditable_id', $ids));
            foreach ($children as $type => $childIds) {
                $q->orWhere(fn ($w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $childIds ?: [0]));
            }
        })->delete();
        PersonTransfer::whereIn('person_id', $ids)->delete();
        VisitorVisit::whereIn('person_id', $ids)->delete();
        VisitorFollowup::whereIn('person_id', $ids)->delete();
        Person::withTrashed()->whereIn('id', $ids)->forceDelete();

        return count($ids);
    }
}
