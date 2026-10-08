<?php

namespace App\Console\Commands;

use App\Models\DutyRota;
use App\Models\Territory;
use App\Services\Facilities\Facilities;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Settings\Settings;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Facilities (P5): the day before a service, a text to each person on duty
 * who has a phone - for churches that switched it on (Settings > Facilities,
 * off by default), once their chosen time has come. Demo numbers are never
 * texted, and nobody is texted twice for the same day.
 */
class RemindDuty extends Command
{
    protected $signature = 'facilities:duty-reminders {--at= : Pretend it is this moment (YYYY-MM-DD HH:MM)}';

    protected $description = "Text tomorrow's duty rota to the people on it (churches that switched it on)";

    public function handle(Settings $settings, PlaceMessenger $messenger): int
    {
        $now = $this->option('at') ? CarbonImmutable::parse($this->option('at'), Facilities::TZ) : CarbonImmutable::now(Facilities::TZ);
        $tomorrow = $now->startOfDay()->addDay();
        $sent = 0;
        $churchIds = DutyRota::where('on', $tomorrow->toDateString())->distinct()->pluck('territory_id');
        foreach (Territory::whereIn('id', $churchIds)->get() as $church) {
            if (! $settings->get('facilities.duty_reminder', $church) || $now->format('H:i') < (string) ($settings->get('facilities.duty_reminder_time', $church) ?: '18:00')) {
                continue;
            }
            $entries = DutyRota::with('person')->where('territory_id', $church->id)->where('on', $tomorrow->toDateString())->get();
            foreach ($entries->groupBy('person_id') as $personId => $theirs) {
                $person = $theirs->first()->person;
                if (! $personId || ! $person?->phone || Phone::isDemo($person->phone) || $person->anonymised_at) {
                    continue;
                }
                if (! Cache::add("duty-remind:{$church->id}:{$tomorrow->toDateString()}:{$personId}", true, now()->addDays(3))) {
                    continue;
                }
                $what = $theirs->map(fn ($e) => strtolower(DutyRota::DUTIES[$e->duty][0] ?? $e->duty)." at the {$e->service}")->unique()->implode(' and ');
                $messenger->sms($church, $person->phone, "Hello {$person->first_name}, a reminder: you're on {$what} tomorrow, {$tomorrow->format('D j M')}. Thank you for serving!", 'duty_reminder');
                $sent++;
            }
        }
        $this->info("Sent {$sent} duty reminder(s).");

        return self::SUCCESS;
    }
}
