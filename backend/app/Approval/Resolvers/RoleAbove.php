<?php

namespace App\Approval\Resolvers;

use App\Approval\Contracts\ApproverResolver;
use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Support\PlaceRoles;
use Illuminate\Support\Collection;

/** {"role": "Regional Overseer", "level": "region"} - holders at the place above (the region or the diocese). */
final class RoleAbove implements ApproverResolver
{
    public function resolve(array $config, ApprovalRequest $request): Collection
    {
        $place = Territory::find($request->territory_id);
        $above = $place ? PlaceRoles::above($place, $config['level'] ?? null) : null;

        return $above ? PlaceRoles::holders($above, RoleHere::roles($config)) : collect();
    }

    public function describe(array $config): string
    {
        return implode(' or ', RoleHere::roles($config)).' of the '.($config['level'] ?? 'place').' above';
    }
}
