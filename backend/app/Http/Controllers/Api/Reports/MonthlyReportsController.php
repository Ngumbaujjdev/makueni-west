<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportComment;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Budget\BudgetRollup;
use App\Services\Reports\MonthlyFigures;
use App\Services\Reports\MonthlyReports;
use App\Support\PlaceAccess;
use App\Support\ReportsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly reports (docs/specs/monthly-reports-spec.md): a church or region
 * writes and sends its own; those above read, mark as seen and comment.
 */
class MonthlyReportsController extends Controller
{
    public function __construct(private MonthlyReports $reports, private MonthlyFigures $figures) {}

    /** GET /monthly-reports?year= - the 12 months of our own reports. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request, 'read');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? CarbonImmutable::now(MonthlyReports::TZ)->year);
        $existing = MonthlyReport::withCount('comments')->where('territory_id', $place->id)->where('year', $year)->get()->keyBy('month');
        $months = collect(range(1, 12))->map(fn ($m) => $this->reports->state($place, $year, $m, $existing->get($m)));
        $sent = $months->whereIn('status', ['sent', 'seen']);
        $current = $months->first(fn ($m) => $m['open'] && ! in_array($m['status'], ['sent', 'seen'], true) && $m['due_in_days'] !== null && ! $m['late']);

        return $this->ok([
            'year' => $year,
            'place' => $this->placeInfo($place),
            'reports' => $this->reportsFor($place),
            'due_day' => $this->reports->dueDay($place),
            'months' => $months->values(),
            'figures' => [
                'sent' => $sent->count(),
                'on_time' => $sent->where('on_time', true)->count(),
                'late' => $months->where('late', true)->count() + $sent->where('on_time', false)->count(),
                'seen' => $months->where('status', 'seen')->count(),
                'comments' => (int) $months->sum('comments'),
                'next' => $current,
            ],
            'can' => $this->can($request->user(), $place),
        ]);
    }

    /** GET /monthly-reports/{year}/{month} - the report (or a blank one), live figures while a draft. */
    public function month(Request $request, int $year, int $month): JsonResponse
    {
        $place = $this->place($request, 'read');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($error = $this->validMonth($year, $month)) {
            return $error;
        }
        $report = MonthlyReport::where('territory_id', $place->id)->where('year', $year)->where('month', $month)->first();

        return $this->ok($this->present($report, $place, $year, $month, 'own', $request->user()));
    }

    /** PUT /monthly-reports/{year}/{month} - save the draft. */
    public function save(Request $request, int $year, int $month): JsonResponse
    {
        $place = $this->place($request, 'write');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($error = $this->validMonth($year, $month)) {
            return $error;
        }
        $rules = array_fill_keys(MonthlyReport::WORDS, ['nullable', 'string', 'max:5000']) + [
            'outreach' => ['nullable', 'string', 'max:5000'],
            'pastoral_visits' => ['nullable', 'integer', 'between:0,10000'],
        ];
        $data = $request->validate($rules);
        $report = MonthlyReport::firstOrNew(['territory_id' => $place->id, 'year' => $year, 'month' => $month]);
        if (in_array($report->status, ['sent', 'seen'], true)) {
            return $this->unprocessable('status', 'It has been sent. Take it back to change it - while it hasn\'t been seen.');
        }
        $report->fill($data + ['updated_by' => $request->user()->id]);
        if (! $report->exists) {
            $report->fill(['status' => 'draft', 'created_by' => $request->user()->id]);
        }
        $report->save();

        return $this->ok($this->present($report->fresh(), $place, $year, $month, 'own', $request->user()), 'Saved.');
    }

    /** POST /monthly-reports/{year}/{month}/send - freeze the figures and send it up. */
    public function send(Request $request, int $year, int $month): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($error = $this->validMonth($year, $month)) {
            return $error;
        }
        if (CarbonImmutable::create($year, $month, 1, 0, 0, 0, MonthlyReports::TZ)->isFuture()) {
            return $this->unprocessable('month', 'That month hasn\'t started yet.');
        }
        $report = MonthlyReport::firstOrNew(['territory_id' => $place->id, 'year' => $year, 'month' => $month]);
        if (in_array($report->status, ['sent', 'seen'], true)) {
            return $this->unprocessable('status', 'It has already been sent.');
        }
        $report->fill([
            'status' => 'sent', 'figures' => $this->figures->for($place, $year, $month), 'sent_at' => now(), 'sent_by' => $request->user()->id,
            'updated_by' => $request->user()->id, 'created_by' => $report->created_by ?? $request->user()->id,
        ])->save();
        $this->reports->notifySent($report->fresh('territory'));
        $above = $this->reports->above($place);

