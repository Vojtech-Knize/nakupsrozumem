<?php
/**
 * Nákup s rozumem - akce elektra (Datart, Planeo) do data_elektro.json.
 * Datart: kampaňové stránky Black Friday (black-friday-01, -02, ...); jen nové zboží s původní cenou, bazar/zánovní se vynechává.
 * Planeo: kampaňové stránky (teď Black Off); cena je "s kuponem", proto je v textu uveden kód.
 * Čteme jen veřejné stránky povolené v robots.txt, bez filtrů a vyhledávání, s identifikovatelným User-Agentem.
 * Volání: cron/fetch_elektro.php?key=...&only=datart|planeo  (bez only oba); &maxp=2 = zkouška (nic se neuloží)
 */
require_once __DIR__ . '/fetch_drug.php';   // http_get_multi, drug_num, drug_text, logmsg, UA, CRON_KEY

define('ELEK_CAT_FROM', isset($_GET['c1']) ? max(1, (int) $_GET['c1']) : 1);
define('ELEK_CAT_TO', isset($_GET['c2']) ? min(60, (int) $_GET['c2']) : 60);
define('ELEK_MAXP', isset($_GET['maxp']) ? max(1, (int) $_GET['maxp']) : 9999);
const MIN_ITEMS_ELEK = ['datart' => 40, 'planeo' => 20];
const DATART_BASE = 'https://www.datart.cz/black-friday-%02d';   // kampaň Black Friday po kategoriích; po skončení se doplní další kampaň
const PLANEO_PAGES = ['https://www.planeo.cz/black-off'];   // kampaňové stránky; po skončení akce se doplní nová
const ELEK_USED_RE = '/zanovn|zánovn|rozbalen|bazar|použit|pouzit/iu';

