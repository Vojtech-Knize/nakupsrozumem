<?php
/**
 * Nákup s rozumem - akce elektra (Datart, Planeo) do data_elektro.json.
 * Datart: výpis "Doprodej posledních kusů" (nové zboží, bazar/zánovní se vynechává).
 * Planeo: kampaňové stránky (teď Black Off); cena je "s kuponem", proto je v textu uveden kód.
 * Čteme jen veřejné stránky povolené v robots.txt, bez filtrů a vyhledávání, s identifikovatelným User-Agentem.
 * Volání: cron/fetch_elektro.php?key=...&only=datart|planeo  (bez only oba); &maxp=2 = zkouška (nic se neuloží)
 */
require_once __DIR__ . '/fetch_drug.php';   // http_get_multi, drug_num, drug_text, logmsg, UA, CRON_KEY

define('ELEK_MAXP', isset($_GET['maxp']) ? max(1, (int) $_GET['maxp']) : 9999);
const MIN_ITEMS_ELEK = ['datart' => 40, 'planeo' => 20];
const DATART_URL = 'https://www.datart.cz/vyprodej-poslednich-kusu.html';
const PLANEO_PAGES = ['https://www.planeo.cz/black-off'];   // kampaňové stránky; po skončení akce se doplní nová
const ELEK_USED_RE = '/zanovn|zánovn|rozbalen|bazar|použit|pouzit/iu';

function elek_validity(?string $html): array {
    $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+3 days'));
    if ($html !== null) {
        $t = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/plat[ií]\s+od\s+[\d. ]+do\s+(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $t, $m)) {
            $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            if ($d >= $from) $to = $d;
        }
    }
    return [$from, $to];
}

