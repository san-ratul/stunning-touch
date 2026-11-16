# Shared Hosting Deployment

Target: generic cPanel shared hosting (Apache/LiteSpeed), **no SSH, no production Node.js, no Composer on the server**. Everything is compiled/built locally; you upload a ZIP.

## Two supported layouts

**Layout 1 (preferred, most secure):** app lives OUTSIDE `public_html`.

```
/home/USERNAME/
├── stunningtouch-app/        ← full Laravel app (vendor, app, storage, .env…)
└── public_html/              ← document root (contains ONLY the app's public/ files)
    ├── index.php             (from the app's public/, with adjusted paths)
    ├── .htaccess
    ├── build/, favicon.svg, robots … (public assets)
```

**Layout 2 (if the host forces everything inside public_html):** upload the app folder into `public_html/stunningtouch-app/` and use the provided root `.htaccess` that rewrites requests into `stunningtouch-app/public/` while denying access to sensitive directories. `deploy/root-public-htaccess.txt` contains it.

## One-command package build (on your local machine)

From the project root on Windows (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File deploy\build-deploy-package.ps1
```

The script:
1. runs `composer install --no-dev --optimize-autoloader` (production vendor),
2. verifies `public/build` exists (run `npm run build` first — the script reminds you),
3. assembles `deploy/package/` in the exact Layout-1 shape:
   - `stunningtouch-app/` — app code, vendor, storage, public assets, prepared `.env.production` template, adjusted `public/index.php` for outside-docroot operation
   - `public_html/` — `index.php` + `.htaccess` + assets to drop into your document root
   - `INSTALL.txt` — step-by-step checklist
4. zips it to `deploy/stunningtouch-deploy.zip`.

Upload that single ZIP via cPanel File Manager, extract in `/home/USERNAME/`, and follow `INSTALL.txt`.

## Manual walkthrough (Layout 1)

1. **Build locally**: `npm run build`, `composer install --no-dev --optimize-autoloader`.
2. **Create the production `.env`** from `.env.production.template` (in `deploy/`):
   - `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL=https://stunningtouch.com`
   - MySQL credentials from cPanel (create DB + user in cPanel → MySQL Databases)
   - `APP_KEY=` leave empty — generated in step 5
   - set a strong `SCHEDULER_SECRET`
3. **Upload** the app folder to `/home/USERNAME/stunningtouch-app` and extract.
4. **Document root**: copy the app's `public/` contents into `public_html/`. Then edit `public_html/index.php`:
   ```php
   require __DIR__.'/../stunningtouch-app/vendor/autoload.php';
   $app = require __DIR__.'/../stunningtouch-app/bootstrap/app.php';
   ```
5. **Import the database**: run locally `php artisan migrate --seed` against the production DB credentials, then import the dump via cPanel → phpMyAdmin. (Alternatively: import structure+seed dump; never run `migrate:fresh` on production.)
6. **Generate the key on any machine with PHP**: `php artisan key:generate --show` → put it into `.env`.
7. **Storage link** (if the host supports symlinks):
   `ln -s /home/USERNAME/stunningtouch-app/storage/app/public /home/USERNAME/stunningtouch-app/public/storage`
   If symlinks are unavailable, the fallback route already handles `/storage/*` by streaming from `storage/app/public` — OR copy uploaded files periodically. Banner/media uploads go through public storage either way.
8. **Permissions**: `storage/` and `bootstrap/cache/` → 755 (files 644) so the web user can write.
9. **Cache for production** (via Option-A cron one-off or locally before upload): `php artisan optimize` (config+routes+views). If you precompiled locally, upload `bootstrap/cache/*.php` too.
10. **Cron**: add the schedule:run cron — see CRON_SETUP.md.
11. **Test**: homepage, one tool of each category, `/admin/login` (change the seeded admin password first!), `/sitemap.xml`, `/robots.txt`.

## Never expose
`.env`, `vendor/`, `storage/`, `bootstrap/`, app source, composer files. Layout 1 keeps them outside the docroot entirely; Layout 2's `.htaccess` blocks them (test that `https://domain/.env` returns 403/404).

## Migrations on future updates
Run `php artisan migrate --force` via an SSH-less host: use a temporary protected route or your host's "Laravel tools" if provided; otherwise export/import SQL via phpMyAdmin after running migrations locally.

## Logging
`storage/logs/laravel.log` (viewable through File Manager). `APP_DEBUG=false` in production; friendly branded error pages render for visitors.
