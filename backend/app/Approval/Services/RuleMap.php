<?php

namespace App\Approval\Services;

use App\Approval\Resolvers\Registry;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStage;
use App\Models\ApprovalWorkflow;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use App\Support\PlaceRoles;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Who signs what at a place (docs/specs/accounting-spec.md, A4b) - the page's
 * lane map: approvers down the side, amounts along the top, and each rule's
 * stages across the amounts they cover; what to fix next; and "Try an amount",
 * a dry run that names who would sign today without creating anything.
 */
final class RuleMap
{
    /** The amounts the map is drawn at (KES). */
    private const MARKS = [0, 10000, 25000, 50000, 100000, 200000, 500000, 1000000];

    private const SUBJECT_KEYS = ['payment_voucher', 'requisition', 'payroll_run'];

    public function __construct(private ApprovalService $engine, private Registry $resolvers, private DelegationResolver $delegations, private WorkflowBuilder $builder) {}

    public function map(Territory $place, string $subject, ?User $viewer): array
    {
        $subject = in_array($subject, self::SUBJECT_KEYS, true) ? $subject : 'payment_voucher';
        $level = $place->territory_type->value;
        $ceiling = ApprovalService::ownRulesUpTo();
        // The amount marks: the standard ones and every band edge the rules here use.
        $edges = collect(self::MARKS);
        foreach ($this->candidates($place, $subject) as $w) {
            foreach ($w->applies_when['and'] ?? [] as $c) {
                if (($c['field'] ?? '') === 'amount' && (float) $c['value'] > 0) {
                    $edges->push((float) $c['value']);
                }
            }
        }
        $marks = $edges->map(fn ($v) => (float) $v)->unique()->sort()->values()->take(11)->all();
        // What applies across each stretch between two marks (and past the last).
        $segments = [];
        foreach ($marks as $i => $from) {
            $to = $marks[$i + 1] ?? null;
            $probe = $to === null ? $from * 1.5 + 1 : ($from + $to) / 2;
            $w = $this->engine->findWorkflow($subject, $place, ['amount' => $probe, 'level' => $level]);
            $segments[] = ['from' => $from, 'to' => $to, 'workflow' => $w, 'standard_only' => $level !== 'diocese' && ($ceiling <= 0 || $probe > $ceiling)];
        }
        // The rows: each stage that signs somewhere here, by its name and who it is.
        $rows = [];
        $fake = $this->fakeRequest($place, $subject, $viewer);
        foreach ($segments as $si => $seg) {
            foreach ($seg['workflow']?->stages ?? [] as $pos => $stage) {
                $key = $stage->name.'|'.json_encode($stage->steps->map(fn ($s) => [$s->resolver_type, $s->resolver_config])->all());
                if (! isset($rows[$key])) {
                    $people = $this->people($stage->steps->map(fn ($s) => ['resolver_type' => $s->resolver_type, 'resolver_config' => $s->resolver_config ?? []])->all(), $fake);
                    $rows[$key] = [
                        'key' => md5($key), 'name' => $stage->name, 'who' => $this->who($stage), 'people' => $people->pluck('full_name')->take(4)->values()->all(),
                        'has_someone' => $people->isNotEmpty(), 'on_empty' => $stage->on_empty, 'sla_hours' => $stage->sla_hours,
                        'approvals' => $this->decidedHere($place, $stage->name), 'icon' => $this->icon($stage), 'segments' => [],
                    ];
                }
                $rows[$key]['segments'][$si] = ['position' => $pos, 'rule' => $seg['workflow']->name, 'owner' => $this->ownerLabel($seg['workflow'], $place)];
            }
        }
        // Blocks: each row's runs of neighbouring stretches with the same position and rule.
        $blocks = [];
        foreach (array_values($rows) as $ri => $row) {
            $run = null;
            foreach ($segments as $si => $seg) {
                $cell = $row['segments'][$si] ?? null;
                if ($run && $cell && $cell['position'] === $run['position'] && $cell['owner'] === $run['owner'] && $run['to'] === $si - 1) {
                    $run['to'] = $si;
                    $run['rule'] = $run['rule'] === $cell['rule'] ? $run['rule'] : $run['rule'].' · '.$cell['rule'];

                    continue;
                }
                if ($run) {
                    $blocks[] = $run;
                    $run = null;
                }
                if ($cell) {
                    $run = ['row' => $ri, 'from' => $si, 'to' => $si, 'rule' => $cell['rule'], 'owner' => $cell['owner'], 'position' => $cell['position'],
                        'label' => [0 => 'Approves first', 1 => 'Then approves', 2 => 'Then approves'][$cell['position']] ?? 'Then approves', 'status' => $row['has_someone'] ? 'ok' : ($row['on_empty'] === 'skip' ? 'skipped' : 'nobody')];
                }
            }
            if ($run) {
                $blocks[] = $run;
            }
        }
        $rows = array_values(array_map(fn ($r) => array_diff_key($r, ['segments' => 1]), $rows));
        $rules = collect($segments)->pluck('workflow')->filter()->unique('id');
        $waiting = ApprovalRequest::where('territory_id', $place->id)->where('status', 'pending')->orderBy('created_at')->get();
        $blockedStages = ApprovalRequestStage::where('status', 'blocked')->whereIn('request_id', $waiting->pluck('id'))->get();

        return [
            'place' => ['id' => $place->id, 'name' => $place->name, 'level' => $level],
            'subject' => $subject,
            'subjects' => collect(WorkflowBuilder::SUBJECTS)->only(self::SUBJECT_KEYS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'ceiling' => $ceiling,
            'marks' => $marks,
            'segments' => collect($segments)->map(fn ($s) => ['from' => $s['from'], 'to' => $s['to'], 'rule' => $s['workflow']?->name, 'standard_only' => $s['standard_only']])->values(),
            'rows' => $rows,
            'blocks' => $blocks,
            'checks' => $this->checks($place, $rows, $segments, $blockedStages, $rules, $viewer),
            'detected' => [
                'rules' => $rules->count(), 'own_rules' => $rules->whereNotNull('territory_id')->count(),
                'stages' => count($rows), 'nobody' => collect($rows)->where('has_someone', false)->count(),
                'waiting' => $waiting->count(), 'oldest_days' => $waiting->first() ? (int) $waiting->first()->created_at->diffInDays(now()) : null,
                'blocked' => $blockedStages->count(),
                'decided_30' => ApprovalRequest::where('territory_id', $place->id)->whereIn('status', ['approved', 'rejected'])->where('updated_at', '>=', now()->subDays(30))->count(),
            ],
            'questions' => $waiting->take(3)->map(fn ($r) => $this->question($r, $blockedStages))->values(),
            'can' => ['manage' => AccountingAccess::canManageRules($viewer, $place)],
        ];
    }

    /** "Try an amount": the rule and, stage by stage, who would sign it today. Nothing is created. */
    public function try(Territory $place, string $subject, float $amount, ?User $viewer): array
    {
        $subject = in_array($subject, self::SUBJECT_KEYS, true) ? $subject : 'payment_voucher';
        $w = $this->engine->findWorkflow($subject, $place, ['amount' => $amount, 'level' => $place->territory_type->value]);
        if (! $w) {
            return ['amount' => $amount, 'rule' => null, 'stages' => [], 'note' => 'No rule covers this amount here - anyone who can authorise payments approves it (not the person who prepared it).'];
        }
        $fake = $this->fakeRequest($place, $subject, $viewer);
        $stages = [];
        foreach ($w->stages as $stage) {
            $steps = $stage->steps->map(fn ($s) => ['resolver_type' => $s->resolver_type, 'resolver_config' => $s->resolver_config ?? []])->all();
            $people = $this->people($steps, $fake);
            $note = null;
            if ($people->isEmpty()) {
                if ($stage->on_empty === 'escalate' && ! empty($stage->escalate_to['resolver_type'])) {
                    $people = $this->people([$stage->escalate_to], $fake);
                    $note = 'Nobody here holds it, so it goes to '.($this->resolvers->get($stage->escalate_to['resolver_type'])->describe($stage->escalate_to['resolver_config'] ?? [])).'.';
                } elseif ($stage->on_empty === 'skip') {
                    $note = 'Nobody here holds it - this step is skipped.';
                } else {
                    $note = 'Nobody here holds it - it would wait until someone is given the role.';
                }
            }
            $stages[] = ['name' => $stage->name, 'who' => $this->who($stage), 'people' => $people->pluck('full_name')->values()->all(), 'note' => $note,
                'type' => $stage->type, 'quorum' => $stage->quorum, 'sla_hours' => $stage->sla_hours];
        }

        return ['amount' => $amount, 'rule' => ['id' => $w->id, 'name' => $w->name, 'owner' => $this->ownerLabel($w, $place)], 'stages' => $stages, 'note' => null];
    }

    // ------------------------------------------------------------ pieces

    /** Every active rule that could apply here for this document. */
    private function candidates(Territory $place, string $subject): Collection
    {
        $above = array_map(fn ($t) => (int) $t->id, PlaceAccess::ancestors($place));

        return ApprovalWorkflow::where('is_active', true)->whereIn('subject_type', [$subject, ApprovalService::ANY])
            ->where(fn ($q) => $q->whereNull('level')->orWhere('level', $place->territory_type->value))
            ->where(fn ($q) => $q->whereNull('territory_id')->orWhere('territory_id', $place->id)->orWhere(fn ($q) => $q->whereIn('territory_id', $above ?: [0])->where('reach', 'below')))
            ->get();
    }

    private function fakeRequest(Territory $place, string $subject, ?User $viewer): ApprovalRequest
    {
        // Never saved: the resolvers only read the place and the kind of document. Nobody has asked yet,
        // so nobody is left out - the person who asks is, when it is real.
        return new ApprovalRequest(['territory_id' => $place->id, 'subject_type' => $subject, 'requested_by' => null]);
    }

    /** Who a set of steps names today, after hand-overs, never the person asking. */
    private function people(array $steps, ApprovalRequest $fake): Collection
    {
        try {
            $found = $this->engine->resolveSteps($steps, $fake);
        } catch (InvalidArgumentException) {
            return collect();
        }

        return $found->map(fn (User $u) => $this->delegations->resolve($u, $fake)[0])
            ->reject(fn (User $u) => $fake->requested_by && (int) $u->id === (int) $fake->requested_by)->unique('id')->values();
    }

    private function who($stage): string
    {
        return $stage->steps->map(fn ($s) => $this->resolvers->has($s->resolver_type) ? $this->resolvers->get($s->resolver_type)->describe($s->resolver_config ?? []) : $s->resolver_type)->implode(' · ');
    }

    private function icon($stage): string
    {
        $t = $stage->steps->first()?->resolver_type;
        $role = strtolower(json_encode($stage->steps->first()?->resolver_config ?? []));

        return match (true) {
            $t === 'role_above' => 'ri-arrow-up-circle-line',
            $t === 'permission_here' => 'ri-shield-check-line',
            $t === 'user' => 'ri-user-star-line',
            str_contains($role, 'pastor') || str_contains($role, 'bishop') => 'ri-user-heart-line',
            str_contains($role, 'committee') || str_contains($role, 'council') => 'ri-team-line',
            str_contains($role, 'treasurer') || str_contains($role, 'finance') => 'ri-wallet-3-line',
            default => 'ri-user-follow-line',
        };
    }

    private function ownerLabel(ApprovalWorkflow $w, Territory $place): string
    {
        return match (true) {
            $w->territory_id === null => 'Diocese standard',
            (int) $w->territory_id === (int) $place->id => 'Our own',
            default => 'From '.($w->owner?->name ?? 'the place above'),
        };
    }

    /** How many approvals this step gave or refused here in the last 30 days. */
    private function decidedHere(Territory $place, string $stageName): int
    {
        return ApprovalRequestStage::where('name', $stageName)->whereIn('status', ['approved', 'rejected'])->where('updated_at', '>=', now()->subDays(30))
            ->whereIn('request_id', ApprovalRequest::where('territory_id', $place->id)->select('id'))->count();
    }

    /** "Why is PV-73 still waiting?" for the oldest waiting requests, with a link to the record. */
    private function question(ApprovalRequest $r, Collection $blocked): array
    {
        $type = ['payment_voucher' => 'voucher', 'requisition' => 'requisition', 'payroll_run' => 'payroll'][$r->subject_type] ?? null;
        $doc = $r->subject_type && class_exists($cls = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($r->subject_type) ?? '') ? $cls::find($r->subject_id) : null;
        // A voucher or requisition has a number; a payroll run names its month.
        $label = $doc?->getAttribute('number') ?: ($doc && method_exists($doc, 'label') ? 'Payroll '.$doc->label() : '#'.$r->subject_id);
        $isBlocked = $blocked->contains('request_id', $r->id);

        return ['text' => $isBlocked ? "Why is {$label} stuck?" : "Why is {$label} still waiting?", 'blocked' => $isBlocked, 'type' => $type, 'id' => $r->subject_id, 'since' => $r->created_at?->toIso8601String()];
    }

    /** What to fix next, most pressing first. */
    private function checks(Territory $place, array $rows, array $segments, Collection $blocked, Collection $rules, ?User $viewer): array
    {
        $out = [];
        foreach (collect($rows)->where('has_someone', false)->where('on_empty', '!=', 'skip') as $r) {
            $out[] = ['tone' => 'warning', 'first' => true, 'title' => "Name who approves as {$r['name']}", 'text' => "Nobody here holds it ({$r['who']}) - payments it covers would wait or go up.", 'action' => 'roles'];
        }
        if ($blocked->count()) {
            $out[] = ['tone' => 'danger', 'first' => true, 'title' => $blocked->count().' '.($blocked->count() === 1 ? 'approval is' : 'approvals are').' stuck', 'text' => 'Nobody could approve a step - give someone the role, then retry them.', 'action' => 'blocked'];
        }
        // Places below with nobody for a step of the standard church rules (for a region or the diocese).
        if ($place->territory_type->value !== 'church') {
            $churches = Territory::where('territory_type', 'church')->whereIn('id', PlaceAccess::descendantIds($place))->pluck('id');
            $roles = ApprovalWorkflow::where('is_active', true)->whereNull('territory_id')->where('level', 'church')->with('stages.steps')->get()
                ->flatMap(fn ($w) => $w->stages->flatMap(fn ($s) => $s->steps->where('resolver_type', 'role_here')->flatMap(fn ($st) => (array) ($st->resolver_config['roles'] ?? [$st->resolver_config['role'] ?? null]))))->filter()->unique();
            foreach ($roles as $role) {
                $have = UserTerritoryAssignment::whereIn('territory_id', $churches)->effective()->whereHas('role', fn ($q) => $q->where('name', $role))->distinct()->pluck('territory_id');
                $missing = $churches->count() - $have->count();
                if ($missing > 0) {
                    $out[] = ['tone' => 'warning', 'first' => false, 'title' => "{$missing} ".($missing === 1 ? 'church has' : 'churches have')." no {$role}", 'text' => 'Their payments skip that step or wait for it.', 'action' => 'roles', 'count' => $missing];
                }
            }
        }
        $gaps = collect($segments)->filter(fn ($s) => ! $s['workflow']);
        if ($gaps->count()) {
            $g = $gaps->first();
            $out[] = ['tone' => 'info', 'first' => false, 'title' => 'Some amounts have no rule', 'text' => 'From KES '.number_format($g['from']).' - anyone who can authorise approves them.', 'action' => 'rules'];
        }
        foreach (collect($rows)->whereNull('sla_hours')->take(2) as $r) {
            $out[] = ['tone' => 'info', 'first' => false, 'title' => "Set how long {$r['name']} has", 'text' => 'Without a time, nobody is reminded and it never goes up when late.', 'action' => 'rules'];
        }
        if (PlaceRoles::withPermission($place, 'accounting.approvalrules.manage')->isEmpty()) {
            $out[] = ['tone' => 'info', 'first' => false, 'title' => 'Nobody here can change these rules', 'text' => 'Give someone "Change approval rules" in Roles & permissions.', 'action' => 'admin'];
        }
        usort($out, fn ($a, $b) => ($b['first'] <=> $a['first']));

        return $out;
    }
}
