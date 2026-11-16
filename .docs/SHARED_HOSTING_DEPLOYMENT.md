# Stunning Touch — Shared Hosting Deployment (cPanel)

This guide is the detailed companion to `deploy/INSTALL.txt`. The short
version: **build one ZIP locally, upload it, open the installer URL, done.**

## What the package contains

`deploy/build-deploy-package.ps1` produces `deploy/stunningtouch-deploy.zip`:

```text
stunningtouch-app/       Laravel application + production vendor/ (outside the webroot)
public_html/             document root: index.php, .htaccess, build/, favicons, robots.txt
public_html/installer/   temporary browser installer + INSTALL-KEY.txt (removed after install)
INSTALL.txt              step-by-step deployment guide
LAYOUT2-root-public-htaccess.txt   hardening .htaccess for forced Layout 2
```

Included but never packaged: `.env` (real), API keys, git history,
`node_modules/`, local SQLite databases, logs, sessions, generated media.

## Layouts

- **Layout 1 (preferred)** — application outside `public_html`:
  `/home/USER/stunningtouch-app` + `/home/USER/public_html`. The shipped
  `public_html/index.php` already points at `../stunningtouch-app/`.
- **Layout 2 (fallback)** — host forces everything inside `public_html`:
  extract there, move `public_html/public_html/*` up one level, and use
  `LAYOUT2-root-public-htaccess.txt` as the root `.htaccess` (it hard-denies
  `stunningtouch-app/` internals and routes all requests through
  `stunningtouch-app/public/`).

## Local build

```powershell
npm ci
npm run build
powershell -ExecutionPolicy Bypass -File deploy\build-deploy-package.ps1
```

The builder:

1. Verifies PHP 8.3+, Composer, required extensions and the Vite manifest
   (refuses to package an incomplete frontend build).
2. Runs `composer install --no-dev --prefer-dist --optimize-autoloader`
   and later **restores your local dev dependencies** automatically.
3. Stages a clean package (Laravel source + `vendor/` outside the webroot,
   compiled assets in the webroot, storage skeleton with placeholders).
4. Generates a **cryptographically secure installer key**
   (`installer/INSTALL-KEY.txt` inside the ZIP, plus a private local copy at
   `deploy/INSTALL-KEY.local.txt` — never commit or share that file).
5. Verifies integrity: `vendor/autoload.php`, `artisan`, `manifest.json`,
   installer files, placeholder-only env template, no `.env`/logs/databases
   in the tree **or inside the ZIP**, and writes
   `deploy/stunningtouch-deploy.zip.sha256`.

## The installer

Open `https://yourdomain.com/installer/install.php` (Layout 1) after
uploading and extracting the ZIP. The wizard:

1. **Authorization** — the installation key (constant-time comparison,
   rate-limited to 10 failures / 15 minutes, CSRF-protected).
2. **Requirements** — PHP ≥ 8.3, extensions, `vendor/autoload.php`,
   compiled assets, writable `storage/` and `bootstrap/cache/`, and that no
   `.env` exists yet (blocks re-installation of a live site).
3. **Configuration** — domain (HTTPS enforced), app name, timezone
   (default `Asia/Dhaka`), and MySQL credentials. The connection is
   validated immediately; credentials travel by POST only and are never
   echoed or logged.
4. **Database safety** — the installer checks `SHOW TABLES` first:
   - **Empty database** → migrations + seeding run through Laravel's
     Artisan API (no `exec`/`shell_exec`/`proc_open`/SSH).
   - **Non-empty database** → **SAFETY STOP**. Nothing is migrated, seeded,
     dropped or overwritten.
5. **Environment** — `.env` is generated in the private app folder with a
   fresh cryptographically secure `APP_KEY`, a 48-char scheduler secret,
   production defaults (`APP_DEBUG=false`, database sessions/cache/queue)
   and empty AI credentials. `chmod 600` is attempted.
6. **Storage** — required directories are verified; the public storage
   link is created as a symlink when the host allows it, otherwise the
   current media files are copied into `public_html/storage` (re-copy after
   future uploads, or ask the host to enable symlinks).
7. **Verification** — essential tables, application boot, URL generation
   against the configured `APP_URL`.
8. **Cleanup** — a permanent lock (`stunningtouch-app/.installed`) is
   created outside the webroot, the installer key is deleted and the
   installer entry point is removed (or replaced by a disabled stub and
   the admin is told to delete the folder manually).

## Post-install checklist

1. Sign in at `/admin/login` as `admin@stunningtouch.com` / `ChangeMe123!`.
2. **Change the password immediately** (My Account → Change Password).
3. Configure AI providers (encrypted, in-database) under AI Provider.
4. Add the cPanel cron job:

   ```cron
   * * * * * /usr/bin/curl -s -m 55 "https://yourdomain.com/run-scheduler/YOUR_SCHEDULER_SECRET" > /dev/null 2>&1
   ```

   (The secret lives in `stunningtouch-app/.env`. CLI cron, where
   available, is equivalent: `php /home/USER/stunningtouch-app/artisan schedule:run`.)

5. Verify `https://yourdomain.com/.env` returns 403/404.

## Troubleshooting

| Problem | Fix |
|---|---|
| Permission denied on storage | File Manager → Permissions → 755 recursive on `storage/` and `bootstrap/cache` |
| Database connection failed | Check the cPanel-prefixed DB name/user, password and ALL PRIVILEGES grant |
| Missing PHP extension | MultiPHP Manager → enable the extension |
| Symlink blocked | The installer copies media instead; re-copy after uploads or ask the host |
| HTTP 500 / blank page | `stunningtouch-app/storage/logs/laravel.log`, `storage/logs/installer.log` |
| Missing styles / assets | `public_html/build` must exist — re-extract the ZIP |
| Installer already locked | `stunningtouch-app/.installed` exists — the app is installed; never reinstall over an existing database |
| Wrong document root | Move the two folders per Layout 1, or apply Layout 2 |

## Security notes

- The installer only accepts its random key, only over POST+CSRF, and is
  permanently disabled after a successful install.
- Generated credentials (`APP_KEY`, `SCHEDULER_SECRET`, DB password) are
  never shown after submission and never logged.
- `vendor/`, `storage/`, `bootstrap/`, `.env` and `composer.*` live outside
  the document root in Layout 1; Layout 2 hard-blocks them via `.htaccess`.
- No secrets are ever committed to git: the ZIP, checksum, staging folder
  and `INSTALL-KEY.local.txt` are gitignored.
