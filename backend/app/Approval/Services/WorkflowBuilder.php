<?php

namespace App\Approval\Services;

use App\Approval\Resolvers\Registry;
use App\Models\ApprovalStage;
use App\Models\ApprovalWorkflow;
use App\Models\Role;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing a workflow from the Approval rules page (docs/specs/accounting-spec.md, A4):
 * its kind of document, level, amount band and stages. Saving replaces the
 * stages and bumps the version - requests already in flight keep the copy
 * they were given.
 */
final class WorkflowBuilder
{
    public const SUBJECTS = ['*' => 'Any money document', 'requisition' => 'Requisitions', 'payment_voucher' => 'Payment vouchers', 'payroll_run' => 'Payroll runs'];

    public function __construct(private Registry $resolvers) {}

    /**
     * Save a rule. With an owner (a church or region, A4b) it is that place's
     * own: for itself, or - a region - for its churches too; its band stays at
     * or under the diocese's ceiling. Without one it is a diocese standard rule.
     */
    public function save(array $data, ?ApprovalWorkflow $w, User $by, ?Territory $owner = null): ApprovalWorkflow
    {
        if ($owner) {
            // A church's rule is for the church; a region's for itself, or (reach below) its churches.
            $data['level'] = $owner->territory_type->value === 'region' && ($data['reach'] ?? 'self') === 'below' ? ($data['level'] ?? null) : $owner->territory_type->value;
        }
        $this->check($data, $w, $owner);

        return DB::transaction(function () use ($data, $w, $by, $owner) {
            $fields = [
                'name' => trim($data['name']),
                'subject_type' => $data['subject_type'],
                'level' => $data['level'] ?: null,
                'territory_id' => $owner?->id,
                'reach' => $owner && $owner->territory_type->value === 'region' && ($data['reach'] ?? 'self') === 'below' ? 'below' : 'self',
                'applies_when' => $this->conditions($data),
                'match_priority' => (int) ($data['match_priority'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $by->id,
            ];
            if ($w) {
                $w->update($fields + ['version' => $w->version + 1]);
                $w->stages()->delete();
            } else {
                $w = ApprovalWorkflow::create($fields + ['created_by' => $by->id, 'key' => $data['key'] ?? null]);
            }
            foreach (array_values($data['stages']) as $i => $s) {
                $stage = ApprovalStage::create([
                    'workflow_id' => $w->id,
                    'sequence' => $i + 1,
                    'name' => trim($s['name']),
                    'type' => $s['type'] ?? 'single',
                    'quorum' => ($s['type'] ?? '') === 'quorum' ? max(1, (int) ($s['quorum'] ?? 1)) : null,
                    'on_reject' => $s['on_reject'] ?? 'terminate',
                    'on_empty' => $s['on_empty'] ?? 'block',
                    'sla_hours' => ! empty($s['sla_hours']) ? (int) $s['sla_hours'] : null,
                    'escalate_after_hours' => ! empty($s['escalate_after_hours']) ? (int) $s['escalate_after_hours'] : null,
                    'escalate_to' => ! empty($s['escalate_to']['resolver_type']) ? ['resolver_type' => $s['escalate_to']['resolver_type'], 'resolver_config' => $s['escalate_to']['resolver_config'] ?? []] : null,
                ]);
                foreach ($s['steps'] as $step) {
                    $stage->steps()->create(['resolver_type' => $step['resolver_type'], 'resolver_config' => $step['resolver_config'] ?? []]);
                }
            }

            return $w->fresh('stages.steps');
        });
    }

    /** amount_min / amount_max -> {"and": [amount > min, amount <= max]} (or the raw applies_when). */
    private function conditions(array $data): ?array
    {
        if (array_key_exists('applies_when', $data) && is_array($data['applies_when'])) {
            return $data['applies_when'] ?: null;
        }
        $and = [];
        if (isset($data['amount_min']) && $data['amount_min'] !== '' && $data['amount_min'] !== null) {
            $and[] = ['field' => 'amount', 'operator' => '>', 'value' => (float) $data['amount_min']];
        }
        if (isset($data['amount_max']) && $data['amount_max'] !== '' && $data['amount_max'] !== null) {
            $and[] = ['field' => 'amount', 'operator' => '<=', 'value' => (float) $data['amount_max']];
        }

        return $and ? ['and' => $and] : null;
    }

    /** Who can approve by a permission - the ones a rule may name. */
    public const PERMISSIONS = [
        'accounting.payments.authorise' => 'authorise payments',
        'accounting.payments.pay' => 'pay vouchers',
        'accounting.payments.prepare' => 'prepare payments',
        'accounting.payroll.manage' => 'run the payroll',
        'accounting.procurement.manage' => 'buy through procurement',
    ];

    private function check(array $data, ?ApprovalWorkflow $w = null, ?Territory $owner = null): void
    {
        $fail = fn ($k, $m) => throw ValidationException::withMessages([$k => [$m]]);
        if (trim((string) ($data['name'] ?? '')) === '') {
            $fail('name', 'Give the rule a name.');
        }
        if (! array_key_exists($data['subject_type'] ?? '', self::SUBJECTS)) {
            $fail('subject_type', 'Pick what it is for.');
        }
        if (! empty($data['level']) && ! in_array($data['level'], ['church', 'region', 'diocese'], true)) {
            $fail('level', 'Pick a level.');
        }
        if (isset($data['amount_min'], $data['amount_max']) && $data['amount_min'] !== '' && $data['amount_max'] !== '' && (float) $data['amount_min'] >= (float) $data['amount_max']) {
            $fail('amount_max', 'The top of the band must be above the bottom.');
        }
        $min = isset($data['amount_min']) && $data['amount_min'] !== '' ? (float) $data['amount_min'] : 0.0;
        $max = isset($data['amount_max']) && $data['amount_max'] !== '' && $data['amount_max'] !== null ? (float) $data['amount_max'] : INF;
        if ($owner) {
            // A place's own rules stop at the diocese's ceiling; above it the standard rules apply.
            $ceiling = ApprovalService::ownRulesUpTo();
            if ($ceiling <= 0) {
                $fail('amount_max', 'The diocese hasn\'t opened rules of their own to churches and regions.');
            }
            if ($max > $ceiling) {
                $fail('amount_max', 'Above KES '.number_format($ceiling).' the diocese\'s rules apply - set the top of the band at KES '.number_format($ceiling).' or less.');
            }
        }
        // No two active rules of the same owner, document and level may cover the same amount.
        $others = ApprovalWorkflow::where('is_active', true)->where('territory_id', $owner?->id)
            ->whereIn('subject_type', [$data['subject_type'] ?? '*'])->where('level', ($data['level'] ?? null) ?: null)
            ->when($w, fn ($q) => $q->where('id', '!=', $w->id))->get();
        if (($data['is_active'] ?? true)) {
            foreach ($others as $o) {
                $and = $o->applies_when['and'] ?? [];
                $omin = (float) (collect($and)->firstWhere('operator', '>')['value'] ?? 0);
                $omaxV = collect($and)->firstWhere('operator', '<=')['value'] ?? null;
                $omax = $omaxV === null ? INF : (float) $omaxV;
                if ($o->applies_when && ! $and) {
                    continue; // custom conditions - can't tell, so don't block
                }
                if ($max > $omin && $omax > $min) {
                    $fail('amount_min', "It overlaps \"{$o->name}\" (KES ".number_format($omin).' to '.($omax === INF ? 'any amount' : 'KES '.number_format($omax)).') - change one band so each amount has one rule.');
                }
            }
        }
        $stages = $data['stages'] ?? [];
        if (! $stages) {
            $fail('stages', 'Add at least one stage.');
        }
        $roles = Role::pluck('name')->all();
        foreach (array_values($stages) as $i => $s) {
            if (trim((string) ($s['name'] ?? '')) === '') {
                $fail("stages.{$i}.name", 'Name each stage.');
            }
            if (! in_array($s['type'] ?? 'single', ['single', 'all', 'quorum'], true)) {
                $fail("stages.{$i}.type", 'Pick how many must approve.');
            }
            if (empty($s['steps'])) {
                $fail("stages.{$i}.steps", "Say who approves \"{$s['name']}\".");
            }
            foreach (array_merge($s['steps'] ?? [], ! empty($s['escalate_to']['resolver_type']) ? [$s['escalate_to']] : []) as $step) {
                if (! $this->resolvers->has($step['resolver_type'] ?? '')) {
                    $fail("stages.{$i}.steps", 'Unknown kind of approver.');
                }
                $cfg = $step['resolver_config'] ?? [];
                if (in_array($step['resolver_type'], ['role_here', 'role_above'], true)) {
                    foreach ((array) ($cfg['roles'] ?? [$cfg['role'] ?? null]) as $r) {
                        if (! in_array($r, $roles, true)) {
                            $fail("stages.{$i}.steps", 'Pick a role that exists.');
                        }
                    }
                }
                if ($step['resolver_type'] === 'permission_here' && ! isset(self::PERMISSIONS[$cfg['permission'] ?? ''])) {
                    $fail("stages.{$i}.steps", 'Pick what they must be able to do.');
                }
                if ($step['resolver_type'] === 'user' && ! User::whereIn('id', (array) ($cfg['user_ids'] ?? []))->exists()) {
                    $fail("stages.{$i}.steps", 'Pick the people by name.');
                }
            }
        }
    }

    /** For the editor: the workflow with plain descriptions of who approves. */
    public function present(ApprovalWorkflow $w): array
    {
        $and = $w->applies_when['and'] ?? [];
        $min = collect($and)->firstWhere('operator', '>')['value'] ?? null;
        $max = collect($and)->firstWhere('operator', '<=')['value'] ?? null;
        $describe = fn ($step) => ! empty($step['resolver_type']) && $this->resolvers->has($step['resolver_type']) ? $this->resolvers->get($step['resolver_type'])->describe($step['resolver_config'] ?? []) : null;

        return [
            'id' => $w->id, 'key' => $w->key, 'name' => $w->name, 'subject_type' => $w->subject_type, 'subject_label' => self::SUBJECTS[$w->subject_type] ?? $w->subject_type,
            'level' => $w->level, 'amount_min' => $min, 'amount_max' => $max, 'custom_conditions' => $w->applies_when && ! ($min !== null || $max !== null),
            'owner' => $w->owner ? ['id' => $w->owner->id, 'name' => $w->owner->name, 'level' => $w->owner->territory_type->value] : null, 'reach' => $w->reach,
            'match_priority' => $w->match_priority, 'version' => $w->version, 'is_active' => $w->is_active,
            'stages' => $w->stages->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'type' => $s->type, 'quorum' => $s->quorum, 'on_reject' => $s->on_reject, 'on_empty' => $s->on_empty,
                'sla_hours' => $s->sla_hours, 'escalate_after_hours' => $s->escalate_after_hours, 'escalate_to' => $s->escalate_to,
                'escalate_label' => $s->escalate_to ? $describe($s->escalate_to) : null,
                'steps' => $s->steps->map(fn ($st) => ['resolver_type' => $st->resolver_type, 'resolver_config' => $st->resolver_config ?? [], 'label' => $describe(['resolver_type' => $st->resolver_type, 'resolver_config' => $st->resolver_config ?? []]),
                    'people' => $st->resolver_type === 'user' ? User::whereIn('id', (array) ($st->resolver_config['user_ids'] ?? []))->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name])->values() : null])->values(),
            ])->values(),
        ];
    }
}