function elek_validity(?string $html): array {
    $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+3 days'));
    if ($html !== null) {
        $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');   // "Akce platí od 1. 11. do 30. 11. 2026." (v HTML jako &iacute;)
        if (preg_match('/Akce\s+plat[ií]\s+od\s+[\d.\s\x{a0}]+do\s+(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $t, $m)) {
            $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            if ($d >= $from) $to = $d;
        }
    }
    return [$from, $to];
}

function fetch_datart(): array {
    $items = []; $seen = [];
    $miss = 0; $maxcat = ELEK_MAXP < 9999 ? min(ELEK_CAT_TO, ELEK_MAXP) : ELEK_CAT_TO; $maxp = min(40, ELEK_MAXP);
    // Kampaňové stránky Black Friday: black-friday-01, -02, ... (každá kategorie zboží zvlášť); konec po 3 chybějících za sebou
    for ($c = ELEK_CAT_FROM; $c <= $maxcat && $miss < 3; $c++) {
        $base = sprintf(DATART_BASE, $c);
        $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+3 days')); $got = false; $done = false;
        for ($p0 = 1; $p0 <= $maxp && !$done; $p0 += 3) {
            $urls = [];
            for ($pg = $p0; $pg < $p0 + 3 && $pg <= $maxp; $pg++) $urls[$pg] = $base . ($pg > 1 ? '?page=' . $pg : '');
            $pages = http_get_multi($urls);
            foreach ($pages as $pg => $h) {
                if ($h === null) { $done = true; break; }
                if ($pg === 1) { $got = true; [$from, $to] = elek_validity($h); }
                $tiles = preg_split('~<div class="product-box(?:\s[^"]*)?"~', $h);
                array_shift($tiles);
                $new = 0;
                foreach ($tiles as $t) {
                    $GLOBALS['elek_dbg']['tiles'] = ($GLOBALS['elek_dbg']['tiles'] ?? 0) + 1;
                    $t = substr($t, 0, 14000);
                    if (!preg_match("~data-gtm-data-product='(\{.*?\})'~s", $t, $mj)) continue;
                    $j = json_decode(html_entity_decode($mj[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                    if (!is_array($j) || empty($j['item_id'])) continue;
                    $id = (string) $j['item_id'];
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true; $new++;
                    $title = trim((string) ($j['item_name'] ?? ''));
                    if ($title === '' || preg_match(ELEK_USED_RE, $title . ' ' . ($j['item_labels'] ?? ''))) { $GLOBALS['elek_dbg']['zanovni'] = ($GLOBALS['elek_dbg']['zanovni'] ?? 0) + 1; continue; }
                    if (!preg_match('~<a href="(/[^"]+)"~', $t, $mh) || str_starts_with($mh[1], '/bazar/')) continue;
                    $final = preg_match('~data-qa="actual-price"[^>]*>(.*?)</div>~s', $t, $mf) ? drug_num(drug_text($mf[1])) : null;
                    if (!$final) $final = isset($j['price']) ? (float) $j['price'] : null;
                    $orig = preg_match('~cut-price--strike">\s*(?:<span class="sr-only">.*?</span>)?(.*?)</span>~s', $t, $mo) ? drug_num(drug_text($mo[1])) : null;
                    if (!$final || $final <= 0 || !$orig || $orig <= $final) { $GLOBALS['elek_dbg']['bez_slevy'] = ($GLOBALS['elek_dbg']['bez_slevy'] ?? 0) + 1; continue; }
                    $sub = preg_match('~class="item-description">\s*<p>(.*?)</p>~s', $t, $md) ? drug_text($md[1]) : '';
                    if (mb_strlen($sub) > 90) $sub = rtrim(mb_substr($sub, 0, 88), " •,;") . '…';
                    $img = preg_match('~<img[^>]+src="(https://image\.datart\.cz/[^"]+)"~', $t, $mi) ? html_entity_decode($mi[1], ENT_QUOTES) : null;
                    $items[] = [
                        'chain' => 'datart', 'id' => 'datart-' . $id,
                        'title' => $title, 'subtitle' => $sub, 'category' => null,
                        'price' => $final, 'oldPrice' => $orig, 'cardPrice' => null,
                        'discount' => (int) round(($orig - $final) / $orig * 100),
                        'unit' => '', 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
                        'img' => $img, 'url' => 'https://www.datart.cz' . $mh[1],
                    ];
                }
                if ($new === 0) { $done = true; break; }
            }
            usleep(300000);
        }
        $miss = $got ? 0 : $miss + 1;
    }
    return $items;
}

/** Jedna stránka katalogu Planea: položky do $items (jen zlevněné), vrací počet dosud neviděných dlaždic. */
function parse_planeo_page(string $h, string $from, string $to, array &$items, array &$seen): int {
    $tiles = preg_split('~data-testid="catalogue\.item"(?=\s)~', $h);
    array_shift($tiles);
    $new = 0;
    foreach ($tiles as $t) {
        $t = substr($t, 0, 20000);
        if (!preg_match('/data-gtm-product-id="(\d+)"/', $t, $mi)) continue;
        $id = $mi[1];
        if (isset($seen[$id])) continue;
        $seen[$id] = true; $new++;
        if (!preg_match("~data-gtm-product-name='(.*?)'\s~s", $t, $mn)) continue;
        $title = trim(html_entity_decode($mn[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $brand = preg_match('/data-gtm-product-brand="([^"]*)"/', $t, $mb) ? html_entity_decode($mb[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $cur = preg_match('~data-testid="catalogue\.item\.price"\s+data-test-value="(\d+(?:\.\d+)?)"~', $t, $mp) ? (float) $mp[1] : null;
        if (!$cur) continue;
        $before = preg_match('~data-testid="catalogue\.item\.price-tag\.strip-price">([^<]*)<~', $t, $mb2) ? drug_num(drug_text($mb2[1])) : null;
        $cpn = null; $code = '';
        if (preg_match('~data-testid="catalogue\.item\.voucher".*?class="fd-mkt-box__text">([^<]*)<.*?class="fd-mkt-box__value">([^<]*)<~s', $t, $mv)) {
            $code = drug_text($mv[1]); $cpn = drug_num(drug_text($mv[2]));
        }
        $coupon = $cpn && $cpn < $cur && $code !== '';
        $price = $coupon ? $cpn : $cur;                                  // s kuponem, jinak zobrazená cena
        $old = ($before && $before > $cur) ? $before : $cur;             // přeškrtnutá cena, jinak cena bez kuponu
        if ($price >= $old) continue;
        if (!preg_match('~<a\s+href="(/[^"]+)"~', $t, $mh)) continue;
        $img = preg_match('~<img\s[^>]*?src="(https://mc-static\.fast\.eu/pics/[^"]+)"~', $t, $mim) ? html_entity_decode($mim[1], ENT_QUOTES) : null;
        $cat = preg_match('/data-gtm-product-item-category2="([^"]*)"/', $t, $mcat) ? $mcat[1] : (preg_match('/data-gtm-product-item-category="([^"]*)"/', $t, $mcat) ? $mcat[1] : '');
        $cat = trim(html_entity_decode($cat, ENT_QUOTES | ENT_HTML5, 'UTF-8'));   // druh zboží (Pračky, Myčky nádobí…) – v názvu není, ale hledá se podle něj
        $items[] = [
            'chain' => 'planeo', 'id' => 'planeo-' . $id,
            'title' => trim(($brand && stripos($title, $brand) !== 0 ? $brand . ' ' : '') . $title),
            'subtitle' => trim(($cat !== '' ? $cat . ' · ' : '') . ($coupon ? 'kupon ' . $code . ' v košíku nebo na prodejně' : ''), ' ·'),
            'category' => null,
            'price' => $price, 'oldPrice' => $old, 'cardPrice' => null,
            'discount' => (int) round(($old - $price) / $old * 100),
            'unit' => '', 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
            'img' => $img, 'url' => 'https://www.planeo.cz' . $mh[1],
        ];
    }
    return $new;
}

function fetch_planeo(): array {
    $items = []; $seen = [];
    $per = 72;   // nejvetsi nabizeny pocet na stranku, tretinovy pocet pozadavku
    foreach (PLANEO_PAGES as $n => $base) {
        $url = fn(int $pg): string => $base . '?limit=' . $per . ($pg > 0 ? '&offset=' . ($pg * $per) : '');
        try { $first = http_get($url(0)); } catch (Throwable $e) { if ($n === 0) throw $e; continue; }
        [$from, $to] = elek_validity($first);
        $before = count($seen);
        if (parse_planeo_page($first, $from, $to, $items, $seen) === 0) continue;
        // pocet stran podle celkoveho poctu produktu: za posledni stranou web vraci 404
        $total = preg_match('~data-gtm-page-products="(\d+)"~', $first, $m) ? (int) $m[1] : 0;
        if (!$total) throw new RuntimeException('chybi celkovy pocet produktu');
        $pages = min((int) ceil($total / $per), 100, ELEK_MAXP);
        for ($pg = 1; $pg < $pages; $pg += 4) {
            $urls = [];
            for ($i = $pg; $i < min($pg + 4, $pages); $i++) $urls[$i] = $url($i);
            foreach (http_get_multi($urls) as $i => $h) {
                if ($h === null) { try { $h = http_get($urls[$i]); } catch (Throwable $e) { logmsg('[planeo] ' . $e->getMessage()); continue; } }
                parse_planeo_page($h, $from, $to, $items, $seen);
            }
            usleep(200000);
        }
        // neuplne stazeni neprepise predchozi data (rezerva na zmeny nabidky behem stahovani)
        $got = count($seen) - $before;
        if (ELEK_MAXP >= 9999 && $got < 0.9 * min($total, $pages * $per)) throw new RuntimeException("nacteno jen $got z $total produktu");
    }
    return $items;
}

function main_elektro(?string $only = null): int {
    $file = dirname(__DIR__) . '/data_elektro.json';
    $prev = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($prev)) $prev = null;
    $res = ['updated' => date('c'), 'sources' => [], 'items' => []];
    $src = ['datart' => 'fetch_datart', 'planeo' => 'fetch_planeo'];
    foreach ($src as $chain => $fn) {
        $keep = function () use (&$res, $prev, $chain) {
            if ($prev) foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === $chain) $res['items'][] = $it;
        };
        if ($only !== null && $only !== $chain) { $keep(); if (isset($prev['sources'][$chain])) $res['sources'][$chain] = $prev['sources'][$chain]; continue; }
        try {
            $items = $fn();
            if ($chain === 'datart' && isset($_GET['c1']) && $prev) {   // davka kategorii: doplnit k drivejsim polozkam
                $ids = []; foreach ($items as $it) $ids[$it['id']] = true;
                foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === 'datart' && empty($ids[$it['id']]) && ($it['validTo'] ?? '9') >= date('Y-m-d')) $items[] = $it;
            }
            if (ELEK_MAXP < 9999) { logmsg("[$chain] test: " . count($items) . ' polozek; diagnostika: ' . json_encode($GLOBALS['elek_dbg'] ?? []) . '; priklad: ' . json_encode(array_slice($items, 0, 3), JSON_UNESCAPED_UNICODE)); continue; }
            if (count($items) < MIN_ITEMS_ELEK[$chain]) throw new RuntimeException('podezrele malo polozek: ' . count($items));
            $res['sources'][$chain] = ['ok' => true, 'count' => count($items), 'fetched' => date('c')];
            array_push($res['items'], ...$items);
        } catch (Throwable $e) {
            logmsg("[$chain] CHYBA: " . $e->getMessage());
            $res['sources'][$chain] = ['ok' => false, 'error' => $e->getMessage()];
            $keep();
            if (isset($prev['sources'][$chain]['fetched'])) $res['sources'][$chain]['fetched'] = $prev['sources'][$chain]['fetched'];
        }
    }
    if (ELEK_MAXP < 9999) return 0;
    if (!$res['items']) { logmsg('Zadna data, data_elektro.json se neprepisuje'); return 1; }
    $tmp = $file . '.tmp';
    $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) < 1000) { logmsg('CHYBA json_encode: ' . json_last_error_msg()); echo 'CHYBA json: ' . json_last_error_msg() . "\n"; return 1; }
    file_put_contents($tmp, $json);
    rename($tmp, $file);
    echo 'OK: ' . count($res['items']) . " polozek\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main_elektro($argv[1] ?? null));
} elseif (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    @set_time_limit(600);
    ignore_user_abort(true);
    exit(main_elektro(isset($_GET['only']) ? (string) $_GET['only'] : null));
}
