<?php

namespace App\Services\Messages;

use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\Phone;
use App\Support\PlaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who a message reaches (docs/specs/messages-spec.md): people at our own
 * place by role, the places below (all, by subregion/region, or picked) by
 * role and/or their own contact, and numbers or emails typed in. Down,
 * never up or sideways; each person once.
 */
final class Audience
{
    public const MAX = 2000;

    /** @return array{recipients: array<int, array>, invalid: string[], summary: string} */
    public function resolve(Territory $place, array $audience): array
    {
        $level = PlaceAccess::level($place);
        $out = collect();
        $parts = [];

        // Our own place.
        $ownRoles = array_values(array_filter((array) ($audience['own']['roles'] ?? [])));
        if ($ownRoles) {
            $people = $this->leaders([(int) $place->id], $ownRoles);
            $out = $out->merge($people);
            $parts[] = $this->rolesWords($ownRoles, 'here').($people->isEmpty() ? ' (nobody yet)' : '');
        }

        // The places below.
        $below = (array) ($audience['below'] ?? []);
        $scope = $below['scope'] ?? 'none';
        if ($level !== 'church' && $scope !== 'none') {
            $places = $this->placesBelow($place, $below);
            $roles = array_values(array_filter((array) ($below['roles'] ?? [])));
            $ids = $places->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($roles) {
                $out = $out->merge($this->leaders($ids, $roles));
            }
            if (! empty($below['place_contacts'])) {
                foreach ($places as $p) {
                    $phone = Phone::kenyaMobile($p->phone);
                    $email = filter_var($p->email, FILTER_VALIDATE_EMAIL) ? $p->email : null;
                    if ($phone || $email) {
                        $out->push(['user_id' => null, 'name' => $p->name, 'phone' => $phone, 'email' => $email, 'place_id' => (int) $p->id, 'role' => 'The place itself']);
                    }
                }
            }
            if ($roles || ! empty($below['place_contacts'])) {
                $parts[] = $this->belowWords($roles, $places, $place, $scope, ! empty($below['place_contacts']));
            }
        }

        // Typed in.
        [$typed, $invalid] = $this->typed((array) ($audience['typed'] ?? []));
        $out = $out->merge($typed);
        if ($typed->isNotEmpty()) {
            $parts[] = $typed->count().' typed in';
        }

        $recipients = $this->unique($out);
        if (count($recipients) > self::MAX) {
            throw ValidationException::withMessages(['audience' => ['That reaches more than '.self::MAX.' people - pick fewer at a time.']]);
        }

        return ['recipients' => $recipients, 'invalid' => $invalid, 'summary' => Str::limit(implode(' · ', $parts) ?: 'Nobody yet', 250)];
    }

    /** The places below that the audience picks - only ever below. */
    public function placesBelow(Territory $place, array $below): Collection
    {
        $descendants = PlaceAccess::descendantIds($place);
        $levels = array_values(array_intersect((array) ($below['levels'] ?? ['church']), ['church', 'region']));
        $levels = $levels ?: ['church'];
        $scope = $below['scope'] ?? 'all';

        if ($scope === 'picked') {
            $ids = array_map('intval', (array) ($below['place_ids'] ?? []));
            $outside = array_diff($ids, $descendants);
            if ($outside) {
                throw ValidationException::withMessages(['audience' => ['You can only send to places below you.']]);
            }

            return Territory::whereIn('id', $ids ?: [0])->get();
        }
        if ($scope === 'groups') {
            $groups = array_map('intval', (array) ($below['group_ids'] ?? []));
            if (array_diff($groups, $descendants)) {
                throw ValidationException::withMessages(['audience' => ['You can only send to places below you.']]);
            }
            $ids = [];
            foreach (Territory::whereIn('id', $groups ?: [0])->get() as $g) {
                $ids = [...$ids, (int) $g->id, ...PlaceAccess::descendantIds($g)];
            }

            return Territory::whereIn('id', $ids ?: [0])->whereIn('territory_type', $levels)->get();
        }

        return Territory::whereIn('id', $descendants ?: [0])->whereIn('territory_type', $levels)->get();
    }

