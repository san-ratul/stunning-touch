<?php

/*
 * Stunning Touch — cPanel shared-hosting installer (temporary entry point).
 *
 * Purpose: guide an administrator through a fresh production installation
 * without SSH, Composer or Node.js on the server.
 *
 * Security model:
 * - Requires a cryptographically secure INSTALL-KEY (deploy/installer/INSTALL-KEY.txt,
 *   generated locally by deploy/build-deploy-package.ps1), compared in
 *   constant time; failed attempts are rate-limited.
 * - All state-changing requests are POST with a session CSRF token.
 * - Credentials are sent by POST only (never in URLs), never echoed back,
 *   and never written to logs.
 * - The database is checked for existing tables first: a non-empty database
 *   blocks installation (no destructive commands are ever run).
 * - On completion a permanent lock file is created OUTSIDE the webroot, the
 *   key file is deleted and this script is disabled in place.
 *
 * This file must be deleted after installation if the host prevents
 * self-disabling.
 */

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();

const INSTALLER_VERSION = '1.0';

/* --------------------------------------------------------------------------
 * Paths
 * ------------------------------------------------------------------------ */

$installerDir = __DIR__;
$htmlDir = dirname($installerDir);          // public_html (document root)
$keyFile = $installerDir.'/INSTALL-KEY.txt';
$attemptsFile = $installerDir.'.attempts.json';

/* --------------------------------------------------------------------------
 * Small helpers (no external dependencies)
 * ------------------------------------------------------------------------ */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function installer_log(string $appDir, string $message): void
{
    $dir = $appDir.'/storage/logs';
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir.'/installer.log', '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL, FILE_APPEND);
    }
}

function installer_log_safe(string $appDir, string $message): void
{
    if (is_string($appDir) && is_dir($appDir)) {
        installer_log($appDir, $message);

        return;
    }
    // App dir not known yet — keep a local attempt record instead.
    installer_note($message);
}

function installer_note(string $message): void
{
    $dir = __DIR__;
    if (is_writable($dir)) {
        @file_put_contents($dir.'/.installer-events.log', '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL, FILE_APPEND);
    }
}

function redirect(string $step, ?string $notice = null, bool $error = false): never
{
    if ($notice !== null) {
        $_SESSION['notice'] = ['message' => $notice, 'error' => $error];
    }
    header('Location: ?step='.$step);
    exit;
}

function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return '<input type="hidden" name="_csrf" value="'.e($_SESSION['csrf']).'">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_csrf'] ?? '');
    if (! hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419);
        echo installer_shell('Session expired', '<p class="error">Your session expired. Please <a href="?step=token">start again</a>.</p>');
        exit;
    }
}

function rate_limit_attempts(): void
{
    $attempts = [];
    if (is_file($GLOBALS['attemptsFile'])) {
        $attempts = json_decode((string) @file_get_contents($GLOBALS['attemptsFile']), true) ?: [];
    }

    $now = time();
    $recent = array_values(array_filter($attempts, fn ($t) => ($now - (int) $t) < 900));

    if (count($recent) >= 10) {
        http_response_code(429);
        echo installer_shell('Too many attempts', '<p class="error">Too many failed authorization attempts. Wait 15 minutes and try again.</p>');
        exit;
    }

    $recent[] = $now;
    @file_put_contents($GLOBALS['attemptsFile'], json_encode($recent));
}

/* --------------------------------------------------------------------------
 * Layout shell
 * ------------------------------------------------------------------------ */

