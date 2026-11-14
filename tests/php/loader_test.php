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
$manifest['version'] = '0.2.0';
$second = brInstall($private, $manifest, $fetch);
$state = brActivate($private, $state, $second);
check($state['previous'] === $first, 'Previous release retained');
$state = brActivate($private, $state, $state['previous']);
check($state['current'] === $first, 'Rollback activated');
check(file_get_contents($private . '/data/catalog.json') === 'user-data', 'Data survived update and rollback');
$manifest['version'] = '0.3.0';
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
