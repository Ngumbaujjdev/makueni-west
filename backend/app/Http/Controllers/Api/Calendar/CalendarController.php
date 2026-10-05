<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Territory;
use App\Services\Calendar\Calendar;
use App\Services\Calendar\Ics;
use App\Services\Calendar\LifeFeed;
use App\Support\CalendarAccess;
use App\Support\SettingsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The calendar of a church, region or the diocese (docs/specs/calendar-spec.md):
 * its own events, everything shared from above (the CCI calendar at the
 * top) and, when asked, the shared events of the places below.
 */
class CalendarController extends Controller
{
    /** The furthest apart "from" and "to" may be. */
    public const MAX_RANGE_DAYS = 400;

    public function __construct(private Calendar $calendar) {}

    /** GET /calendar/events?from=&to=&layers[]=&kinds[]=&sources[]= */
    public function events(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok($this->feed($request, $place));
    }

    /** GET /calendar/ics - the same items as a .ics file to import into Google or Outlook (no live sync). */
    public function ics(Request $request): JsonResponse|Response
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $body = Ics::build($this->feed($request, $place), "{$place->name} calendar");
        $name = Str::slug($place->name).'-calendar.ics';

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /** The occurrences asked for: one set of rules for the page and the download. */
    private function feed(Request $request, Territory $place): array
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'layers' => ['sometimes', 'array'],
            'layers.*' => [Rule::in(Calendar::LAYERS)],
            'kinds' => ['sometimes', 'array'],
            'kinds.*' => [Rule::in(array_keys(CalendarEvent::KINDS))],
            'sources' => ['sometimes', 'array'],
            'sources.*' => [Rule::in(LifeFeed::SOURCES)],
        ]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => ['Ask for at most '.self::MAX_RANGE_DAYS.' days at a time.']]);
        }

        return $this->calendar->occurrences($place, $from, $to, $data['layers'] ?? ['cci', 'diocese', 'region', 'ours'], $data['kinds'] ?? [], $request->user(), $data['sources'] ?? LifeFeed::SOURCES);
    }

    /** GET /calendar/overview - the KPI cards, plus what the page needs to know about this place. */
    public function overview(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $user = $request->user();

        return $this->ok($this->calendar->overview($place, $user) + [
            'place' => ['id' => $place->id, 'name' => $place->name, 'type' => $place->territory_type->value],
            'can' => ['manage' => CalendarAccess::can($user, $place, 'manage'), 'cci' => (bool) $user->hasGlobalAccess()],
            'kinds' => CalendarEvent::KINDS,
            'national' => CalendarEvent::national()?->only(['id', 'name']),
        ]);
    }

    /** POST /calendar/events - an event of this place, or (global admins, cci: true) of the CCI calendar. */
    public function store(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $user = $request->user();
        $cci = $request->boolean('cci');
        if ($cci ? ! $user->hasGlobalAccess() : ! CalendarAccess::can($user, $place, 'manage')) {
            return $this->forbidden($cci ? 'Only global admins add to the CCI calendar.' : "Your role can't add events here.");
        }
        $owner = $cci ? CalendarEvent::national() : $place;
        if (! $owner) {
            return $this->forbidden('There is no national (CCI) territory to hold the CCI calendar.');
        }
        $event = CalendarEvent::create(self::validated($request->all(), $owner) + [
            'territory_id' => $owner->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'source' => 'manual',
        ]);

        return $this->ok($this->present($event), 'Event added.', 201);
    }

    /** PUT /calendar/events/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$place, $event, $error] = $this->editable($request, $id);
        if ($error) {
            return $error;
        }
        $event->fill(self::validated($request->all(), $event->territory) + ['updated_by' => $request->user()->id])->save();

        return $this->ok($this->present($event->fresh('territory')), 'Event saved.');
    }

    /** DELETE /calendar/events/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$place, $event, $error] = $this->editable($request, $id);
        if ($error) {
            return $error;
        }
        $event->delete();

        return response()->json(null, 204);
    }

    /**
     * An event's fields, checked. The CCI calendar and the diocese always
     * share with the places below; a church shares only when it says so.
     *
     * @throws ValidationException
     */
    public static function validated(array $input, Territory $owner): array
    {
        $v = Validator::make($input, [
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(array_keys(CalendarEvent::KINDS))],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'all_day' => ['sometimes', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:all_day,false,0'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:160'],
            'repeats' => ['sometimes', Rule::in(CalendarEvent::REPEATS)],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:starts_on', 'required_if:repeats,weekly,monthly'],
            'shared_below' => ['sometimes', 'boolean'],
        ], [
            'ends_on.after_or_equal' => 'The end date can\'t be before the start.',
            'start_time.required_if' => 'Give a start time, or make it an all-day event.',
            'repeat_until.required_if' => 'Say until when it repeats.',
            'repeat_until.after_or_equal' => 'It can\'t stop repeating before it starts.',
        ]);
        $v->after(function ($v) use ($input) {
            $allDay = filter_var($input['all_day'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $oneDay = empty($input['ends_on']) || ($input['ends_on'] ?? null) === ($input['starts_on'] ?? null);
            if (! $allDay && $oneDay && ! empty($input['start_time']) && ! empty($input['end_time']) && $input['end_time'] <= $input['start_time']) {
                $v->errors()->add('end_time', 'It has to end after it starts.');
            }
        });
        $data = $v->validate();

        $allDay = (bool) ($data['all_day'] ?? true);
        $repeats = $data['repeats'] ?? 'none';
        $type = $owner->territory_type?->value;

        return [
            'title' => trim($data['title']),
            'kind' => $data['kind'],
            'description' => $data['description'] ?? null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? $data['starts_on'],
            'all_day' => $allDay,
            'start_time' => $allDay ? null : ($data['start_time'] ?? null),
            'end_time' => $allDay ? null : ($data['end_time'] ?? null),
            'location' => $data['location'] ?? null,
            'repeats' => $repeats,
            'repeat_until' => $repeats === 'none' ? null : ($data['repeat_until'] ?? null),
            'shared_below' => in_array($type, ['global', 'diocese'], true) ? true : (bool) ($data['shared_below'] ?? $type !== 'church'),
        ];
    }

    private function present(CalendarEvent $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'kind' => $e->kind,
            'description' => $e->description,
            'starts_on' => $e->starts_on?->toDateString(),
            'ends_on' => $e->ends_on?->toDateString(),
            'all_day' => $e->all_day,
            'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null,
            'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
            'location' => $e->location,
            'repeats' => $e->repeats,
            'repeat_until' => $e->repeat_until?->toDateString(),
            'shared_below' => $e->shared_below,
            'cci' => $e->isCci(),
            'owner' => ['id' => $e->territory_id, 'name' => $e->territory?->name],
        ];
    }

    /** @return array{0: ?Territory, 1: ?CalendarEvent, 2: ?JsonResponse} */
    private function editable(Request $request, int $id): array
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return [null, null, $place];
        }
        $event = CalendarEvent::with('territory')->find($id);
        if (! $event) {
            return [$place, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That event no longer exists.'], 404)];
        }
        if (! CalendarAccess::canEdit($request->user(), $place, $event)) {
            return [$place, $event, $this->forbidden($event->isCci() ? 'Only global admins change the CCI calendar.' : 'Only the place that added this event can change it.')];
        }

        return [$place, $event, null];
    }

    /** The place whose calendar this is, or a 403. */
    private function place(Request $request): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = SettingsAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden("This isn't your calendar.");
        }

        return CalendarAccess::can($request->user(), $place, 'read') ? $place : $this->forbidden("Your role can't see this calendar.");
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
