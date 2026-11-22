<?php
/**
 * Nákup s rozumem - akce drogerií (Rossmann, Teta) do data_drug.json.
 * dm drogerie nemá akční letáky ani akční ceny (dlouhodobé ceny), proto pro ni akce nestahujeme; v aplikaci má jen prodejny.
 * Volání: cron/fetch_drug.php?key=...&only=rossmann|teta  (bez only oba)
 */
require_once __DIR__ . '/fetch_akce.php';

define('DRUG_MAXP', isset($_GET['maxp']) ? max(1, (int) $_GET['maxp']) : 9999);
const MIN_ITEMS_DRUG = ['rossmann' => 300, 'teta' => 200];

function drug_num(string $s): ?float {
    if (!preg_match('/(\d[\d\s\x{a0}]*(?:,\d+)?)/u', $s, $m)) return null;
    return (float) str_replace(',', '.', preg_replace('/[\s\x{a0}]/u', '', $m[1]));
}
function drug_text(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Stáhne několik adres souběžně (šetří čas); vrací pole stejných klíčů, při chybě null. */
function http_get_multi(array $urls): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept-Language: cs-CZ,cs;q=0.9']]);
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $out[$k] = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 ? curl_multi_getcontent($ch) : null;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function fetch_rossmann(): array {
    $items = []; $seen = [];
    $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+3 days'));
    $maxp = min(460, DRUG_MAXP); $done = false;
    for ($p0 = 1; $p0 <= $maxp && !$done; $p0 += 5) {
        $urls = [];
        for ($pg = $p0; $pg < $p0 + 5 && $pg <= $maxp; $pg++) $urls[$pg] = 'https://www.rossmann.cz/akce-a-slevy' . ($pg > 1 ? '?page=' . $pg : '');
        $pages = http_get_multi($urls);
        foreach ($pages as $pg => $h) {
            if ($h === null) { if ($pg <= 5) throw new RuntimeException('Rossmann: stranka ' . $pg . ' nejde stahnout'); $done = true; break; }
            $tiles = explode('class="product-tile"', $h);
            array_shift($tiles);
            $n = 0;
            foreach ($tiles as $t) {
                $t = substr($t, 0, 7000);
                if (!preg_match('/id="product-id--sp(\d+)"/', $t, $m)) continue;
                $n++;
                $id = $m[1];
                if (isset($seen[$id])) continue;
                if (!preg_match('~<h3 class="product-tile__title">\s*<a href="([^"]+)"[^>]*>(.*?)</a>~s', $t, $m2)) continue;
                $title = drug_text($m2[2]); $href = $m2[1];
                $brand = preg_match('~product-tile__sub--title">(.*?)</span>~s', $t, $mb) ? drug_text($mb[1]) : '';
                $amount = preg_match('~product-tile__sub--amount">(.*?)</span>~s', $t, $ma) ? drug_text($ma[1]) : '';
                $orig = preg_match('~price--original">\s*<s>(.*?)</s>~s', $t, $mo) ? drug_num($mo[1]) : null;
                $final = preg_match('~price--final">(?:\s*<span class="product-tile__price--original">.*?</span>)?\s*<div>(.*?)</div>~s', $t, $mf) ? drug_num($mf[1]) : null;
                $club = preg_match('~price--club">\s*<div>(.*?)</div>~s', $t, $mc) ? drug_num($mc[1]) : null;
                if (!$final || $final <= 0) continue;
                if (!($orig && $orig > $final) && !($club && $club < $final)) continue;
                $disc = ($orig && $orig > $final) ? (int) round(($orig - $final) / $orig * 100) : null;
                $img = null;
                if (preg_match('~<img[^>]+src="(https://binbox\.rossmann\.cz/[^"]+)"~', $t, $mi)) $img = html_entity_decode($mi[1], ENT_QUOTES);
                $seen[$id] = true;
                $items[] = [
                    'chain' => 'rossmann', 'id' => 'rossmann-' . $id,
                    'title' => trim(($brand && stripos($title, $brand) !== 0 ? $brand . ' ' : '') . $title),
                    'subtitle' => $amount, 'category' => null,
                    'price' => $final, 'oldPrice' => ($orig && $orig > $final) ? $orig : null,
                    'cardPrice' => ($club && $club < $final) ? $club : null, 'discount' => $disc,
                    'unit' => $amount, 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
                    'img' => $img, 'url' => 'https://www.rossmann.cz' . $href,
                ];
            }
            if ($n === 0) { $done = true; break; }
        }
        usleep(100000);
    }
    return $items;
}

function fetch_teta(): array {
    $items = []; $seen = [];
    $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+3 days'));
    $pages = 160;
    for ($page = 1; $page <= min($pages, DRUG_MAXP); $page++) {
        try { $h = http_get('https://www.tetadrogerie.cz/eshop/produkty/v-akci' . ($page > 1 ? '?strana=' . $page : '')); }
        catch (Throwable $e) { if ($page > 5) break; throw $e; }
        if ($page === 1 && preg_match('/z\s+(\d+)\s*</u', preg_replace('/\s+/', ' ', strip_tags(substr($h, 0, 800000))), $mt)) $pages = min(200, (int) ceil(((int) $mt[1]) / 40) + 1);
        preg_match_all('~href="(/eshop/katalog/[^"]+)"[^>]*c-product-card__link"~', $h, $hm);
        $cards = explode('c-product-card__link"', $h);
        array_shift($cards);
        if (!$cards) break;
        foreach ($cards as $ci => $c) {
            $href = $hm[1][$ci] ?? null;
            if (!preg_match('~c-product-card__title[^>]*>([^<]*)<~', $c, $mt2)) continue;
            $title = drug_text($mt2[1]);
            $former = preg_match('~c-product-price__former-price[^>]*>([^<]*)<~', $c, $mf) ? drug_num($mf[1]) : null;
            $val = preg_match('~c-product-price__value[^>]*><strong[^>]*>([^<]*)<~', $c, $mv) ? drug_num($mv[1]) : null;
            if (!$former || !$val || $val >= $former) continue;
            $note = preg_match('~c-product-price__note[^>]*>([^<]*)<~', $c, $mn) ? drug_text($mn[1]) : '';
            $sub = (stripos($note, 'při koupi') !== false && preg_match('~(při koupi[^*]*)~iu', $note, $mm)) ? trim($mm[1]) : '';
            $img = preg_match('~<img[^>]+src="(https://teta-drogerie\.[^"]+)"~', $c, $mi) ? html_entity_decode($mi[1], ENT_QUOTES) : null;
            $key = md5($title . $val);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = [
                'chain' => 'teta', 'id' => 'teta-' . substr($key, 0, 12),
                'title' => $title, 'subtitle' => $sub, 'category' => null,
                'price' => $val, 'oldPrice' => $former, 'cardPrice' => null,
                'discount' => (int) round(($former - $val) / $former * 100),
                'unit' => '', 'basePrice' => null, 'validFrom' => $from, 'validTo' => $to,
                'img' => $img, 'url' => $href ? 'https://www.tetadrogerie.cz' . $href : null,
            ];
        }
        usleep(120000);
    }
    return $items;
}

function main_drug(?string $only = null): int {
    $file = dirname(__DIR__) . '/data_drug.json';
    $prev = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $res = ['updated' => date('c'), 'sources' => [], 'items' => []];
    $src = ['rossmann' => 'fetch_rossmann', 'teta' => 'fetch_teta'];
    foreach ($src as $chain => $fn) {
        $keep = function () use (&$res, $prev, $chain) {
            if ($prev) foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === $chain) $res['items'][] = $it;
        };
        if ($only !== null && $only !== $chain) { $keep(); if (isset($prev['sources'][$chain])) $res['sources'][$chain] = $prev['sources'][$chain]; continue; }
        try {
            $items = $fn();
            if (DRUG_MAXP < 9999) { logmsg("[$chain] test: " . count($items) . ' polozek; priklad: ' . json_encode(array_slice($items, 0, 3), JSON_UNESCAPED_UNICODE)); return 0; }
            if (count($items) < MIN_ITEMS_DRUG[$chain]) throw new RuntimeException('podezrele malo polozek: ' . count($items));
            $res['sources'][$chain] = ['ok' => true, 'count' => count($items), 'fetched' => date('c')];
            array_push($res['items'], ...$items);
        } catch (Throwable $e) {
            logmsg("[$chain] CHYBA: " . $e->getMessage());
            $res['sources'][$chain] = ['ok' => false, 'error' => $e->getMessage()];
            $keep();
            if (isset($prev['sources'][$chain]['fetched'])) $res['sources'][$chain]['fetched'] = $prev['sources'][$chain]['fetched'];
        }
    }
    if (!$res['items']) { logmsg('Zadna data, data_drug.json se neprepisuje'); return 1; }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
    echo 'OK: ' . count($res['items']) . " polozek\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main_drug($argv[1] ?? null));
} elseif (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    @set_time_limit(600);
    ignore_user_abort(true);
    exit(main_drug(isset($_GET['only']) ? (string) $_GET['only'] : null));
}
