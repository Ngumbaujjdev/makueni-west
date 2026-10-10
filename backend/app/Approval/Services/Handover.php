<?php

namespace App\Approval\Services;

use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\PlaceAccess;
use App\Support\PlaceRoles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who may approve for whom, at a place (docs/specs/accounting-spec.md, A4):
 * someone at the same place, or one of the few at the place just above that
 * the rules pass work up to - never a pastor of another church. Also the
 * plain sentence "waiting for X (role)" a submitter sees.
 */
final class Handover
{
    /**
     * The people a user at $place can hand their approvals to: anyone at the
     * same place who takes part in approvals there; at the place just above,
     * only the roles the approval rules pass work up to (e.g. the Regional
     * Overseer) and those who can authorise payments there.
     *
     * @return Collection<int, array{user: User, role: string, place: Territory}>
     */
    public function candidates(Territory $place, ?User $except = null): Collection
    {
        $level = $place->territory_type->value;
        $here = $this->holders($place, fn ($a) => $this->hasPermission($a, "{$level}.accounting.approvals.read", $level));
        $aboveRoles = $this->aboveRoles($level);
        $above = PlaceRoles::above($place);
        $up = $above ? $this->holders($above, fn ($a) => in_array($a->role?->name, $aboveRoles, true)
            || $this->hasPermission($a, $above->territory_type->value.'.accounting.payments.authorise', $above->territory_type->value)) : collect();

        return $here->concat($up)
            ->reject(fn ($c) => $except && (int) $c['user']->id === (int) $except->id)
            ->unique(fn ($c) => $c['user']->id)->sortBy(fn ($c) => [$c['place']->id === $place->id ? 0 : 1, $c['user']->full_name])->values();
    }

    /** Effective holders at $t whose assignment passes $test. @return Collection<int, array> */
    private function holders(Territory $t, callable $test): Collection
    {
        return UserTerritoryAssignment::with(['user', 'role.permissions'])->where('territory_id', $t->id)->effective()->get()
            ->filter(fn ($a) => PlaceRoles::usable($a->user) && $a->role && $test($a))
            ->map(fn ($a) => ['user' => $a->user, 'role' => (string) $a->role->name, 'place' => $t]);
    }

    private function hasPermission(UserTerritoryAssignment $a, string $name, string $level): bool
    {
        return (bool) $a->role?->permissions->contains(fn ($p) => $p->name === $name && $p->territory_scope === $level);
    }

    /** The roles the active rules for this level send work up to (role_above steps and escalations). @return array<int, string> */
    private function aboveRoles(string $level): array
    {
        $roles = [];
        $workflows = \App\Models\ApprovalWorkflow::where('is_active', true)->where(fn ($w) => $w->whereNull('level')->orWhere('level', $level))->pluck('id');
        $stages = \App\Models\ApprovalStage::with('steps')->whereIn('workflow_id', $workflows)->get();
        foreach ($stages as $stage) {
            $configs = $stage->steps->where('resolver_type', 'role_above')->pluck('resolver_config')->all();
            if (($stage->escalate_to['resolver_type'] ?? null) === 'role_above') {
                $configs[] = $stage->escalate_to['resolver_config'] ?? [];
            }
            foreach ($configs as $c) {
                $roles = [...$roles, ...(array) ($c['roles'] ?? []), ...(isset($c['role']) ? [(string) $c['role']] : [])];
            }
        }

        return array_values(array_unique(array_filter($roles)));
    }

    public function eligible(User $delegate, Territory $place): bool
    {
        return $this->candidates($place)->contains(fn ($c) => (int) $c['user']->id === (int) $delegate->id);
    }

    /** "waiting for Benson Manoo (Senior Pastor)" for a document just sent for approval; null when it needs none. */
    public function sentence(Model $subject): ?string
    {
        $r = ApprovalRequest::with('stages.assignments.approver')->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->latest('id')->first();
        if (! $r || $r->status !== 'pending') {
            return null;
        }
        $stage = $r->stages->firstWhere('status', 'active') ?? $r->stages->firstWhere('status', 'blocked');
        if (! $stage || $stage->status === 'blocked') {
            return 'nobody holds the role to approve it yet - the diocese assigns it';
        }
        $place = Territory::find($r->territory_id);
        $names = $stage->assignments->where('status', 'pending')->whereNull('superseded_at')->map(function ($a) use ($place) {
            $role = $place ? UserTerritoryAssignment::with('role')->where('user_id', $a->approver_id)
                ->whereIn('territory_id', [$place->id, ...array_map(fn ($t) => $t->id, PlaceAccess::ancestors($place))])->effective()->get()->first()?->role?->name : null;

            return trim(($a->approver?->full_name ?? '').($role ? " ({$role})" : ''));
        })->filter()->unique()->values();

        return $names->isEmpty() ? null : 'waiting for '.$names->join(', ', ' or ');
    }
}
