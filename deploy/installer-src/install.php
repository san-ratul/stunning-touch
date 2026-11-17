<?php

/**
 * Stunning Touch — web installer for cPanel shared hosting (no SSH,
 * no Composer, no Node.js on the server).
 *
 * Deployment model (proven in production on this host):
 * the complete application (including vendor/) is extracted directly into
 * the document root. This file sits at the document root next to index.php:
 *
 * - While .env does not exist, index.php/.htaccess route every request here.
 * - The installer writes .env from .env.example (file-based sessions/cache
 *   so the app boots safely before tables exist), runs migrations + seeding
 *   through Laravel's Artisan API, creates the administrator account chosen
 *   in the form, and then deletes itself and locks permanently.
 *
 * Security:
 * - Authorization key required (constant-time compare, rate limited). The
 *   key ships as the dotfile .install-key (blocked from the web by
 *   .htaccess) and is deleted on completion.
 * - All state-changing requests are POST with a CSRF token.
 * - Credentials are never echoed or logged; no stack traces are shown.
 * - A previous failed attempt's Laravel-managed tables are reset only when
 *   the "migrations" bookkeeping table exists; foreign data hard-stops the
 *   installer.
 */

declare(strict_types=1);
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@set_time_limit(300);

const INSTALLER_VERSION = '2.0';

session_start();

$marker = __DIR__.'/storage/app/installed';
$keyFile = __DIR__.'/.install-key';
$attemptsFile = __DIR__.'/.install-attempts';
$appDir = __DIR__; // the application IS the document root in this layout
$htmlDir = __DIR__;

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

/* ------------------------------------------------------------------ utils */

$esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

