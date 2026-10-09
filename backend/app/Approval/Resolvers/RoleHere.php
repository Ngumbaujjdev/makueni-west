<?php

namespace App\Approval\Resolvers;

use App\Approval\Contracts\ApproverResolver;
use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Support\PlaceRoles;
use Illuminate\Support\Collection;

/** {"role": "Senior Pastor"} (or "roles": [...]) - holders at the place the document belongs to. */
final class RoleHere implements ApproverResolver
{
    public function resolve(array $config, ApprovalRequest $request): Collection
    {
        $place = Territory::find($request->territory_id);

        return $place ? PlaceRoles::holders($place, self::roles($config)) : collect();
    }

    public function describe(array $config): string
    {
        return implode(' or ', self::roles($config)).' of the place asking';
    }

    public static function roles(array $config): array
    {
        return array_values(array_filter((array) ($config['roles'] ?? [$config['role'] ?? null])));
    }
}
