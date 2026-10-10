<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Approval\Resolvers\Registry;
use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Approval\Services\WorkflowBuilder;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStage;
use App\Models\ApprovalWorkflow;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approvals (docs/specs/accounting-spec.md, A4): my inbox - what waits for
 * me, what I asked for, what I decided - each request with its document and
 * timeline; deciding; my delegations; and, for the diocese finance officer,
 * the approval rules.
 */
class ApprovalController extends AccountingBase
{
    public function __construct(private ApprovalService $engine, private Inbox $inbox, private WorkflowBuilder $builder) {}

    /** GET /approvals?tab=waiting|mine|decided */
    public function index(Request $request): JsonResponse
    {
        $tab = in_array($request->query('tab'), ['waiting', 'mine', 'decided'], true) ? $request->query('tab') : 'waiting';
        $user = $request->user();

        return $this->ok([
            'tab' => $tab,
            'counts' => $this->inbox->counts($user),
            'items' => $this->inbox->list($user, $tab)->map(fn ($r) => $this->inbox->present($r, $user))->values(),
            'can' => ['rules' => AccountingAccess::canManageRules($user)],
        ]);
    }

    /** GET /approvals/board?territory_id= - every request waiting at the place, this month's numbers, the latest happenings. */
    public function board(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok($this->inbox->board($request->user(), $place) + ['place' => $this->placeInfo($place)]);
    }

    /** GET /approvals/requests/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $r = ApprovalRequest::find($id);
        if (! $r || ! $r->subject || ! $this->inbox->canView($request->user(), $r)) {
            return $this->notFound('That approval isn\'t yours to see.');
        }

        return $this->ok($this->inbox->present($r, $request->user(), true));
    }

    /** POST /approvals/requests/{id}/{approve|reject|return} {comment} */
    public function decide(Request $request, int $id, string $decision): JsonResponse
    {
        $r = ApprovalRequest::find($id);
        if (! $r || ! $this->inbox->canView($request->user(), $r)) {
            return $this->notFound('That approval isn\'t yours to see.');
        }
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $turn = $this->inbox->myTurn($request->user(), $r);
        if (! $turn) {
            throw ValidationException::withMessages(['approval' => [(int) $r->requested_by === (int) $request->user()->id ? 'You asked for it - someone else must approve it.' : 'It isn\'t waiting for you.']]);
        }
        $r = $this->engine->decide($turn, $request->user(), $decision, $data['comment'] ?? null);
        $msg = ['approve' => $r->status === 'approved' ? 'Approved - it can now be paid.' : 'Approved - it moves to the next stage.', 'reject' => 'Rejected.', 'return' => 'Sent back for changes.'][$decision];

        return $this->ok($this->inbox->present($r->fresh(), $request->user(), true), $msg);
    }

    /** POST /approvals/requests/{id}/cancel - the person who asked withdraws it. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = ApprovalRequest::find($id);
        if (! $r || (int) $r->requested_by !== (int) $request->user()->id) {
            return $this->notFound('Only the person who asked can withdraw it.');
        }
        $this->engine->cancel($r, $request->user());

        return $this->ok($this->inbox->present($r->fresh(), $request->user(), true), 'Withdrawn.');
    }

    /** POST /approvals/requests/{id}/retry - a blocked stage, after someone was given the role. */
    public function retry(Request $request, int $id): JsonResponse
    {
        $r = ApprovalRequest::find($id);
        if (! $r || ! AccountingAccess::canManageRules($request->user())) {
            return $this->forbidden('Only whoever looks after the approval rules can retry a stuck stage.');
        }
        $stage = ApprovalRequestStage::where('request_id', $r->id)->where('status', 'blocked')->first();
        if (! $stage || ! $this->engine->retryStage($stage)) {
            throw ValidationException::withMessages(['approval' => ['Nothing to retry.']]);
        }
        $r->refresh();

        return $this->ok($this->inbox->present($r, $request->user(), true), $r->stages()->where('status', 'blocked')->exists() ? 'Still nobody to approve it - give someone the role first.' : 'It moves on.');
    }

