<?php

namespace App\Approval\Resolvers;

use App\Approval\Contracts\ApproverResolver;
use InvalidArgumentException;

/** The ways a step can name its approvers. An unknown type is an error, never silently nobody. */
final class Registry
{
    public const TYPES = [
        'role_here' => RoleHere::class,
        'role_above' => RoleAbove::class,
        'permission_here' => PermissionHere::class,
        'user' => NamedUsers::class,
    ];

    public function get(string $type): ApproverResolver
    {
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown approver type: {$type}");
        }

        return app(self::TYPES[$type]);
    }

    public function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }
}