function fetch_datart(): array {
    $items = []; $seen = [];
    [$from, $to] = elek_validity(null);
    $maxp = min(150, ELEK_MAXP); $done = false;
    for ($p0 = 1; $p0 <= $maxp && !$done; $p0 += 3) {
        $urls = [];
        for ($pg = $p0; $pg < $p0 + 3 && $pg <= $maxp; $pg++) $urls[$pg] = DATART_URL . ($pg > 1 ? '?page=' . $pg : '');
        $pages = http_get_multi($urls);
        foreach ($pages as $pg => $h) {
            if ($h === null) { if ($pg <= 3) throw new RuntimeException('Datart: stranka ' . $pg . ' nejde stahnout'); $done = true; break; }
            $tiles = explode('<div class="product-box ', $h);
            array_shift($tiles);
            $n = 0;
            foreach ($tiles as $t) {
                $GLOBALS['elek_dbg']['tiles'] = ($GLOBALS['elek_dbg']['tiles'] ?? 0) + 1;
                $t = substr($t, 0, 14000);
                if (!preg_match("~data-gtm-data-product='(\{.*?\})'~s", $t, $mj)) continue;
                $j = json_decode(html_entity_decode($mj[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!is_array($j) || empty($j['item_id'])) continue;
                $n++;
                $id = (string) $j['item_id'];
                if (isset($seen[$id])) continue;
                $title = trim((string) ($j['item_name'] ?? ''));
                if ($title === '' || preg_match(ELEK_USED_RE, $title . ' ' . ($j['item_labels'] ?? ''))) { $GLOBALS['elek_dbg']['used'] = ($GLOBALS['elek_dbg']['used'] ?? 0) + 1; if (($GLOBALS['elek_dbg']['used']) <= 4) $GLOBALS['elek_dbg']['vzorek'][] = mb_substr($title, 0, 60) . ' | ' . json_encode($j['item_labels'] ?? null, JSON_UNESCAPED_UNICODE); continue; }
                if (!preg_match('~<a href="(/[^"]+)"~', $t, $mh) || str_starts_with($mh[1], '/bazar/')) continue;
                $final = preg_match('~data-qa="actual-price"[^>]*>(.*?)</div>~s', $t, $mf) ? drug_num(drug_text($mf[1])) : null;
                if (!$final) $final = isset($j['price']) ? (float) $j['price'] : null;
                $orig = preg_match('~cut-price--strike">\s*(?:<span class="sr-only">.*?</span>)?(.*?)</span>~s', $t, $mo) ? drug_num(drug_text($mo[1])) : null;
                if (!$final || $final <= 0 || !$orig || $orig <= $final) { $GLOBALS['elek_dbg']['bez_slevy'] = ($GLOBALS['elek_dbg']['bez_slevy'] ?? 0) + 1; continue; }
                $sub = preg_match('~class="item-description">\s*<p>(.*?)</p>~s', $t, $md) ? drug_text($md[1]) : '';
                if (mb_strlen($sub) > 90) $sub = rtrim(mb_substr($sub, 0, 88), " •,;") . '…';
                $img = preg_match('~<img[^>]+src="(https://image\.datart\.cz/[^"]+)"~', $t, $mi) ? html_entity_decode($mi[1], ENT_QUOTES) : null;
                $seen[$id] = true;
                $items[] = [
                    'chain' => 'datart', 'id' => 'datart-' . $id,
                    'title' => $title, 'subtitle' => $sub, 'category' => null,
                    'price' => $final, 'oldPrice' => $orig, 'cardPrice' => null,
                    'discount' => (int) round(($orig - $final) / $orig * 100),
                    'unit' => '', 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
                    'img' => $img, 'url' => 'https://www.datart.cz' . $mh[1],
                ];
            }
            if ($n === 0) { $done = true; break; }
        }
        usleep(300000);
    }
    return $items;
}

function fetch_planeo(): array {
    $items = []; $seen = [];
    foreach (PLANEO_PAGES as $base) {
        [$from, $to] = [date('Y-m-d'), date('Y-m-d', strtotime('+3 days'))];
        $maxp = min(80, ELEK_MAXP);
        for ($pg = 0; $pg < $maxp; $pg++) {
            $url = $base . ($pg > 0 ? '?offset=' . ($pg * 24) : '');
            try { $h = http_get($url); } catch (Throwable $e) { if ($pg === 0 && count($items) === 0 && $base === PLANEO_PAGES[0]) throw $e; break; }
            if ($pg === 0) [$from, $to] = elek_validity($h);
            $tiles = explode('class="c-product c-product--catalogue"', $h);
            array_shift($tiles);
            $new = 0;
            foreach ($tiles as $t) {
                $t = substr($t, 0, 16000);
                if (!preg_match('/data-gtm-product-id="(\d+)"/', $t, $mi)) continue;
                $id = $mi[1];
                if (isset($seen[$id])) continue;
                $seen[$id] = true; $new++;
                if (!preg_match("~data-gtm-product-name='(.*?)'\s~s", $t, $mn)) continue;
                $title = trim(html_entity_decode($mn[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $brand = preg_match('/data-gtm-product-brand="([^"]*)"/', $t, $mb) ? html_entity_decode($mb[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
                $reg = preg_match('~data-testid="catalogue\.item\.price" data-test-value="(\d+)"~', $t, $mp) ? (float) $mp[1] : null;
                $cpn = preg_match('~c-product__voucher-price">([^<]*)<~', $t, $mc) ? drug_num(drug_text($mc[1])) : null;
                $code = preg_match('~c-product__voucher-code">([^<]*)<~', $t, $mk) ? drug_text($mk[1]) : '';
                if (!$reg || !$cpn || $cpn >= $reg || $code === '') continue;
                if (!preg_match('~<a href="(/[^"]+)">~', $t, $mh)) continue;
                $img = preg_match('~<img src="(https://mc-static\.fast\.eu/pics/[^"]+)"~', $t, $mim) ? html_entity_decode($mim[1], ENT_QUOTES) : null;
                $items[] = [
                    'chain' => 'planeo', 'id' => 'planeo-' . $id,
                    'title' => trim(($brand && stripos($title, $brand) !== 0 ? $brand . ' ' : '') . $title),
                    'subtitle' => 'Cena po zadání kuponu ' . $code . ' v košíku nebo na prodejně',
                    'category' => null,
                    'price' => $cpn, 'oldPrice' => $reg, 'cardPrice' => null,
                    'discount' => (int) round(($reg - $cpn) / $reg * 100),
                    'unit' => '', 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
                    'img' => $img, 'url' => 'https://www.planeo.cz' . $mh[1],
                ];
            }
            if ($new === 0) break;
            usleep(300000);
        }
    }
    return $items;
}

function main_elektro(?string $only = null): int {
    $file = dirname(__DIR__) . '/data_elektro.json';
    $prev = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $res = ['updated' => date('c'), 'sources' => [], 'items' => []];
    $src = ['datart' => 'fetch_datart', 'planeo' => 'fetch_planeo'];
    foreach ($src as $chain => $fn) {
        $keep = function () use (&$res, $prev, $chain) {
            if ($prev) foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === $chain) $res['items'][] = $it;
        };
        if ($only !== null && $only !== $chain) { $keep(); if (isset($prev['sources'][$chain])) $res['sources'][$chain] = $prev['sources'][$chain]; continue; }
        try {
            $items = $fn();
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
    file_put_contents($tmp, json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