        return $this->ok($this->present($report->fresh(), $place, $year, $month, 'own', $request->user()), 'Sent'.($above ? " to {$above->name}." : '.'));
    }

    /** POST /monthly-reports/{year}/{month}/reopen - back to a draft while it hasn't been seen. */
    public function reopen(Request $request, int $year, int $month): JsonResponse
    {
        $place = $this->place($request, 'send');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $report = MonthlyReport::where('territory_id', $place->id)->where('year', $year)->where('month', $month)->first();
        if (! $report || $report->status !== 'sent') {
            return $this->unprocessable('status', $report?->status === 'seen' ? 'It has been seen - add a comment instead.' : 'Only a sent report can be taken back.');
        }
        $report->forceFill(['status' => 'draft', 'figures' => null, 'sent_at' => null, 'sent_by' => null, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->present($report->fresh(), $place, $year, $month, 'own', $request->user()), 'Taken back - it is a draft again.');
    }

    /** GET /monthly-reports/{id} - one report: our own, or one below. */
    public function show(Request $request, int $id): JsonResponse
    {
        [$place, $report, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->present($report, $report->territory, $report->year, $report->month, $relation, $request->user(), $place));
    }

    /** POST /monthly-reports/{id}/seen */
    public function seen(Request $request, int $id): JsonResponse
    {
        [$place, $report, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation !== 'below' || ! ReportsAccess::can($request->user(), $place, 'review')) {
            return $this->forbidden('Only the places above can mark it as seen.');
        }
        if ($report->status !== 'sent') {
            return $this->unprocessable('status', $report->status === 'seen' ? 'It is already marked as seen.' : 'It hasn\'t been sent yet.');
        }
        $report->forceFill(['status' => 'seen', 'seen_at' => now(), 'seen_by' => $request->user()->id])->save();
        $this->reports->notifySeen($report->fresh('territory'), $place);

        return $this->ok($this->present($report->fresh(), $report->territory, $report->year, $report->month, 'below', $request->user(), $place), 'Marked as seen.');
    }

    /** POST /monthly-reports/{id}/comments - {body} */
    public function comment(Request $request, int $id): JsonResponse
    {
        [$place, $report, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        $allowed = $relation === 'own' ? ReportsAccess::can($request->user(), $place, 'write') : ReportsAccess::can($request->user(), $place, 'review');
        if (! $allowed) {
            return $this->forbidden("Your role can't comment here.");
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Write a comment.']);
        MonthlyReportComment::create(['monthly_report_id' => $report->id, 'user_id' => $request->user()->id, 'territory_id' => $place->id, 'body' => trim($data['body'])]);
        $this->reports->notifyComment($report->fresh('territory'), $place, trim($data['body']));

        return $this->ok($this->present($report->fresh(), $report->territory, $report->year, $report->month, $relation, $request->user(), $place), 'Comment added.', 201);
    }

    /** POST /monthly-reports/{id}/attachments - {file} */
    public function attach(Request $request, int $id): JsonResponse
    {
        [$place, $report, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation !== 'own' || ! ReportsAccess::can($request->user(), $place, 'write') || $report->status !== 'draft') {
            return $this->forbidden('Files can be added to your own draft only.');
        }
        if ($report->getMedia('attachments')->count() >= MonthlyReport::MAX_ATTACHMENTS) {
            return $this->unprocessable('file', 'A report can have at most '.MonthlyReport::MAX_ATTACHMENTS.' files.');
        }
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']], ['file.mimes' => 'Add a photo (JPG, PNG, WebP) or a PDF.', 'file.max' => 'Each file can be up to 5 MB.']);
        $report->addMediaFromRequest('file')->toMediaCollection('attachments');

        return $this->ok(['attachments' => $this->attachments($report->fresh())], 'File added.', 201);
    }

    /** DELETE /monthly-reports/{id}/attachments/{media} */
    public function detach(Request $request, int $id, int $media): JsonResponse
    {
        [$place, $report, $relation, $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        if ($relation !== 'own' || ! ReportsAccess::can($request->user(), $place, 'write') || $report->status !== 'draft') {
            return $this->forbidden('Files can be removed from your own draft only.');
        }
        $file = $report->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That file is no longer there.'], 404);
        }
        $file->delete();

        return $this->ok(['attachments' => $this->attachments($report->fresh())], 'File removed.');
    }

    /** GET /monthly-reports/{id}/attachments/{media} - streamed to those who can read the report. */
    public function file(Request $request, int $id, int $media): JsonResponse|StreamedResponse
    {
        [, $report, , $error] = $this->visible($request, $id);
        if ($error) {
            return $error;
        }
        $file = $report->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That file is no longer there.'], 404);
        }

        return response()->streamDownload(fn () => print (stream_get_contents($file->stream())), $file->file_name, ['Content-Type' => $file->mime_type]);
    }

    /** GET /monthly-reports/below?year=&month= - the reports of the places below. */
    public function below(Request $request): JsonResponse
    {
        $place = $this->place($request, 'below');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $now = CarbonImmutable::now(MonthlyReports::TZ)->subMonthNoOverflow();
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'integer', 'between:1,12']]);
        $year = (int) ($data['year'] ?? $now->year);
        $month = (int) ($data['month'] ?? $now->month);
        $rollup = app(BudgetRollup::class);
        $levels = $place->territory_type->value === 'diocese' ? ['region', 'church'] : ['church'];
        $places = collect($levels)->flatMap(fn ($l) => $rollup->placesBelow($place, $l))->values();
        $reports = MonthlyReport::withCount('comments')->whereIn('territory_id', $places->pluck('id')->all() ?: [0])->where('year', $year)->where('month', $month)->get()->keyBy('territory_id');
        $territories = Territory::whereIn('id', $places->pluck('id')->all() ?: [0])->get()->keyBy('id');

        $rows = $places->map(function ($p) use ($reports, $territories, $year, $month) {
            $report = $reports->get($p['id']);
            $state = $this->reports->state($territories->get($p['id']), $year, $month, $report);
            $f = $report?->figures;

            return $state + [
                'place' => ['id' => $p['id'], 'name' => $p['name'], 'type' => $p['type']],
                'group' => $p['group'],
                'key' => $f ? [
                    'average_sunday' => $f['attendance']['average_sunday'] ?? null,
                    'members' => $f['people']['members'] ?? null,
                    'income' => $f['money']['income'] ?? null,
                    'expenses' => $f['money']['expenses'] ?? null,
                ] : null,
            ];
        });
        $count = fn ($status) => $rows->where('status', $status)->count();
        $late = $rows->where('late', true)->count();
        $notStarted = $rows->where('status', 'not_started')->count();
        $label = CarbonImmutable::create($year, $month, 1)->format('F');
        $noticed = array_values(array_filter([
            $late ? ['tone' => 'danger', 'text' => "{$late} ".($late === 1 ? 'place hasn\'t' : 'places haven\'t')." sent {$label}'s report and it is past the due day."] : null,
            $count('sent') ? ['tone' => 'primary', 'text' => $count('sent').' '.($count('sent') === 1 ? 'report is' : 'reports are').' waiting to be read.'] : null,
            $rows->count() && $count('sent') + $count('seen') === $rows->count() ? ['tone' => 'success', 'text' => "Every place has sent {$label}'s report."] : null,
        ]));

        return $this->ok([
            'year' => $year, 'month' => $month, 'label' => CarbonImmutable::create($year, $month, 1)->format('F Y'),
            'place' => $this->placeInfo($place),
            'counts' => ['places' => $rows->count(), 'sent' => $count('sent') + $count('seen'), 'seen' => $count('seen'), 'waiting' => $count('sent'), 'late' => $late, 'not_started' => $notStarted, 'drafts' => $count('draft')],
            'groups' => $rows->groupBy(fn ($r) => $r['group'] ?? '')->map(fn ($g, $name) => ['name' => $name ?: null, 'places' => $g->count(), 'sent' => $g->whereIn('status', ['sent', 'seen'])->count(), 'late' => $g->where('late', true)->count()])->values(),
            'rows' => $rows->values(),
            'noticed' => $noticed,
            'can' => ['review' => ReportsAccess::can($request->user(), $place, 'review')],
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function present(?MonthlyReport $report, Territory $owner, int $year, int $month, string $relation, User $user, ?Territory $viewer = null): array
    {
        $viewer ??= $owner;
        $live = ! $report || $report->status === 'draft';
        $state = $this->reports->state($owner, $year, $month, $report);
        $own = $relation === 'own';
        $draftOrNew = ! $report || $report->status === 'draft';

        return $state + [
            'place' => $this->placeInfo($owner),
            'reports_to' => ($above = $this->reports->above($owner)) ? ['id' => $above->id, 'name' => $above->name] : null,
            'relation' => $relation,
            'figures' => $live ? $this->figures->for($owner, $year, $month) : $report->figures,
            'figures_live' => $live,
            'words' => collect(MonthlyReport::WORDS)->mapWithKeys(fn ($w) => [$w => $report?->{$w}])->all(),
            'pastoral_visits' => $report?->pastoral_visits,
            'outreach' => $report?->outreach,
            'sent_by' => $report?->sent_by ? $this->who($report->sent_by) : null,
            'seen_by' => $report?->seen_by ? $this->who($report->seen_by) : null,
            'attachments' => $report ? $this->attachments($report) : [],
            'comments' => $report ? $report->comments()->with(['user', 'territory'])->get()->map(fn (MonthlyReportComment $c) => [
                'id' => $c->id, 'body' => $c->body, 'at' => $c->created_at?->toIso8601String(),
                'who' => $c->user ? trim("{$c->user->firstname} {$c->user->lastname}") : 'Someone',
                'place' => $c->territory?->name, 'from_above' => (int) $c->territory_id !== (int) $owner->id,
            ])->values() : [],
            'can' => [
                'write' => $own && $draftOrNew && ReportsAccess::can($user, $viewer, 'write'),
                'send' => $own && $draftOrNew && $state['open'] && ReportsAccess::can($user, $viewer, 'send'),
                'reopen' => $own && $report?->status === 'sent' && ReportsAccess::can($user, $viewer, 'send'),
                'attach' => $own && $draftOrNew && ReportsAccess::can($user, $viewer, 'write'),
                'comment' => (bool) $report && ($own ? ReportsAccess::can($user, $viewer, 'write') : ReportsAccess::can($user, $viewer, 'review')),
                'seen' => ! $own && $report?->status === 'sent' && ReportsAccess::can($user, $viewer, 'review'),
            ],
        ];
    }

    private function attachments(MonthlyReport $report): array
    {
        return $report->getMedia('attachments')->map(fn (Media $m) => [
            'id' => $m->id, 'name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size, 'is_image' => str_starts_with((string) $m->mime_type, 'image/'),
        ])->values()->all();
    }

    private function who(int $id): ?string
    {
        $u = User::find($id);

        return $u ? trim("{$u->firstname} {$u->lastname}") : null;
    }

    private function can(User $user, Territory $place): array
    {
        return [
            'write' => ReportsAccess::can($user, $place, 'write'),
            'send' => ReportsAccess::can($user, $place, 'send'),
            'below' => $place->territory_type->value !== 'church' && ReportsAccess::can($user, $place, 'below'),
            'review' => $place->territory_type->value !== 'church' && ReportsAccess::can($user, $place, 'review'),
        ];
    }

    private function placeInfo(Territory $place): array
    {
        return ['id' => $place->id, 'name' => $place->name, 'type' => $place->territory_type->value];
    }

    /** Does this place write reports itself? (Churches and regions do; the diocese reads those below.) */
    private function reportsFor(Territory $place): bool
    {
        return in_array($place->territory_type->value, ReportsAccess::REPORTING_LEVELS, true);
    }

    private function validMonth(int $year, int $month): ?JsonResponse
    {
        return $year < 2000 || $year > 2100 || $month < 1 || $month > 12
            ? response()->json(['success' => false, 'status' => 404, 'message' => 'That month doesn\'t exist.'], 404)
            : null;
    }

    /** @return array{0: ?Territory, 1: ?MonthlyReport, 2: ?string, 3: ?JsonResponse} */
    private function visible(Request $request, int $id): array
    {
        $place = $this->place($request, null);
        if ($place instanceof JsonResponse) {
            return [null, null, null, $place];
        }
        $report = MonthlyReport::with('territory')->find($id);
        $relation = $report ? ReportsAccess::relation($place, $report) : null;
        if (! $report || ! $relation || ($relation === 'below' && $report->status === 'draft')) {
            return [$place, null, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That report isn\'t one you can see.'], 404)];
        }
        $may = $relation === 'own' ? ReportsAccess::can($request->user(), $place, 'read') : ReportsAccess::can($request->user(), $place, 'below');
        if (! $may) {
            return [$place, null, null, $this->forbidden("Your role can't see this report.")];
        }

        return [$place, $report, $relation, null];
    }

    /** The acting place, if the role may do this there (null = checked later). */
    private function place(Request $request, ?string $ability): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden("This isn't your place.");
        }
        if (in_array($ability, ['write', 'send'], true) && ! $this->reportsFor($place)) {
            return $this->forbidden('The diocese reads the reports of the places below; it doesn\'t send one.');
        }
        if ($ability === 'below' && $place->territory_type->value === 'church') {
            return $this->forbidden('A church has no places below.');
        }
        if ($ability && ! ReportsAccess::can($request->user(), $place, $ability) && ! ($ability === 'read' && PlaceAccess::isBelow($request->user(), $place))) {
            return $this->forbidden("Your role can't do that with monthly reports.");
        }

        return $place;
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
