<?php
declare(strict_types=1);
define('BIKERIDERS_LOADER_TEST', true);
require dirname(__DIR__, 2) . '/loader.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$private = $argv[1];
mkdir($private, 0700, true);
mkdir($private . '/releases', 0700);
mkdir($private . '/data', 0700);
file_put_contents($private . '/data/catalog.json', 'user-data');
$source = dirname(__DIR__, 2) . '/';
$manifest = brJson($source . 'deploy/cpanel/manifest.json');
$fetch = static fn(string $path): string => (string)file_get_contents($source . $path);
$state = brState($private, '/example/public');
$first = brInstall($private, $manifest, $fetch);
$state = brActivate($private, $state, $first);
check(brReleaseValid($private, $first), 'First release valid');
check(brState($private, '/example/public')['current'] === $first, 'State persisted');
check(brInstall($private, $manifest, $fetch) === $first, 'Reinstall idempotent');
check(brActivate($private, $state, $first)['previous'] === null, 'Reinstall preserves rollback');
$reuseManifest = $manifest;
$reuseManifest['version'] = '0.1.99';
brInstall($private, $reuseManifest, static function ($path) { throw new RuntimeException('Unchanged files must not download'); }, $first);
$manifest['version'] = '999.0.1';
$second = brInstall($private, $manifest, $fetch);
$state = brActivate($private, $state, $second);
check($state['previous'] === $first, 'Previous release retained');
$state = brActivate($private, $state, $state['previous']);
check($state['current'] === $first, 'Rollback activated');
check(file_get_contents($private . '/data/catalog.json') === 'user-data', 'Data survived update and rollback');
$manifest['version'] = '999.0.2';
try {
    brInstall($private, $manifest, static fn(string $path): string => 'tampered');
    throw new RuntimeException('Checksum mismatch should fail');
} catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'checksum mismatch'), 'Rejected corruption'); }
check(brState($private, '/example/public')['current'] === $first, 'Failed update preserves active version');
check(count(glob($private . '/releases/.staging-*')) === 0, 'Failed staging cleaned');
$manifest['files']['../escape.php'] = str_repeat('0', 64);
try { brManifest($manifest); throw new RuntimeException('Traversal should fail'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'file list'), 'Rejected path traversal'); }
file_put_contents($private . '/releases/' . $second . '/index.php', 'modified');
check(!brReleaseValid($private, $second), 'Detect modified rollback target');
try { brActivate($private, $state, $second); throw new RuntimeException('Corrupt activation should fail'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'verification'), 'Corrupt activation rejected'); }
echo "Install, idempotence, update, rollback, preservation, checksum failure, traversal, corruption: passed\n";

$localLoader = $private . '/test-loader.php';
file_put_contents($localLoader, '<?php // previous loader');
$originalHash = hash_file('sha256', $localLoader);
$loaderManifest = brJson($source . 'deploy/cpanel/manifest.json');
check(brUpdateLoader($private, $loaderManifest, $fetch, $localLoader), 'Loader updated');
check(hash_file('sha256', $localLoader) === $loaderManifest['loader_sha256'], 'Loader verified');
check(is_file($private . '/backups/loader-' . $originalHash . '.php'), 'Previous loader backed up');
check(!brUpdateLoader($private, $loaderManifest, $fetch, $localLoader), 'Unchanged loader retained');
$loaderManifest['loader_sha256'] = str_repeat('0', 64);
try { brUpdateLoader($private, $loaderManifest, static fn($path) => 'bad', $localLoader); throw new RuntimeException('Corrupt loader should fail'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'checksum mismatch'), 'Corrupt loader rejected'); }
check(hash_file('sha256', $localLoader) === hash_file('sha256', $source . 'loader.php'), 'Existing loader preserved after failed update');
echo "Loader self-update, backup, reuse and corruption rejection: passed\n";

$operationPrivate = $private . '/operations';
$public = $private . '/public';
mkdir($operationPrivate, 0700); mkdir($public, 0700);
file_put_contents($public . '/loader.php', '<?php // old installer');
$releaseManifest = brJson($source . 'deploy/cpanel/manifest.json');
$operationFetch = static function ($path) use (&$releaseManifest, $source) {
    return $path === 'deploy/cpanel/manifest.json' ? json_encode($releaseManifest) : (string)file_get_contents($source . $path);
};
brOperate($operationPrivate, $public, 'install', $operationFetch);
$firstState = brState($operationPrivate, $public);
check(brStatus($operationPrivate)['progress']['status'] === 'complete', 'Completed progress recorded');
brAtomic($operationPrivate . '/data/catalog.json', '{"schema":1,"sightings":[],"keep":"existing data"}');
$releaseManifest['version'] = '999.1.1';
$validLoaderHash = $releaseManifest['loader_sha256'];
$releaseManifest['loader_sha256'] = str_repeat('0', 64);
try { brOperate($operationPrivate, $public, 'install', $operationFetch); throw new RuntimeException('Self-update corruption should fail operation'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'Loader checksum'), 'Self-update failure reported'); }
check(brState($operationPrivate, $public) === $firstState, 'App activation restored on self-update failure');
check(brStatus($operationPrivate)['progress']['status'] === 'failed', 'Failure progress persisted');
$releaseManifest['loader_sha256'] = $validLoaderHash;
brOperate($operationPrivate, $public, 'install', $operationFetch);
check(brState($operationPrivate, $public)['previous'] === $firstState['current'], 'Prior code release retained');
brOperate($operationPrivate, $public, 'rollback', $operationFetch);
check(brState($operationPrivate, $public)['current'] === $firstState['current'], 'Operation rollback succeeded');
check(brJson($operationPrivate . '/data/catalog.json')['keep'] === 'existing data', 'Operation data preserved');
brAtomic($operationPrivate . '/progress.json', '{"status":"running"}');
check(brStatus($operationPrivate)['progress']['status'] === 'interrupted', 'Interrupted process detected');
echo "Full install/update, progress, self-update failure recovery, rollback and preserved data: passed\n";
