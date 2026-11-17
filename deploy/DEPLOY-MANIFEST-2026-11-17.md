# Deployment Manifest — 2026-11-17

- **Commit:** `c3c90db` — `refactor: simplify global footer advertisement management`
- **Package:** `deploy/stunningtouch-deploy.zip` (8.55 MB)
- **SHA-256:** `6be1808a32e42d3901a009802be3564db1143987e54984961922bd2963b382ed`
- **Installer key:** `deploy/INSTALL-KEY.local.txt` (private, never commit)
- **Workflow:** upload ZIP to `public_html` via cPanel File Manager, extract in place (overwrite), run `install.php` if `storage/app/installed` marker is absent. No new migrations are required — the ad settings reuse the existing `site_settings` table.

## Changed files (vs previous deployed commit `3d986a5`)

Application files:

```
app/Http/Controllers/Admin/AdvertisementController.php   (simplified: edit + update of global status/script)
app/Http/Controllers/Admin/DashboardController.php       (removed Active Ads KPI)
app/Http/Controllers/Public/AdClickController.php        (deleted)
app/Http/Controllers/Public/BlogController.php           (removed placement ad rendering)
app/Http/Controllers/Public/HomeController.php           (removed placement ad rendering)
app/Http/Controllers/Public/SlugResolverController.php   (removed placement ad rendering)
app/Http/Controllers/Public/ToolsController.php          (removed placement ad rendering)
app/Support/AdRenderer.php                               (deleted)
resources/views/admin/ads/form.blade.php                 (deleted)
resources/views/admin/ads/index.blade.php                (deleted)
resources/views/admin/ads/preview.blade.php              (deleted)
resources/views/admin/advertisement.blade.php            (new: simplified admin page)
resources/views/admin/dashboard.blade.php                (removed Active Ads card)
resources/views/blog/category.blade.php                  (removed ad slot)
resources/views/blog/index.blade.php                     (removed ad slots)
resources/views/blog/show.blade.php                      (removed ad slots)
resources/views/home.blade.php                           (removed ad slots)
resources/views/layouts/admin.blade.php                  (sidebar route to new page)
resources/views/layouts/public.blade.php                 (global footer injection before </body>)
resources/views/layouts/tool.blade.php                   (removed ad slots)
resources/views/partials/ad-slot.blade.php               (deleted)
resources/views/tools/category.blade.php                 (removed ad slot)
resources/views/tools/index.blade.php                    (removed ad slot)
routes/web.php                                           (2 new routes; per-ad + ad-click routes removed)
tests/Feature/AdminManagementTest.php                    (old ad tests removed)
tests/Feature/AdvertisementTest.php                      (new: 14 tests)
public/build/assets/app-B4jycmuI.css                     (replaced by hashed rebuild)
public/build/assets/app-BbecGHJM.css
public/build/manifest.json
```

Documentation (not part of the deployable runtime):

```
.docs/ADVERTISEMENT_MANAGEMENT.md
.docs/DATABASE.md
.docs/PROJECT_OVERVIEW.md
README.md
```

## Verification performed

- Full test suite: 482 passed (1733 assertions), including 14 new advertisement tests.
- Laravel Pint: passed.
- Vite production build: succeeded.
- Manual render check on a local dev server: the exact Monetag snippet renders once, immediately before `</body>`, on `/`, `/tools`, `/blog`; absent on `/sitemap.xml`, `/robots.txt`, `/tools/search` JSON, `/admin/login` and 404 error pages; disabling removes it on the next request with no deployment step.

## Notes for the live site

- The global advertisement starts **disabled** (`advertisement_enabled` defaults to `'0'`). After deployment, open Admin → Advertisement, paste the Monetag snippet and set status to Enabled.
- The Monetag verification meta tag in the public layout is unchanged and unrelated to this feature.
- If the previous per-placement ad system had live rows in production, they remain in the database untouched but are no longer rendered. The audit confirmed the local database had zero advertisement rows before this change.
