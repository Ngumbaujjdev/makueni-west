<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\Territory;
use App\Services\Facilities\Facilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rooms and who has them when (P5). A booking that overlaps another in the
 * same room is refused (409) and names the clash; a weekly booking is
 * checked on every date. Only the booker or a facilities manager changes or
 * cancels one; a booking made for an event moves with the event.
 */
class BookingsController extends FacilitiesBase
{
    /** GET /bookings?from=&to=&room= - the booked times, weekly ones expanded. */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'room' => ['nullable', 'integer']]);
        $from = CarbonImmutable::parse($d['from'], Facilities::TZ)->startOfDay();
        $to = CarbonImmutable::parse($d['to'], Facilities::TZ)->startOfDay()->min($from->addDays(62));
        $rows = $this->facilities->occurrences($church, $from, $to, $d['room'] ?? null);

        return $this->ok(['items' => $rows->map(fn ($o) => $this->facilities->occurrenceRow($o, $request->user(), $church))->values(), 'can' => $this->can($request, $church)]);
    }

    /** GET /bookings/check?room_id=&date=&start=&end=&repeat=&repeat_until=&except= - the clash, before booking. */
    public function check(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $this->validated($request, $church, check: true);
        if ($d instanceof JsonResponse) {
            return $d;
        }
        $clash = $this->facilities->clash($church, $d['room']->id, $d['slots'], $request->integer('except') ?: null);
        $day = CarbonImmutable::parse($request->input('date'), Facilities::TZ);
        $taken = $this->facilities->occurrences($church, $day->startOfDay(), $day->addDay()->startOfDay(), $d['room']->id, $request->integer('except') ?: null);

        return $this->ok(['clash' => $clash, 'day' => $taken->map(fn ($o) => $this->facilities->occurrenceRow($o))->values(), 'dates' => count($d['slots'])]);
    }

    /** POST /bookings */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request, 'book');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $this->validated($request, $church);
        if ($d instanceof JsonResponse) {
            return $d;
        }
        if ($clash = $this->facilities->clash($church, $d['room']->id, $d['slots'])) {
            return $this->clashReply($clash);
        }
        $b = RoomBooking::create($d['fields'] + ['territory_id' => $church->id, 'booked_by' => $request->user()->id, 'status' => 'booked']);
        $n = count($d['slots']);

        return $this->ok($this->row($b, $request, $church), $n > 1 ? "{$d['room']->name} booked - {$n} weeks." : "{$d['room']->name} booked.", 201);
    }

    /** PUT /bookings/{id} - the booker or a facilities manager. */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $b, $error] = $this->booking($request, $id);
        if ($error) {
            return $error;
        }
        $d = $this->validated($request, $church, existing: $b);
        if ($d instanceof JsonResponse) {
            return $d;
        }
        if ($clash = $this->facilities->clash($church, $d['room']->id, $d['slots'], $b->id)) {
            return $this->clashReply($clash);
        }
        $b->fill($d['fields'])->save();

        return $this->ok($this->row($b->fresh(), $request, $church), 'Booking changed.');
    }

    /** DELETE /bookings/{id} - cancels it (the booker or a facilities manager). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [, $b, $error] = $this->booking($request, $id);
        if ($error) {
            return $error;
        }
        $b->update(['status' => 'cancelled']);

        return $this->ok(['id' => $b->id], 'Booking cancelled.');
    }

    /** POST /rooms · PUT /rooms/{id} · DELETE /rooms/{id} - those who manage facilities. */
    public function saveRoom(Request $request, ?int $id = null): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $room = $id ? Room::where('territory_id', $church->id)->find($id) : null;
        if ($id && ! $room) {
            return $this->notFound("That room isn't in your church.");
        }
        $s = $room ? 'sometimes' : 'required';
        $d = $request->validate([
            'name' => [$s, 'string', 'max:80', Rule::unique('rooms', 'name')->where('territory_id', $church->id)->whereNull('deleted_at')->ignore($room?->id)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,100000'],
            'bookable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'colour' => ['sometimes', 'nullable', Rule::in(Room::COLOURS)],
            'order' => ['sometimes', 'integer', 'between:0,1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], ['name.unique' => 'There is already a room with that name.']);
        if ($room) {
            $room->fill($d)->save();
        } else {
            $room = Room::create($d + ['territory_id' => $church->id, 'order' => (int) Room::where('territory_id', $church->id)->max('order') + 1]);
        }

        return $this->ok($this->facilities->roomRow($room->fresh()), $id ? 'Room saved.' : "{$room->name} added.", $id ? 200 : 201);
    }

    public function destroyRoom(Request $request, int $id): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $room = Room::where('territory_id', $church->id)->find($id);
        if (! $room) {
            return $this->notFound("That room isn't in your church.");
        }
        $coming = RoomBooking::where('room_id', $room->id)->where('status', 'booked')
            ->where(fn ($w) => $w->where('starts_at', '>=', $this->facilities->now()->format('Y-m-d H:i:s'))->orWhere(fn ($r) => $r->where('repeat', 'weekly')->where('repeat_until', '>=', $this->facilities->today()->toDateString())))->exists();
        if ($coming) {
            return $this->unprocessable('id', "{$room->name} has bookings to come - cancel them or switch the room off instead.");
        }
        $room->delete();

        return $this->ok(['id' => $room->id], "{$room->name} removed.");
    }

    // ------------------------------------------------------------------ helpers

    private function row(RoomBooking $b, Request $request, Territory $church): array
    {
        $b->loadMissing(['room', 'booker', 'ministry', 'activity']);
        $start = Facilities::local($b->starts_at);

        return $this->facilities->occurrenceRow(['booking' => $b, 'start' => $start, 'end' => Facilities::local($b->ends_at)], $request->user(), $church);
    }

    private function clashReply(array $clash): JsonResponse
    {
        $message = Facilities::clashMessage($clash);

        return response()->json(['success' => false, 'status' => 409, 'message' => $message, 'errors' => ['start' => [$message]], 'data' => ['clash' => $clash]], 409);
    }

    /** [church, booking, error] - the booker or a facilities manager; an event's booking moves with the event. */
    private function booking(Request $request, int $id): array
    {
        $church = $this->church($request, 'book');
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $b = RoomBooking::where('territory_id', $church->id)->where('status', 'booked')->find($id);
        if (! $b) {
            return [$church, null, $this->notFound("That booking isn't in your church.")];
        }
        if ((int) $b->booked_by !== (int) $request->user()->id && ! $this->facilities->canManage($request->user(), $church)) {
            return [$church, null, $this->forbidden('Only the person who booked it, or those who look after the facilities, can change it.')];
        }
        if ($b->activity_id) {
            return [$church, null, $this->unprocessable('id', 'This room is booked for an event - change the event and it moves with it.')];
        }

        return [$church, $b, null];
    }

    /** The checked fields and the times they take - or the error. */
    private function validated(Request $request, Territory $church, bool $check = false, ?RoomBooking $existing = null): array|JsonResponse
    {
        $d = $request->validate([
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->where('territory_id', $church->id)->where('active', true)->where('bookable', true)->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'repeat' => ['sometimes', Rule::in(['none', 'weekly'])],
            'repeat_until' => ['nullable', 'required_if:repeat,weekly', 'date_format:Y-m-d', 'after:date'],
            'purpose' => [$check ? 'nullable' : 'required', 'string', 'max:160'],
            'ministry_id' => ['nullable', 'integer', Rule::exists('ministries', 'id')->where('territory_id', $church->id)->whereNull('deleted_at')],
        ], [
            'room_id.exists' => 'Pick one of our rooms that can be booked.',
            'end.after' => 'It has to end after it starts.',
            'repeat_until.after' => 'Repeat until a date after the first one.',
            'purpose.required' => 'Say what the room is for.',
        ]);
        $repeat = $d['repeat'] ?? 'none';
        $start = CarbonImmutable::parse("{$d['date']} {$d['start']}", Facilities::TZ);
        $end = CarbonImmutable::parse("{$d['date']} {$d['end']}", Facilities::TZ);
        [$open, $close] = $this->facilities->hours($church);
        if ($d['start'] < $open || $d['end'] > $close) {
            return $this->unprocessable('start', "Rooms can be booked between {$open} and {$close}.");
        }
        // A change that keeps the first date (a weekly booking under way) isn't held to "it has passed".
        $sameStart = $existing && Facilities::local($existing->starts_at)->format('Y-m-d H:i') === $start->format('Y-m-d H:i');
        if (! $check && ! $sameStart) {
            $notice = $this->facilities->minNoticeHours($church);
            if ($start->toDateString() < $this->facilities->today()->toDateString()) {
                return $this->unprocessable('date', 'That date has passed.');
            }
            if ($end->lte($this->facilities->now())) {
                return $this->unprocessable('start', 'That time has passed.');
            }
            if ($notice > 0 && $start->lt($this->facilities->now()->addHours($notice))) {
                return $this->unprocessable('start', "Book at least {$notice} hours ahead.");
            }
        }
        if ($repeat === 'weekly' && CarbonImmutable::parse($d['repeat_until'])->gt($start->addWeeks(Facilities::MAX_WEEKS))) {
            return $this->unprocessable('repeat_until', 'A weekly booking runs a year at most.');
        }

        return [
            'room' => Room::find($d['room_id']),
            'slots' => $this->facilities->slots($start, $end, $repeat, $d['repeat_until'] ?? null),
            'fields' => [
                'room_id' => $d['room_id'], 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
                'purpose' => $d['purpose'] ?? '', 'ministry_id' => $d['ministry_id'] ?? null,
                'repeat' => $repeat, 'repeat_until' => $repeat === 'weekly' ? $d['repeat_until'] : null,
            ],
        ];
    }
}
