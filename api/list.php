<?php
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';
/*
 * Společný nákupní seznam (Nákup s rozumem).
 * Ukládá jen položky seznamu, odškrtnutí a nastavení (včetně domovské polohy).
 * Přístup má každý, kdo zná tajný kód seznamu (8 znaků). Žádné účty, žádná e-mailová adresa.
 * Sloučení změn probíhá po položkách: u každé vyhrává novější změna (časové razítko t v ms),
 * takže se nic neztratí, ani když oba upraví seznam současně.
 *
 *   POST ?a=create             tělo {doc}            -> {code, rev, doc}
 *   POST ?a=sync&code=XXXXXXXX tělo {doc}            -> {rev, doc}   (sloučí a vrátí výsledek)
 *   GET  ?a=get&code=..&rev=N                         -> {same:true} nebo {rev, doc}
 */
const BASE = __DIR__ . '/lists';
const MAX_BODY = 60000;
const MAX_ITEMS = 100;
const TTL = 120 * 86400;      // seznam, který nikdo 120 dní neotevřel, se smaže
const TOMB_TTL = 30 * 86400;  // záznamy o smazání / odškrtnutí starší 30 dní se zahodí
const MAX_LISTS = 20000;
const CREATE_PER_HOUR = 20;
const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';


function clean_doc($d, int $nowMs): array {
    $maxT = $nowMs + 86400000;
    $d = is_array($d) ? $d : [];
    $t = fn($v) => max(0, min($maxT, (int)$v));
    $items = [];
    foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $k => $v) {
        $k = mb_substr((string)$k, 0, 80);
        if ($k === '' || !is_array($v)) continue;
        $grp = $v['grp'] ?? null;
        $it = [
            'term' => mb_substr(trim((string)($v['term'] ?? $k)), 0, 60),
            'qty'  => max(1, min(99, (int)($v['qty'] ?? 1))),
            'grp'  => in_array($grp, ['potraviny', 'drogerie', 'ostatni'], true) ? $grp : null,
            't'    => $t($v['t'] ?? 0),
            'o'    => $t($v['o'] ?? 0),
        ];
        if (!empty($v['del'])) $it['del'] = true;
        $items[$k] = $it;
    }
    $done = [];
    foreach (is_array($d['done'] ?? null) ? $d['done'] : [] as $k => $v) {
        $k = mb_substr((string)$k, 0, 80);
        if ($k === '' || !is_array($v)) continue;
        $done[$k] = ['v' => !empty($v['v']), 't' => $t($v['t'] ?? 0)];
    }
    $cfg = ['t' => 0, 'v' => null];
    if (is_array($d['cfg'] ?? null) && is_array($d['cfg']['v'] ?? null)) {
        $v = $d['cfg']['v'];
        $home = null;
        if (is_array($v['home'] ?? null) && isset($v['home']['lat'], $v['home']['lon'])) {
            $home = ['lat' => round((float)$v['home']['lat'], 5), 'lon' => round((float)$v['home']['lon'], 5),
                     'label' => mb_substr((string)($v['home']['label'] ?? ''), 0, 120)];
        }
        $cfg = ['t' => $t($d['cfg']['t'] ?? 0), 'v' => [
            'home'  => $home,
            'mode'  => in_array($v['mode'] ?? '', ['foot', 'bus', 'car'], true) ? $v['mode'] : 'foot',
            'max'   => max(1, min(3, (int)($v['max'] ?? 2))),
            'cards' => !empty($v['cards']),
            'fmt'   => in_array($v['fmt'] ?? '', ['all', 'super', 'hyper'], true) ? $v['fmt'] : 'all',
            'pick'  => isset($v['pick']) && is_string($v['pick']) ? mb_substr($v['pick'], 0, 200) : null,
        ]];
    }
    $last = ['t' => 0, 'v' => null];
    if (is_array($d['last'] ?? null)) {
        $lv = null;
        if (is_array($d['last']['v'] ?? null)) {
            $its = [];
            foreach (is_array($d['last']['v']['items'] ?? null) ? $d['last']['v']['items'] : [] as $x) {
                if (!is_array($x)) continue;
                $term = mb_substr(trim((string)($x['term'] ?? '')), 0, 60);
                if ($term === '') continue;
                $g = $x['grp'] ?? null;
                $its[] = ['term' => $term, 'qty' => max(1, min(99, (int)($x['qty'] ?? 1))), 'grp' => in_array($g, ['potraviny', 'drogerie', 'ostatni'], true) ? $g : null];
                if (count($its) >= MAX_ITEMS) break;
            }
            $lv = ['at' => $t($d['last']['v']['at'] ?? 0), 'items' => $its];
        }
        $last = ['t' => $t($d['last']['t'] ?? 0), 'v' => $lv];
    }
    return ['items' => $items, 'done' => $done, 'cfg' => $cfg, 'last' => $last];
}

function merge_maps(array $x, array $y): array {
    foreach ($y as $k => $v) if (!isset($x[$k]) || $v['t'] > $x[$k]['t']) $x[$k] = $v;
    return $x;
}
/** Sloučí dva vyčištěné dokumenty; u každého klíče vyhrává vyšší t (při shodě platí $a). */
function merge_docs(array $a, array $b, int $nowMs): array {
    $items = merge_maps($a['items'], $b['items']);
    $done = merge_maps($a['done'], $b['done']);
    $cfg = $b['cfg']['t'] > $a['cfg']['t'] ? $b['cfg'] : $a['cfg'];
    $old = $nowMs - TOMB_TTL * 1000;
    foreach ($items as $k => $v) if (!empty($v['del']) && $v['t'] < $old) unset($items[$k]);
    foreach ($done as $k => $v) if (!$v['v'] && $v['t'] < $old) unset($done[$k]);
    $last = $b['last']['t'] > $a['last']['t'] ? $b['last'] : $a['last'];
    return ['items' => $items, 'done' => $done, 'cfg' => $cfg, 'last' => $last];
}
function live_count(array $doc): int {
    return count(array_filter($doc['items'], fn($v) => empty($v['del'])));
}
function doc_out(array $doc): array {
    return ['items' => (object)$doc['items'], 'done' => (object)$doc['done'], 'cfg' => $doc['cfg'], 'last' => $doc['last']];
}

