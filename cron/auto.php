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

// retezec, ktery se nedavno zkousel a nedobehl (napr. ho server ukoncil), se na 6 h preskoci, aby neblokoval ostatni
$can = fn(string $k) => $now - ($st['try'][$k] ?? 0) > 6 * 3600;
$chainsAkce = ['kaufland', 'penny', 'lidl', 'billa', 'globus', 'albert', 'norma', 'coop', 'terno'];
$akceDue = null;   // po jednom řetězci na volání, aby běh nezatěžoval web
// retezec, jehoz posledni stazeni skoncilo chybou, se zkousi znovu (po 6 h), i kdyz neni starsi nez 20 h
$srcOk = function (string $c) use ($dataFile): bool { $d = is_file($dataFile) ? json_decode((string) file_get_contents($dataFile), true) : null; return ($d['sources'][$c]['ok'] ?? true) !== false; };
foreach ($chainsAkce as $c) if (($now - ($st['akce'][$c] ?? 0) > 20 * 3600 || !$srcOk($c)) && $can("akce:$c")) { $akceDue = $c; break; }
$drugDue = null;   // akce drogerií (Rossmann, Teta) do data_drug.json
foreach (['rossmann', 'teta'] as $c) if ($now - ($st['drug'][$c] ?? 0) > 20 * 3600 && $can("drug:$c")) { $drugDue = $c; break; }
$elecDue = null;   // akce elektra (Datart, Planeo) do data_elektro.json
foreach (['datart', 'planeo'] as $c) if ($now - ($st['elek'][$c] ?? 0) > 20 * 3600 && $can("elek:$c")) { $elecDue = $c; break; }
$chainsStores = ['kaufland', 'penny', 'lidl', 'billa', 'globus', 'albert', 'norma', 'coop', 'terno', 'dm', 'rossmann', 'teta', 'datart', 'planeo'];
$storeDue = null;
foreach ($chainsStores as $c) if ($now - ($st['stores'][$c] ?? 0) > 7 * 86400 && $can("stores:$c")) { $storeDue = $c; break; }
if ((!$akceDue && !$storeDue && !$drugDue && !$elecDue) || $now - ($st['attempt'] ?? 0) < 1500) { echo "ok\n"; exit; }   // 25 min, aby pulhodinovy cron nepreskakoval behy kvuli vterinam navic

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
// pokus se zapise pred spustenim ulohy a po uspechu smaze; stav se uklada po kazde uloze
$begin = function (string $k) use (&$st, $state) { $st['try'][$k] = time(); file_put_contents($state, json_encode($st)); };
$done = function (string $k) use (&$st, $state) { unset($st['try'][$k]); file_put_contents($state, json_encode($st)); };
ob_start();
try {
    if ($akceDue) {
        require_once __DIR__ . '/fetch_akce.php';
        $begin("akce:$akceDue");
        if (main($akceDue) === 0 && $srcOk($akceDue)) { $st['akce'][$akceDue] = time(); $done("akce:$akceDue"); }
    }
    if ($drugDue) {
        require_once __DIR__ . '/fetch_drug.php';
        $begin("drug:$drugDue");
        if (main_drug($drugDue) === 0) { $st['drug'][$drugDue] = time(); $done("drug:$drugDue"); }
    }
    if ($elecDue) {
        // Datart se stahuje po davkach 6 kategorii (jeden pozadavek na WEDOSu je casove omezeny); cyklus 1,7,13,19,25
        $win = 6; $c1 = 1;
        if ($elecDue === 'datart') { $c1 = (int) ($st['elek_c1'] ?? 1); if ($c1 < 1 || $c1 > 60) $c1 = 1; $_GET['c1'] = $c1; $_GET['c2'] = $c1 + $win - 1; }
        require_once __DIR__ . '/fetch_elektro.php';
        $begin("elek:$elecDue");
        if (main_elektro($elecDue) === 0) {
            $done("elek:$elecDue");
            if ($elecDue !== 'datart') $st['elek'][$elecDue] = time();
            elseif ($c1 + $win > 30) { $st['elek'][$elecDue] = time(); $st['elek_c1'] = 1; }   // cyklus dokoncen
            else $st['elek_c1'] = $c1 + $win;
        }
    }
    if ($storeDue) {
        require_once __DIR__ . '/fetch_stores.php';
        $begin("stores:$storeDue");
        if (main_stores($storeDue) === 0) { $st['stores'][$storeDue] = time(); $done("stores:$storeDue"); }
    }
} catch (Throwable $e) {
    $st['error'] = substr($e->getMessage(), 0, 200);
}
ob_end_clean();
file_put_contents($state, json_encode($st));