    /** GET /approvals/requests/{id}/files/{media} - the document's papers, for whoever may see the request. */
    public function file(Request $request, int $id, int $media): Response|JsonResponse
    {
        $r = ApprovalRequest::find($id);
        if (! $r || ! $r->subject || ! $this->inbox->canView($request->user(), $r)) {
            return $this->notFound('That file isn\'t yours to see.');
        }
        $file = collect($r->subject->approvalDetails()['media'] ?? [])->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That file isn\'t on this document.');
        }

        return response()->file($file->getPath(), ['Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($file->file_name).'"']);
    }

    // ------------------------------------------------------------ delegations

    /** GET /approvals/delegations - mine, and people I could hand to (my place's leaders and the place above). */
    public function delegations(Request $request): JsonResponse
    {
        $user = $request->user();
        $place = PlaceAccess::acting($user);
        $people = collect();
        if ($place) {
            $ids = [$place->id, ...array_map(fn ($t) => $t->id, PlaceAccess::ancestors($place))];
            $people = User::whereHas('activeAssignments', fn ($q) => $q->whereIn('territory_id', $ids))->where('id', '!=', $user->id)->orderBy('firstname')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name]);
        }

        return $this->ok([
            'items' => ApprovalDelegation::with('delegate')->where('delegator_id', $user->id)->orderByDesc('starts_at')->get()->map(fn ($d) => [
                'id' => $d->id, 'delegate' => $d->delegate?->full_name, 'starts_at' => $d->starts_at->toDateString(), 'ends_at' => $d->ends_at->toDateString(),
                'subject_type' => $d->subject_type, 'reason' => $d->reason, 'live' => $d->is_active && $d->starts_at->lte(now()) && $d->ends_at->gte(now()), 'is_active' => $d->is_active,
            ]),
            'people' => $people->values(),
        ]);
    }

    /** POST /approvals/delegations {delegate_id, starts_at, ends_at, subject_type?, reason?} */
    public function delegate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'subject_type' => ['nullable', 'in:requisition,payment_voucher'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['ends_at.after_or_equal' => 'It must end on or after the day it starts.']);
        if ((int) $data['delegate_id'] === (int) $request->user()->id) {
            throw ValidationException::withMessages(['delegate_id' => ['Pick someone else.']]);
        }
        $d = ApprovalDelegation::create($data + [
            'delegator_id' => $request->user()->id, 'is_active' => true, 'created_by' => $request->user()->id,
            'starts_at' => \Carbon\Carbon::parse($data['starts_at'])->startOfDay(), 'ends_at' => \Carbon\Carbon::parse($data['ends_at'])->endOfDay(),
        ]);

        return $this->ok(['id' => $d->id], 'Done - they approve for you on those days.', 201);
    }

    /** DELETE /approvals/delegations/{id} */
    public function undelegate(Request $request, int $id): JsonResponse
    {
        $d = ApprovalDelegation::where('delegator_id', $request->user()->id)->find($id);
        if (! $d) {
            return $this->notFound('That isn\'t yours.');
        }
        $d->update(['is_active' => false]);

        return $this->ok(null, 'Stopped.');
    }

    // ------------------------------------------------------------ the rules

    /** GET /approvals/workflows - every rule, and what the editor picks from. */
    public function workflows(Request $request): JsonResponse
    {
        if (! AccountingAccess::canManageRules($request->user())) {
            return $this->forbidden('Only the diocese finance officer changes the approval rules.');
        }

        return $this->ok([
            'items' => ApprovalWorkflow::with('stages.steps')->orderByRaw("FIELD(level, 'church', 'region', 'diocese')")->orderBy('subject_type')->orderBy('match_priority')->get()->map(fn ($w) => $this->builder->present($w))->values(),
            'subjects' => collect(WorkflowBuilder::SUBJECTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'roles' => Role::whereIn('territory_level', ['church', 'region', 'diocese'])->orderBy('territory_level')->orderBy('name')->get(['name', 'territory_level'])->map(fn ($r) => ['name' => $r->name, 'level' => $r->territory_level])->values(),
            'resolvers' => array_keys(Registry::TYPES),
        ]);
    }

    /** GET /approvals/people?q= - people by name, for a "named person" step. */
    public function people(Request $request): JsonResponse
    {
        if (! AccountingAccess::canManageRules($request->user())) {
            return $this->forbidden('Only the diocese finance officer changes the approval rules.');
        }
        $q = trim((string) $request->query('q', ''));

        return $this->ok(User::when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('firstname', 'like', "%{$q}%")->orWhere('lastname', 'like', "%{$q}%")))
            ->orderBy('firstname')->limit(30)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name])->values());
    }

    /** POST /approvals/workflows · PUT /approvals/workflows/{id} */
    public function saveWorkflow(Request $request, ?int $id = null): JsonResponse
    {
        if (! AccountingAccess::canManageRules($request->user())) {
            return $this->forbidden('Only the diocese finance officer changes the approval rules.');
        }
        $w = $id ? ApprovalWorkflow::find($id) : null;
        if ($id && ! $w) {
            return $this->notFound('That rule doesn\'t exist.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject_type' => ['required', 'string'],
            'level' => ['nullable', 'string'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'match_priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'stages' => ['required', 'array', 'min:1', 'max:8'],
            'stages.*.name' => ['required', 'string', 'max:120'],
            'stages.*.type' => ['nullable', 'in:single,all,quorum'],
            'stages.*.quorum' => ['nullable', 'integer', 'min:1', 'max:20'],
            'stages.*.on_reject' => ['nullable', 'in:terminate,return_previous,continue'],
            'stages.*.on_empty' => ['nullable', 'in:block,skip,escalate'],
            'stages.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'stages.*.escalate_after_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'stages.*.escalate_to' => ['nullable', 'array'],
            'stages.*.steps' => ['required', 'array', 'min:1', 'max:6'],
            'stages.*.steps.*.resolver_type' => ['required', 'string'],
            'stages.*.steps.*.resolver_config' => ['nullable', 'array'],
        ], ['stages.required' => 'Add at least one stage.']);
        $w = $this->builder->save($data, $w, $request->user());

        return $this->ok($this->builder->present($w), 'Saved - new requests follow it; ones already waiting keep their rule.', $id ? 200 : 201);
    }

    /** DELETE /approvals/workflows/{id} */
    public function deleteWorkflow(Request $request, int $id): JsonResponse
    {
        if (! AccountingAccess::canManageRules($request->user())) {
            return $this->forbidden('Only the diocese finance officer changes the approval rules.');
        }
        $w = ApprovalWorkflow::find($id);
        if (! $w) {
            return $this->notFound('That rule doesn\'t exist.');
        }
        $w->delete();

        return $this->ok(null, 'Removed - requests already waiting keep it.');
    }
}
