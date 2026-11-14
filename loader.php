<?php
declare(strict_types=1);
/* Bike + Kit Spotter standalone cPanel installer, PHP 8.1+.
 * Upload ONLY this file. Set a random 32+ character key below, or in the
 * BIKERIDERS_SETUP_KEY environment variable. Never commit your actual key.
 * Video processing stays on your laptop. This installs the public PHP site.
 */
$setupKey = ''; // EDIT YOUR UPLOADED COPY ONLY. Generate: openssl rand -hex 32

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
    $url = $token
        ? 'https://api.github.com/repos/efrgdgr0024345/bikeriders/contents/' . $path . '?ref=main'
        : 'https://raw.githubusercontent.com/efrgdgr0024345/bikeriders/main/' . $path;
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
function brInstall(string $private, array $manifest, callable $fetch): string {
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
            $bytes = $fetch('deploy/cpanel/app/' . $name);
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

// The test harness imports the same functions without running the web controller.
if (PHP_SAPI === 'cli' && defined('BIKERIDERS_LOADER_TEST')) { return; }

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
$key = getenv('BIKERIDERS_SETUP_KEY') ?: $setupKey;
$message = '';
$requirements = [
    'PHP 8.1 or later' => PHP_VERSION_ID >= 80100,
    'cURL extension' => extension_loaded('curl'),
    'JSON / SHA-256 support' => function_exists('json_decode') && in_array('sha256', hash_algos(), true),
    'Writable installation folder' => is_writable(__DIR__),
    '32+ character installation key configured' => strlen($key) >= 32,
];
$public = (string)realpath(__DIR__);
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$defaultPrivate = dirname((string)$documentRoot) . '/.bikeriders-' . substr(hash('sha256', $public), 0, 12);
$private = getenv('BIKERIDERS_PRIVATE_DIR') ?: $defaultPrivate;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (in_array(false, $requirements, true)) { throw new RuntimeException('Resolve the failed requirements before continuing.'); }
        if (!hash_equals($key, (string)($_POST['key'] ?? ''))) {
            http_response_code(403);
            throw new RuntimeException('Incorrect installation key.');
        }
        if (!is_dir($private) && !mkdir($private, 0700, true)) { throw new RuntimeException('Cannot create private storage. Set BIKERIDERS_PRIVATE_DIR to a writable path outside your web roots.'); }
        $private = (string)realpath($private);
        $root = rtrim((string)$documentRoot, DIRECTORY_SEPARATOR);
        if ($private === $root || str_starts_with($private, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private storage must be outside the document root.');
        }
        if (!is_writable($private)) { throw new RuntimeException('Private storage is not writable.'); }
        $lock = fopen($private . '/install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another installation is running.'); }
        $state = brState($private, $public);
        $action = (string)($_POST['action'] ?? 'check');
        if ($action === 'rollback') {
            if (empty($state['previous'])) { throw new RuntimeException('No previous release is available.'); }
            $state = brActivate($private, $state, $state['previous']);
            $message = 'Rolled back to ' . $state['current'] . '. Data was preserved.';
        } elseif ($action === 'check' || $action === 'install') {
            $manifest = brManifest(json_decode(brFetch('deploy/cpanel/manifest.json', 65536), true, 32, JSON_THROW_ON_ERROR));
            $message = 'Available version: ' . $manifest['version'] . '. Current release: ' . ($state['current'] ?? 'not installed') . '.';
            if ($action === 'install') {
                $bootstrap = "<?php\n// BikeRiders managed entry point v1\n\$brPrivate = " . var_export($private, true) . ";\n";
                $bootstrap .= <<<'BOOTSTRAP'
$brState = json_decode((string)file_get_contents($brPrivate . '/state.json'), true);
$brRelease = $brState['current'] ?? '';
if (!is_string($brRelease) || !preg_match('/^\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $brRelease)) {
    http_response_code(503); exit('No active release.');
}
require $brPrivate . '/releases/' . $brRelease . '/index.php';
BOOTSTRAP;
                $index = $public . '/index.php';
                if (file_exists($index) && file_get_contents($index) !== $bootstrap) {
                    throw new RuntimeException('An existing index.php is present. Use an empty subfolder; existing websites will not be overwritten.');
                }
                if (file_exists($public . '/index.html')) { throw new RuntimeException('An index.html already exists. Use an empty subfolder.'); }
                if (!is_dir($private . '/releases')) { mkdir($private . '/releases', 0700); }
                if (!is_dir($private . '/data')) { mkdir($private . '/data', 0700); }
                if (!file_exists($private . '/data/catalog.json')) {
                    brAtomic($private . '/data/catalog.json', '{"schema":1,"sightings":[]}');
                }
                $release = brInstall($private, $manifest, 'brFetch');
                $state = brActivate($private, $state, $release);
                if (!file_exists($index)) {
                    // Exclusive creation avoids replacing an existing website in a race.
                    $entry = fopen($index, 'x');
                    if ($entry === false) { throw new RuntimeException('Could not create index.php.'); }
                    $written = fwrite($entry, $bootstrap); fclose($entry);
                    if ($written !== strlen($bootstrap)) { unlink($index); throw new RuntimeException('Could not write entry point.'); }
                    chmod($index, 0644);
                }
                $message = 'Installed ' . $release . '. Open index.php. Data preserved in ' . $private . '/data. Keep loader.php and the key for future updates.';
            }
        } else { throw new RuntimeException('Unknown action.'); }
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bike + Kit · Installer</title><style>
body{font:16px/1.6 system-ui;background:#f3f5ed;color:#203c30;max-width:740px;margin:60px auto;padding:24px}section{background:white;border:1px solid #cedac8;border-radius:16px;padding:28px;margin:24px 0}h1{font-size:38px;letter-spacing:-1px}li{margin:8px 0}label{display:block;font-weight:700}input{width:95%;padding:12px;margin:12px 0;border:1px solid #9aaf97;border-radius:6px}button{background:#27533b;color:white;border:0;border-radius:6px;padding:12px 18px;margin:6px 6px 6px 0;font:inherit;cursor:pointer}.message{white-space:pre-wrap;font-weight:650}small{color:#526858}
</style><h1>bike + kit ↗</h1><p>Install once. Update here. Keep processing on your laptop.</p>
<section><h2>Hosting checks</h2><ul><?php foreach ($requirements as $label => $pass): ?><li><?= $pass ? '✓' : '✗' ?> <?= brEscape($label) ?></li><?php endforeach; ?></ul>
<small>Installs the PHP public-site foundation. No Python, AI models, paid server, database account, or shell access is needed here. The public site starts empty.</small></section>
<?php if ($message !== ''): ?><section class="message" role="status"><?= brEscape($message) ?></section><?php endif; ?>
<section><form method="post" autocomplete="off"><label for="key">Installation key</label><input id="key" type="password" name="key" required minlength="32" autocomplete="off">
<button name="action" value="check">Check updates</button><button name="action" value="install">Install / update</button><button name="action" value="rollback">Roll back</button></form>
<small>Set the key in your uploaded loader.php or BIKERIDERS_SETUP_KEY first. Use HTTPS. Updates verify SHA-256 checksums, activate a complete release atomically, and retain the previous release. Downloaded PHP code is trusted from the configured GitHub repository; checksums detect incomplete or corrupted releases, not a compromised repository.</small></section>
</html>
