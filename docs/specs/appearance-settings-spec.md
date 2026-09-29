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

**Added 2026-09-29 (UI redesign, Phase 2):** `theme`
(`light`/`dark`/`system`, default `light`) and `accent`
(`teal`/`navy`/`green`/`purple`/`orange`/`red`, default `teal` - the
diocese brand colour). Neither maps to a CSS class; the frontend renders
`theme` as `<html data-theme-mode>` (`system` is resolved in the browser
via `prefers-color-scheme`, since PHP can't see it) and `accent` as an
inline `--primary-rgb` on `<html>`. The template already ships its dark
palette under `[data-theme-mode="dark"]`. Accent is a curated key, not a
free hex value, so every option is one checked against white button
text; `App\Support\Appearance::ACCENT_RGB` holds the key -> RGB map.

## API Contract

All three routes are self-scoped to the authenticated user - no
territory checks, unlike most of this app's other endpoints, since
appearance preferences aren't territory data.

| Method | Path | Request | Response |
|---|---|---|---|
| `GET` | `/api/appearance` | - | `{success, data: {theme, accent, density, text_size, reduce_motion, high_contrast, focus_outlines, underline_links, big_targets, classes: [...], accent_rgb}}` - effective settings with defaults filled in, the resolved CSS class list `App\Support\Appearance::classesFor()` computes, and the accent's `"r, g, b"` string. |
| `PUT` | `/api/appearance` | Any subset of the keys above | Upserts only the keys present in the payload; a key set to its own default deletes that row instead of storing it. Returns the same shape as `GET`. |
| `DELETE` | `/api/appearance` | - | Deletes all of this user's `user_preferences` rows (reset to defaults). Returns the same shape as `GET`, now all-default. |

Validation: `theme`, `accent`, `density` and `text_size` must each be
one of their allowed values, the 5 toggles boolean-ish (`Validator`'s `boolean` rule,
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
- [ ] `PUT` with `{"theme": "dark", "accent": "purple"}` persists both,
      returns `accent_rgb` for purple, and adds no CSS classes.
- [ ] `PUT` with an invalid `theme` or a free-hex `accent` returns `422`.
- [ ] `DELETE` removes all of the user's rows; a subsequent `GET`
      returns all defaults again.
- [ ] One user's saved preferences never affect another user's `GET`
      response (basic row-scoping check).
