<?php
/** Samoobslužné obnovení dat bez cronu: aplikace tento skript zavolá při načtení; sám hlídá stáří dat a zámek.
 *  Akce se stahují po jednom řetězci, každý max. 1× za ~20 h, prodejny po jednom řetězci max. 1× týdně. Návštěvníkovi odpoví hned, práce běží na pozadí. */
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
ignore_user_abort(true);
@set_time_limit(600);
$root = dirname(__DIR__);
$dataFile = $root . '/data.json';
$state = $root . '/auto_state.json';
$st = is_file($state) ? (json_decode((string) file_get_contents($state), true) ?: []) : [];
$now = time();

$chainsAkce = ['kaufland', 'penny', 'lidl', 'billa', 'globus', 'albert', 'norma', 'coop', 'terno'];
$akceDue = null;   // po jednom řetězci na volání, aby běh nezatěžoval web
foreach ($chainsAkce as $c) if ($now - ($st['akce'][$c] ?? 0) > 20 * 3600) { $akceDue = $c; break; }
$drugDue = null;   // akce drogerií (Rossmann, Teta) do data_drug.json
foreach (['rossmann', 'teta'] as $c) if ($now - ($st['drug'][$c] ?? 0) > 20 * 3600) { $drugDue = $c; break; }
$chainsStores = ['kaufland', 'penny', 'lidl', 'billa', 'globus', 'albert', 'norma', 'coop', 'terno', 'dm', 'rossmann', 'teta'];
$storeDue = null;
foreach ($chainsStores as $c) if ($now - ($st['stores'][$c] ?? 0) > 7 * 86400) { $storeDue = $c; break; }
if ((!$akceDue && !$storeDue && !$drugDue) || $now - ($st['attempt'] ?? 0) < 1800) { echo "ok\n"; exit; }

$lock = fopen($root . '/auto.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "bezi\n"; exit; }

// odpovědět hned, zbytek na pozadí
$body = "spusteno\n";
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { @ob_end_flush(); flush(); }

$st['attempt'] = $now;
file_put_contents($state, json_encode($st));
ob_start();
try {
    if ($akceDue) {
        require_once __DIR__ . '/fetch_akce.php';
        if (main($akceDue) === 0) $st['akce'][$akceDue] = time();
    }
    if ($drugDue) {
        require_once __DIR__ . '/fetch_drug.php';
        if (main_drug($drugDue) === 0) $st['drug'][$drugDue] = time();
    }
    if ($storeDue) {
        require_once __DIR__ . '/fetch_stores.php';
        if (main_stores($storeDue) === 0) $st['stores'][$storeDue] = time();
    }
} catch (Throwable $e) {
    $st['error'] = substr($e->getMessage(), 0, 200);
}
ob_end_clean();
file_put_contents($state, json_encode($st));