    /** People with these roles (or any, with "*") at these places, with a login. */
    private function leaders(array $placeIds, array $roles): Collection
    {
        $any = in_array('*', $roles, true);

        return UserTerritoryAssignment::with(['user', 'role', 'territory'])->whereIn('territory_id', $placeIds ?: [0])->effective()->get()
            ->filter(fn ($a) => $a->user && ($any || in_array($a->role?->name, $roles, true)))
            ->map(fn ($a) => [
                'user_id' => (int) $a->user_id,
                'name' => trim("{$a->user->firstname} {$a->user->lastname}"),
                'phone' => Phone::kenyaMobile($a->user->phone),
                'email' => filter_var($a->user->email, FILTER_VALIDATE_EMAIL) ? $a->user->email : null,
                'place_id' => (int) $a->territory_id,
                'role' => $a->role?->name,
            ])->values();
    }

    /** @return array{0: Collection, 1: string[]} typed numbers/emails, and the ones that aren't either */
    private function typed(array $entries): array
    {
        $out = collect();
        $invalid = [];
        foreach ($entries as $raw) {
            // One per line, or separated by commas or semicolons - spaces inside a number are fine.
            foreach (preg_split('/[,;\r\n]+/', trim((string) $raw)) ?: [] as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                if (str_contains($entry, '@')) {
                    filter_var($entry, FILTER_VALIDATE_EMAIL) ? $out->push($this->typedPerson(null, strtolower($entry))) : $invalid[] = $entry;

                    continue;
                }
                $phone = Phone::kenyaMobile($entry);
                $phone ? $out->push($this->typedPerson($phone, null)) : $invalid[] = $entry;
            }
        }

        return [$out, array_values(array_unique($invalid))];
    }

    /** A typed number or email - the person it belongs to, if someone has it. */
    private function typedPerson(?string $phone, ?string $email): array
    {
        $user = $phone ? User::where('phone_key', Phone::key($phone))->first() : User::where('email', $email)->first();
        $assignment = $user ? UserTerritoryAssignment::where('user_id', $user->id)->effective()->orderByRaw("assignment_type = 'primary' DESC")->first() : null;

        return [
            'user_id' => $user?->id, 'name' => $user ? trim("{$user->firstname} {$user->lastname}") : ($phone ?? $email),
            'phone' => $phone ?? ($user ? Phone::kenyaMobile($user->phone) : null), 'email' => $email ?? ($user && filter_var($user->email, FILTER_VALIDATE_EMAIL) ? $user->email : null),
            'place_id' => $assignment?->territory_id, 'role' => $user ? 'Typed in' : null,
        ];
    }

    /** Each person once: by login, then by phone or email. */
    private function unique(Collection $people): array
    {
        $seen = [];
        $out = [];
        foreach ($people as $p) {
            $keys = array_filter([$p['user_id'] ? "u{$p['user_id']}" : null, $p['phone'] ? 'p'.Phone::key($p['phone']) : null, $p['email'] ? 'e'.strtolower($p['email']) : null]);
            if (array_intersect($keys, $seen)) {
                continue;
            }
            $seen = [...$seen, ...$keys];
            $out[] = $p;
        }

        return $out;
    }

    private function rolesWords(array $roles, string $where): string
    {
        return in_array('*', $roles, true) ? "Everyone {$where}" : implode(', ', array_map(fn ($r) => Str::plural($r), $roles))." {$where}";
    }

    private function belowWords(array $roles, Collection $places, Territory $place, string $scope, bool $contacts): string
    {
        $counts = $places->countBy(fn ($p) => $p->territory_type->value);
        $where = collect($counts)->map(fn ($n, $type) => $n.' '.Str::plural($type, $n))->implode(' and ');
        $who = $roles ? (in_array('*', $roles, true) ? 'All leaders' : implode(', ', array_map(fn ($r) => Str::plural($r), $roles))) : '';
        $who = trim($who.($contacts ? ($who ? ' and the contacts' : 'The contacts') : ''));

        return "{$who} of {$where}".($scope === 'all' ? " in {$place->name}" : '');
    }
}
