# Advertisement Management

A single, global, admin-managed advertisement script (e.g. a Monetag snippet), rendered automatically in the footer of every public page.

## Admin interface

Admin → **Advertisement** (`/admin/advertisements`). Exactly two controls plus a save button:

1. **Advertisement Status** — Enabled / Disabled.
2. **Advertisement Script** — large textarea accepting raw JavaScript/HTML snippets. Complete `<script>` tags can be pasted directly; multiple script tags are supported. The code is stored and rendered exactly as supplied — never escaped, re-encoded or wrapped.

Changes take effect immediately (no deployment, no cache-clearing step needed).

## Storage

Two keys in the existing `site_settings` table (key/value), read through `App\Models\SiteSetting`:

- `advertisement_enabled` — `'1'` or `'0'` (default `'0'`).
- `advertisement_script` — the raw snippet as typed by the admin.

`SiteSetting::set()` invalidates the forever-cached `site_settings` entry on every save, so the very next public page render reflects the change.

## Rendering

- Injected once, immediately before `</body>`, inside `resources/views/layouts/public.blade.php` — the single layout used by all public pages (home, tools, categories, tool pages, blog, static pages).
- Raw output (`{!! ... !!}`) only at that one location; the snippet must never be HTML-escaped there.
- Injected **only when** status is enabled **and** the script is non-empty.

## Exclusions (never injected)

- Admin panel pages and admin login (separate layouts that do not extend the public layout).
- Error responses (`403/404/419/429/500/503` — rendered with `pageType = 'error'` which the layout gates on).
- JSON/API responses, downloads (they do not render Blade views).
- `sitemap.xml` and `robots.txt` (standalone responses, no layout).

## Safety model

- Only authenticated, active admins can reach the edit page; the save endpoint is `PUT /admin/advertisements`, CSRF-protected, validated (`enabled in:1,0`, script `string max:100000`), and re-authorized by the `auth` + `EnsureAdmin` middleware group.
- Saved ad code is **trusted admin-controlled executable content**: it is never executable in the admin UI (the admin page displays it escaped inside a `<textarea>` only).
- The stored script is never exposed through unrelated public APIs or responses.
- Every save writes an `advertisement.updated` audit log entry.

## Legacy placement system (retired)

The previous per-placement ad system (11 seeded `ad_placements`, per-ad `advertisements` CRUD, `AdRenderer`, `/ad-click/{ad}` tracking) has been removed from the UI and rendering pipeline. The `ad_placements` and `advertisements` tables, their models and existing rows are **preserved untouched** — no data was deleted or migrated. Any historical ad rows remain in the database but are no longer rendered anywhere.

## Tests

`tests/Feature/AdvertisementTest.php` covers: admin save/edit, guest denial, enabled rendering, disabled non-rendering, admin-page non-execution, single injection before `</body>`, exact snippet preservation, multiple script tags, error/JSON/sitemap/robots exclusions, immediate effect without deployment, cache invalidation, legacy data preservation, validation and audit logging.
