<?php
declare(strict_types=1);
if (!isset($brPrivate)) { http_response_code(503); exit('Install with loader.php first.'); }
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'");
if (isset($_GET['asset'])) {
    $assets = ['style.css' => 'text/css; charset=utf-8', 'app.js' => 'application/javascript; charset=utf-8'];
    $asset = $_GET['asset'];
    if (!is_string($asset) || !isset($assets[$asset])) { http_response_code(404); exit; }
    header('Content-Type: ' . $assets[$asset]);
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/' . $asset); exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$catalog = json_decode((string)@file_get_contents($brPrivate . '/data/catalog.json'), true);
if (!is_array($catalog) || ($catalog['schema'] ?? null) !== 1 || !is_array($catalog['sightings'] ?? null)) {
    http_response_code(503); exit('Catalog unavailable. Your administrator can restore the catalog backup.');
}
// Only records explicitly approved for publication are visible.
$sightings = array_values(array_filter($catalog['sightings'], static fn($row) => is_array($row) && ($row['approved'] ?? false) === true));
usort($sightings, static fn($a, $b) => strcmp((string)($b['seen_at'] ?? ''), (string)($a['seen_at'] ?? '')));
$setups = [];
foreach ($sightings as $sighting) {
    if (is_string($sighting['setup_id'] ?? null) && $sighting['setup_id'] !== '') { $setups[$sighting['setup_id']] = true; }
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bike + Kit Spotter</title><link rel="stylesheet" href="?asset=style.css"><script src="?asset=app.js" defer></script>
<main><header><a class="brand" href="index.php">bike + kit <span>↗</span></a><span class="badge">The cyclist's field guide</span></header>
<div class="eyebrow">THE WHEELERS’ APPRECIATION TEAM SPOTTERS</div>
<h1>Spot the machine.<br>Appreciate the kit.</h1><p class="intro">A growing collection of bikes and cycling kit. Just the setups, the colours, and the stories in the details.</p>
<section class="numbers" aria-label="Collection statistics"><div><strong><?= count($sightings) ?></strong>approved sightings</div><div><strong><?= count($setups) ?></strong>bike + kit setups</div><div><strong>Local first</strong>no cloud video processing</div></section>
<section class="collection"><div class="heading"><h2>The spotting log</h2><label>Search bike or kit<input id="search" type="search" placeholder="Try red, gravel, or team kit"></label></div>
<?php if (!$sightings): ?><div class="empty"><span class="wheel">◎</span><h3>The collection starts with the first spot.</h3><p>This site is installed and ready for approved records.<br>Private videos and raw frames stay off the public website.</p></div><?php else: ?>
<div class="grid" id="sightings"><?php foreach ($sightings as $row):
$bike = is_string($row['bike'] ?? null) ? $row['bike'] : 'Unclassified bike';
$kit = is_string($row['kit'] ?? null) ? $row['kit'] : 'Unclassified kit';
$seen = is_string($row['seen_at'] ?? null) ? $row['seen_at'] : '';
$tags = array_filter(is_array($row['tags'] ?? null) ? $row['tags'] : [], 'is_string');
?><article class="card" data-search="<?= escape(strtolower($bike . ' ' . $kit . ' ' . implode(' ', $tags))) ?>"><div class="bike-mark" aria-hidden="true">◎ ─ ◎</div><p class="eyebrow">APPROVED SIGHTING</p><h3><?= escape($bike) ?></h3><p><?= escape($kit) ?></p><div class="tags"><?php foreach ($tags as $tag): ?><span><?= escape($tag) ?></span><?php endforeach; ?></div><p class="date"><?= escape($seen) ?></p></article><?php endforeach; ?></div><p id="no-results" hidden>No matching sightings. Try another bike, colour, or kit.</p>
<?php endif; ?></section>
<footer>Bike + Kit Spotter · No names, face recognition, or body-shape matching.<br>Early public-site foundation · Image publication, automated sync, setup histories and rarity/value estimates are still in development.</footer></main></html>