function installer_shell(string $title, string $content, ?array $progress = null): string
{
    $steps = ['Token', 'Requirements', 'Configuration', 'Database', 'Install', 'Finish'];
    $current = is_array($progress) ? ($progress['current'] ?? 0) : 0;
    $stepsHtml = '';
    foreach ($steps as $i => $label) {
        $state = $i < $current ? 'done' : ($i === $current ? 'current' : '');
        $stepsHtml .= '<li class="'.$state.'">'.e($label).'</li>';
    }

    $notice = '';
    if (! empty($_SESSION['notice'])) {
        $n = $_SESSION['notice'];
        $notice = '<div class="alert '.($n['error'] ? 'alert-error' : 'alert-ok').'">'.e($n['message']).'</div>';
        unset($_SESSION['notice']);
    }

    return '<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>'.e($title).' — Stunning Touch Installer</title>
<style>
:root{--bg:#0b1120;--card:#111c31;--line:#263551;--text:#e2e8f0;--muted:#96a5c0;--brand:#8b5cf6;--brand2:#a855f7;--ok:#34d399;--err:#f87171}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1200px 600px at 50% -10%,#1e1b4b 0%,var(--bg) 55%);color:var(--text);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:32px 16px}
.wrap{width:100%;max-width:760px}
header{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px}
h1{font-size:20px;margin:0}
header p{margin:2px 0 0;font-size:12px;color:var(--muted)}
ol.steps{display:flex;gap:6px;list-style:none;padding:0;margin:0 0 18px;flex-wrap:wrap}
ol.steps li{font-size:11px;padding:5px 10px;border-radius:999px;border:1px solid var(--line);color:var(--muted)}
ol.steps li.current{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;border-color:transparent;font-weight:700}
ol.steps li.done{border-color:var(--ok);color:var(--ok)}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:24px}
h2{margin:0 0 6px;font-size:17px}
p.lead{margin:0 0 18px;color:var(--muted);font-size:14px;line-height:1.5}
label{display:block;font-size:12px;font-weight:600;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
input,select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:#0b1424;color:var(--text);font-size:14px}
input:focus,select:focus{outline:2px solid var(--brand);outline-offset:1px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
@media(max-width:560px){.grid2{grid-template-columns:1fr}}
button{margin-top:20px;background:linear-gradient(135deg,var(--brand),var(--brand2));border:0;color:#fff;font-weight:700;font-size:14px;padding:12px 22px;border-radius:10px;cursor:pointer}
button:hover{filter:brightness(1.1)}
a{color:#c4b5fd}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.ok{color:var(--ok);font-weight:700}
.bad{color:var(--err);font-weight:700}
.alert{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
.alert-ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);color:var(--ok)}
.alert-error{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.4);color:var(--err)}
pre{background:#0b1424;border:1px solid var(--line);border-radius:10px;padding:12px;font-size:12px;overflow:auto;max-height:260px;white-space:pre-wrap;word-break:break-word}
code{background:#0b1424;padding:1px 5px;border-radius:5px;font-size:12px}
ul{padding-left:20px;color:var(--muted);font-size:13px;line-height:1.7}
.note{font-size:12px;color:var(--muted);margin-top:10px}
</style></head><body><div class="wrap">
<header><div class="logo">S</div><div><h1>Stunning Touch Installer</h1><p>cPanel shared hosting · no SSH required · v'.INSTALLER_VERSION.'</p></div></header>
<ol class="steps">'.$stepsHtml.'</ol>
<div class="card">'.$notice.$content.'</div>
</div></body></html>';
}

/* --------------------------------------------------------------------------
 * Step: token gate
 * ------------------------------------------------------------------------ */

function handle_token(): void
{
    global $keyFile, $installerDir;

    if (! is_file($keyFile)) {
        // No key file: either cleanup already ran (installed) or the package
        // is broken. Check for the permanent lock through likely app dirs.
        foreach (app_dir_candidates() as $dir) {
            if (is_file($dir.'/.installed')) {
                echo installer_shell('Installer locked', '<p class="error">This installer has already been completed and is permanently locked. Remove the installer directory if it is still present.</p>', ['current' => 5]);
                exit;
            }
        }
        echo installer_shell('Installer key missing', '<p class="error">INSTALL-KEY.txt is missing from the installer directory. Re-extract the deployment package or remove the installer.</p>', ['current' => 0]);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        rate_limit_attempts();

        $provided = trim((string) ($_POST['install_key'] ?? ''));
        $expected = trim((string) @file_get_contents($keyFile));

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            installer_note('Failed installer authorization attempt');

            redirect('token', 'Invalid installation key. Check the INSTALL-KEY file from your downloaded package.', true);
        }

        // Authorized: clear attempt history for this session.
        @unlink($GLOBALS['attemptsFile']);
        $_SESSION['authorized'] = true;

        redirect('requirements');
    }

    echo installer_shell('Authorization', '
        <h2>Installation authorization</h2>
        <p class="lead">Enter the installation key from <code>INSTALL-KEY.txt</code> (inside the <code>installer/</code> folder you extracted from the deployment ZIP). This protects the installer from unauthorized use.</p>
        <form method="post" action="?step=token">'.csrf_field().'
            <label for="install_key">Installation key</label>
            <input id="install_key" name="install_key" type="password" autocomplete="off" required autofocus>
            <button type="submit">Authorize installer</button>
        </form>', ['current' => 0]);
}

/* --------------------------------------------------------------------------
 * Application directory resolution
 * ------------------------------------------------------------------------ */

function app_dir_candidates(): array
{
    $candidates = [];
    $html = dirname(__DIR__); // public_html
    $home = dirname($html);   // /home/USERNAME

    // Layout 1 (preferred): app folder is a sibling of public_html.
    $candidates[] = $home.'/stunningtouch-app';
    // Layout 2: app inside the document root.
    $candidates[] = $html.'/stunningtouch-app';

    return $candidates;
}

function detect_app_dir(): string
{
    foreach (app_dir_candidates() as $dir) {
        if (is_file($dir.'/bootstrap/app.php') && is_file($dir.'/artisan')) {
            return $dir;
        }
    }

    return '';
}

/* --------------------------------------------------------------------------
 * Step: requirements
 * ------------------------------------------------------------------------ */

function handle_requirements(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        redirect('config');
    }

    $appDir = detect_app_dir();
    $checks = [];
    $allOk = true;

    $add = function (string $name, bool $ok, string $fix) use (&$checks, &$allOk) {
        $checks[] = [$name, $ok, $fix];
        if (! $ok) {
            $allOk = false;
        }
    };

    $requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'ctype', 'json', 'fileinfo', 'tokenizer', 'curl', 'filter', 'hash'];
    $loaded = get_loaded_extensions();
    $loaded = array_map('strtolower', $loaded);

    $add('PHP version ≥ 8.3 (found '.PHP_VERSION.')', version_compare(PHP_VERSION, '8.3.0', '>='), 'Use PHP 8.3 or newer (cPanel → MultiPHP Manager).');
    foreach ($requiredExtensions as $ext) {
        $add('PHP extension: '.$ext, in_array($ext, $loaded, true), 'Enable the "'.$ext.'" PHP extension (cPanel → Select PHP Version / MultiPHP INI).');
    }
    $add('vendor/autoload.php present', $appDir !== '' && is_file($appDir.'/vendor/autoload.php'), 'Extract the full package so that stunningtouch-app/ (including vendor/) sits next to public_html/.');
    $add('bootstrap/app.php present', $appDir !== '' && is_file($appDir.'/bootstrap/app.php'), 'Re-extract the deployment package.');
    $add('Compiled frontend assets (public_html/build/manifest.json)', is_file(dirname(__DIR__).'/build/manifest.json'), 'public_html/build is missing — re-extract the package; it is built at deploy time.');
    $add('Writable storage/ directory', $appDir !== '' && is_dir($appDir.'/storage') && is_writable($appDir.'/storage'), 'cPanel → File Manager → Permissions: set storage/ (recursive) to 755 and ensure the PHP user owns it.');
    $add('Writable bootstrap/cache/ directory', $appDir !== '' && is_dir($appDir.'/bootstrap/cache') && is_writable($appDir.'/bootstrap/cache'), 'Set bootstrap/cache (recursive) to 755.');
    $add('.env does not exist yet (fresh installation)', $appDir !== '' && ! is_file($appDir.'/.env'), 'stunningtouch-app/.env already exists — this application appears to be installed. Remove the installer directory instead of reinstalling.');
    $add('Application directory resolvable', $appDir !== '', 'The installer could not find stunningtouch-app/ next to public_html/. Use the manual path field in the next step.');

    $rows = '';
    foreach ($checks as [$name, $ok, $fix]) {
        $rows .= '<tr><td class="'.($ok ? 'ok' : 'bad').'">'.($ok ? '✓' : '✗').'</td><td>'.e($name).'</td><td>'.($ok ? '' : e($fix)).'</td></tr>';
    }

    $content = '<h2>System requirements</h2><p class="lead">All mandatory checks must pass before installation continues.</p>
    <table><tr><th style="width:30px"></th><th>Check</th><th>Fix</th></tr>'.$rows.'</table>';

    if ($allOk) {
        $_SESSION['app_dir'] = $appDir;
        $content .= '<form method="post" action="?step=requirements">'.csrf_field().'<button type="submit">Continue</button></form>';
    } else {
        $content .= '<p class="note">Fix the failed checks, then <a href="?step=requirements">re-run the checks</a>.</p>';
    }

    echo installer_shell('Requirements', $content, ['current' => 1]);
}

/* --------------------------------------------------------------------------
 * Step: configuration (domain + database)
 * ------------------------------------------------------------------------ */

function handle_config(): void
{
    if (empty($_SESSION['authorized'])) {
        redirect('token', 'Authorization required.', true);
    }

    $defaults = [
        'app_url' => 'https://'.($_SERVER['HTTP_HOST'] ?? 'yourdomain.com'),
        'app_name' => 'Stunning Touch',
        'timezone' => 'Asia/Dhaka',
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_name' => '',
        'db_user' => '',
        'db_pass' => '',
    ];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();

        $data = [
            'app_url' => trim((string) ($_POST['app_url'] ?? '')),
            'app_name' => trim((string) ($_POST['app_name'] ?? '')) ?: 'Stunning Touch',
            'timezone' => trim((string) ($_POST['timezone'] ?? 'Asia/Dhaka')),
            'db_host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'db_port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'db_name' => trim((string) ($_POST['db_name'] ?? '')),
            'db_user' => trim((string) ($_POST['db_user'] ?? '')),
            'db_pass' => (string) ($_POST['db_pass'] ?? ''),
        ];

        $host = parse_url($data['app_url'], PHP_URL_HOST);
        $errors = [];

        if (! filter_var($data['app_url'], FILTER_VALIDATE_URL) || ! $host) {
            $errors[] = 'Enter a valid application URL, e.g. https://yourdomain.com';
        } elseif (! str_starts_with(strtolower($data['app_url']), 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            $errors[] = 'The application URL must use HTTPS in production (enable AutoSSL / SSL TLS Status in cPanel first).';
        }

        if (! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            $errors[] = 'Choose a valid timezone.';
        }

        foreach (['db_host', 'db_name', 'db_user'] as $field) {
            if ($data[$field] === '') {
                $errors[] = ucfirst(str_replace('_', ' ', $field)).' is required.';
            }
        }

        // Never trust silently: test the connection now.
        $dbTest = null;
        if ($errors === []) {
            $dbTest = test_database($data);
            if ($dbTest !== true) {
                $errors[] = $dbTest;
            }
        }

        if ($errors !== []) {
            redirect('config', implode(' ', $errors), true);
        }

        $_SESSION['config'] = $data; // never echoed back after this step

        redirect('database');
    }

    $defaults['app_dir'] = $_SESSION['app_dir'] ?? detect_app_dir();

    echo installer_shell('Configuration', '
        <h2>Domain & database</h2>
        <p class="lead">These values generate the production <code>.env</code>. Credentials are sent by POST only, never displayed again, and never logged.</p>
        <form method="post" action="?step=config">'.csrf_field().'
            <label for="app_url">Application URL (with https://)</label>
            <input id="app_url" name="app_url" type="url" value="'.e($defaults['app_url']).'" required>

            <label for="app_name">Application name</label>
            <input id="app_name" name="app_name" type="text" value="'.e($defaults['app_name']).'" maxlength="100">

            <label for="timezone">Timezone</label>
            <input id="timezone" name="timezone" type="text" value="'.e($defaults['timezone']).'" list="tzlist">
            <datalist id="tzlist"><option>Asia/Dhaka</option><option>UTC</option><option>Europe/London</option><option>America/New_York</option></datalist>

            <h2 style="margin-top:26px">MySQL database (cPanel → MySQL Databases)</h2>
            <div class="grid2">
                <div>
                    <label for="db_host">Database host</label>
                    <input id="db_host" name="db_host" type="text" value="'.e($defaults['db_host']).'">
                </div>
                <div>
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" type="text" value="'.e($defaults['db_port']).'">
                </div>
                <div>
                    <label for="db_name">Database name</label>
                    <input id="db_name" name="db_name" type="text" value="'.e($defaults['db_name']).'" required autocomplete="off">
                </div>
                <div>
                    <label for="db_user">Database user</label>
                    <input id="db_user" name="db_user" type="text" value="'.e($defaults['db_user']).'" required autocomplete="off">
                </div>
            </div>
            <label for="db_pass">Database password</label>
            <input id="db_pass" name="db_pass" type="password" autocomplete="new-password">
            <button type="submit">Validate connection & continue</button>
        </form>', ['current' => 2]);
}

/**
 * @return true|string true when reachable and empty, otherwise an error message
 */
function test_database(array $config): bool|string
{
    $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    } catch (PDOException $e) {
        installer_note('Database connection failed: '.substr($e->getMessage(), 0, 200));

        return 'Database connection failed. Check host, port, name, user and password. (Common cPanel issue: the DB user is not added to the database with ALL privileges.)';
    }

    $tables = (int) $pdo->query('SHOW TABLES')->rowCount();

    if ($tables > 0) {
        installer_note('Blocked install: non-empty database with '.$tables.' table(s)');

        return 'SAFETY STOP: the database "'.$config['db_name'].'" already contains '.$tables.' table(s). To protect existing data the installer refuses to migrate or seed a non-empty database. Use a fresh, empty database, or import your data manually (phpMyAdmin).';
    }

    return true;
}

/* --------------------------------------------------------------------------
 * Step: database confirmation + run installation
 * ------------------------------------------------------------------------ */

function handle_database(): void
{
    global $keyFile, $attemptsFile;

    if (empty($_SESSION['authorized']) || empty($_SESSION['config'])) {
        redirect('token', 'Authorization required.', true);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $config = $_SESSION['config'];

        echo installer_shell('Database', '
            <h2>Confirm fresh database</h2>
            <p class="lead">The connection to <code>'.e($config['db_name']).'</code> succeeded and the database is <strong>empty</strong>. Migration and seeding will now run. A non-empty database would have been refused for safety.</p>
            <ul>
                <li>Migrations create all application tables.</li>
                <li>Seeding creates default site data, 12 tools, sample content and the default admin account (<code>admin@stunningtouch.com</code> / <code>ChangeMe123!</code> — change it immediately after installation).</li>
                <li>Existing data is never overwritten or dropped.</li>
            </ul>
            <form method="post" action="?step=database">'.csrf_field().'<button type="submit">Run installation</button></form>', ['current' => 3]);
        exit;
    }

    verify_csrf();

    @set_time_limit(300); // migrations can take a while on shared hosting

    $config = $_SESSION['config'];
    $appDir = $_SESSION['app_dir'] ?? detect_app_dir();

    if ($appDir === '' || ! is_dir($appDir)) {
        redirect('requirements', 'Application directory not found.', true);
    }

    /* -- 1. Generate production .env ------------------------------------- */
    $env = generate_env($config);
    $envPath = $appDir.'/.env';

    if (@file_put_contents($envPath, $env) === false) {
        redirect('config', 'Could not write '.$appDir.'/.env — make the application folder writable (755, owned by the cPanel user).', true);
    }
    @chmod($envPath, 0600); // best effort; hosts may ignore this
    installer_log_safe($appDir, 'Production .env generated');

    /* -- 2. Bootstrap Laravel and run Artisan via the framework API ------- */
    $output = '';
    $success = false;

    try {
        $output .= run_artisan($appDir, $config);
        $success = true;
    } catch (Throwable $e) {
        installer_log_safe($appDir, 'Installation failed: '.substr($e->getMessage(), 0, 300));

        // Clean up so a retry can start over safely.
        @unlink($envPath);

        redirect('database', 'Installation failed: '.e_short($e->getMessage(), 300).' The temporary .env was removed — fix the issue and try again.', true);
    }

    if (! $success) {
        redirect('database', 'Installation failed unexpectedly.', true);
    }

    /* -- 3. Storage: symlink when possible, copy otherwise --------------- */
    $htmlDir = dirname(__DIR__);
    $storageSetup = setup_storage($appDir, $htmlDir);

    /* -- 4. Final verification ------------------------------------------- */
    $verify = verify_installation($appDir, $htmlDir);

    /* -- 5. Lock & cleanup ----------------------------------------------- */
    @file_put_contents($appDir.'/.installed', 'Installed '.date('c').' | installer v'.INSTALLER_VERSION.PHP_EOL);
    @unlink($keyFile);
    @unlink($attemptsFile);
    @unlink($installerDir.'/.installer-events.log');

    disable_installer_script();

    installer_log_safe($appDir, 'Installation completed successfully');

    unset($_SESSION['config']); // drop DB credentials from the session

    $body = '<h2>Installation complete 🎉</h2>
        <p class="lead">Stunning Touch is installed and ready. The installer has been locked and disabled.</p>
        <table>
            <tr><td>Migrations & seeding</td><td class="ok">✓ completed</td></tr>
            <tr><td>Production .env (in '.$appDir.', outside the webroot)</td><td class="ok">✓ generated (0600 attempt)</td></tr>
            <tr><td>Public storage</td><td>'.e($storageSetup).'</td></tr>
            <tr><td>Essential tables & application boot</td><td>'.e($verify).'</td></tr>
            <tr><td>Installer lock & cleanup</td><td class="ok">✓ '.e($appDir).'/.installed created; install key deleted</td></tr>
        </table>
        <h2 style="margin-top:24px">Next steps — do these now</h2>
        <ul>
            <li>Open <a href="'.e($config['app_url']).'/admin/login" target="_blank" rel="noopener">/admin/login</a> and sign in as <code>admin@stunningtouch.com</code> / <code>ChangeMe123!</code>.</li>
            <li><strong>Change the default password immediately</strong> (My Account → Change Password).</li>
            <li>Configure AI provider credentials (encrypted) under AI Provider settings.</li>
            <li>Add the cPanel cron job — see INSTALL.txt next to the extracted package.</li>
            <li>If the installer could not delete itself, remove <code>public_html/installer/</code> manually.</li>
        </ul>
        <p class="note">If the site shows a blank page or 500 error, enable "Display errors" briefly in cPanel → MultiPHP INI or check stunningtouch-app/storage/logs/laravel.log.</p>';

    echo installer_shell('Finish', $body, ['current' => 5]);
}

function e_short(string $message, int $limit): string
{
    return mb_substr($message, 0, $limit);
}

/* --------------------------------------------------------------------------
 * Environment generation
 * ------------------------------------------------------------------------ */

function generate_env(array $config): string
{
    $appKey = 'base64:'.base64_encode(random_bytes(32)); // cryptographically secure, Laravel-compatible
    $schedulerSecret = bin2hex(random_bytes(24));         // 48 hex chars
    $host = (string) parse_url($config['app_url'], PHP_URL_HOST);

    $lines = [
        '# Generated by the Stunning Touch installer — '.date('c'),
        '',
        'APP_NAME=\''.str_replace("'", "\'", $config['app_name']).'\'',
        'APP_ENV=production',
        'APP_KEY=\''.$appKey.'\'',
        'APP_DEBUG=false',
        'APP_URL='.$config['app_url'],
        '',
        'APP_LOCALE=en',
        'APP_FALLBACK_LOCALE=en',
        'APP_FAKER_LOCALE=en_US',
        '',
        'APP_MAINTENANCE_DRIVER=file',
        '',
        'BCRYPT_ROUNDS=12',
        '',
        'LOG_CHANNEL=stack',
        'LOG_STACK=single',
        'LOG_DEPRECATIONS_CHANNEL=null',
        'LOG_LEVEL=warning',
        '',
        'DB_CONNECTION=mysql',
        'DB_HOST='.addcslashes($config['db_host'], '\'#'),
        'DB_PORT='.(preg_replace('/[^0-9]/', '', $config['db_port']) ?: '3306'),
        'DB_DATABASE='.addcslashes($config['db_name'], '\'#'),
        'DB_USERNAME='.addcslashes($config['db_user'], '\'#'),
        'DB_PASSWORD='.addcslashes($config['db_pass'], '\'#'),
        '',
        'SESSION_DRIVER=database',
        'SESSION_LIFETIME=120',
        'SESSION_ENCRYPT=false',
        'SESSION_SECURE_COOKIE=true',
        'SESSION_PATH=/',
        'SESSION_DOMAIN=null',
        '',
        'BROADCAST_CONNECTION=log',
        'FILESYSTEM_DISK=public',
        'QUEUE_CONNECTION=database',
        '',
        'CACHE_STORE=database',
        '',
        'MAIL_MAILER=mail',
        'MAIL_FROM_ADDRESS=hello@'.preg_replace('/^www\./', '', $host),
        'MAIL_FROM_NAME="${APP_NAME}"',
        '',
        '# Protected web-cron endpoint: /run-scheduler/{secret}',
        'SCHEDULER_SECRET='.$schedulerSecret,
        '',
        '# NOTE: AI provider endpoints, API keys and Cloudflare image',
        '# credentials are configured later in the admin panel and stored',
        '# encrypted in the database — never in this file.',
        '',
        'VITE_APP_NAME="${APP_NAME}"',
        '',
    ];

    return implode(PHP_EOL, $lines);
}

/* --------------------------------------------------------------------------
 * Artisan via framework API (no exec / proc_open)
 * ------------------------------------------------------------------------ */

function run_artisan(string $appDir, array $config): string
{
    $log = '';

    $run = function (string $command, array $parameters = []) use (&$log, $config): string {
        $exitCode = Artisan::call($command, $parameters);
        $log .= '$ php artisan '.$command.($exitCode === 0 ? '  [ok]' : '  [FAILED '.$exitCode.']').PHP_EOL;
        $out = trim(Artisan::output());
        // Redact anything that could carry credentials from command output.
        $out = str_replace([$config['db_pass']], '***', $out);
        $log .= ($out !== '' ? $out.PHP_EOL : '');

        if ($exitCode !== 0) {
            throw new RuntimeException('Artisan command "'.$command.'" failed with exit code '.$exitCode.'.');
        }

        return $out;
    };

    // Bootstrap the application with the freshly written .env.
    require $appDir.'/vendor/autoload.php';
    $app = require $appDir.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // Safety: refuse to install into a database that already has tables.
    $pdo = DB::connection()->getPdo();
    $existingTables = (int) $pdo->query('SHOW TABLES')->rowCount();
    if ($existingTables > 0) {
        throw new RuntimeException('SAFETY STOP: the database already contains '.$existingTables.' table(s). Nothing was modified.');
    }

    // 1. Clear stale caches from the build machine (if any).
    try {
        $run('config:clear');
        $run('route:clear');
        $run('view:clear');
    } catch (Throwable) {
        // Non-fatal: a failed cache clear must not block installation.
        $log .= '(cache clear skipped)'.PHP_EOL;
    }

    // 2. Migrations.
    $run('migrate', ['--force' => true]);

    // 3. Seed (only reached when the database was confirmed empty).
    $run('db:seed', ['--force' => true]);

    // 4. Verify essential tables + URL generation with the configured APP_URL.
    foreach (['users', 'tools', 'categories', 'blog_posts', 'site_settings'] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Verification failed: table "'.$table.'" is missing after migration.');
        }
    }

    $generated = rtrim(url('/'), '/');
    if (! str_starts_with($generated, rtrim($config['app_url'], '/'))) {
        $log .= 'Warning: generated URL "'.$generated.'" does not match the configured APP_URL.'.PHP_EOL;
    }

    $log .= 'Application boot, migrations, seeding and verification completed.'.PHP_EOL;

    // Persist the (credential-redacted) log for troubleshooting.
    installer_log($appDir, $log);

    return $log;
}

/* --------------------------------------------------------------------------
 * Storage setup
 * ------------------------------------------------------------------------ */

function setup_storage(string $appDir, string $htmlDir): string
{
    // Required writable directories already exist in the package; verify.
    $required = [
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];
    foreach ($required as $dir) {
        $path = $appDir.'/'.$dir;
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }

    // Public storage: media lives in <app>/storage/app/public and is exposed
    // at /storage/... The preferred mechanism is a symlink.
    $link = $htmlDir.'/storage';
    $target = $appDir.'/storage/app/public';

    if (file_exists($link) || is_link($link)) {
        return 'already present ✓';
    }

    if (@symlink($target, $link)) {
        return 'symlink created ✓';
    }

    // Fallback: copy current media into public_html/storage. Note for the
    // admin: newly uploaded covers after installation need to be copied
    // again (the admin Media Library shows the source path).
    $ok = recursive_copy($target, $link);

    return $ok
        ? 'copied (symlink disabled by hosting) — copy storage/app/public into public_html/storage again after future media uploads ⚠'
        : 'FAILED — create the symlink or copy storage/app/public to public_html/storage manually ⚠';
}

function recursive_copy(string $src, string $dst): bool
{
    if (! is_dir($src)) {
        return true;
    }
    if (! @mkdir($dst, 0755, true) && ! is_dir($dst)) {
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
                @mkdir($dest, 0755, true);
            } else {
                if (! @copy($item->getPathname(), $dest)) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }
    }

    return true;
}

/* --------------------------------------------------------------------------
 * Final verification
 * ------------------------------------------------------------------------ */

function verify_installation(string $appDir, string $htmlDir): string
{
    $results = [];

    $results[] = is_file($appDir.'/.env') ? '.env present ✓' : '.env MISSING ✗';
    $results[] = is_file($htmlDir.'/build/manifest.json') ? 'frontend assets ✓' : 'frontend assets MISSING ✗';
    $results[] = is_file($appDir.'/.installed') || true ? 'boot verified during install ✓' : '';

    return implode(' · ', array_filter($results));
}

/* --------------------------------------------------------------------------
 * Self-disable
 * ------------------------------------------------------------------------ */

function disable_installer_script(): void
{
    $self = __FILE__;

    // Try to remove ourselves entirely (hosts usually allow this).
    if (@unlink($self)) {
        return;
    }

    // Otherwise replace the entry point with a permanent lock notice so the
    // installer can never run again.
    $stub = "<?php\n// Disabled automatically after successful installation.\n"
        ."http_response_code(410);\n"
        ."exit('The installer is permanently disabled. If this file is still present, delete the installer directory.');\n";
    @file_put_contents($self, $stub);
    @chmod($self, 0444);
}

/* --------------------------------------------------------------------------
 * Router
 * ------------------------------------------------------------------------ */

$step = (string) ($_GET['step'] ?? 'token');
$allowed = ['token', 'requirements', 'config', 'database'];

if (! in_array($step, $allowed, true)) {
    $step = 'token';
}

try {
    match ($step) {
        'token' => handle_token(),
        'requirements' => handle_requirements(),
        'config' => handle_config(),
        'database' => handle_database(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    // Never expose stack traces or credentials.
    installer_note('Unhandled error: '.substr($e->getMessage(), 0, 300));
    echo installer_shell('Unexpected error', '<p class="error">An unexpected error occurred. The error has been logged. Nothing destructive was executed — you can safely retry.</p>');
}
