<?php

namespace App\Http\Controllers\Api\Settings;

use App\Actions\Users\AddPersonToPlace;
use App\Actions\Users\SendSignInDetails;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Services\Settings\Settings;
use App\Support\Settings\PersonMatch;
use App\Support\Settings\SettingsRegistry;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Settings > Leadership & team (docs/specs/settings-spec.md): the people
 * with a role at this place. A manager adds people with roles below their
 * own, changes those roles, removes people and gives them a fresh
 * temporary password. Nobody changes their own role or removes themselves,
 * and the last manager can't be removed or moved down.
 */
class TeamController extends SettingsController
{
    private const SECTION = 'team';

    /** GET /settings/team */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION)) {
            return $deny;
        }

        return $this->ok($this->payload($request, $place));
    }

    /**
     * GET /settings/team/check?phone=&email= - as the Add someone window is
     * filled in: is the phone a Kenyan mobile, does someone already have this
     * phone or email (masked), and would adding them clash.
     */
    public function check(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'manage')) {
            return $deny;
        }
        $request->validate(['phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:255']]);
        $result = PersonMatch::check($request->query('phone'), $request->query('email'), $place);
        unset($result['user']);

        return $this->ok($result);
    }

    /** POST /settings/team - {firstname, lastname, email?, phone?, role_id} */
    public function store(Request $request, AddPersonToPlace $add, Settings $settings, SendSignInDetails $send): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'manage')) {
            return $deny;
        }
        $grantable = SettingsAccess::grantable($request->user(), $place);
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'role_id' => ['required', 'integer', Rule::in($grantable->pluck('id')->all())],
            'send' => ['sometimes', 'array'],
            'send.*' => ['in:sms,email'],
        ], [
            'role_id.in' => "You can only give roles below your own at {$place->name}.",
            'email.required_without' => 'Give an email address or a phone number.',
            'phone.required_without' => 'Give a phone number or an email address.',
        ]);

        // The same checks the window runs as you type (S6a): a Kenyan mobile,
        // and no phone/email that belongs to someone else or to someone already here.
        $check = PersonMatch::check($data['phone'] ?? null, $data['email'] ?? null, $place);
        if ($check['phone']['error']) {
            return $this->unprocessable('phone', $check['phone']['error']);
        }
        if ($check['conflict']) {
            return $this->unprocessable($check['phone']['given'] ? 'phone' : 'email', $check['conflict']);
        }

        $role = $grantable->firstWhere('id', (int) $data['role_id']);
        $result = $add($data, $place, $role, $request->user());
        $name = trim("{$result['user']->firstname} {$result['user']->lastname}");
        $settings->audit($place, self::SECTION, [$name => ['old' => null, 'new' => $role->name]], $request->user(), 'settings.team');

        // S6d: their sign-in details by SMS / email, the way this place's messages go.
        $delivery = $result['credentials'] && ! empty($data['send'])
            ? $send($result['user'], $place, $role->name, $result['credentials'], $data['send'], $request->user())
            : [];

        return $this->ok([
            'person' => $this->person($result['assignment']->fresh(['user', 'role']), $request, $place),
            'existing' => $result['existing'],
            'credentials' => $result['credentials'],
            'delivery' => $delivery,
            'team' => $this->payload($request, $place),
        ], $result['existing'] ? "{$name} already had an account - they're now on the team." : "{$name} is on the team.", 201);
    }

    /** PUT /settings/team/{assignment} - {role_id} */
    public function update(Request $request, int $assignment, Settings $settings): JsonResponse
    {
        [$place, $target, $error] = $this->target($request, $assignment);
        if ($error) {
            return $error;
        }
        $grantable = SettingsAccess::grantable($request->user(), $place);
        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::in($grantable->pluck('id')->all())],
        ], ['role_id.in' => 'You can only give roles below your own.']);

        $newRole = $grantable->firstWhere('id', (int) $data['role_id']);
        if ((int) $target->role_id === (int) $newRole->id) {
            return $this->ok($this->payload($request, $place), 'Nothing changed.');
        }
        if ($this->isManagerRole($target->role, $place) && ! $this->isManagerRole($newRole, $place) && $this->managerCount($place) <= 1) {
            return $this->unprocessable('role_id', 'This is the only person who can manage the team here. Give someone else that role first.');
        }

        $oldRole = $target->role;
        $target->update(['role_id' => $newRole->id]);
        $user = $target->user;
        if (! $user->hasRole($newRole->name)) {
            $user->assignRole($newRole);
        }
        $this->dropRoleIfUnused($user, $oldRole);
        $settings->audit($place, self::SECTION, [trim("{$user->firstname} {$user->lastname}") => ['old' => $oldRole?->name, 'new' => $newRole->name]], $request->user(), 'settings.team');

        return $this->ok($this->payload($request, $place), 'Role changed.');
    }

    /** DELETE /settings/team/{assignment} - ends the person's role here (their account stays). */
    public function destroy(Request $request, int $assignment, Settings $settings): JsonResponse
    {
        [$place, $target, $error] = $this->target($request, $assignment);
        if ($error) {
            return $error;
        }
        if ($this->isManagerRole($target->role, $place) && $this->managerCount($place) <= 1) {
            return $this->unprocessable('assignment', 'This is the only person who can manage the team here, so they can\'t be removed.');
        }

        $target->update(['is_active' => false, 'expires_at' => now()]);
        $user = $target->user;
        $this->dropRoleIfUnused($user, $target->role);
        $settings->audit($place, self::SECTION, [trim("{$user->firstname} {$user->lastname}") => ['old' => $target->role?->name, 'new' => null]], $request->user(), 'settings.team');

        return $this->ok($this->payload($request, $place), 'Removed from the team.');
    }

    /**
     * POST /settings/team/{assignment}/reset-access - a new employee code, a
     * new temporary password and a new PIN, so whoever knew the old details is
     * locked out; every existing sign-in ends.
     */
    public function resetAccess(Request $request, int $assignment, Settings $settings, SendSignInDetails $send): JsonResponse
    {
        [$place, $target, $error] = $this->target($request, $assignment);
        if ($error) {
            return $error;
        }
        $channels = $request->validate(['send' => ['sometimes', 'array'], 'send.*' => ['in:sms,email']])['send'] ?? [];
        $user = $target->user;
        $password = AddPersonToPlace::temporaryPassword();
        $pin = AddPersonToPlace::temporaryPin();
        $code = AddPersonToPlace::employeeCode();
        $usernameWasCode = $user->username !== null && $user->username === $user->employee_code;
        $user->forceFill([
            'employee_code' => $code,
            'username' => $usernameWasCode || $user->username === null ? $code : $user->username,
            'password' => Hash::make($password),
            'pin' => Hash::make($pin),
            'pin_changed_at' => now(),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
            'must_change_password' => true,
            'password_changed_at' => now(),
            'login_attempts' => 0,
        ])->save();
        $user->tokens()->delete();
        $name = trim("{$user->firstname} {$user->lastname}");
        $settings->audit($place, self::SECTION, [$name => ['old' => 'sign-in details', 'new' => 'new code and temporary password']], $request->user(), 'settings.team');

        $credentials = ['employee_code' => $code, 'temporary_password' => $password, 'pin' => $pin];

        return $this->ok([
            'person' => $this->person($target, $request, $place),
            'credentials' => $credentials,
            'delivery' => $channels ? $send($user, $place, $target->role?->name, $credentials, $channels, $request->user(), reset: true) : [],
        ], "New sign-in details for {$name}.");
    }

    /**
     * The assignment being changed, checked: at this place, still active,
     * not the user's own, and in a role below theirs.
     *
     * @return array{0: ?Territory, 1: ?UserTerritoryAssignment, 2: ?JsonResponse}
     */
    private function target(Request $request, int $id): array
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return [null, null, $place];
        }
        if ($deny = $this->deny($request, $place, self::SECTION, 'manage')) {
            return [null, null, $deny];
        }
        $target = UserTerritoryAssignment::with(['user', 'role'])
            ->whereKey($id)->where('territory_id', $place->id)->where('is_active', true)->first();
        if (! $target || ! $target->user) {
            return [null, null, response()->json(['success' => false, 'status' => 404, 'message' => "That person isn't on the team here."], 404)];
        }
        if ((int) $target->user_id === (int) $request->user()->id) {
            return [null, null, $this->unprocessable('assignment', "You can't change your own role or access here - ask someone above you.")];
        }
        if (! $this->canTouch($request->user(), $place, $target)) {
            return [null, null, $this->forbidden("{$target->user->firstname}'s role ({$target->role?->name}) is not below yours.")];
        }

        return [$place, $target, null];
    }

    private function payload(Request $request, Territory $place): array
    {
        $people = UserTerritoryAssignment::with(['user', 'role'])
            ->where('territory_id', $place->id)->where('is_active', true)->get()
            ->filter(fn ($a) => $a->user)
            ->sortBy(fn ($a) => sprintf('%03d-%s', $this->rank($a->role?->name, $place), $a->user->firstname))
            ->values();

        $canManage = SettingsAccess::can($request->user(), $place, self::SECTION, 'manage');

        return [
            'people' => $people->map(fn ($a) => $this->person($a, $request, $place))->all(),
            'grantable' => $canManage ? SettingsAccess::grantable($request->user(), $place)->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'blurb' => $r->description ?: config("settings.role_blurbs.{$r->name}"),
            ])->values()->all() : [],
            'counts' => [
                'people' => $people->pluck('user_id')->unique()->count(),
                'managers' => $this->managerCount($place),
                'roles' => $people->pluck('role.name')->filter()->countBy()->sortDesc()->all(),
            ],
            'can' => ['manage' => $canManage],
        ];
    }

    private function person(UserTerritoryAssignment $a, Request $request, Territory $place): array
    {
        $user = $a->user;
        $mine = (int) $user->id === (int) $request->user()->id;
        $touchable = ! $mine && SettingsAccess::can($request->user(), $place, self::SECTION, 'manage') && $this->canTouch($request->user(), $place, $a);

        return [
            'assignment_id' => $a->id,
            'user' => [
                'id' => $user->id,
                'name' => trim("{$user->firstname} {$user->lastname}"),
                'initials' => AddPersonToPlace::initials($user),
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'role' => ['id' => $a->role?->id, 'name' => $a->role?->name, 'manager' => $this->isManagerRole($a->role, $place)],
            'assignment_type' => $a->assignment_type?->value ?? (string) $a->assignment_type,
            'since' => ($a->assigned_at ?? $a->created_at)?->toDateString(),
            'is_me' => $mine,
            'can' => ['change' => $touchable, 'remove' => $touchable, 'reset' => $touchable],
        ];
    }

    /** Whether the role is below the user's own here (global admins can touch anyone). */
    private function canTouch(User $user, Territory $place, UserTerritoryAssignment $target): bool
    {
        return $user->hasGlobalAccess()
            || SettingsAccess::grantable($user, $place)->contains('id', (int) $target->role_id);
    }

    /** Whether people in this role can manage the team at this place. */
    private function isManagerRole(?Role $role, Territory $place): bool
    {
        $permission = SettingsRegistry::permission(SettingsAccess::level($place), self::SECTION, 'manage');

        return (bool) $role?->permissions->contains(fn ($p) => $p->name === $permission);
    }

    private function managerCount(Territory $place): int
    {
        return UserTerritoryAssignment::with('role.permissions')
            ->where('territory_id', $place->id)->where('is_active', true)->get()
            ->filter(fn ($a) => $this->isManagerRole($a->role, $place))
            ->pluck('user_id')->unique()->count();
    }

    private function rank(?string $role, Territory $place): int
    {
        $i = array_search($role, SettingsAccess::TEAM_ROLES[SettingsAccess::level($place)] ?? [], true);

        return $i === false ? 999 : $i;
    }

    /** Take the Spatie role off once no active assignment uses it. */
    private function dropRoleIfUnused(User $user, ?Role $role): void
    {
        if ($role && ! $user->territoryAssignments()->where('role_id', $role->id)->where('is_active', true)->exists() && $user->hasRole($role->name)) {
            $user->removeRole($role);
        }
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
