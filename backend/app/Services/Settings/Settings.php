<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\Territory;
use App\Models\User;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Models\Audit;

/**
 * Reads and writes the Settings hub's values (docs/specs/settings-spec.md).
 *
 * A setting resolves from the place up: its own value, else the nearest
 * place above it (subregion, region, diocese), else the system value, else
 * the config default - unless a place above has LOCKED it, which wins. A
 * place keeps a row only while its value differs from what it would
 * inherit. Secrets are stored encrypted and never leave here in plain
 * text except to the code that uses them.
 *
 * Rows are cached per place under a version number; every write bumps the
 * version, so every place below sees a change at once.
 */
final class Settings
{
    private const VERSION_KEY = 'settings:version';

    public const SECRET_MASK = '••••';

    public const CLEAR_SECRET = '__clear__';

    /** @var array<string, Collection> rows per "v{version}:{scope}" for this request */
    private array $memo = [];

    private ?Territory $systemPlace = null;

    /** @var array<string, mixed> field key => its config value before applyToConfig() */
    private array $configOriginals = [];

    private ?int $applied = null;

    public function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * The place and every place above it, then the system:
     * [['id' => 13, 'type' => 'church', 'name' => '…'], …, ['id' => 0, 'type' => 'system', 'name' => 'System']].
     */
    public function chain(?Territory $place): array
    {
        $chain = [];
        for ($t = $place, $depth = 0; $t && $depth < 7; $depth++) {
            $chain[] = ['id' => (int) $t->id, 'type' => $t->territory_type?->value ?? 'territory', 'name' => $t->name];
            $t = $t->parent_territory_id ? Territory::find($t->parent_territory_id) : null;
        }
        $chain[] = ['id' => 0, 'type' => 'system', 'name' => 'System'];

        return $chain;
    }

    /**
     * Where a setting's value comes from for a place (null = the system level).
     *
     * @return array{value: mixed, source: string, from: ?array, locked_by: ?array, changed: bool, secret_set: bool}
     */
    public function resolve(string $key, ?Territory $place, ?array $chain = null): array
    {
        $field = SettingsRegistry::field($key) ?? ['default' => null, 'secret' => false];
        $chain ??= $this->chain($place);
        $default = $this->defaultFor($field);
        $self = $chain[0];
        $above = array_slice($chain, 1);

        $result = fn ($value, string $source, ?array $from, ?array $lockedBy) => [
            'value' => $value,
            'source' => $source,
            'from' => $from ? ['type' => $from['type'], 'name' => $from['name']] : null,
            'locked_by' => $lockedBy ? ['type' => $lockedBy['type'], 'name' => $lockedBy['name']] : null,
            'changed' => $value !== $default,
            'secret_set' => ! empty($field['secret']) && $value !== null && $value !== '',
        ];

        // A place's own details (payment details): only its own row counts.
        if (($field['inherits'] ?? true) === false) {
            $row = $this->rows($self['id'])->get($key);

            return $row ? $result($this->decode($row, $field), 'own', null, null) : $result($default, 'default', null, null);
        }

        foreach (array_reverse($above) as $up) {
            $row = $this->rows($up['id'])->get($key);
            if ($row && $row->is_locked) {
                return $result($this->decode($row, $field), 'inherited', $up, $up);
            }
        }
        foreach ($chain as $at) {
            $row = $this->rows($at['id'])->get($key);
            if ($row) {
                $own = $at['id'] === $self['id'];

                return $result($this->decode($row, $field), $own ? 'own' : 'inherited', $own ? null : $at, null);
            }
        }

        return $result($default, 'default', null, null);
    }

    /** Just the value. */
    public function get(string $key, ?Territory $place = null): mixed
    {
        return $this->resolve($key, $place)['value'];
    }

    /** A diocese system setting (Security, Documents, Maintenance...), falling back to its default. */
    public function system(string $key): mixed
    {
        try {
            return $this->get($key, $this->systemPlace());
        } catch (\Throwable) {
            // No settings table yet (fresh install, config:cache) - the default applies.
            return SettingsRegistry::field($key)['default'] ?? null;
        }
    }

    /** What the place would get without its own row (what "reset" falls back to). */
    public function inherited(string $key, ?Territory $place): array
    {
        $chain = $this->chain($place);
        if ((SettingsRegistry::field($key)['inherits'] ?? true) === false) {
            return $this->resolve($key, null, [['id' => -1, 'type' => 'default', 'name' => 'Default']]);
        }

        return $place ? $this->resolve($key, null, array_slice($chain, 1)) : $this->resolve($key, null, [['id' => -1, 'type' => 'default', 'name' => 'Default']]);
    }

