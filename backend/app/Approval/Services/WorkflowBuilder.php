<?php

namespace App\Approval\Services;

use App\Approval\Resolvers\Registry;
use App\Models\ApprovalStage;
use App\Models\ApprovalWorkflow;
use App\Models\Role;
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

    public function save(array $data, ?ApprovalWorkflow $w, User $by): ApprovalWorkflow
    {
        $this->check($data);

        return DB::transaction(function () use ($data, $w, $by) {
            $fields = [
                'name' => trim($data['name']),
                'subject_type' => $data['subject_type'],
                'level' => $data['level'] ?: null,
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

    private function check(array $data): void
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
            'match_priority' => $w->match_priority, 'version' => $w->version, 'is_active' => $w->is_active,
            'stages' => $w->stages->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'type' => $s->type, 'quorum' => $s->quorum, 'on_reject' => $s->on_reject, 'on_empty' => $s->on_empty,
                'sla_hours' => $s->sla_hours, 'escalate_after_hours' => $s->escalate_after_hours, 'escalate_to' => $s->escalate_to,
                'escalate_label' => $s->escalate_to ? $describe($s->escalate_to) : null,
                'steps' => $s->steps->map(fn ($st) => ['resolver_type' => $st->resolver_type, 'resolver_config' => $st->resolver_config ?? [], 'label' => $describe(['resolver_type' => $st->resolver_type, 'resolver_config' => $st->resolver_config ?? []])])->values(),
            ])->values(),
        ];
    }
}
