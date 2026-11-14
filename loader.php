<?php
declare(strict_types=1);
/* Bike + Kit Spotter standalone cPanel installer, PHP 8.1+.
 * Upload ONLY this file, open it, and read var/setup-token.php in cPanel File
 * Manager. Use that private token to unlock maintenance. No source edits needed.
 * Video processing stays on your laptop. This installs the public PHP site.
 */
$setupKey = ''; // Legacy key support; new installations generate a private token.
const BR_GUARD = "<?php http_response_code(404); exit; ?>\n";

function brEscape(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function brAtomic(string $path, string $contents): void {
    $temporary = tempnam(dirname($path), '.write-');
    if ($temporary === false) { throw new RuntimeException('Cannot create temporary file.'); }
    try {
        if (file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write file (check disk quota).');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { throw new RuntimeException('Could not activate file.'); }
    } finally { if (is_file($temporary)) { unlink($temporary); } }
}
function brJson(string $path): array {
    $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('Invalid configuration.'); }
    return $data;
}
function brFetch(string $path, int $limit = 1048576): string {
    // Fixed repository and HTTPS hosts; no user-supplied download URLs or redirects.
    $token = getenv('BIKERIDERS_GITHUB_TOKEN');
    $ref = getenv('BIKERIDERS_RELEASE_REF') ?: 'main';
    if (!preg_match('/^(?:main|[a-f0-9]{40})$/D', $ref)) {
        throw new RuntimeException('BIKERIDERS_RELEASE_REF must be main or a full Git commit SHA.');
    }
    $url = $token
        ? 'https://api.github.com/repos/efrgdgr0024345/bikeriders/contents/' . $path . '?ref=' . $ref
        : 'https://raw.githubusercontent.com/efrgdgr0024345/bikeriders/' . $ref . '/' . $path
            . '?installer_check=' . bin2hex(random_bytes(8));
    $handle = curl_init($url);
    $body = '';
    $headers = ['Accept: application/vnd.github.raw+json', 'User-Agent: BikeKit-Installer/1'];
    if ($token) { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) { return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($ok === false || $status !== 200) {
        throw new RuntimeException('GitHub download failed (HTTP ' . $status . '). Check outbound HTTPS, GitHub rate limits, or private-repository token access.');
    }
    return $body;
}
function brManifest(array $manifest): array {
    $expected = ['index.php', 'style.css', 'app.js'];
    if (($manifest['schema'] ?? null) !== 1 ||
        !is_string($manifest['version'] ?? null) ||
        !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['version']) ||
        !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Unsupported release manifest.');
    }
    $names = array_keys($manifest['files']);
    sort($names); sort($expected);
    if ($names !== $expected) { throw new RuntimeException('Unexpected release file list.'); }
    foreach ($manifest['files'] as $hash) {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new RuntimeException('Invalid release checksum.');
        }
    }
    if (isset($manifest['loader_sha256']) && (!is_string($manifest['loader_sha256']) ||
        !preg_match('/^[a-f0-9]{64}$/D', $manifest['loader_sha256']))) {
        throw new RuntimeException('Invalid loader checksum.');
    }
    return $manifest;
}
function brReleaseValid(string $private, string $release): bool {
    if (!preg_match('/^\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $release)) { return false; }
    $directory = $private . '/releases/' . $release;
    if (!is_file($directory . '/manifest.json')) { return false; }
    try { $manifest = brManifest(brJson($directory . '/manifest.json')); }
    catch (Throwable $error) { return false; }
    foreach ($manifest['files'] as $name => $hash) {
        if (!is_file($directory . '/' . $name) || !hash_equals($hash, hash_file('sha256', $directory . '/' . $name))) { return false; }
    }
    return true;
}
function brInstall(string $private, array $manifest, callable $fetch, ?string $reuse = null): string {
    $manifest = brManifest($manifest);
    $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $release = $manifest['version'] . '-' . substr(hash('sha256', $encoded), 0, 16);
    $target = $private . '/releases/' . $release;
    if (is_dir($target)) {
        if (!brReleaseValid($private, $release)) { throw new RuntimeException('Existing release failed integrity checks.'); }
        return $release;
    }
    $staging = $private . '/releases/.staging-' . bin2hex(random_bytes(8));
    if (!mkdir($staging, 0700)) { throw new RuntimeException('Could not create staging directory.'); }
    try {
        foreach ($manifest['files'] as $name => $expectedHash) {
            brProgress($private, 'files', 'Preparing ' . $name, 25 + (int)(50 * count(glob($staging . '/*')) / count($manifest['files'])));
            $existing = $reuse !== null && preg_match('/^\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $reuse)
                ? $private . '/releases/' . $reuse . '/' . $name : '';
            $bytes = $existing !== '' && is_file($existing) && hash_equals($expectedHash, hash_file('sha256', $existing))
                ? (string)file_get_contents($existing) : $fetch('deploy/cpanel/app/' . $name);
            if (!hash_equals($expectedHash, hash('sha256', $bytes))) {
                throw new RuntimeException('Release checksum mismatch. Nothing was activated; retry after the repository update completes.');
            }
            brAtomic($staging . '/' . $name, $bytes);
        }
        brAtomic($staging . '/manifest.json', $encoded);
        if (!rename($staging, $target)) { throw new RuntimeException('Could not retain verified release.'); }
    } finally {
        if (is_dir($staging)) {
            foreach (scandir($staging) as $file) {
                if ($file !== '.' && $file !== '..') { unlink($staging . '/' . $file); }
            }
            rmdir($staging);
        }
    }
    return $release;
}
function brState(string $private, string $public): array {
    $path = $private . '/state.json';
    if (!is_file($path)) { return ['app' => 'bikeriders', 'public' => $public, 'current' => null, 'previous' => null]; }
    $state = brJson($path);
    if (($state['app'] ?? '') !== 'bikeriders' || ($state['public'] ?? '') !== $public) {
        throw new RuntimeException('This data directory belongs to a different installation.');
    }
    return $state;
}
function brActivate(string $private, array $state, string $release): array {
    if (!brReleaseValid($private, $release)) { throw new RuntimeException('Release is missing or failed verification.'); }
    if ($state['current'] !== $release) {
        $state['previous'] = $state['current'];
        $state['current'] = $release;
        brAtomic($private . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }
    return $state;
}

function brWriteGuarded(string $path, array $data): void {
    if (is_link($path)) { throw new RuntimeException('Private configuration must not be a symbolic link.'); }
    brAtomic($path, BR_GUARD . json_encode($data, JSON_THROW_ON_ERROR));
}
function brReadGuarded(string $path): array {
    if (is_link($path)) { throw new RuntimeException('Unsafe private configuration.'); }
    $bytes = (string)file_get_contents($path);
    if (!str_starts_with($bytes, BR_GUARD)) { throw new RuntimeException('Invalid private configuration.'); }
    return json_decode(substr($bytes, strlen(BR_GUARD)), true, 32, JSON_THROW_ON_ERROR);
}
function brAuthDirectory(string $directory): void {
    if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        throw new RuntimeException('The var path must be a real directory.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new RuntimeException('Cannot create var. Check hosting permissions.'); }
    $deny = $directory . '/.htaccess';
    if (!file_exists($deny)) {
        brAtomic($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        chmod($deny, 0644);
    }
    if (is_link($deny) || !is_file($deny)) { throw new RuntimeException('Unsafe var/.htaccess.'); }
    $rules = (string)file_get_contents($deny);
    if (!preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $rules) ||
        preg_match('/Require\s+all\s+granted|Allow\s+from\s+all/i', $rules)) {
        throw new RuntimeException('Protect var/.htaccess with deny rules before continuing.');
    }
    if (!file_exists($directory . '/index.php')) { brAtomic($directory . '/index.php', BR_GUARD); }
}
function brAuthSource(string $directory, string $legacyKey = ''): array {
    $environment = getenv('BIKERIDERS_SETUP_KEY');
    if ($environment) {
        if (strlen($environment) < 32) { throw new RuntimeException('BIKERIDERS_SETUP_KEY must have at least 32 characters.'); }
        return ['plain' => $environment, 'version' => hash('sha256', $environment), 'environment' => true];
    }
    if (is_file($directory . '/maintenance.php')) {
        $data = brReadGuarded($directory . '/maintenance.php');
        if (!is_string($data['password_hash'] ?? null)) { throw new RuntimeException('Invalid maintenance key hash.'); }
        return ['hash' => $data['password_hash'], 'version' => hash('sha256', $data['password_hash'])];
    }
    if (strlen($legacyKey) >= 32) { return ['plain' => $legacyKey, 'version' => hash('sha256', $legacyKey)]; }
    $path = $directory . '/setup-token.php';
    if (!file_exists($path)) { brWriteGuarded($path, ['setup_token' => bin2hex(random_bytes(32))]); }
    $data = brReadGuarded($path);
    if (!is_string($data['setup_token'] ?? null) || strlen($data['setup_token']) !== 64) {
        throw new RuntimeException('Invalid setup token. Restore private configuration through cPanel.');
    }
    return ['plain' => $data['setup_token'], 'version' => hash('sha256', $data['setup_token'])];
}
function brAuthLock(string $directory) {
    $path = $directory . '/auth.lock';
    if (is_link($path)) { throw new RuntimeException('Unsafe authentication lock.'); }
    $lock = fopen($path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock maintenance configuration.'); }
    return $lock;
}
function brPrivateDirectory(string $path, string $documentRoot): string {
    if (is_link($path)) { throw new RuntimeException('Private storage must not be a symbolic link.'); }
    if (!is_dir($path) && !mkdir($path, 0700, true)) {
        throw new RuntimeException('Cannot create private storage. Set BIKERIDERS_PRIVATE_DIR to a writable path outside your web roots.');
    }
    $private = (string)realpath($path);
    $root = rtrim($documentRoot, DIRECTORY_SEPARATOR);
    if ($private === $root || str_starts_with($private, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private storage must be outside the document root.');
    }
    if (!is_writable($private)) { throw new RuntimeException('Private storage is not writable.'); }
    return $private;
}
function brStatus(string $private): array {
    $running = false;
    if (is_file($private . '/install.lock')) {
        $lock = fopen($private . '/install.lock', 'r');
        if (!$lock) { throw new RuntimeException('Cannot inspect maintenance lock.'); }
        if (flock($lock, LOCK_EX | LOCK_NB)) { flock($lock, LOCK_UN); } else { $running = true; }
        fclose($lock);
    }
    $progress = is_file($private . '/progress.json') ? brJson($private . '/progress.json') : null;
    if (!$running && ($progress['status'] ?? '') === 'running') {
        $progress['status'] = 'interrupted';
        $progress['message'] = 'The process stopped before reporting completion. Review the last task and retry.';
    }
    return ['running' => $running, 'progress' => $progress];
}
function brProgress(string $private, string $stage, string $message, int $percent, string $status = 'running'): void {
    if (!isset($GLOBALS['brProgressRoot']) || $GLOBALS['brProgressRoot'] !== $private) { return; }
    $previous = is_file($private . '/progress.json') ? brJson($private . '/progress.json') : [];
    brAtomic($private . '/progress.json', json_encode([
        'stage' => $stage, 'message' => $message, 'percent' => $percent, 'status' => $status,
        'started_at' => $previous['started_at'] ?? time(), 'updated_at' => time(),
    ], JSON_THROW_ON_ERROR));
}
function brUpdateLoader(string $private, array $manifest, callable $fetch, string $path): bool {
    $expected = $manifest['loader_sha256'] ?? null;
    if (!is_string($expected)) { throw new RuntimeException('Publish a release manifest with a loader checksum first.'); }
    if (is_link($path)) { throw new RuntimeException('The loader must not be a symbolic link.'); }
    $old = (string)file_get_contents($path);
    if (hash_equals($expected, hash('sha256', $old))) { return false; }
    $bytes = $fetch('loader.php');
    if (!hash_equals($expected, hash('sha256', $bytes)) || !str_starts_with($bytes, '<?php')) {
        throw new RuntimeException('Loader checksum mismatch; the current loader was preserved.');
    }
    if (!is_dir($private . '/backups')) { mkdir($private . '/backups', 0700); }
    $backup = $private . '/backups/loader-' . hash('sha256', $old) . '.php';
    if (!file_exists($backup)) { brAtomic($backup, $old); }
    if (!hash_equals(hash('sha256', $old), hash_file('sha256', $backup))) {
        throw new RuntimeException('Loader backup failed verification.');
    }
    brAtomic($path, $bytes); // Replace last, after the app has been verified and activated.
    chmod($path, 0644);
    if (function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
    return true;
}
function brBootstrap(string $private): string {
    $code = "<?php\n// BikeRiders managed entry point v1\n\$brPrivate = " . var_export($private, true) . ";\n";
    return $code . <<<'BOOTSTRAP'
$brState = json_decode((string)file_get_contents($brPrivate . '/state.json'), true);
$brRelease = $brState['current'] ?? '';
if (!is_string($brRelease) || !preg_match('/^\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $brRelease)) {
    http_response_code(503); exit('No active release.');
}
require $brPrivate . '/releases/' . $brRelease . '/index.php';
BOOTSTRAP;
}
function brOperate(string $private, string $public, string $action, ?callable $fetch = null): string {
    $fetch ??= 'brFetch';
    $lock = fopen($private . '/install.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another operation is running. Check maintenance progress; do not delete lock files.'); }
    $state = brState($private, $public);
    $before = $state;
    $activated = false;
    try {
        $GLOBALS['brProgressRoot'] = $private;
        brAtomic($private . '/progress.json', json_encode(['started_at' => time()], JSON_THROW_ON_ERROR));
        brProgress($private, 'starting', 'Checking installation and protected storage.', 5);
        if ($action === 'rollback') {
            if (empty($state['previous'])) { throw new RuntimeException('No previous release is available.'); }
            brActivate($private, $state, $state['previous']);
            brProgress($private, 'complete', 'Previous app release restored. Data preserved.', 100, 'complete');
            return 'Rolled back to ' . $state['previous'] . '. Data was preserved.';
        }
        $bootstrap = brBootstrap($private);
        $index = $public . '/index.php';
        if ($action === 'install') {
            if (file_exists($index) && file_get_contents($index) !== $bootstrap) {
                throw new RuntimeException('An existing index.php is present. Use an empty subfolder; existing websites will not be overwritten.');
            }
            if (file_exists($public . '/index.html')) { throw new RuntimeException('An index.html already exists. Use an empty subfolder.'); }
        }
        brProgress($private, 'manifest', 'Checking the published GitHub release.', 15);
        $manifest = brManifest(json_decode($fetch('deploy/cpanel/manifest.json', 65536), true, 32, JSON_THROW_ON_ERROR));
        if ($action === 'check') {
            $message = 'Available version: ' . $manifest['version'] . '. Current release: ' . ($state['current'] ?? 'not installed') . '.';
            brProgress($private, 'complete', $message, 100, 'complete');
            return $message;
        }
        if (!isset($manifest['loader_sha256'])) { throw new RuntimeException('The published release needs a loader checksum. Publish the current manifest first.'); }
        foreach (['releases', 'data'] as $directory) {
            if (!is_dir($private . '/' . $directory)) { mkdir($private . '/' . $directory, 0700); }
        }
        if (!file_exists($private . '/data/catalog.json')) { brAtomic($private . '/data/catalog.json', '{"schema":1,"sightings":[]}'); }
        $release = brInstall($private, $manifest, $fetch, $state['current']);
        brProgress($private, 'verify', 'Verifying release and catalogue data.', 75);
        $catalog = brJson($private . '/data/catalog.json');
        if (($catalog['schema'] ?? null) !== 1 || !is_array($catalog['sightings'] ?? null)) { throw new RuntimeException('Restore a valid catalogue before activating an update.'); }
        brActivate($private, $state, $release);
        $activated = true;
        if (!file_exists($index)) {
            $entry = fopen($index, 'x');
            if ($entry === false) { throw new RuntimeException('Could not create index.php.'); }
            $written = fwrite($entry, $bootstrap); fclose($entry);
            if ($written !== strlen($bootstrap)) { unlink($index); throw new RuntimeException('Could not write entry point.'); }
            chmod($index, 0644);
        }
        brProgress($private, 'loader', 'Backing up and updating the maintenance loader.', 90);
        brUpdateLoader($private, $manifest, $fetch, $public . '/loader.php');
        brProgress($private, 'complete', 'Public-site foundation verified. Private catalogue preserved.', 100, 'complete');
        return 'Installed ' . $release . '. Public-site foundation ready. Data preserved in ' . $private . '/data.';
    } catch (Throwable $error) {
        if ($activated) { brAtomic($private . '/state.json', json_encode($before, JSON_THROW_ON_ERROR)); }
        brProgress($private, 'failed', $error->getMessage(), 0, 'failed');
        throw $error;
    } finally {
        unset($GLOBALS['brProgressRoot']);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

// The test harness imports the same functions without running the web controller.
if (PHP_SAPI === 'cli' && defined('BIKERIDERS_LOADER_TEST')) { return; }

function brRequirements(): array {
    return [
        'PHP 8.1 or later' => PHP_VERSION_ID >= 80100,
        'cURL with HTTPS' => extension_loaded('curl') && in_array('https', curl_version()['protocols'], true),
        'PHP sessions' => extension_loaded('session'),
        'Writable installation folder' => is_writable(__DIR__),
    ];
}
$public = (string)realpath(__DIR__);
$authDirectory = $public . '/var';
$startupError = '';
try { $storage = is_file($authDirectory . '/storage.php') ? brReadGuarded($authDirectory . '/storage.php') : []; }
catch (Throwable $exception) { $storage = []; $startupError = 'Cannot read var/storage.php. Restore its guarded configuration through cPanel.'; }
$documentRoot = PHP_SAPI === 'cli'
    ? (getenv('BIKERIDERS_DOCUMENT_ROOT') ?: ($storage['document_root'] ?? $public))
    : (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) ?: $public);
$privatePath = getenv('BIKERIDERS_PRIVATE_DIR') ?: ($storage['private_dir'] ??
    dirname($documentRoot) . '/.bikeriders-' . substr(hash('sha256', $public), 0, 12));
if (PHP_SAPI === 'cli') {
    try {
        if ($startupError !== '') { throw new RuntimeException($startupError); }
        $command = $argv[1] ?? '--help';
        if ($command === '--help') {
            echo "Bike + Kit maintenance: --check --status --show-setup-token --install --update --rollback\n"; exit;
        }
        if ($command === '--check') { echo json_encode(brRequirements(), JSON_PRETTY_PRINT) . "\n"; exit(in_array(false, brRequirements(), true) ? 1 : 0); }
        if ($command === '--status') { echo json_encode(brStatus($privatePath), JSON_PRETTY_PRINT) . "\n"; exit; }
        brAuthDirectory($authDirectory);
        $authLock = brAuthLock($authDirectory);
        try {
            $source = brAuthSource($authDirectory, $setupKey);
            if ($command === '--show-setup-token') {
                if (is_file($authDirectory . '/maintenance.php') || !is_file($authDirectory . '/setup-token.php')) {
                    throw new RuntimeException('No initial setup token is available. Use your saved maintenance key.');
                }
                echo brReadGuarded($authDirectory . '/setup-token.php')['setup_token'] . "\n"; exit;
            }
            if (strlen($setupKey) >= 32 && !getenv('BIKERIDERS_SETUP_KEY') && !is_file($authDirectory . '/maintenance.php')) {
                brWriteGuarded($authDirectory . '/maintenance.php', ['password_hash' => password_hash($setupKey, PASSWORD_DEFAULT)]);
            }
        } finally { flock($authLock, LOCK_UN); fclose($authLock); }
        $action = ['--install' => 'install', '--update' => 'install', '--rollback' => 'rollback'][$command] ?? null;
        if ($action === null) { throw new RuntimeException('Unknown command; use --help.'); }
        if (!$storage && !getenv('BIKERIDERS_DOCUMENT_ROOT')) {
            throw new RuntimeException('Open loader.php in your browser once to record the hosting document root, or set BIKERIDERS_DOCUMENT_ROOT before CLI installation.');
        }
        if (in_array(false, brRequirements(), true)) { throw new RuntimeException('Hosting requirements are not met. Run --check.'); }
        $private = brPrivateDirectory($privatePath, $documentRoot);
        echo json_encode(['message' => brOperate($private, $public, $action), 'status' => brStatus($private)], JSON_PRETTY_PRINT) . "\n";
        exit;
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'");
$message = ''; $error = ''; $authenticated = false; $source = null; $maintenance = null; $installed = false;
$requirements = brRequirements();
$live = ($_POST['live'] ?? '') === '1';
try {
    if ($startupError !== '') { throw new RuntimeException($startupError); }
    brAuthDirectory($authDirectory);
    // Retain the same outside-web-root storage location for cPanel Terminal commands.
    $newStorage = ['document_root' => $documentRoot, 'private_dir' => $privatePath];
    if ($storage !== $newStorage) { brWriteGuarded($authDirectory . '/storage.php', $newStorage); }
    $authLock = brAuthLock($authDirectory);
    try { $source = brAuthSource($authDirectory, $setupKey); }
    finally { flock($authLock, LOCK_UN); fclose($authLock); }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('BRMAINT' . substr(hash('sha256', $public), 0, 12));
    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/loader.php')), '/') . '/';
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => $cookiePath]);
    if (!session_start()) { throw new RuntimeException('PHP sessions are unavailable. Check the hosting session save path.'); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $authenticated = ($_SESSION['maintenance_version'] ?? '') === $source['version'] && ($_SESSION['last_seen'] ?? 0) > time() - 3600;
    if ($authenticated) { $_SESSION['last_seen'] = time(); }
    $csrf = $_SESSION['csrf'];
    if (($_GET['action'] ?? '') === 'status') {
        if (!$authenticated) { http_response_code(403); throw new RuntimeException('Unlock maintenance to view progress.'); }
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(brStatus($privatePath), JSON_THROW_ON_ERROR); exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403); throw new RuntimeException('The form expired. Reload and try again.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $attempts = array_filter($_SESSION['attempts'] ?? [], static fn($time) => $time > time() - 60);
            if (count($attempts) >= 8) { throw new RuntimeException('Too many attempts. Wait one minute.'); }
            $attempts[] = time(); $_SESSION['attempts'] = $attempts;
            $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
            $authLock = brAuthLock($authDirectory);
            try {
                $source = brAuthSource($authDirectory, $setupKey);
                $valid = isset($source['hash']) ? password_verify($key, $source['hash']) : hash_equals($source['plain'], $key);
                if (!$valid) { http_response_code(403); throw new RuntimeException('The maintenance key is incorrect.'); }
                if (!is_file($authDirectory . '/maintenance.php')) { brWriteGuarded($authDirectory . '/maintenance.php', ['password_hash' => password_hash($key, PASSWORD_DEFAULT)]); }
                if (is_file($authDirectory . '/setup-token.php')) { unlink($authDirectory . '/setup-token.php'); }
                $source = brAuthSource($authDirectory, $setupKey);
            } finally { flock($authLock, LOCK_UN); fclose($authLock); }
            session_regenerate_id(true);
            $_SESSION['maintenance_version'] = $source['version']; $_SESSION['last_seen'] = time(); $_SESSION['attempts'] = [];
            $_SESSION['csrf'] = bin2hex(random_bytes(32)); $csrf = $_SESSION['csrf'];
            $authenticated = true;
            $message = 'Maintenance unlocked. Save your key; the initial setup-token file has been removed.';
        } else {
            if (!$authenticated) { http_response_code(403); throw new RuntimeException('Unlock maintenance before making changes.'); }
            if ($action === 'logout') {
                $_SESSION = ['csrf' => bin2hex(random_bytes(32))]; $csrf = $_SESSION['csrf'];
                session_regenerate_id(true); $authenticated = false; $message = 'Maintenance locked.';
            } elseif ($action === 'rotate') {
                if (!empty($source['environment'])) { throw new RuntimeException('Change BIKERIDERS_SETUP_KEY in your hosting settings.'); }
                $newKey = is_string($_POST['new_key'] ?? null) ? $_POST['new_key'] : '';
                if (strlen($newKey) < 32 || strlen($newKey) > 512) { throw new RuntimeException('Use a maintenance key between 32 and 512 characters.'); }
                $authLock = brAuthLock($authDirectory);
                try {
                    brWriteGuarded($authDirectory . '/maintenance.php', ['password_hash' => password_hash($newKey, PASSWORD_DEFAULT)]);
                    $source = brAuthSource($authDirectory);
                } finally { flock($authLock, LOCK_UN); fclose($authLock); }
                session_regenerate_id(true); $_SESSION['maintenance_version'] = $source['version'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32)); $csrf = $_SESSION['csrf'];
                $message = 'Maintenance key changed. Other sessions are now locked.';
            } elseif (in_array($action, ['install', 'check', 'rollback'], true)) {
                if ($action !== 'check' && ($_POST['confirm'] ?? '') !== 'yes') { throw new RuntimeException('Confirm installation or rollback before changing files.'); }
                if (in_array(false, $requirements, true)) { throw new RuntimeException('Resolve the failed hosting requirements.'); }
                $private = brPrivateDirectory($privatePath, $documentRoot);
                session_write_close(); // Allow this authenticated browser to poll progress concurrently.
                ignore_user_abort(true);
                if (function_exists('set_time_limit')) { @set_time_limit(180); }
                $message = brOperate($private, $public, $action);
            } else { throw new RuntimeException('Unknown maintenance action.'); }
        }
    }
    if ($authenticated) {
        $maintenance = brStatus($privatePath);
        $state = brState($privatePath, $public);
        $installed = !empty($state['current']) && brReleaseValid($privatePath, $state['current']) && is_file($public . '/index.php');
    }
} catch (Throwable $exception) { $error = $exception->getMessage(); }
if ($live || ($_GET['action'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$authenticated) { http_response_code(403); } elseif ($error !== '') { http_response_code(409); }
    echo json_encode(['message' => $message, 'error' => $error ?: null, 'status' => $authenticated ? $maintenance : null], JSON_THROW_ON_ERROR); exit;
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bike + Kit · Maintenance</title><style>
body{font:16px/1.6 system-ui;background:#f3f5ed;color:#203c30;max-width:800px;margin:40px auto;padding:24px}section{background:white;border:1px solid #cedac8;border-radius:16px;padding:28px;margin:24px 0}h1{font-size:38px;letter-spacing:-1px}li{margin:8px 0}label{display:block;font-weight:700}input[type=password]{width:95%;padding:12px;margin:12px 0;border:1px solid #9aaf97;border-radius:6px}button,.button{display:inline-block;background:#27533b;color:white;border:0;border-radius:6px;padding:12px 18px;margin:6px 6px 6px 0;font:inherit;cursor:pointer;text-decoration:none}button:disabled{opacity:.5}.message{white-space:pre-wrap;font-weight:650}.error{border-color:#c78764}small,.note{color:#526858;font-size:13px}code{overflow-wrap:anywhere}progress{width:100%;height:20px;accent-color:#69964b}.row{display:flex;gap:12px;flex-wrap:wrap}[hidden]{display:none!important}
</style><h1>bike + kit ↗</h1><p>One file to install, maintain and update your public site.</p>
<?php if ($message !== ''): ?><section class="message" role="status"><?= brEscape($message) ?></section><?php endif; ?>
<?php if ($error !== ''): ?><section class="message error" role="alert"><?= brEscape($error) ?></section><?php endif; ?>
<?php if ($source && !$authenticated): ?>
<section><h2>Unlock maintenance</h2>
<?php if (!empty($source['environment'])): ?><p>Use your hosting environment's <code>BIKERIDERS_SETUP_KEY</code>.</p>
<?php elseif (is_file($authDirectory . '/setup-token.php')): ?><p>In <strong>cPanel → File Manager</strong>, open <code>var/setup-token.php</code> beside this loader. Copy its <code>setup_token</code> value into the login below and save it in your password manager.</p><p class="note">The token is never shown on this page. Its guarded file returns 404 over HTTP and is removed after your first successful login. No editing of loader.php is required.</p>
<?php else: ?><p>Use your saved maintenance key. If lost, remove <code>var/maintenance.php</code> through cPanel File Manager and reload to generate a new setup token. Existing catalogue data is preserved. Environment-configured keys must be reset in hosting settings.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= brEscape($csrf ?? '') ?>"><input type="hidden" name="action" value="login"><label for="key">Maintenance key</label><input id="key" type="password" name="key" required maxlength="512" autocomplete="current-password"><button>Unlock setup ↗</button></form></section>
<?php elseif ($authenticated): ?>
<section><h2><?= $installed ? 'Maintain your public site' : 'Install & set up' ?></h2><p>Download the published app and loader from GitHub. Verified code releases, protected catalogue data, and a backup before replacing the loader.</p><p class="note">Unchanged app files are reused after checksum verification. Source videos and AI processing stay on your laptop. The current public-site foundation starts with an empty catalogue; automated sync and matching are still in development.</p>
<form method="post" class="maintenance"><input type="hidden" name="csrf" value="<?= brEscape($csrf) ?>"><label><input type="checkbox" name="confirm" value="yes" required> Install/update or roll back code while preserving catalogue data.</label><div class="row"><button name="action" value="install"><?= $installed ? 'Back up & update' : 'Install & set up' ?> ↗</button><button name="action" value="rollback">Roll back app</button></div></form>
<form method="post" class="maintenance"><input type="hidden" name="csrf" value="<?= brEscape($csrf) ?>"><button name="action" value="check">Check updates</button></form>
<?php if ($installed): ?><a class="button" href="index.php">Open site ↗</a><?php endif; ?></section>
<section id="progress" hidden><h2>Maintenance progress</h2><progress id="bar" max="100" value="0"></progress><p id="task" role="status"></p><p id="timing" class="note"></p><p id="outcome"></p><a class="button" href="loader.php">Reload verified status</a></section>
<section><details><summary>Maintenance key</summary><form method="post"><input type="hidden" name="csrf" value="<?= brEscape($csrf) ?>"><input type="hidden" name="action" value="rotate"><label for="new-key">New key (32+ characters)</label><input id="new-key" type="password" name="new_key" minlength="32" maxlength="512" required autocomplete="new-password"><button>Change key</button></form></details><form method="post"><input type="hidden" name="csrf" value="<?= brEscape($csrf) ?>"><input type="hidden" name="action" value="logout"><button>Lock maintenance</button></form></section>
<?php endif; ?>
<section><h2>Hosting checks</h2><ul><?php foreach ($requirements as $label => $pass): ?><li><?= $pass ? '✓' : '✗' ?> <?= brEscape($label) ?></li><?php endforeach; ?></ul><p class="note">PHP hosting only: no Python, AI models, database account, or paid server needed here. Enable HTTPS. Catalogue storage must be outside all hosted web roots.</p></section>
<?php if ($authenticated): ?><script>
const box=document.querySelector('#progress'),task=document.querySelector('#task'),bar=document.querySelector('#bar'),timing=document.querySelector('#timing'),outcome=document.querySelector('#outcome');
function render(state){const p=state?.progress;if(!p)return;box.hidden=false;bar.value=p.percent||0;task.textContent=p.message;timing.textContent='Status: '+p.status+' · elapsed '+Math.max(0,Math.floor(Date.now()/1000)-p.started_at)+'s · last report '+Math.max(0,Math.floor(Date.now()/1000)-p.updated_at)+'s ago';}
async function poll(){try{const r=await fetch('loader.php?action=status',{cache:'no-store'});if(r.ok)render(await r.json());}catch{}}
document.querySelectorAll('form.maintenance').forEach(form=>form.addEventListener('submit',async event=>{event.preventDefault();if(!form.reportValidity())return;const data=new FormData(form);data.set('action',event.submitter.value);data.set('live','1');document.querySelectorAll('button').forEach(b=>b.disabled=true);box.hidden=false;task.textContent='Starting maintenance…';outcome.textContent='';const timer=setInterval(poll,1000);try{const r=await fetch('loader.php',{method:'POST',body:data});const result=await r.json();render(result.status);outcome.textContent=result.error||result.message;}catch{outcome.textContent='Connection ended. Reload verified status before retrying; the server may still be working.';}finally{clearInterval(timer);await poll();document.querySelectorAll('button').forEach(b=>b.disabled=false);}}));
poll();
</script><?php endif; ?></html>