    /**
     * A section's fields as the screen shows them - secrets never in plain text.
     *
     * @return array<int, array> cards [{title, fields: [...]}]
     */
    public function present(string $section, ?Territory $place, string $level): array
    {
        $chain = $this->chain($place);
        $cards = [];
        foreach (SettingsRegistry::fields($section) as $key => $field) {
            $r = $this->resolve($key, $place, $chain);
            $editable = in_array($level, $field['levels'], true) && $r['locked_by'] === null;
            $cards[$field['card']][] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'help' => $field['help'],
                'used_by' => $field['used_by'],
                'span' => $field['span'],
                'options' => $field['options'],
                'value' => $field['secret'] ? null : $r['value'],
                'default' => $field['secret'] ? null : $this->defaultFor($field),
                'secret' => (bool) $field['secret'],
                'secret_set' => $r['secret_set'],
                'source' => $r['source'],
                'from' => $r['from'],
                'locked_by' => $r['locked_by'],
                'locked_here' => $r['source'] === 'own' && (bool) $this->rows($chain[0]['id'])->get($key)?->is_locked,
                'changed' => $r['changed'],
                'editable' => $editable,
                'lockable' => $field['lockable'] && in_array($level, ['region', 'diocese', 'system'], true),
            ];
        }

        return array_map(fn ($title, $fields) => ['title' => $title, 'fields' => $fields], array_keys($cards), $cards);
    }

    /**
     * Save a section's changes for a place (null = system).
     *
     * @param  array<string, mixed>  $values  key => new value (blank secret = keep; CLEAR_SECRET = remove)
     * @param  array<string, bool>  $locks  key => lock for the places below
     * @param  string[]  $reset  keys going back to what the place inherits
     * @return array<string, array{old: mixed, new: mixed}> what changed
     *
     * @throws ValidationException
     */
    public function setMany(?Territory $place, string $level, string $section, array $values, array $locks = [], array $reset = [], ?User $user = null): array
    {
        $fields = SettingsRegistry::fields($section);
        $chain = $this->chain($place);
        $errors = [];
        foreach (array_unique([...array_keys($values), ...array_keys($locks), ...$reset]) as $key) {
            $field = $fields[$key] ?? null;
            if (! $field || ! in_array($level, $field['levels'], true)) {
                $errors[$key][] = "This setting can't be changed here.";

                continue;
            }
            $lockedBy = $this->resolve($key, $place, $chain)['locked_by'];
            if ($lockedBy) {
                $errors[$key][] = "Set by the {$lockedBy['type']} ({$lockedBy['name']}) - it's locked.";
            }
            if (isset($locks[$key]) && (! $field['lockable'] || ! in_array($level, ['region', 'diocese', 'system'], true))) {
                $errors[$key][] = "This setting can't be locked here.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $values = $this->validated($fields, $values);
        $scope = $chain[0]['id'];
        $changes = [];

        foreach (array_unique([...array_keys($values), ...array_keys($locks), ...$reset]) as $key) {
            $field = $fields[$key];
            $own = Setting::where('scope_id', $scope)->where('key', $key)->first();
            $before = $this->resolve($key, $place, $chain)['value'];
            $wasLocked = (bool) $own?->is_locked;
            $lock = array_key_exists($key, $locks) ? (bool) $locks[$key] : $wasLocked;
            $inherited = $this->inherited($key, $place)['value'];

            if (in_array($key, $reset, true) || ($field['secret'] && ($values[$key] ?? null) === self::CLEAR_SECRET)) {
                // Back to what the place inherits (for a system secret: the .env default).
                $own?->delete();
                $after = $inherited;
            } else {
                $new = array_key_exists($key, $values) ? $values[$key] : $before;
                if ($new === $inherited && ! $lock) {
                    $own?->delete();
                } else {
                    Setting::updateOrCreate(
                        ['territory_id' => $scope ?: null, 'key' => $key],
                        ['value' => $this->encode($new, $field), 'is_locked' => $lock, 'updated_by' => $user?->id],
                    );
                }
                $after = $new;
            }

            if ($after !== $before || $lock !== $wasLocked) {
                $changes[$key] = [
                    'old' => $field['secret'] ? ($before ? self::SECRET_MASK : null) : $before,
                    'new' => $field['secret'] ? ($after ? self::SECRET_MASK : null) : $after,
                ] + ($lock !== $wasLocked ? ['locked' => $lock] : []);
            }
        }

        if ($changes) {
            $this->bump();
            $this->audit($place, $section, $changes, $user);
        }

        return $changes;
    }

    /**
     * One audit row per save, on the place (or the system), secrets masked.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function audit(?Territory $place, string $section, array $changes, ?User $user, string $event = 'settings.updated'): void
    {
        $request = request();
        (new Audit)->forceFill([
            'user_type' => $user ? 'user' : null,
            'user_id' => $user?->id,
            'event' => $event,
            'auditable_type' => $place ? 'territory' : 'setting',
            'auditable_id' => $place?->id ?? 0,
            'old_values' => array_map(fn ($c) => $c['old'], $changes),
            'new_values' => array_map(fn ($c) => $c['new'], $changes),
            'url' => $request?->fullUrl(),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 1023),
            'tags' => "settings,{$section}",
        ])->save();
    }

    /** The place system settings are kept at: the diocese (there is one). */
    public function systemPlace(): ?Territory
    {
        return $this->systemPlace ??= Territory::where('territory_type', 'diocese')->orderBy('id')->first();
    }

    /**
     * Saved email and other system settings take over from .env for this
     * process (each field's 'config' key). Values still at their default
     * leave the .env value alone. Safe at boot: a missing table or database
     * just means .env applies.
     */
    public function applyToConfig(): void
    {
        $place = $this->systemPlace();
        $chain = $this->chain($place);
        foreach (SettingsRegistry::fields() as $key => $field) {
            if (empty($field['config'])) {
                continue;
            }
            // The config value before any setting touched it (the .env one), so a
            // setting put back to its default restores it in long-running workers.
            if (! array_key_exists($key, $this->configOriginals)) {
                $this->configOriginals[$key] = config($field['config']);
            }
            $r = $this->resolve($key, $place, $chain);
            if ($r['source'] === 'default') {
                config([$field['config'] => $this->configOriginals[$key]]);
            } else {
                $value = $r['value'] === '' ? null : $r['value'];
                if (isset($field['config_scale']) && is_numeric($value)) {
                    $value = (int) $value * $field['config_scale'];
                }
                config([$field['config'] => $value]);
            }
        }
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
        $this->applied = $this->version();
    }

    /** For long-running queue workers: re-apply when a setting changed since. */
    public function applyIfStale(): void
    {
        if ($this->applied !== $this->version()) {
            $this->applyToConfig();
        }
    }

    /** The most recent settings change at a place (or the system). */
    public function lastChange(?Territory $place): ?array
    {
        $audit = Audit::query()
            ->where('auditable_type', $place ? 'territory' : 'setting')
            ->where('auditable_id', $place?->id ?? 0)
            ->where('event', 'like', 'settings.%')
            ->latest('id')->first();
        if (! $audit) {
            return null;
        }
        $who = $audit->user_id ? User::find($audit->user_id) : null;

        return [
            'at' => $audit->created_at?->toIso8601String(),
            'by' => $who ? trim("{$who->firstname} {$who->lastname}") : null,
            'section' => explode(',', (string) $audit->tags)[1] ?? null,
        ];
    }

    private function bump(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, $this->version() + 1);
        } catch (\Throwable) {
            // No cache store - the next request reads the table fresh anyway.
        }
        $this->memo = [];
    }

    /** One place's own rows, keyed by setting key. A missing table reads as no rows. */
    private function rows(int $scope): Collection
    {
        if ($scope < 0) {
            return collect();
        }
        $memoKey = "v{$this->version()}:{$scope}";
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        try {
            $rows = Cache::rememberForever("settings:{$memoKey}", fn () => Setting::where('scope_id', $scope)->get()->keyBy('key'));
        } catch (\Throwable) {
            $rows = collect();
        }

        return $this->memo[$memoKey] = $rows;
    }

    private function defaultFor(array $field): mixed
    {
        return $field['default'] ?? null;
    }

    private function decode(Setting $row, array $field): mixed
    {
        if ($row->value === null) {
            return null;
        }
        if (! empty($field['secret'])) {
            try {
                return json_decode(Crypt::decryptString($row->value), true);
            } catch (\Throwable) {
                return null; // e.g. APP_KEY rotated - shows as "Not set"
            }
        }

        return json_decode($row->value, true);
    }

    private function encode(mixed $value, array $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value);

        return ! empty($field['secret']) ? Crypt::encryptString($json) : $json;
    }

    /**
     * Validate with each field's rules (dotted keys travel as "a__b" so the
     * validator doesn't read them as nesting), drop blank secrets (keep the
     * saved one) and coerce to the field's type.
     */
    private function validated(array $fields, array $values): array
    {
        $input = [];
        $rules = [];
        foreach ($values as $key => $value) {
            $field = $fields[$key];
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
            }
            if ($field['secret'] && $value === self::CLEAR_SECRET) {
                $input[str_replace('.', '__', $key)] = $value;

                continue;
            }
            $name = str_replace('.', '__', $key);
            $input[$name] = $value;
            $rules[$name] = $field['rules'] ?: ['nullable'];
            if ($field['type'] === 'select' && is_array($field['options'])) {
                $rules[$name] = [...(array) $rules[$name], 'in:'.implode(',', array_keys($field['options']))];
            }
        }
        // Messages name the field as the screen does ("Paybill or till number"), not "finance  mpesa number".
        $attributes = [];
        foreach (array_keys($input) as $name) {
            $attributes[$name] = $fields[str_replace('__', '.', $name)]['label'] ?? $name;
        }
        $validator = Validator::make($input, $rules, [], $attributes);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $name => $messages) {
                $errors[str_replace('__', '.', $name)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        $clean = [];
        foreach ($input as $name => $value) {
            $key = str_replace('__', '.', $name);
            $clean[$key] = $value === self::CLEAR_SECRET ? $value : $this->coerce($value, $fields[$key]['type']);
        }

        return $clean;
    }

    private function coerce(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return $type === 'switch' ? false : null;
        }

        return match ($type) {
            'switch' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : $value,
            default => is_array($value) ? array_values($value) : trim((string) $value),
        };
    }
}
