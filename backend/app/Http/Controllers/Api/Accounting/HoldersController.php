<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\UserTerritoryAssignment;
use App\Support\AccountingAccess;
use App\Support\PlaceRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who may do something here (docs/specs/accounting-spec.md, entry by
 * permission): when a page can't offer its write button, it says which
 * permission that needs and who holds it at this place - the diocese admin
 * gives it in Roles & permissions. Nothing about who records is hard-coded.
 */
class HoldersController extends AccountingBase
{
    /** GET /accounting/holders?permission=collections.record */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $known = array_map(fn ($p) => substr($p, strlen('accounting.')), array_values(AccountingAccess::ABILITIES));
        $permission = (string) $request->query('permission', '');
        if (! in_array($permission, $known, true)) {
            return $this->notFound('That isn\'t an Accounting permission.');
        }
        $level = $place->territory_type->value;
        $holders = UserTerritoryAssignment::with(['user', 'role.permissions'])->where('territory_id', $place->id)->effective()->get()
            ->filter(fn ($a) => PlaceRoles::usable($a->user) && $a->role?->permissions->contains(fn ($p) => $p->name === "{$level}.accounting.{$permission}" && $p->territory_scope === $level))
            ->map(fn ($a) => ['name' => $a->user->full_name, 'role' => $a->role->name])->unique('name')->values();
        $roles = \App\Models\Role::where('territory_level', $level)->whereHas('permissions', fn ($q) => $q->where('name', "{$level}.accounting.{$permission}"))->orderBy('name')->pluck('name');

        return $this->ok(['place' => $this->placeInfo($place), 'permission' => $permission, 'holders' => $holders, 'roles' => $roles]);
    }
}
