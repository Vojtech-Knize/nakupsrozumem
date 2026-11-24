<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
/*
 *  GET  ?a=get                     -> {exists, favs:[{term,cat}], digest}
 *  POST ?a=save {favs, digest}     -> {ok}     (uloží hlídané věci a zapnutí týdenního e-mailu)
 *  GET  ?a=unsub&u=ID&t=PODPIS     -> stránka „odhlášeno“ (odkaz z e-mailu, bez přihlášení)
 */
$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($a === 'unsub' && $method === 'GET') {
    $uid = (int)($_GET['u'] ?? 0);
    $ok = $uid > 0 && hash_equals(unsub_token($uid), (string)($_GET['t'] ?? ''));
    if ($ok) db()->prepare('UPDATE watch SET digest=0 WHERE user_id=?')->execute([$uid]);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $msg = $ok ? 'Týdenní e-mail s hlídanými věcmi jsme vám vypnuli. Znovu ho můžete zapnout v aplikaci.' : 'Odkaz už neplatí. E-mail můžete vypnout přímo v aplikaci u hlídaných věcí.';
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nákup s rozumem</title>'
       . '<body style="font:19px/1.5 system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem"><h1 style="font-size:1.4rem">Nákup s rozumem</h1><p>' . $msg . '</p><p><a href="../">Otevřít aplikaci</a></p></body></html>';
    exit;
}

$u = require_user();
if ($a === 'get' && $method === 'GET') {
    $s = db()->prepare('SELECT terms, digest FROM watch WHERE user_id=?'); $s->execute([$u['id']]);
    $r = $s->fetch();
    out(['exists' => (bool)$r, 'favs' => $r ? (json_decode((string)$r['terms'], true) ?: []) : [], 'digest' => $r ? (bool)$r['digest'] : false]);
}
if ($a === 'save' && $method === 'POST') {
    require_xhr();
    $in = body_json(20000);
    $favs = []; $seen = [];
    foreach ((array)($in['favs'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $t = trim(mb_substr((string)($f['term'] ?? ''), 0, 60));
        $c = in_array(($f['cat'] ?? ''), ['drug', 'elec'], true) ? $f['cat'] : 'food';
        $k = $c . '|' . mb_strtolower($t);
        if ($t === '' || isset($seen[$k])) continue;
        $seen[$k] = 1; $favs[] = ['term' => $t, 'cat' => $c];
        if (count($favs) >= 30) break;
    }
    $dg = !empty($in['digest']) ? 1 : 0;
    db()->prepare('INSERT INTO watch(user_id, terms, digest) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET terms=excluded.terms, digest=excluded.digest')
        ->execute([$u['id'], json_encode($favs, JSON_UNESCAPED_UNICODE), $dg]);
    out(['ok' => true]);
}
out(['error' => 'bad_request'], 400);
