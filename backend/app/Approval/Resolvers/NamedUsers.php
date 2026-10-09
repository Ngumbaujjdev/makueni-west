<?php

namespace App\Approval\Resolvers;

use App\Approval\Contracts\ApproverResolver;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\PlaceRoles;
use Illuminate\Support\Collection;

/** {"user_ids": [12, 40]} - named people. */
final class NamedUsers implements ApproverResolver
{
    public function resolve(array $config, ApprovalRequest $request): Collection
    {
        $ids = array_map('intval', (array) ($config['user_ids'] ?? [$config['user_id'] ?? 0]));

        return User::whereIn('id', $ids ?: [0])->get()->filter(fn ($u) => PlaceRoles::usable($u))->values();
    }

    public function describe(array $config): string
    {
        $names = User::whereIn('id', array_map('intval', (array) ($config['user_ids'] ?? [])))->get()->map(fn ($u) => $u->full_name)->implode(', ');

        return $names ?: 'Named people';
    }
}
