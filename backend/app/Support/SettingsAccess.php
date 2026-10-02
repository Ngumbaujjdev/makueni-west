<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;
use App\Support\Settings\SettingsRegistry;

/**
 * Whose Settings a user is looking at, and what they may do there
 * (docs/specs/settings-spec.md). The place is the one the user is acting
 * for - the same X-Assignment-Id rule as budgets (BudgetAccess) - and only
 * a global admin may pick another place (?territory_id=). Permissions are
 * "{level}.settings.hub.{section}.{read|update}" of the acting role.
 */
final class SettingsAccess
{
    /**
     * Each level's roles, most senior first. Leadership & team only lets
     * someone give - or change, or remove - roles strictly below their own.
     * Global admins can give any role of the level.
     */
    public const TEAM_ROLES = [
        'church' => [
            'Senior Pastor', 'Church Administrator', 'Associate Pastor', 'Youth Pastor',
            'Church Secretary', 'Church Treasurer', 'Elder', 'Deacon', 'Church Committee Member',
            'Youth Leader', "Women's Ministry Leader", "Men's Ministry Leader", "Children's Ministry Leader",
            'Music Director', 'Worship Leader', 'Choir Director', 'Sunday School Teacher',
            'Usher Coordinator', 'Prayer Group Leader',
        ],
        'region' => [
            'Regional Overseer', 'Regional Secretary', 'Regional Treasurer', 'Regional Coordinator', 'Regional Committee Member',
        ],
        'diocese' => [
            'Bishop', 'Diocese Administrator', 'Diocese Secretary', 'Diocese Treasurer', 'Diocese Finance Officer', 'Diocese Council Member',
        ],
    ];

    /**
     * Roles the user may give at this place, most senior first: those of the
     * place's level strictly below the role they act in here.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Role>
     */
    public static function grantable(?User $user, Territory $place): \Illuminate\Support\Collection
    {
        $level = self::level($place);
        $order = self::TEAM_ROLES[$level] ?? [];
        $roles = \App\Models\Role::where('territory_level', $level)->get()
            ->sortBy(fn ($r) => ($i = array_search($r->name, $order, true)) === false ? 999 : $i)
            ->values();
        if ($user?->hasGlobalAccess()) {
            return $roles;
        }
        $assignment = BudgetAccess::assignment($user);
        if (! $assignment || (int) $assignment->territory_id !== (int) $place->id) {
            return collect();
        }
        $mine = array_search($assignment->role?->name, $order, true);
        if ($mine === false) {
            return collect();
        }

        return $roles->filter(fn ($r) => ($i = array_search($r->name, $order, true)) !== false && $i > $mine)->values();
    }

    /** The church, region or diocese whose settings these are, or null (refuse). */
    public static function place(?User $user, ?int $territoryId = null): ?Territory
    {
        if (! $user) {
            return null;
        }
        $isLevel = fn (?Territory $t) => $t && in_array($t->territory_type?->value, SettingsRegistry::LEVELS, true);

        if ($user->hasGlobalAccess()) {
            $place = $territoryId ? Territory::find($territoryId) : BudgetAccess::assignment($user)?->territory;
            $place = $isLevel($place) ? $place : Territory::where('territory_type', 'diocese')->orderBy('id')->first();

            return $isLevel($place) ? $place : null;
        }

        $place = BudgetAccess::assignment($user)?->territory;
        if (! $isLevel($place) || ($territoryId && $territoryId !== (int) $place->id)) {
            return null;
        }

        return $place;
    }

    public static function level(Territory $place): string
    {
        return $place->territory_type->value;
    }

    /**
     * The links of a section like Access control that this user may open,
     * each {key, label, icon, colour, url, sentence}.
     */
    public static function links(?User $user, string $section): array
    {
        $links = [];
        foreach (SettingsRegistry::section($section)['links'] ?? [] as $key => $link) {
            if ($user && ($user->hasGlobalAccess() || BudgetAccess::has($user, $link['permission']))) {
                $links[] = ['key' => $key] + array_diff_key($link, ['permission' => true]);
            }
        }

        return $links;
    }

    /** read: the section's read OR update permission; update/manage: that exact permission. */
    public static function can(?User $user, Territory $place, string $section, string $action = 'read'): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $level = self::level($place);
        // The diocese's system settings (S4) are for global admins only.
        if (SettingsRegistry::section($section)['global_only'] ?? false) {
            return false;
        }
        // Access control (S4b): whoever can open one of its pages can see it - and only those links.
        if (isset(SettingsRegistry::section($section)['links'])) {
            return $action === 'read' && self::links($user, $section) !== [];
        }
        // A linked page (S3) keeps its own permission; only reading applies.
        $linked = SettingsRegistry::section($section)['permission'] ?? null;
        if ($linked) {
            return $action === 'read' && BudgetAccess::has($user, "{$level}.{$linked}");
        }
        $has = fn (string $a) => BudgetAccess::has($user, SettingsRegistry::permission($level, $section, $a));

        return $action === 'read' ? ($has('read') || $has('update') || $has('manage')) : $has($action);
    }
}
