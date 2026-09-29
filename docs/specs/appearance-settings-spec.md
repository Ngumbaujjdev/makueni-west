# Appearance Settings Spec

## Data Model

New table `user_preferences` - sparse `(user_id, key, value)` rows, one
row per *non-default* setting. A user with no rows gets every default;
setting a control back to its default deletes the row rather than
storing the default value explicitly (mirrors the pattern this was
ported from, `v1-events-backend`'s `UserPreference` model).

```php
Schema::create('user_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('key');
    $table->string('value');
    $table->timestamps();

    $table->unique(['user_id', 'key']);
});
```

`App\Support\Appearance` is the single source of truth for which keys
exist, their allowed values, defaults, and the CSS class each value
maps to - not duplicated between the controller and any view.

**Scope for this rollout** (confirmed with the user): `density`
(`compact`/`comfortable`/`spacious`, default `comfortable`),
`text_size` (`small`/`medium`/`large`, default `medium`), and 5 boolean
accessibility toggles (`reduce_motion`, `high_contrast`,
`focus_outlines`, `underline_links`, `big_targets`, all default `off`).
No `theme` (light/dark/system) key - this app hardcodes
`data-theme-mode="light"` everywhere and has no dark-mode CSS; that's
tracked as separate future work, not part of this table or this API.

## API Contract

All three routes are self-scoped to the authenticated user - no
territory checks, unlike most of this app's other endpoints, since
appearance preferences aren't territory data.

| Method | Path | Request | Response |
|---|---|---|---|
| `GET` | `/api/appearance` | - | `{success, data: {density, text_size, reduce_motion, high_contrast, focus_outlines, underline_links, big_targets, classes: [...]}}` - effective settings with defaults filled in, plus the resolved CSS class list `App\Support\Appearance::classes()` computes. |
| `PUT` | `/api/appearance` | Any subset of the keys above | Upserts only the keys present in the payload; a key set to its own default deletes that row instead of storing it. Returns the same shape as `GET`. |
| `DELETE` | `/api/appearance` | - | Deletes all of this user's `user_preferences` rows (reset to defaults). Returns the same shape as `GET`, now all-default. |

Validation: `density` must be one of the 3 allowed values, `text_size`
one of the 3, the 5 toggles boolean-ish (`Validator`'s `boolean` rule,
accepting `true`/`false`/`1`/`0`). Unknown keys in the `PUT` payload are
ignored, not rejected - keeps this endpoint forward-compatible with a
future key without a hard version coupling to the frontend.

## Permission Rules

None beyond `auth:sanctum` - every authenticated user manages their own
appearance preferences, at every territory tier. Not gated by the
module/permission system at all, matching how `v1-events-backend`
treats this as "nothing here belongs to the client or the platform."

## Acceptance Criteria

- [ ] `GET` for a user with no saved rows returns all defaults.
- [ ] `PUT` with `{"density": "compact"}` persists one row; a
      subsequent `GET` reflects it.
- [ ] `PUT` with a value equal to that key's default deletes any
      existing row for it rather than storing the default explicitly.
- [ ] `PUT` with an invalid `density`/`text_size` value returns `422`.
- [ ] `DELETE` removes all of the user's rows; a subsequent `GET`
      returns all defaults again.
- [ ] One user's saved preferences never affect another user's `GET`
      response (basic row-scoping check).
