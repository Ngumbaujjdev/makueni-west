<?php

namespace App\Approval\Resolvers;

use App\Approval\Contracts\ApproverResolver;
use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Support\PlaceRoles;
use Illuminate\Support\Collection;

/** {"permission": "accounting.payments.authorise"} - anyone at the place whose role holds it. */
final class PermissionHere implements ApproverResolver
{
    public function resolve(array $config, ApprovalRequest $request): Collection
    {
        $place = Territory::find($request->territory_id);

        return $place && ! empty($config['permission']) ? PlaceRoles::withPermission($place, $config['permission']) : collect();
    }

    public function describe(array $config): string
    {
        return 'Anyone who can authorise payments at the place asking';
    }
}