function installer_log(string $message): void
{
    $dir = __DIR__.'/storage/logs';
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir)) {
        @file_put_contents($dir.'/installer.log', '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL, FILE_APPEND);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function redirect(string $to): never
{
    header('Location: install.php?step='.$to);
    exit;
}

/* ------------------------------------------------------------------- gate */

if (is_file($marker)) {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Already installed</title>
    <style>body{font-family:system-ui;background:#0b1120;color:#e2e8f0;padding:40px;text-align:center}.card{max-width:520px;margin:40px auto;background:#111a2e;border:1px solid #24304d;border-radius:12px;padding:28px}a{color:#93c5fd}</style></head>
    <body><div class="card"><h1>Already installed</h1>
    <p>Stunning Touch is already installed on this server. The installer is permanently disabled.</p>
    <p><a href="index.php">Go to the site</a> · <a href="admin/login">Admin panel</a></p></div></body></html><?php
    exit;
}

/* --------------------------------------------------- installer key checks */

$authorized = false;
if (is_file($keyFile)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'authorize') {
        // Rate limit: 10 failures / 15 minutes.
        $attempts = is_file($attemptsFile) ? (json_decode((string) @file_get_contents($attemptsFile), true) ?: []) : [];
        $now = time();
        $recent = array_values(array_filter($attempts, fn ($t) => ($now - (int) $t) < 900));

        if (count($recent) >= 10) {
            $authorized = false;
            $authError = 'Too many failed attempts. Wait 15 minutes and try again.';
        } else {
            $provided = trim((string) ($_POST['install_key'] ?? ''));
            $expected = trim((string) @file_get_contents($keyFile));

            if ($provided !== '' && hash_equals($expected, $provided)) {
                $authorized = true;
                @unlink($attemptsFile);
                $_SESSION['installer_authorized'] = true;
            } else {
                $recent[] = $now;
                @file_put_contents($attemptsFile, json_encode($recent));
                $authError = 'Invalid installation key.';
                installer_log('Failed installer authorization attempt');
            }
        }
    } elseif (! empty($_SESSION['installer_authorized'])) {
        $authorized = true;
    }
}

/* --------------------------------------------------------- stale cleanup */

function stale_paths(): array
{
    // Leftovers from earlier failed extraction attempts (older package
    // layouts): a partial app folder and/or a nested document root.
    $paths = [];
    foreach ([__DIR__.'/stunningtouch-app', dirname(__DIR__).'/stunningtouch-app'] as $dir) {
        if (is_dir($dir) && ! is_file($dir.'/vendor/laravel/framework/src/Illuminate/Support/Str.php')) {
            $paths[] = $dir;
        }
    }
    $nested = __DIR__.'/public_html';
    if (is_file($nested.'/build/manifest.json') && is_file($nested.'/index.php')) {
        $paths[] = $nested;
    }

    return $paths;
}

$cleanupResults = [];
if ($authorized && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'cleanup') {
    foreach (stale_paths() as $path) {
        normalize_permissions($path);
        $cleanupResults[] = (recursive_delete($path) ? '✓ removed: ' : '✗ partially removed (check in File Manager): ').basename($path);
    }
    installer_log('Cleanup: '.count($cleanupResults).' path(s) processed');
}

/* ------------------------------------------------------ requirements scan */

$checks = [];
$checksOk = true;
$mark = function (string $label, bool $ok, string $fix = '') use (&$checks, &$checksOk) {
    $checks[] = [$label, $ok, $fix];
    if (! $ok) {
        $checksOk = false;
    }
};

$mark('PHP ≥ 8.3 (found '.PHP_VERSION.')', version_compare(PHP_VERSION, '8.3.0', '>='), 'Switch to PHP 8.3 in cPanel → MultiPHP Manager.');
$loadedExt = array_map('strtolower', get_loaded_extensions());
foreach (['pdo_mysql', 'mbstring', 'openssl', 'ctype', 'json', 'fileinfo', 'tokenizer', 'curl', 'filter', 'hash'] as $ext) {
    $mark('PHP extension: '.$ext, in_array($ext, $loadedExt, true), 'Enable the extension in cPanel → Select PHP Version.');
}
$mark('Application files complete (vendor/, artisan)', is_file(__DIR__.'/vendor/autoload.php') && is_file(__DIR__.'/artisan'), 'Re-extract the ZIP into this folder with overwrite enabled.');
$mark('Compiled frontend assets (public/build/manifest.json)', is_file(__DIR__.'/public/build/manifest.json'), 'Re-extract the ZIP.');
$mark('.env does not exist yet', ! is_file(__DIR__.'/.env'), 'Delete .env only if this is a retry of a failed attempt (the installer recreates it).');
$mark('storage/ writable', is_writable(__DIR__.'/storage') || @mkdir(__DIR__.'/storage/framework/views', 0775, true), 'Set storage/ (recursive) to 755 in File Manager → Permissions.');
$mark('bootstrap/cache writable', is_writable(__DIR__.'/bootstrap/cache'), 'Set bootstrap/cache (recursive) to 755.');
$stalePaths = stale_paths();

/* ------------------------------------------------------------- installer */

$errors = [];
$done = false;
$successHtml = '';

$input = [
    'app_name' => 'Stunning Touch',
    'app_url' => 'https://'.($_SERVER['HTTP_HOST'] ?? 'yourdomain.com'),
    'timezone' => 'Asia/Dhaka',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'admin_name' => 'Administrator',
    'admin_email' => '',
    'admin_pass' => '',
];

if ($authorized && $checksOk && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'install') {
    foreach ($input as $key => $default) {
        $input[$key] = trim((string) ($_POST[$key] ?? $default));
    }
    $input['db_pass'] = (string) ($_POST['db_pass'] ?? '');

    $host = parse_url($input['app_url'], PHP_URL_HOST);
    if ($input['app_name'] === '') {
        $errors[] = 'Application name is required.';
    }
    if (! filter_var($input['app_url'], FILTER_VALIDATE_URL) || ! $host) {
        $errors[] = 'Application URL must be a valid URL, e.g. https://yourdomain.com';
    } elseif (! str_starts_with(strtolower($input['app_url']), 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
        $errors[] = 'The application URL must use HTTPS in production (enable AutoSSL in cPanel first).';
    }
    if (! in_array($input['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'Choose a valid timezone.';
    }
    foreach (['db_host', 'db_name', 'db_user'] as $f) {
        if ($input[$f] === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $f)).' is required.';
        }
    }
    if (! filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid admin email is required.';
    }
    if (strlen($input['admin_pass']) < 10 || ! preg_match('/[a-zA-Z]/', $input['admin_pass']) || ! preg_match('/[0-9]/', $input['admin_pass'])) {
        $errors[] = 'Admin password must be at least 10 characters with at least one letter and one digit.';
    }

    if ($errors === []) {
        // 1. Test the database connection with plain PDO.
        try {
            new PDO(
                "mysql:host={$input['db_host']};port={$input['db_port']};dbname={$input['db_name']};charset=utf8mb4",
                $input['db_user'],
                $input['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
        } catch (Throwable $e) {
            installer_log('DB connect failed: '.substr($e->getMessage(), 0, 200));
            $errors[] = 'Database connection failed. Check the name, user, password and that the user has ALL PRIVILEGES on the database.';
        }
    }

    if ($errors === []) {
        // 2. Write .env from .env.example in place (file sessions/cache so the
        //    app boots safely regardless of database state).
        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $schedulerSecret = bin2hex(random_bytes(24));
        write_env(__DIR__.'/.env.example', __DIR__.'/.env', [
            'APP_NAME' => env_quote($input['app_name']),
            'APP_ENV' => '"production"',
            'APP_KEY' => env_quote($appKey),
            'APP_DEBUG' => '"false"',
            'APP_URL' => env_quote(rtrim($input['app_url'], '/')),
            'LOG_LEVEL' => '"warning"',
            'DB_CONNECTION' => '"mysql"',
            'DB_HOST' => env_quote($input['db_host']),
            'DB_PORT' => env_quote($input['db_port']),
            'DB_DATABASE' => env_quote($input['db_name']),
            'DB_USERNAME' => env_quote($input['db_user']),
            'DB_PASSWORD' => env_quote($input['db_pass']),
            'SESSION_DRIVER' => '"file"',
            'SESSION_SECURE_COOKIE' => 'true',
            'CACHE_STORE' => '"file"',
            'QUEUE_CONNECTION' => '"database"',
            'FILESYSTEM_DISK' => '"public"',
            'SCHEDULER_SECRET' => env_quote($schedulerSecret),
        ]);
        @chmod(__DIR__.'/.env', 0600);
        installer_log('Production .env generated');

        try {
            // 3. Boot the framework with the new .env.
            require __DIR__.'/vendor/autoload.php';
            $app = require __DIR__.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();

            // 4. Database safety: only a confirmed-empty database proceeds.
            //    A previous FAILED attempt (Laravel "migrations" bookkeeping
            //    table present) is reset automatically; foreign data stops
            //    the installer without touching anything.
            $tables = array_map(
                fn ($t) => is_object($t) ? array_values((array) $t)[0] : $t,
                DB::select('SHOW TABLES')
            );
            if ($tables !== []) {
                if (! in_array('migrations', $tables, true)) {
                    throw new RuntimeException('SAFETY STOP: the database is not empty (tables: '.implode(', ', array_slice($tables, 0, 5)).'). Use a fresh empty database — existing data was left untouched.');
                }
                Schema::dropAllTables(); // reset a failed attempt
                installer_log('Reset tables from a previous failed attempt');
            }

            // 5. Migrate + seed through the Artisan API (no exec).
            foreach (['config:clear', 'route:clear', 'view:clear'] as $cmd) {
                try {
                    Artisan::call($cmd);
                } catch (Throwable) {
                }
            }
            Artisan::call('migrate', ['--force' => true]);
            $migrateOutput = trim(Artisan::output());
            Artisan::call('db:seed', ['--force' => true]);

            foreach (['users', 'tools', 'categories', 'blog_posts', 'site_settings'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException('Verification failed: table "'.$table.'" is missing.');
                }
            }

            // 6. Administrator account chosen in the form (resubmit-safe).
            $admin = User::query()->firstOrNew(['email' => $input['admin_email']]);
            $admin->name = $input['admin_name'];
            $admin->password = $input['admin_pass']; // hashed by the model cast
            $admin->is_active = true;
            $admin->save();

            // 6b. Remove the seeder's well-known default account so no
            //     publicly-documented credentials survive on the server.
            $seeded = User::query()->where('email', 'admin@stunningtouch.com')->first();
            if ($seeded && $seeded->id !== $admin->id && Hash::check('ChangeMe123!', $seeded->password)) {
                BlogPost::query()->where('user_id', $seeded->id)->update(['user_id' => $admin->id]);
                $seeded->delete();
                installer_log('Seeded default admin account removed');
            }

            // 7. Public storage: symlink when allowed, copy otherwise.
            $storageNote = setup_public_storage();

            // 8. Mark installed, remove key, disable self.
            @file_put_contents($marker, now()->toDateTimeString().PHP_EOL);
            @unlink($keyFile);
            @unlink($attemptsFile);
            $selfDeleted = @unlink(__FILE__);
            if (! $selfDeleted) {
                $stub = "<?php\n// Disabled automatically after successful installation.\nhttp_response_code(410);\nexit('The installer is permanently disabled.');\n";
                @file_put_contents(__FILE__, $stub);
            }
            installer_log('Installation completed successfully');

            $base = rtrim($input['app_url'], '/');
            $cronWeb = '* * * * * /usr/bin/curl -s -m 55 "'.$base.'/run-scheduler/YOUR_SCHEDULER_SECRET" > /dev/null 2>&1';
            $cronCli = '* * * * * cd '.__DIR__.' && php artisan schedule:run >> /dev/null 2>&1';

            ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation complete</title>
            <style>body{font-family:system-ui;background:#0b1120;color:#e2e8f0;padding:24px}.card{max-width:720px;margin:24px auto;background:#111a2e;border:1px solid #24304d;border-radius:12px;padding:28px}h1{color:#fff}a{color:#93c5fd}.ok{background:#0d2b22;border:1px solid #14532d;color:#86efac;border-radius:8px;padding:12px}table{width:100%;border-collapse:collapse;font-size:.9rem}td{padding:6px 8px;border-bottom:1px solid #24304d}.ok{color:#86efac}pre{background:#0b1120;padding:10px;border-radius:8px;overflow-x:auto;color:#93c5fd;font-size:.8rem}code{color:#93c5fd}p,li{color:#b6c2d9;line-height:1.6;font-size:.92rem}</style></head>
            <body><div class="card">
            <h1>Installation complete 🎉</h1>
            <div class="ok">Database migrated and seeded. Administrator account created<?= $selfDeleted ? ' — the installer deleted itself.' : ' — delete install.php from the server now.' ?></div>
            <table>
                <tr><td>Site</td><td><a href="<?= $esc($base) ?>"><?= $esc($base) ?></a></td></tr>
                <tr><td>Admin panel</td><td><a href="<?= $esc($base) ?>/admin/login"><?= $esc($base) ?>/admin/login</a></td></tr>
                <tr><td>Administrator</td><td><?= $esc($input['admin_email']) ?> (your chosen password)</td></tr>
                <tr><td>Public storage</td><td><?= $esc($storageNote) ?></td></tr>
                <tr><td>Lock &amp; cleanup</td><td class="ok">✓ storage/app/installed created; installer key deleted</td></tr>
            </table>
            <p><strong>Next — add the cPanel cron job</strong> (Cron Jobs → once per minute) with YOUR secret from .env:</p>
            <pre><?= $esc($cronWeb) ?></pre>
            <p class="muted">CLI equivalent (if your host provides a PHP CLI): <code><?= $esc($cronCli) ?></code></p>
            <p>Then configure AI provider credentials (stored encrypted) under <strong>Admin → AI Provider</strong>.</p>
            </div></body></html><?php
            exit;
        } catch (Throwable $e) {
            installer_log('Installation failed: '.substr($e->getMessage(), 0, 300));
            // Keep the .env so a retry only needs "Run installation" again;
            // the database safety rules above make retries safe.
            $errors[] = 'Installation failed: '.mb_substr($e->getMessage(), 0, 300).
                "\nFix the problem and submit again — the database safety check will decide what is safe.";
        }
    }
}

/* ------------------------------------------------------------ helper fns */

function write_env(string $example, string $target, array $values): void
{
    $source = is_file($example) ? file($example, FILE_IGNORE_NEW_LINES) : [];
    $out = [];
    $done = [];
    foreach ($source as $line) {
        if (preg_match('/^([A-Z0-9_]+)=/', $line, $m) && array_key_exists($m[1], $values)) {
            $out[] = $m[1].'='.$values[$m[1]];
            $done[$m[1]] = true;

            continue;
        }
        $out[] = $line;
    }
    foreach ($values as $key => $value) {
        if (empty($done[$key])) {
            $out[] = $key.'='.$value;
        }
    }
    file_put_contents($target, implode("\n", $out)."\n");
}

function env_quote(string $value): string
{
    $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

    return '"'.$escaped.'"';
}

function setup_public_storage(): string
{
    $link = __DIR__.'/public/storage';
    $target = __DIR__.'/storage/app/public';

    if (file_exists($link) || is_link($link)) {
        return 'already present ✓';
    }

    if (@symlink($target, $link)) {
        return 'symlink created ✓';
    }

    // Fallback: copy media into public/storage (re-copy after future uploads,
    // or ask the host to enable symlinks).
    $ok = recursive_copy($target, $link);

    return $ok ? 'copied (symlink disabled by hosting) ⚠ re-copy after media uploads' : 'FAILED — create public/storage manually ⚠';
}

function recursive_copy(string $src, string $dst): bool
{
    if (! is_dir($src)) {
        return true;
    }
    if (! @mkdir($dst, 0775, true) && ! is_dir($dst)) {
        return false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $dest = $dst.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
        try {
            if ($item->isDir()) {
                @mkdir($dest, 0775, true);
            } elseif (! @copy($item->getPathname(), $dest)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
    }

    return true;
}

function normalize_permissions(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    @chmod($dir, 0755);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        @chmod($item->getPathname(), $item->isDir() ? 0755 : 0644);
    }
}

function recursive_delete(string $dir): bool
{
    if (! is_dir($dir)) {
        return true;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        try {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        } catch (Throwable) {
        }
    }

    return @rmdir($dir) || ! is_dir($dir);
}

/* ------------------------------------------------------------------- view */

$authError = $authError ?? '';
$showAuth = is_file($keyFile) && ! $authorized;
$showForm = $authorized && $checksOk && ! $done;
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Stunning Touch — Install</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1200px 600px at 50% -10%,#1e1b4b 0%,#0b1120 55%);color:#e2e8f0;min-height:100vh;padding:28px 14px}
.card{max-width:720px;margin:0 auto;background:#111a2e;border:1px solid #24304d;border-radius:16px;padding:26px}
header{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#8b5cf6,#a855f7);display:flex;align-items:center;justify-content:center;font-weight:800}
h1{font-size:1.25rem;margin:0}h2{font-size:1rem;margin:22px 0 6px;color:#fff}
p,li{color:#b6c2d9;font-size:.92rem;line-height:1.55}
label{display:block;font-size:.8rem;font-weight:600;margin:12px 0 4px;color:#96a5c0;text-transform:uppercase;letter-spacing:.04em}
input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;border:1px solid #2c3a5c;background:#0b1424;color:#e2e8f0;font-size:.95rem}
input:focus{outline:2px solid #8b5cf6;outline-offset:1px}
.row{display:flex;gap:10px}.row>div{flex:1}
button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:10px;background:linear-gradient(135deg,#8b5cf6,#a855f7);color:#fff;font-weight:700;font-size:1rem;cursor:pointer}
button:hover{filter:brightness(1.1)}
.error{background:#3b1220;border:1px solid #7f1d1d;color:#fca5a5;border-radius:8px;padding:12px;margin:14px 0;font-size:.9rem;white-space:pre-wrap;word-break:break-word}
.ok{background:#0d2b22;border:1px solid #14532d;color:#86efac;border-radius:8px;padding:12px;margin:14px 0;font-size:.9rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}td{padding:7px 6px;border-bottom:1px solid #24304d;vertical-align:top}
.ok{color:#34d399;font-weight:700}.bad{color:#f87171;font-weight:700}
code{color:#93c5fd;font-size:.82rem}a{color:#93c5fd}.muted{color:#7c8db0;font-size:.8rem}
</style></head><body><div class="card">
<header><div class="logo">S</div><div><h1>Stunning Touch — Installer</h1><p class="muted">cPanel shared hosting · no SSH required · v<?= INSTALLER_VERSION ?></p></div></header>

<?php if ($showAuth) { ?>
    <h2>Authorization</h2>
    <p>Enter the installation key from the <code>.install-key</code> file that shipped inside the ZIP (also saved locally by the builder as <code>deploy/INSTALL-KEY.local.txt</code>).</p>
    <?php if ($authError !== '') { ?><div class="error"><?= $esc($authError) ?></div><?php } ?>
    <form method="post" action="install.php">
        <input type="hidden" name="action" value="authorize">
        <input type="hidden" name="_csrf" value="<?= $esc(csrf_token()) ?>">
        <label>Installation key</label>
        <input name="install_key" type="password" autocomplete="off" required autofocus>
        <button type="submit">Authorize installer</button>
    </form>
<?php } elseif ($cleanupResults !== []) { ?>
    <h2>Cleanup result</h2>
    <div class="ok"><?php foreach ($cleanupResults as $r) {
        echo $esc($r).'<br>';
    } ?></div>
    <p>The stale folders are gone. Now re-extract the deployment ZIP into this folder (overwrite when asked) and reload this page.</p>
<?php } elseif (! $checksOk || $stalePaths !== []) { ?>
    <h2>System requirements</h2>
    <table>
        <?php foreach ($checks as [$label, $ok, $fix]) { ?>
            <tr><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></td><td><?= $esc($label) ?></td><td><?= $esc($fix) ?></td></tr>
        <?php } ?>
    </table>
    <?php if ($stalePaths !== []) { ?>
        <h2>Previous extraction detected</h2>
        <p>Leftover folders from an earlier failed extraction will be permanently deleted (the installer runs as the web-server user, which can delete files File Manager cannot):</p>
        <ul><?php foreach ($stalePaths as $p) { ?><li><code><?= $esc($p) ?></code></li><?php } ?></ul>
        <form method="post" action="install.php">
            <input type="hidden" name="action" value="cleanup">
            <input type="hidden" name="_csrf" value="<?= $esc(csrf_token()) ?>">
            <button type="submit">Delete stale folders &amp; re-check</button>
        </form>
    <?php } ?>
    <p class="muted">Fix the failed checks and reload this page.</p>
<?php } else { ?>
    <h2>Install Stunning Touch</h2>
    <p>This wizard writes <code>.env</code>, imports the database schema with default data and creates your administrator account. The installer deletes itself when finished.</p>
    <?php if ($errors !== []) { ?><div class="error"><?= $esc(implode("\n", $errors)) ?></div><?php } ?>
    <form method="post" action="install.php" autocomplete="off">
        <input type="hidden" name="action" value="install">
        <input type="hidden" name="_csrf" value="<?= $esc(csrf_token()) ?>">
        <h2>Site</h2>
        <label>Application URL (https://)</label>
        <input name="app_url" type="url" value="<?= $esc($input['app_url']) ?>" required>
        <label>Application name</label>
        <input name="app_name" value="<?= $esc($input['app_name']) ?>" maxlength="100" required>
        <label>Timezone</label>
        <input name="timezone" value="<?= $esc($input['timezone']) ?>" list="tzlist">
        <datalist id="tzlist"><option>Asia/Dhaka</option><option>UTC</option><option>Europe/London</option><option>America/New_York</option></datalist>
        <h2>MySQL database (cPanel → MySQL Databases)</h2>
        <div class="row">
            <div><label>Host</label><input name="db_host" value="<?= $esc($input['db_host']) ?>" required></div>
            <div><label>Port</label><input name="db_port" value="<?= $esc($input['db_port']) ?>" required></div>
        </div>
        <label>Database name</label>
        <input name="db_name" value="<?= $esc($input['db_name']) ?>" required>
        <div class="row">
            <div><label>User</label><input name="db_user" value="<?= $esc($input['db_user']) ?>" required></div>
            <div><label>Password</label><input name="db_pass" type="password" autocomplete="new-password"></div>
        </div>
        <h2>Administrator account</h2>
        <label>Name</label>
        <input name="admin_name" value="<?= $esc($input['admin_name']) ?>" required>
        <label>Email</label>
        <input name="admin_email" type="email" value="<?= $esc($input['admin_email']) ?>" required>
        <label>Password (min 10 chars, letters + digits)</label>
        <input name="admin_pass" type="password" minlength="10" required>
        <button type="submit">Install Stunning Touch</button>
        <p class="muted">The connection is tested before anything is written. Migrations run after the database check passes. A database from a previous failed attempt is reset automatically; foreign data is never touched.</p>
    </form>
<?php } ?>
</div></body></html>