function ensure_dir(): void {
    if (!is_dir(BASE)) {
        @mkdir(BASE, 0755, true);
        @file_put_contents(BASE . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
}
function valid_code(string $c): bool { return (bool)preg_match('/^[2-9a-hj-km-np-z]{8}$/', $c); }
function list_path(string $code): string { return BASE . '/' . $code . '.json'; }
function read_locked($fp): array {
    rewind($fp);
    $j = json_decode((string)stream_get_contents($fp), true);
    return is_array($j) && isset($j['doc']) ? $j : ['rev' => 0, 'doc' => clean_doc([], now_ms())];
}
function write_locked($fp, array $rec): void {
    $rec['doc'] = doc_out($rec['doc']);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($rec, JSON_UNESCAPED_UNICODE));
    fflush($fp);
}
function body_doc(): array {
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if ($raw === false || strlen($raw) > MAX_BODY) out(['error' => 'too_big'], 413);
    $j = json_decode($raw, true);
    if (!is_array($j)) out(['error' => 'bad_json'], 400);
    return clean_doc($j['doc'] ?? [], now_ms());
}
function purge_old(): void {
    foreach (glob(BASE . '/*.json') ?: [] as $f) if (filemtime($f) < time() - TTL) @unlink($f);
}

function route(): void {
    ensure_dir();
    $a = $_GET['a'] ?? '';
    $code = strtolower((string)($_GET['code'] ?? ''));
    $nowMs = now_ms();
    $user = require_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') require_xhr();
    $setCode = fn(?string $c) => db()->prepare('UPDATE users SET list_code=? WHERE id=?')->execute([$c, $user['id']]);

    if ($a === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rk = ['cr:' . $user['id']];
        if (too_many($rk, CREATE_PER_HOUR, 3600)) out(['error' => 'rate'], 429);
        note_attempt($rk);
        if (random_int(1, 20) === 1) purge_old();
        if (count(glob(BASE . '/*.json') ?: []) >= MAX_LISTS) out(['error' => 'full'], 503);
        $doc = body_doc();
        if (live_count($doc) > MAX_ITEMS) out(['error' => 'too_many'], 422);
        for ($try = 0; $try < 10; $try++) {
            $c = ''; for ($i = 0; $i < 8; $i++) $c .= ALPHABET[random_int(0, strlen(ALPHABET) - 1)];
            $p = list_path($c);
            if (file_exists($p)) continue;
            $fp = fopen($p, 'c+'); flock($fp, LOCK_EX);
            $rec = ['rev' => 1, 'doc' => merge_docs(clean_doc([], $nowMs), $doc, $nowMs)];
            write_locked($fp, $rec); flock($fp, LOCK_UN); fclose($fp);
            $setCode($c);
            out(['code' => $c, 'rev' => 1, 'doc' => doc_out($rec['doc'])]);
        }
        out(['error' => 'busy'], 503);
    }

    if (!valid_code($code) || !is_file(list_path($code))) out(['error' => 'not_found'], 404);
    $p = list_path($code);

    // připojení k seznamu partnera: kdo zná kód, může se připojit; seznam pak patří k jeho účtu
    if ($a === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $setCode($code);
        $fp = fopen($p, 'r'); flock($fp, LOCK_SH); $rec = read_locked($fp); flock($fp, LOCK_UN); fclose($fp);
        out(['code' => $code, 'rev' => (int)$rec['rev'], 'doc' => doc_out(clean_doc($rec['doc'], $nowMs))]);
    }
    if ($code !== $user['list_code']) out(['error' => 'forbidden'], 403);

    if ($a === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $fp = fopen($p, 'r'); flock($fp, LOCK_SH);
        $rec = read_locked($fp); flock($fp, LOCK_UN); fclose($fp);
        if (filemtime($p) < time() - 86400) @touch($p);
        if (isset($_GET['rev']) && (int)$_GET['rev'] === (int)$rec['rev']) out(['same' => true, 'rev' => (int)$rec['rev']]);
        out(['rev' => (int)$rec['rev'], 'doc' => doc_out(clean_doc($rec['doc'], $nowMs))]);
    }

    if ($a === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = body_doc();
        $fp = fopen($p, 'c+'); flock($fp, LOCK_EX);
        $rec = read_locked($fp);
        $cur = clean_doc($rec['doc'], $nowMs);
        $merged = merge_docs($cur, $in, $nowMs);
        if (live_count($merged) > MAX_ITEMS) { flock($fp, LOCK_UN); fclose($fp); out(['error' => 'too_many'], 422); }
        if (json_encode(doc_out($merged)) !== json_encode(doc_out($cur))) { $rec['rev'] = (int)$rec['rev'] + 1; $rec['doc'] = $merged; write_locked($fp, $rec); }
        else @touch($p);
        flock($fp, LOCK_UN); fclose($fp);
        out(['rev' => (int)$rec['rev'], 'doc' => doc_out($merged)]);
    }
    out(['error' => 'bad_request'], 400);
}

if (!defined('LIST_TEST')) {
    route();
}
