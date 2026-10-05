<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Calendar\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CCI national calendar (docs/specs/calendar-spec.md) - global admins
 * only: the year's CCI events, a template, and importing the year's
 * calendar from an Excel or CSV file, checked row by row before saving.
 */
class CciCalendarController extends Controller
{
    public const MAX_ROWS = 500;

    /** The template's columns, in order. */
    public const COLUMNS = ['title', 'kind', 'starts_on', 'ends_on', 'all_day', 'start_time', 'end_time', 'location', 'description', 'repeats', 'repeat_until'];

    /** GET /calendar/cci?year= */
    public function index(Request $request, Calendar $calendar): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $year = (int) ($request->query('year') ?: now()->year);

        return $this->ok([
            'year' => $year,
            'national' => CalendarEvent::national()?->only(['id', 'name']),
            'events' => $calendar->cciYear($year)->map(fn (CalendarEvent $e) => [
                'id' => $e->id, 'title' => $e->title, 'kind' => $e->kind, 'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on->toDateString(),
                'all_day' => $e->all_day, 'start_time' => $e->start_time ? substr($e->start_time, 0, 5) : null, 'end_time' => $e->end_time ? substr($e->end_time, 0, 5) : null,
                'location' => $e->location, 'description' => $e->description, 'repeats' => $e->repeats, 'repeat_until' => $e->repeat_until?->toDateString(), 'source' => $e->source,
            ])->values(),
        ]);
    }

    /** GET /calendar/cci/template - a CSV to fill in. */
    public function template(Request $request): StreamedResponse|JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $year = now()->year;

        return response()->streamDownload(function () use ($year) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS);
            fputcsv($out, ['National Prayer and Fasting Week', 'Fasting & prayer', "{$year}-01-05", "{$year}-01-11", 'yes', '', '', 'All churches', 'Seven days of prayer and fasting', 'none', '']);
            fputcsv($out, ['CCI National Conference', 'conference', "{$year}-08-14", "{$year}-08-17", 'yes', '', '', 'Nairobi', '', 'none', '']);
            fputcsv($out, ['Bishops\' Council', 'meeting', "{$year}-03-20", '', 'no', '09:00', '15:00', 'CCI Headquarters', '', 'none', '']);
            fclose($out);
        }, "cci-calendar-template-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * POST /calendar/cci/import - {file, commit}. Without commit: every row
     * checked, nothing saved. With commit: the valid rows that aren't
     * already on the CCI calendar are saved.
     */
    public function import(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx,xls'], 'commit' => ['sometimes', 'boolean']]);
        $national = CalendarEvent::national();
        if (! $national) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'There is no national (CCI) territory to hold the CCI calendar.'], 422);
        }

        try {
            $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, false, false, false);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => ["That file couldn't be read - use the template (CSV or Excel)."]]);
        }
        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim((string) $h))), array_shift($sheet) ?? []);
        $missing = array_diff(['title', 'kind', 'starts_on'], $header);
        if ($missing) {
            throw ValidationException::withMessages(['file' => ['The first row needs the column names from the template - missing: '.implode(', ', $missing).'.']]);
        }
        $sheet = array_values(array_filter($sheet, fn ($row) => trim(implode('', array_map('strval', $row))) !== ''));
        if (count($sheet) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => ['At most '.self::MAX_ROWS.' events per file.']]);
        }

        $rows = [];
        $seen = [];
        foreach ($sheet as $i => $cells) {
            $raw = [];
            foreach ($header as $c => $name) {
                $raw[$name] = $cells[$c] ?? null;
            }
            $values = $this->normalise($raw);
            $errors = [];
            $clean = null;
            try {
                $clean = CalendarController::validated($values, $national);
            } catch (ValidationException $e) {
                $errors = collect($e->errors())->flatten()->values()->all();
            }
            $key = $clean ? mb_strtolower($clean['title']).'|'.$clean['starts_on'] : null;
            $duplicate = $clean && (isset($seen[$key]) || CalendarEvent::where('territory_id', $national->id)->where('title', $clean['title'])->whereDate('starts_on', $clean['starts_on'])->exists());
            if ($key) {
                $seen[$key] = true;
            }
            $rows[] = ['line' => $i + 2, 'values' => $values, 'errors' => $errors, 'duplicate' => $duplicate, 'clean' => $clean];
        }

        $valid = array_values(array_filter($rows, fn ($r) => ! $r['errors'] && ! $r['duplicate']));
        $created = 0;
        if ($request->boolean('commit')) {
            // All the ready rows, or none - never half a calendar.
            DB::transaction(function () use ($valid, $national, $request, &$created) {
                foreach ($valid as $r) {
                    CalendarEvent::create($r['clean'] + ['territory_id' => $national->id, 'source' => 'import', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
                    $created++;
                }
            });
        }

        return $this->ok([
            'rows' => array_map(fn ($r) => array_diff_key($r, ['clean' => true]), $rows),
            'valid' => count($valid),
            'invalid' => count(array_filter($rows, fn ($r) => $r['errors'])),
            'duplicates' => count(array_filter($rows, fn ($r) => ! $r['errors'] && $r['duplicate'])),
            'created' => $created,
        ], $request->boolean('commit') ? "{$created} event(s) added to the CCI calendar." : 'Checked - nothing saved yet.');
    }

    /** A spreadsheet row as the event fields: kinds by label or key, Excel or DD/MM/YYYY dates, yes/no. */
    private function normalise(array $raw): array
    {
        $text = fn ($v) => $v === null ? null : (trim((string) $v) === '' ? null : trim((string) $v));
        $date = function ($v) use ($text) {
            if (is_numeric($v)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
            }
            $v = $text($v);
            if ($v && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }

            return $v;
        };
        $time = function ($v) use ($text) {
            if (is_numeric($v) && $v < 1) {
                $minutes = (int) round($v * 1440);

                return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
            $v = $text($v);

            return $v && preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) ? sprintf('%02d:%s', $m[1], $m[2]) : $v;
        };
        $kind = $text($raw['kind'] ?? null);
        if ($kind !== null) {
            $key = array_search(mb_strtolower($kind), array_map('mb_strtolower', CalendarEvent::KINDS), true);
            $kind = $key !== false ? $key : str_replace([' ', '&', '-'], ['_', '', '_'], mb_strtolower($kind));
            $kind = str_replace('__', '_', $kind);
        }
        $allDay = $text($raw['all_day'] ?? null);
        $startTime = $time($raw['start_time'] ?? null);

        return [
            'title' => $text($raw['title'] ?? null),
            'kind' => $kind,
            'starts_on' => $date($raw['starts_on'] ?? null),
            'ends_on' => $date($raw['ends_on'] ?? null),
            'all_day' => $allDay === null ? $startTime === null : in_array(mb_strtolower($allDay), ['yes', 'y', 'true', '1'], true),
            'start_time' => $startTime,
            'end_time' => $time($raw['end_time'] ?? null),
            'location' => $text($raw['location'] ?? null),
            'description' => $text($raw['description'] ?? null),
            'repeats' => mb_strtolower($text($raw['repeats'] ?? null) ?? 'none'),
            'repeat_until' => $date($raw['repeat_until'] ?? null),
        ];
    }

    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()?->hasGlobalAccess() ? null : response()->json(['success' => false, 'status' => 403, 'message' => 'Only global admins manage the CCI calendar.'], 403);
    }

    private function ok(mixed $data, string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'status' => 200, 'message' => $message, 'data' => $data]);
    }
}
