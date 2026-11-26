<?php
/**
 * Nákup s rozumem - denní stahování akčních nabídek (Kaufland, Penny).
 * Spouští se z CRONu WEDOS (volá URL): https://test.nakupsrozumem.cz/cron/fetch_akce.php?key=...
 * nebo z příkazové řádky: php fetch_akce.php
 * Výstup: ../data.json (zapisuje se atomicky; při chybě zůstane předchozí verze).
 *
 * Pozn.: Kaufland má nabídky jako JSON přímo v HTML, Penny jako HTML (data-test atributy).
 * Stahujeme jen veřejné stránky, jednou denně, s identifikovatelným User-Agentem.
 */

if (!defined('CRON_KEY')) { $ck = dirname(__DIR__) . '/cronkey.php'; if (is_file($ck)) require_once $ck; else define('CRON_KEY', bin2hex(random_bytes(16))); }   // tajny klic je jen na serveru (cronkey.php), ne v repozitari
const UA = 'NakupSRozumem/0.1 (+https://nakupsrozumem.cz; denni aktualizace akci pro seniory)';
const MIN_ITEMS = ['kaufland' => 200, 'penny' => 15, 'albert' => 30, 'norma' => 15, 'coop' => 20, 'terno' => 20, 'lidl' => 30, 'billa' => 100, 'globus' => 200]; // pod tímto počtem berem stahování jako chybné

function logmsg(string $m): void { echo $m . "\n"; }

function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_ENCODING       => '',            // gzip/deflate
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => ['Accept-Language: cs-CZ,cs;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        throw new RuntimeException("HTTP $code $err pro $url");
    }
    return $body;
}

/** Adresa obrázku: jen https, bez dat v řádku a bez ikon (svg); relativní adresy se doplní o $origin. */
function clean_img($u, string $origin = ''): ?string {
    if (!is_string($u)) return null;
    $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5));
    if ($u === '' || str_starts_with($u, 'data:')) return null;
    if (str_starts_with($u, '//')) $u = 'https:' . $u;
    elseif ($u[0] === '/' && $origin !== '') $u = $origin . $u;
    if (!str_starts_with($u, 'https://') || strlen($u) > 300) return null;
    if (preg_match('/\.svg(\?|$)/i', $u)) return null;
    return $u;
}
/** Zmenšená verze obrázku (originály mají 1400-2400 px, na mobilu by to stálo megabajty). Přípony používají weby samy. */
function small_img(?string $u, string $chain): ?string {
    if ($u === null) return null;
    if ($chain === 'kaufland' && !str_contains($u, '?')) return $u . '?JGstbGVnYWN5LW9uc2l0ZS0xJA==';   // 150 px
    if ($chain === 'billa' && preg_match('/\.jpe?g$/i', $u)) return preg_replace('/\.jpe?g$/i', '-small.jpg', $u);   // 150 px
    return $u;
}
/** První obrázek produktu uvnitř dlaždice (src, data-src nebo první položka srcset). */
function tile_img(DOMXPath $xp, DOMNode $tile, string $origin = ''): ?string {
    foreach ($xp->query('.//img', $tile) as $im) {
        foreach (['src', 'data-src'] as $a) { $u = clean_img($im->getAttribute($a), $origin); if ($u) return $u; }
        $ss = trim((string) $im->getAttribute('srcset'));
        if ($ss !== '') { $u = clean_img(preg_split('/\s+/', explode(',', $ss)[0])[0] ?? '', $origin); if ($u) return $u; }
    }
    return null;
}

function cz_num(?string $s): ?float {
    if ($s === null) return null;
    if (!preg_match('/\d[\d\s\x{00a0}.]*(?:,\d+)?/u', $s, $m)) return null;
    $n = str_replace([" ", "\u{00a0}", "."], '', $m[0]);
    return (float) str_replace(',', '.', $n);
}

function cz_date(?string $s): ?string { // "18.11.2026" -> "2026-11-18"
    if ($s !== null && preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

/** Kaufland: objekty {"offerId":...,"formattedPrice":"..."} uvnitř skriptu v HTML. */
function parse_kaufland(string $html): array {
    $n = preg_match_all('/\{"offerId":".*?"formattedPrice":"[^"]*"\}/su', $html, $mm, PREG_OFFSET_CAPTURE);
    if (!$n) return [];
    // kategorie: poslední "displayName" před nabídkou
    preg_match_all('/"displayName":"([^"]+)"/u', $html, $cats, PREG_OFFSET_CAPTURE);
    $catPos = array_map(fn($c) => $c[1], $cats[1]);
    $catName = array_map(fn($c) => $c[0], $cats[1]);

    $out = [];
    foreach ($mm[0] as [$json, $pos]) {
        $o = json_decode($json, true);
        if (!is_array($o) || empty($o['offerId'])) continue;
        $id = $o['offerId'];
        if (isset($out[$id])) continue;
        $cat = null;
        for ($i = count($catPos) - 1; $i >= 0; $i--) {
            if ($catPos[$i] < $pos) { $cat = $catName[$i]; break; }
        }
        $price    = isset($o['price']) ? (float) $o['price'] : cz_num($o['formattedPrice'] ?? null);
        $oldPrice = cz_num($o['formattedOldPrice'] ?? null);
        $out[$id] = [
            'chain'     => 'kaufland',
            'id'        => $id,
            'title'     => trim(($o['title'] ?? '')),
            'subtitle'  => trim(preg_replace('/\s+/u', ' ', $o['subtitle'] ?? '')),
            'category'  => $cat,
            'price'     => $price,
            'oldPrice'  => $oldPrice ?: null,
            'cardPrice' => null,                 // Kaufland Card XTRA ceny jsou zatím v jiných polích, doplníme
            'discount'  => isset($o['discount']) ? (int) $o['discount'] : null,
            'unit'      => $o['unit'] ?? null,
            'basePrice' => $o['basePrice'] ?? null,
            'validFrom' => $o['dateFrom'] ?? null,
            'validTo'   => $o['dateTo'] ?? null,
            'img'       => small_img(clean_img($o['listImage'] ?? null), 'kaufland'),
        ];
    }
    return array_values($out);
}

/** Penny: <li data-test="product-tile"> s poli označenými data-test. */
function parse_penny(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $txt = function (DOMNode $ctx, string $dt) use ($xp): ?string {
        $n = $xp->query('.//*[@data-test="' . $dt . '"]', $ctx)->item(0);
        return $n ? trim(preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $n->textContent))) : null;
    };
    $out = [];
    foreach ($xp->query('//li[@data-test="product-tile"]') as $li) {
        $title = $txt($li, 'product-title');
        if (!$title) continue;
        $validity = $xp->query('.//*[@data-test="product-price-validity"]', $li)->item(0);
        $dates = [];
        if ($validity) preg_match_all('/\d{1,2}\.\d{1,2}\.\d{4}/', $validity->textContent, $dates);
        $dates = $dates[0] ?? [];

        $normal = null; $card = null; $old = null; $base = null;
        foreach ($xp->query('.//*[@data-test="product-price-type"]', $li) as $t) {
            $label = $txt($t, 'product-price-type-price-label');   // "bez PENNY karty" / "s PENNY kartou" / null
            $val   = $txt($t, 'product-price-type-value');
            $unit  = $txt($t, 'product-price-type-label');         // "100 g 13,20 Kč"
            $strike = $xp->query('.//s', $t)->item(0);
            $price = cz_num($val);
            if ($label && stripos($label, 's PENNY') !== false) {
                $card = $price;
            } else {
                $normal = $price;
                if ($strike) $old = cz_num($strike->textContent);
                $base = $unit;
            }
            if ($base === null) $base = $unit;
        }
        $disc = $txt($li, 'product-badge-discount-value');
        // pokud je jen cena s kartou a obyčejná, "price" = cena bez karty, cardPrice = s kartou
        $out[] = [
            'chain'     => 'penny',
            'id'        => 'penny:' . md5($title . '|' . ($txt($li, 'product-information-piece-description') ?? '') . '|' . ($dates[0] ?? '')),
            'title'     => $title,
            'subtitle'  => $txt($li, 'product-information-piece-description') ?? '',
            'category'  => null,
            'price'     => $normal,
            'oldPrice'  => $old,
            'cardPrice' => $card,
            'discount'  => $disc !== null ? abs((int) $disc) : null,
            'unit'      => $txt($li, 'product-information-piece-description'),
            'basePrice' => $base,
            'validFrom' => cz_date($dates[0] ?? null),
            'validTo'   => cz_date($dates[1] ?? null),
            'img'       => tile_img($xp, $li, 'https://www.penny.cz'),
        ];
    }
    // Penny opakuje stejné produkty vícekrát (různé varianty) - sloučit duplicity
    // (ponechat záznam s nejvíce vyplněnými poli, tj. i s cenou s kartou)
    $uniq = [];
    $score = fn(array $o) => count(array_filter($o, fn($v) => $v !== null && $v !== ''));
    foreach ($out as $o) {
        $id = $o['id'];
        if (!isset($uniq[$id]) || $score($o) > $score($uniq[$id])) $uniq[$id] = $o;
    }
    return array_values($uniq);
}


/** Lidl: každá dlaždice produktu má JSON v atributu data-grid-data (je už v HTML, bez JavaScriptu). */
function parse_lidl(string $html, string $category = ''): array {
    $n = preg_match_all('/data-grid-data="([^"]*)"/u', $html, $mm);
    if (!$n) return [];
    $day = fn($ts) => $ts ? (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d') : null;
    $out = [];
    foreach ($mm[1] as $raw) {
        $d = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($d) || empty($d['fullTitle']) || !isset($d['price']['price'])) continue;
        $p = $d['price'];
        $price = (float) $p['price'];
        $old = isset($p['oldPrice']) ? (float) $p['oldPrice'] : null;
        $plus = null;
        if (!empty($d['lidlPlus'][0]['price']['price'])) $plus = (float) $d['lidlPlus'][0]['price']['price'];
        $isDeal = ($old !== null && $old > $price) || ($plus !== null && $plus < $price);
        if (!$isDeal || $price <= 0) continue;
        $id = 'lidl:' . ($d['productId'] ?? md5($d['fullTitle']));
        $pack = $p['packaging']['text'] ?? null;
        if ($pack !== null && preg_match('/^Dostupn/u', $pack)) $pack = null;   // poznámka o dostupnosti není balení
        $out[$id] = [
            'chain'     => 'lidl',
            'id'        => $id,
            'title'     => trim($d['fullTitle']),
            'subtitle'  => trim((string) $pack),
            'category'  => $category ?: ($d['category'] ?? null),
            'price'     => $price,
            'oldPrice'  => $old,
            'cardPrice' => $plus,
            'discount'  => isset($p['discount']['percentageDiscount']) ? (int) $p['discount']['percentageDiscount'] : null,
            'unit'      => $pack,
            'basePrice' => $p['basePrice']['text'] ?? null,
            'validFrom' => $day($d['storeStartDate'] ?? null),
            'validTo'   => $day($d['storeEndDate'] ?? null),
            'img'       => clean_img($d['imageList_V2'][0]['variants'][0]['sizes']['smallUrl'] ?? ($d['image'] ?? null)),
        ];
    }
    return array_values($out);
}

/** Lidl: seznam kategorií potravin a drogerie se bere ze stránky potravin, každá kategorie je samostatná stránka. */
/** Penny leták, hlavní čtení: bere přímo velkou cenu z vektorové stránky (SVG) a přiřadí ji k bloku "název / gramáž / cena za jednotku".
 *  Koncovku ceny kreslí leták zvláštním znakem. Nejdřív se párují ceny, které sedí s cenou za jednotku, zbytek podle polohy v dlaždici
 *  (jen když si cena a název vyberou vzájemně). Neznámá koncovka nebo nejisté přiřazení = položka se vynechá. */
const PENNY_END = ["\u{01FA}" => 0.9, "\u{01F7}" => 0.5, "\u{0206}" => 0.25, "\u{024F}" => 0.0];

function parse_penny_page(string $svg): array {
    $red = '255,45,22'; $light = ['43,46,52', '255,255,255'];
    $E = []; $P = [];                                              // E = kousky textu, P = velké ceny
    if (!preg_match_all('#<svg:text\s+transform="matrix\(([-\d.]+) 0 0 ([-\d.]+) ([-\d.]+) ([-\d.]+)\)[^"]*"[^>]*>(.*?)</svg:text>#s', $svg, $tm, PREG_SET_ORDER)) return [];
    foreach ($tm as $t) {
        $sx = (float) $t[1]; $tx = (float) $t[3]; $ty = (float) $t[4];
        if (!preg_match_all('#<svg:tspan([^>]*)>(.*?)</svg:tspan>#s', $t[5], $ts, PREG_SET_ORDER)) continue;
        foreach ($ts as $q) {
            $xs = preg_match('/\bx="([^"]*)"/', $q[1], $xm) ? array_map('floatval', preg_split('/\s+/', trim($xm[1]))) : [0.0];
            $raw = html_entity_decode(strip_tags($q[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $col = preg_match('/rgb\(([^)]*)\)/', $q[1], $cm) ? str_replace(' ', '', $cm[1]) : '';
            if ($sx >= 10 && ($col === $red || $col === '255,255,255') && preg_match_all('/(\d{1,4})([\x{01FA}\x{01F7}\x{0206}\x{024F}])/u', $raw, $pm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($pm as $m) {
                    $i = mb_strlen(substr($raw, 0, $m[0][1]), 'UTF-8');
                    if (isset($xs[$i])) $P[] = ['x' => $tx + $sx * $xs[$i], 'y' => $ty, 'v' => round((int) $m[1][0] + PENNY_END[$m[2][0]], 2)];
                }
            }
            // jeden tspan může nést víc textů vedle sebe: rozdělit tam, kde x skočí
            $segs = [[$xs[0], $raw]];
            if ($chars && count($chars) === count($xs)) {
                $segs = []; $cur = ''; $start = $xs[0];
                foreach ($chars as $i => $ch) {
                    if ($i > 0 && $xs[$i] - $xs[$i - 1] > 4) { $segs[] = [$start, $cur]; $cur = ''; $start = $xs[$i]; }
                    $cur .= $ch;
                }
                $segs[] = [$start, $cur];
            }
            foreach ($segs as [$x0, $str]) {
                $v = trim(preg_replace('/\s+/u', ' ', $str));
                if ($v !== '') $E[] = ['x' => $tx + $sx * $x0, 'y' => $ty, 'sz' => $sx, 'col' => $col, 's' => $v];
            }
        }
    }
    if (!$P) return [];
    // sloupce textu podle x
    $lines = array_values(array_filter($E, fn($e) => $e['sz'] >= 3.9 && $e['sz'] <= 7.6 && in_array($e['col'], $light, true)
        && preg_match('/\p{L}/u', $e['s']) && !preg_match('/[\x{01FA}\x{01F7}\x{0206}\x{024F}]/u', $e['s'])));
    usort($lines, fn($a, $b) => $a['x'] <=> $b['x']);
    $cols = [];
    foreach ($lines as $l) {
        $k = null;
        foreach ($cols as $i => $c) if (abs($c['x'] - $l['x']) <= 4) { $k = $i; break; }
        if ($k === null) { $cols[] = ['x' => $l['x'], 'l' => []]; $k = count($cols) - 1; }
        $cols[$k]['l'][] = $l;
    }
    // bloky: řádky verzálkami = název, pak popis
    $B = [];
    foreach ($cols as $c) {
        $ls = $c['l'];
        usort($ls, fn($a, $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
        $cur = null; $prev = null;
        foreach ($ls as $l) {
            if (preg_match('/^CENA ZA/u', $l['s'])) continue;       // stitek u ceny, ne nazev zbozi
            $up = preg_match('/\p{Lu}{2}/u', $l['s']) && !preg_match('/\p{Ll}{2}/u', $l['s']);
            if ($prev !== null && $prev - $l['y'] > 12) $cur = null;
            if ($up && ($cur === null || $B[$cur]['rest'])) { $B[] = ['x' => $c['x'], 'top' => $l['y'], 'name' => [$l['s']], 'rest' => []]; $cur = count($B) - 1; }
            elseif ($cur !== null) { if ($up) $B[$cur]['name'][] = $l['s']; else $B[$cur]['rest'][] = $l['s']; }
            $prev = $l['y'];
        }
    }
    $unitRe = '/(?<![\d,])(\d+(?:,\d+)?)\s?(kg|g|ml|l|ks)\s*(\d+(?:\s\d{3})*,\d{2})\s*Kč/u';
    $packRe = '/(?:(\d+)\s?[x\x{00d7}]\s?)?(?<![\d,])(\d+(?:,\d+)?)(?:\s?\/\s?(\d+(?:,\d+)?))?\s?(kg|g|ml|l|ks)(?!\p{L})/u';
    $num = fn(string $v) => (float) str_replace(',', '.', preg_replace('/\s/u', '', $v));
    $base = fn(float $v, string $u) => ($u === 'kg' || $u === 'l') ? $v * 1000 : $v;
    $cls = fn(string $u) => ($u === 'kg' || $u === 'g') ? 'g' : ($u === 'ks' ? 'ks' : 'ml');
    $blocks = [];
    foreach ($B as $b) {
        $txt = preg_split('/v nabídce|běžně/iu', implode(' ', $b['rest']))[0];
        $unit = null; $perKg = null;
        if (preg_match('/cena za 1\s?kg\s*(\d+)\s*Kč/iu', $txt, $km)) { $perKg = (int) $km[1]; $txt = str_replace($km[0], ' ', $txt); }
        if (preg_match($unitRe, $txt, $um)) {
            $txt = str_replace($um[0], ' ', $txt);
            $unit = ['a' => $base($num($um[1]), $um[2]), 'c' => $cls($um[2]), 'p' => $num($um[3]), 's' => "$um[1] $um[2] $um[3] Kč"];
        }
        $title = trim(preg_replace('/\s+/u', ' ', str_replace('*', '', implode(' ', $b['name']))));
        $pack = null;
        if (preg_match($packRe, $txt, $m) || preg_match($packRe, implode(' ', $b['name']), $m)) {
            $n = $num($m[3] !== '' ? $m[3] : $m[2]);                  // "680/385 g": cena za jednotku se počítá z pevného podílu
            $pack = ['q' => $base($n, $m[4]) * ($m[1] !== '' ? (int) $m[1] : 1), 'c' => $cls($m[4]), 's' => trim($m[0])];
        } elseif (preg_match('/cena za (1\s?kg|100\s?g|1\s?ks)(?!\s*\d+(?:,\d+)?\s*Kč)/iu', $txt, $m)) $pack = ['q' => 0, 'c' => '', 's' => 'cena za ' . $m[1]];
        elseif (preg_match('/(?<!\p{L})(\d+)\s?(balení|bal\.|páry?|sada|set)(?!\p{L})/u', $txt, $m)) $pack = ['q' => 0, 'c' => '', 's' => $m[0]];
        elseif ($unit || $perKg) $pack = ['q' => 0, 'c' => '', 's' => ''];
        $blocks[] = ['x' => $b['x'], 'top' => $b['top'], 'title' => $title, 'pack' => $pack, 'unit' => $unit, 'perKg' => $perKg];   // i bloky bez balení: brání přiřazení cizí ceny
    }
    $ok = function (array $b, array $p): bool {                       // sedí velká cena s cenou za jednotku?
        if (!$b['unit'] || !$b['pack'] || !$b['pack']['q'] || $b['unit']['c'] !== $b['pack']['c']) return false;
        $exp = $b['unit']['p'] * $b['pack']['q'] / $b['unit']['a'];
        return abs($exp - $p['v']) <= max(0.06, $p['v'] * 0.006);
    };
    $cost = function (array $b, array $p): float {
        $dx = $b['x'] - $p['x']; $dy = $b['top'] - $p['y'];
        if (abs($dx) <= 35 && $dy >= 15 && $dy <= 135) return abs($dx) + $dy * 0.5;                              // název nad cenou
        if ($dx >= -175 && $dx <= -40 && $dy >= -25 && $dy <= 50) return 40 + abs($dx) * 0.3 + abs($dy - 15);   // název vlevo od ceny
        if ($dx >= -130 && $dx <= -55 && $dy > 50 && $dy <= 135) return 90 + abs($dx + 85) * 0.3 + abs($dy - 95) * 0.3;   // název v levém horním rohu, cena vpravo dole
        return INF;
    };
    $usedP = []; $usedB = []; $res = []; $cand = [];
    foreach ($blocks as $i => $b) foreach ($P as $j => $p) {
        $dx = $b['x'] - $p['x']; $dy = $b['top'] - $p['y'];
        if (abs($dx) <= 180 && $dy >= -60 && $dy <= 160 && $ok($b, $p)) { $c = $cost($b, $p); $cand[] = [$c < INF ? $c : 100 + hypot($dx, $dy), $i, $j]; }   // stejna cena u vic dlazdic: rozhodne poloha
    }
    usort($cand, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($cand as [, $i, $j]) { if (isset($usedB[$i]) || isset($usedP[$j])) continue; $usedB[$i] = $usedP[$j] = true; $res[] = [$i, $j]; }
    $best = function (array $list, array $skip, callable $f): int {
        $bi = -1; $bc = INF;
        foreach ($list as $k => $a) { if (isset($skip[$k])) continue; $c = $f($a); if ($c < $bc) { $bc = $c; $bi = $k; } }
        return $bi;
    };
    do {
        $again = false;
        foreach ($blocks as $i => $b) {
            if (isset($usedB[$i]) || $b['unit'] || !$b['pack']) continue;
            $j = $best($P, $usedP, fn($p) => $cost($b, $p));
            if ($j < 0 || $best($blocks, $usedB, fn($bb) => $cost($bb, $P[$j])) !== $i) continue;
            $usedB[$i] = $usedP[$j] = true; $res[] = [$i, $j]; $again = true;
        }
    } while ($again);

    $badges = [];                                                     // stitky "Platnost: 25.11.–1.12." nad casti stranky
    foreach ($E as $e) {
        if (!preg_match('/^(\d{1,2})\.\s?(\d{1,2})\.\s?[–-]\s?(\d{1,2})\.\s?(\d{1,2})\.$/u', $e['s'], $m)) continue;
        foreach ($E as $f) if (stripos($f['s'], 'Platnost') === 0 && abs($f['y'] - $e['y']) < 3 && abs($f['x'] - $e['x']) < 80) { $badges[] = ['y' => $e['y'], 'd' => [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]]]; break; }
    }
    $out = [];
    foreach ($res as [$i, $j]) {
        $b = $blocks[$i]; $p = $P[$j];
        $range = null; $ry = INF;                                      // nejblizsi stitek nad cenou
        foreach ($badges as $g) if ($g['y'] > $p['y'] && $g['y'] < $ry) { $ry = $g['y']; $range = $g['d']; }
        $note = null;                                                 // "cena bez penny karty" pod velkou cenou
        foreach ($E as $e) if (preg_match('/bez ?penny/iu', $e['s']) && abs($e['x'] - $p['x']) < 25 && $e['y'] <= $p['y'] + 3 && $e['y'] >= $p['y'] - 14) { $note = $e; break; }
        $badge = null; $bd = INF;                                     // kolečko se slevou "NN %"
        foreach ($E as $e) {
            if ($e['sz'] < 10 || !preg_match('/^\d{2}$/', $e['s'])) continue;
            $dx = $e['x'] - $p['x']; $dy = $e['y'] - $p['y'];
            if ($dx < -10 || $dx > 75 || $dy < 15 || $dy > 60) continue;
            $pct = false;
            foreach ($E as $f) if (strpos($f['s'], '%') !== false && abs($f['y'] - $e['y']) < 8 && $f['x'] > $e['x'] && $f['x'] - $e['x'] < 45) { $pct = true; break; }
            if (!$pct) continue;
            $d = hypot($dx - 20, $dy - 33);
            if ($d < $bd) { $bd = $d; $badge = (int) $e['s']; }
        }
        $dg = null;                                                   // celé koruny původní ceny / ceny bez karty
        foreach ($E as $e) {
            if (!preg_match('/^\d{1,4}$/', $e['s'])) continue;
            $hit = $note
                ? $e['sz'] < 9 && abs($e['x'] - $note['x']) < 30 && $e['y'] < $note['y'] - 2 && $e['y'] > $note['y'] - 13
                : $e['sz'] < 10 && $e['x'] >= $p['x'] - 6 && $e['x'] <= $p['x'] + 55 && $e['y'] <= $p['y'] + 1 && $e['y'] >= $p['y'] - 9;
            if ($hit) { $dg = (int) $e['s']; break; }
        }
        $other = null;                                                // haléře se berou jen takové, které sedí se slevou v kolečku
        if ($dg !== null && $badge !== null) {
            foreach ([0.9, 0.5, 0.0] as $f) { $o = round($dg + $f, 2); if ($o > $p['v'] && (int) floor((1 - $p['v'] / $o) * 100 + 1e-9) === $badge) { $other = $o; break; } }
            if ($other === null) foreach ([0.9, 0.5, 0.0] as $f) { $o = round($dg + $f, 2); if ($o > $p['v'] && abs((1 - $p['v'] / $o) * 100 - $badge) <= 1) { $other = $o; break; } }
        }
        if ($note && $other === null) continue;                       // cena jen s kartou bez ověřené ceny bez karty: vynechat
        if (mb_strlen($b['title']) < 3 || $p['v'] < 1) continue;
        $out[] = [
            'title' => $b['title'], 'range' => $range,
            'grams' => $b['perKg'] && abs($p['v'] * 10 - $b['perKg']) <= 1 ? '100 g' : ($b['pack']['s'] ?? ''),
            'price' => $note ? $other : $p['v'], 'cardPrice' => $note ? $p['v'] : null, 'oldPrice' => $note ? null : $other,
            'discount' => $other !== null ? $badge : null, 'basePrice' => $b['unit']['s'] ?? null,
        ];
    }
    return $out;
}

/** Platnost uvedená v záhlaví stránky ("od pátku 27. 11. do neděle 29. 11. 2026"); jen když je na stránce právě jedna. */
function penny_page_validity(string $svg): ?array {
    $text = html_entity_decode(strip_tags(str_replace('</svg:text>', "\n", $svg)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    if (!preg_match_all('/od\s+\p{L}+\s+(\d{1,2})\.\s*(\d{1,2})\.\s*do\s+\p{L}+\s+(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/iu', $text, $mm, PREG_SET_ORDER)) return null;
    $set = [];
    foreach ($mm as $m) {
        $yFrom = (int) $m[2] > (int) $m[4] ? (int) $m[5] - 1 : (int) $m[5];
        $r = [sprintf('%04d-%02d-%02d', $yFrom, $m[2], $m[1]), sprintf('%04d-%02d-%02d', $m[5], $m[4], $m[3])];
        $set[implode('|', $r)] = $r;
    }
    return count($set) === 1 ? reset($set) : null;
}

/** Penny leták, záložní čtení (když se nepoznají velké ceny): z bloků "název / gramáž / cena za 100 g" cenu dopočítá.
 *  Položky bez ověřitelné ceny se vynechají. */
function parse_penny_svg(string $svg): array {
    $cells = [];   // [x, y, text]
    if (!preg_match_all('#<svg:text\s+transform="matrix\(([-\d.]+) 0 0 ([-\d.]+) ([-\d.]+) ([-\d.]+)\)[^"]*"[^>]*>(.*?)</svg:text>#s', $svg, $tm, PREG_SET_ORDER)) return [];
    foreach ($tm as $t) {
        $sx = (float) $t[1]; $sy = (float) $t[2];
        if ($sy > 9 || $sy < 3.5) continue;                         // jen běžné popisky (ne nadpisy)
        if (!preg_match_all('#<svg:tspan([^>]*)>(.*?)</svg:tspan>#s', $t[5], $ts, PREG_SET_ORDER)) continue;
        foreach ($ts as $q) {
            if (strpos($q[1], 'rgb(255,45,22)') !== false) continue;  // velké červené ceny se nečtou
            if (!preg_match('/\bx="([^"]+)"/', $q[1], $xm)) continue;
            $xs = preg_split('/\s+/', trim($xm[1]));
            $raw = html_entity_decode(strip_tags($q[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);
            // jeden tspan může nést víc produktů vedle sebe: rozdělit tam, kde x skočí (mezera mezi znaky > 4)
            $segs = [[(float) $xs[0], $raw]];
            if (count($chars) === count($xs)) {
                $segs = []; $cur = ''; $start = (float) $xs[0];
                foreach ($chars as $i => $ch) {
                    if ($i > 0 && (float) $xs[$i] - (float) $xs[$i - 1] > 4) { $segs[] = [$start, $cur]; $cur = ''; $start = (float) $xs[$i]; }
                    $cur .= $ch;
                }
                $segs[] = [$start, $cur];
            }
            foreach ($segs as [$x0, $str]) {
                $txt = trim(preg_replace('/\s+/u', ' ', $str));
                if ($txt === '' || strpos($txt, 'Ǻ') !== false || preg_match('/^[\d\s%,.*+<>-]+$/u', $txt) || $txt === '<') continue;
                // pozice sloupce: jednotný násobek 5.4, aby měl produkt stejný začátek v názvu i v řádku s cenou
                $cells[] = [round((float) $t[3] + 5.4 * $x0), (float) $t[4], $txt];
            }
        }
    }
    // sloupce podle x (tolerance 4 px)
    usort($cells, fn($a, $b) => $a[0] <=> $b[0]);
    $cols = [];
    foreach ($cells as $c) {
        $k = null;
        foreach ($cols as $key => $col) if (abs($key - $c[0]) <= 4) { $k = $key; break; }
        if ($k === null) { $k = $c[0]; $cols[$k] = []; }
        $cols[$k][] = $c;
    }
    $out = []; $blocks = []; $units = [];
    // cena z ceny za jednotku a gramáže; null, pokud nesedí jednotky nebo cena nedává smysl
    $mk = function (array $name, array $grams, array $u): ?array {
        [$amt, $unit, $perStr] = $u;
        $per = (float) str_replace(',', '.', str_replace(' ', '', $perStr));
        $perAmt = (float) str_replace(',', '.', $amt);
        if ($unit === 'kg' || $unit === 'l') $perAmt *= 1000;
        if ($perAmt <= 0 || $grams[1] !== (($unit === 'kg' || $unit === 'g') ? 'g' : 'ml')) return null;
        $price = $per * $grams[0] / $perAmt;
        $r1 = round($price, 1);
        $price = abs($price - $r1) < 0.06 ? $r1 : round($price, 2);
        if ($price < 2 || $price > 2000) return null;
        if (abs($price * 10 - round($price * 10)) > 0.001 || !in_array((int) round($price * 10) % 10, [0, 5, 9], true)) return null;   // neobvykla koncovka = spatne sparovana nebo nepresna cena
        $title = trim(preg_replace('/\s+/u', ' ', implode(' ', $name)));
        if (!preg_match('/^[^\p{Ll}]{3}/u', $title)) return null;                          // utrzek nazvu (nazvy v letaku jsou verzalkami)
        if (preg_match('/(?<![\p{L}\d])\d+\s?[x\x{00d7}](?![\p{L}\d])/u', $title)) return null;   // vicebaleni "6x": cena za cele baleni se neda overit
        return ['title' => $title, 'grams' => $grams[0] . ' ' . $grams[1], 'price' => $price, 'basePrice' => $amt . ' ' . $unit . ' ' . $perStr . ' Kč'];
    };
    foreach ($cols as $col) {
        usort($col, fn($a, $b) => $b[1] <=> $a[1]);                  // shora dolů (Y klesá)
        $name = []; $grams = null; $wy = null; $prevY = null; $cx = $col[0][0];
        $flush = function () use (&$name, &$grams, &$wy, &$blocks, &$cx) { if ($name && $grams) $blocks[] = [$cx, $wy, $name, $grams]; $name = []; $grams = null; $wy = null; };
        foreach ($col as [$x, $y, $txt]) {
            if ($prevY !== null && $prevY - $y > 14) $flush();        // velká mezera = nový produkt
            $name = $name ?: []; $prevY = $y;
            // řádek s cenou za jednotku: "100 g 10,64 Kč" / "1 kg 99,90 Kč" / "100 ml ..."
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s?(g|kg|ml|l)\s*(\d+(?:\s?\d{3})*,\d{2})\s*Kč/u', $txt, $m)) {
                $u = [$m[1], $m[2], $m[3]];
                if ($name && $grams) { $o = $mk($name, $grams, $u); if ($o) $out[] = $o; $name = []; $grams = null; $wy = null; }
                else $units[] = [$x, $y, $u];                         // název je v jiném sloupci: spáruje se podle pořadí v řádku
                continue;
            }
            if (preg_match('/^\d+(?:\s?\d{3})*,\d{2}\s*Kč$/u', $txt) || preg_match('/^\d+,?\s*Kč?$/u', $txt)) continue;   // 30denní minimum
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s?(g|kg|ml|l)\s*\|?$/u', $txt, $m)) {
                $v = (float) str_replace(',', '.', $m[1]);
                $grams = in_array($m[2], ['kg', 'l']) ? [$v * 1000, $m[2] === 'kg' ? 'g' : 'ml'] : [$v, $m[2]]; $wy = $y;
                continue;
            }
            if (preg_match('/^(.*[^\d,.\s])\s*(\d+(?:[.,]\d+)?)\s?(g|kg|ml|l)\s*$/u', $txt, $m) && mb_strlen($m[1]) > 2) {   // "jemně perlivá 0,5 l"
                if ($grams) $flush();
                $v = (float) str_replace(',', '.', $m[2]);
                $grams = in_array($m[3], ['kg', 'l']) ? [$v * 1000, $m[3] === 'kg' ? 'g' : 'ml'] : [$v, $m[3]]; $wy = $y;
                $name[] = trim($m[1], " |");
                continue;
            }
            if ($grams) $flush();                                     // nový název po gramáži = další produkt
            $name[] = $txt;
        }
        $flush();
    }
    // zbylé řádky s cenou za jednotku: ke každému řádku (stejné Y) se podle pořadí zleva doprava přiřadí nespárované názvy těsně nad ním, jen když sedí počty
    usort($units, fn($a, $b) => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);
    $rows = [];
    foreach ($units as $u) {
        $last = count($rows) - 1;
        if ($last >= 0 && abs($rows[$last][0][1] - $u[1]) <= 2) $rows[$last][] = $u; else $rows[] = [$u];
    }
    foreach ($rows as $row) {
        usort($row, fn($a, $b) => $a[0] <=> $b[0]);
        $ry = $row[0][1];
        $cand = array_values(array_filter($blocks, fn($b) => $b[1] !== null && $b[1] - $ry > 0 && $b[1] - $ry <= 16));
        if (count($cand) !== count($row)) continue;
        usort($cand, fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($row as $i => $u) {
            $dx = $u[0] - $cand[$i][0];
            if ($dx < -4 || $dx > 75) continue;                       // cena za jednotku je daleko od nazvu: patri jinemu zbozi
            $o = $mk($cand[$i][2], $cand[$i][3], $u[2]); if ($o) $out[] = $o;
        }
    }
    return $out;
}

/** Adresy týdenních letáků v HTML. Koncovku složky Penny mění (_ad, _kvod, ...), proto se bere jakákoli;
 *  katalogy (_katalog_...) mají jinou dobu platnosti, ty se vynechávají. */
function penny_leaflet_dirs(string $html): array {
    preg_match_all('#PennyIntLeaflet(?:/|\\\\u002F)CZ(?:/|\\\\u002F)(\d{2})_(\d{2})_(\d{4})_([A-Za-z0-9_-]+)#', $html, $lm, PREG_SET_ORDER);
    $dirs = [];
    foreach ($lm as $m) {
        if (stripos($m[4], 'katalog') !== false) continue;
        $dirs["files.rewe.co.at/PennyIntLeaflet/CZ/$m[1]_$m[2]_$m[3]_$m[4]"] = [$m[1], $m[2], $m[3]];
    }
    return $dirs;
}

function fetch_penny(): array {
    $html = http_get('https://www.penny.cz/nabidky');
    $items = parse_penny($html);                                        // akce z webu (základ)
    // datum platnosti: nejčastější z dlaždic
    $vf = []; $vt = [];
    foreach ($items as $it) { if ($it['validFrom']) $vf[] = $it['validFrom']; if ($it['validTo']) $vt[] = $it['validTo']; }
    $mode = function (array $a) { if (!$a) return null; $c = array_count_values($a); arsort($c); return array_key_first($c); };
    $validFrom = $mode($vf); $validTo = $mode($vt);
    $seen = [];
    foreach ($items as $it) $seen[mb_strtolower(preg_replace('/\W+/u', '', $it['title']))] = true;   // titulky z webu (bez gramáže)

    $dirs = penny_leaflet_dirs($html);
    foreach ($dirs as $path => [$d, $mo, $y]) {
        $from = "$y-$mo-$d";
        $to = ($from === $validFrom && $validTo) ? $validTo : date('Y-m-d', strtotime("$from +6 days"));
        $miss = 0; $found = []; $backup = [];
        for ($p = 1; $p <= 60; $p++) {
            try { $svg = http_get('https://' . $path . '/files/assets/common/page-vectorlayers/' . sprintf('%04d', $p) . '.svg'); }
            catch (Throwable $e) { if (++$miss >= 3) break; continue; }   // stranka jen jako obrazek chybi; konec letaku = 3 chybejici po sobe
            $miss = 0;
            $header = penny_page_validity($svg);
            [$pf, $pt] = $header ?? [$from, $to];
            foreach (parse_penny_page($svg) as $o) {
                [$o['from'], $o['to']] = [$pf, $pt];
                if ($header && $o['range']) {                            // cast stranky ma vlastni platnost
                    [$d1, $m1, $d2, $m2] = $o['range']; $yr = (int) substr($pf, 0, 4);
                    $o['from'] = sprintf('%04d-%02d-%02d', $yr, $m1, $d1);
                    $o['to'] = sprintf('%04d-%02d-%02d', $m2 < $m1 ? $yr + 1 : $yr, $m2, $d2);
                }
                $k = mb_strtolower(preg_replace('/\W+/u', '', $o['title'] . $o['grams'])) . '|' . $o['price'];
                // stejna nabidka na vic stranach (titulka a vikendova strana): plati kratsi platnost
                if (!isset($found[$k]) || strtotime($o['to']) - strtotime($o['from']) < strtotime($found[$k]['to']) - strtotime($found[$k]['from'])) $found[$k] = $o;
            }
            foreach (parse_penny_svg($svg) as $o) $backup[] = $o + ['cardPrice' => null, 'oldPrice' => null, 'discount' => null, 'from' => $pf, 'to' => $pt];
        }
        if (count($found) < 100) {                                      // velke ceny se nepodarilo precist (jiny vzhled letaku)
            foreach ($backup as $o) {
                $k = mb_strtolower(preg_replace('/\W+/u', '', $o['title'] . $o['grams'])) . '|' . $o['price'];
                if (!isset($found[$k])) $found[$k] = $o;
            }
        }
        foreach ($found as $key => $o) {
            if (isset($seen[$key]) || isset($seen[mb_strtolower(preg_replace('/\W+/u', '', $o['title']))])) continue;   // uz je z webu
            $seen[$key] = true;
            $items[] = [
                'chain' => 'penny', 'id' => 'penny:l:' . md5($o['title'] . '|' . $o['grams'] . '|' . $o['price'] . '|' . $o['from']),
                'title' => $o['title'], 'subtitle' => $o['grams'], 'category' => null,
                'price' => $o['price'], 'oldPrice' => $o['oldPrice'], 'cardPrice' => $o['cardPrice'], 'discount' => $o['discount'],
                'unit' => $o['grams'], 'basePrice' => $o['basePrice'],
                'validFrom' => $o['from'], 'validTo' => $o['to'], 'img' => null,
            ];
        }
    }
    return $items;
}

/** Albert: leták (Publitas) - text stránek ze spreads.json. Produkty se váží na "• 520 g • 1 kg 575 Kč": cena = gramáž x cena za jednotku.
 *  Položky, u kterých se cena nedá takto ověřit, se vynechají (špatná cena je horší než chybějící). */
function parse_albert_text(string $text): array {
    $lines = array_values(array_filter(array_map(fn($l) => trim(str_replace("\u{00a0}", ' ', $l)), preg_split('/\R+/u', $text)), fn($l) => $l !== ''));
    $unitRe = '/(1\s?kg|1\s?l|100\s?g|100\s?ml)\s*=?\s*(\d+(?:\s?\d{3})*(?:,\d{1,2})?)\s*Kč/u';
    $junk = fn(string $l) => (bool) preg_match('/^[\d\s,.\-–\/%]+$/u', $l) || preg_match('/^\d+\s?bal\.?$/u', $l) || stripos($l, 'NEPORA') !== false || preg_match('/^-?\d+\s?%$/u', $l) || preg_match('/^(od|do)\s/iu', $l) && mb_strlen($l) < 14;
    $out = []; $prevUnit = -1;
    foreach ($lines as $i => $line) {
        if (!preg_match($unitRe, $line, $um, PREG_OFFSET_CAPTURE)) continue;
        $run = [$line];
        for ($j = $i - 1; $j > $prevUnit && $j >= 0 && count($run) < 7; $j--) {
            if ($junk($lines[$j])) break;
            array_unshift($run, $lines[$j]);
        }
        $prevUnit = $i;
        $joined = implode(' ', $run);
        if (preg_match('/\d\s*[–-]\s*\d+(?:,\d+)?\s*(g|kg|ml|l)\b/u', $joined)) continue;   // rozsah gramáží, cena nejde určit
        // jednotka ceny pryč, zbytek hledá gramáž balení
        $rest = preg_replace($unitRe, ' ', $joined);
        if (!preg_match_all('/(\d+(?:,\d+)?)\s*(g|kg|ml|l)\b/u', $rest, $wm, PREG_SET_ORDER)) continue;
        $w = end($wm);
        $wv = (float) str_replace(',', '.', $w[1]);
        $wUnit = $w[2];
        $wBase = in_array($wUnit, ['kg', 'l']) ? $wv * 1000 : $wv;
        $wCls = ($wUnit === 'kg' || $wUnit === 'g') ? 'g' : 'ml';
        $uTok = preg_replace('/\s+/', '', $um[1][0]);
        $uAmt = in_array($uTok, ['1kg', '1l']) ? 1000 : (float) preg_replace('/\D/', '', $uTok);
        $uCls = in_array($uTok, ['1kg', '100g']) ? 'g' : 'ml';
        if ($wCls !== $uCls) continue;
        $per = (float) str_replace(',', '.', str_replace(' ', '', $um[2][0]));
        $price = $per * $wBase / $uAmt;
        $r1 = round($price, 1);
        $price = abs($price - $r1) < 0.06 ? $r1 : round($price, 2);
        if ($price < 2 || $price > 3000) continue;
        $tenth = (int) round($price * 10) % 10;
        if (!in_array($tenth, [0, 5, 9], true) || abs($price * 10 - round($price * 10)) > 0.3) continue;   // běžné koncovky cen (,90 ,50 ,-); jinak výpočet nesedí
        $name = array_values(array_filter($run, fn($l) => strpos($l, '•') !== 0 && !preg_match($unitRe, $l)));
        $name = array_slice($name, -3);
        $title = trim(preg_replace('/\s+/u', ' ', implode(' ', $name)));
        $title = trim(preg_replace('/^(JIŽ\s+)?OD\s+\d{1,2}\.\s?\d{1,2}\.(\s?\d{4})?\s*/iu', '', $title));
        $title = trim(preg_replace('/^(NOVINKA|NOVÉ|AKCE)\s+/u', '', $title));
        if (!preg_match('/\p{L}{3}/u', $title)) continue;
        $out[] = ['title' => $title, 'grams' => ($wBase >= 1000 && $wCls === 'g' ? ($wBase / 1000) . ' kg' : ($wBase >= 1000 ? ($wBase / 1000) . ' l' : $wBase . ' ' . $wCls)), 'price' => $price, 'basePrice' => ['1kg' => '1 kg', '1l' => '1 l', '100g' => '100 g', '100ml' => '100 ml'][$uTok] . ' ' . $um[2][0] . ' Kč'];
    }
    return $out;
}

function fetch_albert(): array {
    $html = http_get('https://www.albert.cz/aktualni-letaky');
    preg_match_all('#letaky\.albert\.cz/(\d{2}(?:sm|hm)_akcni_letak)#', $html, $m);
    $pubs = array_values(array_unique($m[1]));
    // stránka s letáky někdy zaostává: zkusit i čísla týdnů kolem dneška (týden letáku = ISO týden jeho začátku)
    $w = (int) date('W');
    foreach ([$w - 1, $w, $w + 1] as $wk) {
        if ($wk < 1 || $wk > 53) continue;
        foreach (['sm', 'hm'] as $f) {
            $slug = sprintf('%02d%s_akcni_letak', $wk, $f);
            if (in_array($slug, $pubs, true)) continue;
            try { http_get("https://letaky.albert.cz/$slug/spreads.json"); $pubs[] = $slug; } catch (Throwable $e) {}
        }
    }
    if (!$pubs) throw new RuntimeException('letaky Albert nenalezeny');
    $out = []; $seen = [];
    foreach ($pubs as $pub) {
        $json = json_decode(http_get("https://letaky.albert.cz/$pub/spreads.json"), true);
        if (!is_array($json)) continue;
        $from = null; $to = null; $skip = false;
        foreach ($json as $sp) foreach ($sp['pages'] ?? [] as $pg) {
            if ($skip) break 2;
            $t = (string) ($pg['text'] ?? '');
            if ($from === null && preg_match('/Od\s+(\d{1,2})\.\s*(\d{1,2})\.\s*do\s+(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $t, $d)) {
                $y2 = (int) $d[5]; $y1 = ((int) $d[2] > (int) $d[4]) ? $y2 - 1 : $y2;
                $from = sprintf('%04d-%02d-%02d', $y1, $d[2], $d[1]); $to = sprintf('%04d-%02d-%02d', $y2, $d[4], $d[3]);
                if ($to < date('Y-m-d')) { $skip = true; break; }   // starý leták
            }
            foreach (parse_albert_text($t) as $o) {
                $key = mb_strtolower(preg_replace('/\W+/u', '', $o['title'] . $o['grams'])) . '|' . $o['price'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = [
                    'chain' => 'albert', 'id' => 'albert:' . md5($pub . '|' . $o['title'] . '|' . $o['grams']),
                    'title' => $o['title'], 'subtitle' => $o['grams'], 'category' => null,
                    'price' => $o['price'], 'oldPrice' => null, 'cardPrice' => null, 'discount' => null,
                    'unit' => $o['grams'], 'basePrice' => $o['basePrice'],
                    'validFrom' => $from, 'validTo' => $to, 'img' => null,
                ];
            }
        }
    }
    return $out;
}

/** Norma: leták je PDF (text ve vrstvě je zdvojený a ceny jsou oddělené od názvů). Cena = gramáž x cena za jednotku "(100 g 23,57)"; neověřitelné položky se vynechají. */
function norma_undouble(string $l): string {
    $l = trim(preg_replace('/[ \t\x{00a0}]+/u', ' ', $l));
    if ($l === '') return '';
    $t = explode(' ', $l);
    $n = count($t);
    if ($n % 2 === 0 && $n >= 2 && array_slice($t, 0, $n / 2) === array_slice($t, $n / 2)) return implode(' ', array_slice($t, 0, $n / 2));
    $len = mb_strlen($l);
    if ($len % 2 === 0 && $len >= 4 && mb_substr($l, 0, $len / 2) === mb_substr($l, $len / 2)) return trim(mb_substr($l, 0, $len / 2));
    return $l;
}
function parse_norma_text(string $text): array {
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $l) { $l = norma_undouble($l); if ($l !== '') $lines[] = $l; }
    $junk = fn(string $l) => (bool) preg_match('/^[\d\s,.\-–\/%*]+$/u', $l) || stripos($l, 'super cena') !== false || stripos($l, 'cena za provedení') !== false || stripos($l, 'ilustrační') !== false || stripos($l, 'cena za 1') !== false;
    // spojený text s ukazateli řádků, aby šlo najít jednotku i přes zalomení
    $joined = ''; $starts = [];
    foreach ($lines as $i => $l) { $starts[$i] = strlen($joined); $joined .= $l . "\n"; }
    $re = '/•\s*(\d+(?:,\d+)?)\s*(g|kg|ml|l)\b[^\n(]*\n?[^(\n]*\(\s*(100\s?g|1\s?kg|1\s?l|100\s?ml)\s*\n?\s*(\d+(?:\s?\d{3})*,\d{2})\s*\)/u';
    $priceLines = [];
    foreach ($lines as $i => $l) if (preg_match('/^(\d{1,4}),(\d{2})$/', $l, $pm)) $priceLines[$i] = (float) ($pm[1] . '.' . $pm[2]);
    $out = [];
    if (!preg_match_all($re, $joined, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
    foreach ($mm as $m) {
        $off = $m[0][1];
        $li = 0; foreach ($starts as $i => $st) { if ($st <= $off) $li = $i; else break; }
        $name = [];
        for ($j = $li - 1; $j >= 0 && count($name) < 4; $j--) {
            $l = $lines[$j];
            if ($junk($l) || strpos($l, '•') === 0) break;
            array_unshift($name, $l);
        }
        $title = trim(preg_replace('/\s+/u', ' ', implode(' ', $name)));
        if (!preg_match('/\p{L}{3}/u', $title)) continue;
        $wv = (float) str_replace(',', '.', $m[1][0]); $wu = $m[2][0];
        $wBase = in_array($wu, ['kg', 'l']) ? $wv * 1000 : $wv;
        $wCls = ($wu === 'kg' || $wu === 'g') ? 'g' : 'ml';
        $uTok = preg_replace('/\s+/', '', $m[3][0]);
        $uAmt = in_array($uTok, ['1kg', '1l']) ? 1000 : (float) preg_replace('/\D/', '', $uTok);
        $uCls = in_array($uTok, ['1kg', '100g']) ? 'g' : 'ml';
        if ($wCls !== $uCls) continue;
        $per = (float) str_replace(',', '.', str_replace(' ', '', $m[4][0]));
        $est = $per * $wBase / $uAmt;
        $tol = 0.005 * $wBase / $uAmt + 0.03;
        $price = null; $bestD = PHP_INT_MAX;
        foreach ($priceLines as $pi => $pv) {
            if (abs($pv - $est) <= $tol && abs($pi - $li) < $bestD) { $bestD = abs($pi - $li); $price = $pv; }
        }
        if ($price === null || $price < 2 || $price > 3000) continue;
        $out[] = ['title' => $title, 'grams' => ($wBase >= 1000 ? ($wBase / 1000) . ($wCls === 'g' ? ' kg' : ' l') : $wBase . ' ' . $wCls), 'price' => $price,
                  'basePrice' => ['1kg' => '1 kg', '1l' => '1 l', '100g' => '100 g', '100ml' => '100 ml'][$uTok] . ' ' . $m[4][0] . ' Kč'];
    }
    return $out;
}

function fetch_norma(): array {
    $parserDir = __DIR__ . '/pdfparser/alt_autoload.php';
    if (!is_file($parserDir)) throw new RuntimeException('chybi pdfparser');
    require_once $parserDir;
    $out = []; $seen = [];
    $today = new DateTime('today');
    foreach ([0, 7] as $add) {
        $d = (clone $today)->modify("+$add days");
        $year = (int) $d->format('o'); $wk = (int) $d->format('W');
        $url = sprintf('https://www.norma-online.de/cz/angebote/online-prospekt/%d-%02d_CZ.pdf', $year, $wk);
        $tmp = tempnam(sys_get_temp_dir(), 'norma');
        try {
            $fp = fopen($tmp, 'w');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 110, CURLOPT_USERAGENT => UA]);
            curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
            if ($code !== 200 || filesize($tmp) < 100000) continue;     // tento týden ještě není
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($tmp);
            $from = (clone $d)->setISODate($year, $wk, 1)->format('Y-m-d');
            $to = (clone $d)->setISODate($year, $wk, 7)->format('Y-m-d');
            foreach ($pdf->getPages() as $pg) {
                foreach (parse_norma_text($pg->getText()) as $o) {
                    $key = mb_strtolower(preg_replace('/\W+/u', '', $o['title'] . $o['grams'])) . "|$wk";
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $out[] = [
                        'chain' => 'norma', 'id' => 'norma:' . md5("$wk|" . $o['title'] . '|' . $o['grams']),
                        'title' => $o['title'], 'subtitle' => $o['grams'], 'category' => null,
                        'price' => $o['price'], 'oldPrice' => null, 'cardPrice' => null, 'discount' => null,
                        'unit' => $o['grams'], 'basePrice' => $o['basePrice'],
                        'validFrom' => $from, 'validTo' => $to, 'img' => null,
                    ];
                }
            }
        } finally { @unlink($tmp); }
    }
    return $out;
}

/** Coop: letáky skupiny COOP (PDF s textem). Řádek "120 g | 1 kg 290,83" nebo "1 kg 189,– | pultový prodej"; cena se ověří proti samostatným cenám "18,\n90" na stránce. */
function parse_coop_text(string $text): array {
    $lines = [];
    foreach (preg_split('/\R/u', $text) as $l) { $l = trim(preg_replace('/[ \t\x{00a0}]+/u', ' ', $l)); if ($l !== '') $lines[] = $l; }
    // gramáž a "| 1 kg ..." bývají na dvou řádcích
    for ($i = 0; $i + 1 < count($lines); $i++) {
        if (preg_match('/^\d+(?:,\d+)?(?:\s?×\s?\d+(?:,\d+)?)?\s?(g|kg|ml|l|ks|m)$/u', $lines[$i]) && str_starts_with($lines[$i + 1], '|')) { $lines[$i] .= ' ' . $lines[$i + 1]; array_splice($lines, $i + 1, 1); }
    }
    // ceny rozlomené na dva řádky: "18," + "90"
    $priceAt = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match('/^(\d{1,4}),$/', $lines[$i], $m) && $i + 1 < $n && preg_match('/^(\d{2})$/', $lines[$i + 1], $m2)) $priceAt[$i] = (float) ($m[1] . '.' . $m2[1]);
        elseif (preg_match('/^(\d{1,4}),(\d{2})$/', $lines[$i], $m)) $priceAt[$i] = (float) ($m[1] . '.' . $m[2]);
    }
    $unitRe = '/^(?:(\d+(?:,\d+)?(?:\s?×\s?\d+(?:,\d+)?)?)\s*(g|kg|ml|l|ks|m|cm)\s*\|\s*)?(1\s?kg|100\s?g|1\s?l|100\s?ml|1\s?ks|1\s?m)\s*=?\s*(\d+(?:\s?\d{3})*),(\d{2}|–)(?:\s*Kč)?(?:\s*\|.*)?$/u';
    $out = []; $prev = -1;
    foreach ($lines as $i => $line) {
        if (!preg_match($unitRe, $line, $m)) continue;
        $name = [];
        for ($j = $i - 1; $j > $prev && count($name) < 5; $j--) {
            if (isset($priceAt[$j]) || preg_match('/^\d{1,2}$/', $lines[$j]) || preg_match('/^(\d{1,4}),$/', $lines[$j])) continue;
            if (preg_match('/^(\d+\s?(g|kg|ml|l)\s*)?\|?\s*(1\s?kg|1\s?l|100\s?g)\s*=/u', $lines[$j]) || preg_match('/^100 g$/u', $lines[$j])) break;
            if (mb_strlen($lines[$j]) > 50 || preg_match('/www\.|\||coopclub|\d\s?(g|kg|ml|l)\s+\d/iu', $lines[$j])) break;   // věta z patičky, jiný řádek s jednotkami
            array_unshift($name, $lines[$j]);
        }
        $prev = $i;
        // řádky s dodavatelem (VELKÝMI, víceslovné nebo s čárkou/pomlčkou) na začátku bloku zahodit; jednoslovné značky (RANKO, OLMA) zůstanou
        while (count($name) > 1 && !preg_match('/\p{Ll}/u', $name[0]) && (count(preg_split('/[\s,\-]+/u', trim($name[0]), -1, PREG_SPLIT_NO_EMPTY))>= 2 || preg_match('/[,\-.]/u', $name[0]))) array_shift($name);
        $title = trim(preg_replace('/\s+/u', ' ', implode(' ', $name)));
        if (!preg_match('/\p{L}{3}/u', $title) || stripos($title, 'COOP XXL') !== false && mb_strlen($title) < 20) continue;
        $per = (float) (str_replace(' ', '', $m[4]) . '.' . ($m[5] === '–' ? '00' : $m[5]));
        $uTok = preg_replace('/\s+/', '', $m[3]);
        $uAmt = ['1kg' => 1000, '1l' => 1000, '100g' => 100, '100ml' => 100, '1ks' => 1, '1m' => 1][$uTok];
        $uCls = ['1kg' => 'g', '100g' => 'g', '1l' => 'ml', '100ml' => 'ml', '1ks' => 'ks', '1m' => 'm'][$uTok];
        if ($m[1] === '' ) {                       // jen cena za jednotku (váženo / kus): cena = cena za 1 kg/l
            if (!in_array($uTok, ['1kg', '1l'], true)) continue;
            $price = $per; $grams = $uTok === '1kg' ? '1 kg' : '1 l'; $est = null;
        } else {
            $q = 1.0; $wtxt = str_replace(',', '.', $m[1]);
            if (str_contains($wtxt, '×')) { [$a, $b] = array_map('floatval', preg_split('/\s?×\s?/u', $wtxt)); $q = $a * $b; } else $q = (float) $wtxt;
            $wu = $m[2];
            $base = ['g' => 1, 'kg' => 1000, 'ml' => 1, 'l' => 1000, 'ks' => 1, 'm' => 1, 'cm' => 0.01][$wu] * $q;
            $wCls = ['g' => 'g', 'kg' => 'g', 'ml' => 'ml', 'l' => 'ml', 'ks' => 'ks', 'm' => 'm', 'cm' => 'm'][$wu];
            if ($wCls !== $uCls) continue;
            $est = $per * $base / $uAmt;
            $tol = 0.005 * $base / $uAmt + 0.03;
            $price = null; $bestD = PHP_INT_MAX;
            foreach ($priceAt as $pi => $pv) if (abs($pv - $est) <= $tol && abs($pi - $i) < $bestD) { $bestD = abs($pi - $i); $price = $pv; }
            if ($price === null || abs($pi ?? 0) > 1e9) { if ($price === null) continue; }
            $grams = trim($m[1] . ' ' . $m[2]);
        }
        if ($price < 2 || $price > 3000) continue;
        $out[] = ['title' => $title, 'grams' => $grams, 'price' => $price, 'basePrice' => preg_replace('/\s+/', ' ', $m[3]) . ' ' . $m[4] . ',' . $m[5] . ' Kč'];
    }
    return $out;
}

function coop_leaflet_pdf(string $slug): ?array {
    $html = http_get('https://www.coopclub.cz/letaky/' . $slug . '/');
    if (!preg_match('~https://www\.coopclub\.cz/wp-content/uploads/[^"\'\s]+\.pdf~', $html, $pm)) return null;
    $from = $to = null;
    if (preg_match('/od\s+(\d{1,2})\.\s?(\d{1,2})\.\s+do\s+(\d{1,2})\.\s?(\d{1,2})\./u', strip_tags($html), $dm)) {
        $y = (int) date('Y');
        $from = sprintf('%04d-%02d-%02d', $y, $dm[2], $dm[1]); $to = sprintf('%04d-%02d-%02d', $y, $dm[4], $dm[3]);
        if ($to < $from) $to = sprintf('%04d-%02d-%02d', $y + 1, $dm[4], $dm[3]);
    }
    return ['url' => $pm[0], 'from' => $from, 'to' => $to];
}

function fetch_coop_pdf(string $chain, string $slug): array {
    $parserDir = __DIR__ . '/pdfparser/alt_autoload.php';
    if (!is_file($parserDir)) throw new RuntimeException('chybi pdfparser');
    require_once $parserDir;
    $info = coop_leaflet_pdf($slug);
    if (!$info) throw new RuntimeException("coopclub $slug: PDF nenalezeno");
    if ($info['to'] !== null && $info['to'] < date('Y-m-d')) throw new RuntimeException("coopclub $slug: leták už neplatí");
    $tmp = tempnam(sys_get_temp_dir(), 'coop');
    $out = [];
    try {
        $fp = fopen($tmp, 'w'); $ch = curl_init($info['url']);
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 110, CURLOPT_USERAGENT => UA]);
        curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
        if ($code !== 200 || filesize($tmp) < 50000) throw new RuntimeException("coop pdf HTTP $code");
        $pdf = (new \Smalot\PdfParser\Parser())->parseFile($tmp);
        logmsg('[' . $chain . '] pdf ' . basename($info['url']) . ' ' . filesize($tmp) . ' B, stran ' . count($pdf->getPages()) . ', platnost ' . $info['from'] . '..' . $info['to']);
        $seen = [];
        foreach ($pdf->getPages() as $pg) foreach (parse_coop_text($pg->getText()) as $o) {
            $key = mb_strtolower(preg_replace('/\W+/u', '', $o['title'] . $o['grams']));
            if (isset($seen[$key])) continue; $seen[$key] = true;
            $out[] = ['chain' => $chain, 'id' => $chain . ':' . md5($info['url'] . '|' . $o['title'] . '|' . $o['grams']),
                'title' => $o['title'], 'subtitle' => $o['grams'], 'category' => null, 'price' => $o['price'], 'oldPrice' => null, 'cardPrice' => null, 'discount' => null,
                'unit' => $o['grams'], 'basePrice' => $o['basePrice'], 'validFrom' => $info['from'], 'validTo' => $info['to'], 'img' => null];
        }
    } finally { @unlink($tmp); }
    return $out;
}
function fetch_coop(): array  { return fetch_coop_pdf('coop', 'sc-aktualni-letak'); }
function fetch_terno(): array { return fetch_coop_pdf('terno', 'csc-aktualni-letak'); }

function fetch_lidl(): array {
    $want = '#^/h/(ovoce|maso|ryby|syry|pekarna|pecivo|chlebove|trvanlive|oleje|hotova|cerealie|mrazene|sladkosti|napoje|vino|zdravi|domacnost|krmivo|kvetiny|uklid|krasa|drogerie)#';
    $index = http_get('https://www.lidl.cz/c/potraviny-a-napoje/s10068374');
    preg_match_all('#href="(/h/[^"\#?]+)"#', $index, $m);
    $paths = array_values(array_unique(array_filter($m[1], fn($h) => preg_match($want, $h))));
    if (!$paths) throw new RuntimeException('nenalezeny kategorie');
    $all = [];
    foreach ($paths as $h) {
        // stránka ukáže max. 48 produktů; další se načtou přes ?offset=
        for ($off = 0; $off < 480; $off += 48) {
            try {
                $html = http_get('https://www.lidl.cz' . $h . ($off ? '?offset=' . $off : ''));
                $tiles = substr_count($html, 'data-grid-data=');
                foreach (parse_lidl($html, explode('/', $h)[2] ?? '') as $it) $all[$it['id']] = $it;
            } catch (Throwable $e) { logmsg('[lidl] ' . $h . ' off ' . $off . ': ' . $e->getMessage()); break; }
            sleep(1);
            if ($tiles < 48) break;
        }
    }
    return array_values($all);
}

/** Billa: veřejné JSON API webu billa.cz (filtr inPromotion), ceny jsou v haléřích. */
function parse_billa(array $results): array {
    $out = [];
    foreach ($results as $d) {
        if (empty($d['inPromotion']) || empty($d['name']) || empty($d['sku'])) continue;
        $p = $d['price'] ?? [];
        $value = $p['regular']['value'] ?? null;
        $crossed = $p['crossed'] ?? null;
        if (!is_numeric($value) || $value <= 0 || !is_numeric($crossed) || $crossed <= $value) continue;
        $tags = $p['regular']['tags'] ?? [];
        if (in_array('pt-multi', $tags, true) && ($p['regular']['promotionType'] ?? '') !== 'FROM') continue; // množstevní akce neumíme
        $unit = null;
        $am = isset($d['amount']) ? (float) $d['amount'] : 0;
        $ul = trim((string) ($d['volumeLabelShort'] ?? ''));
        if ($am > 0 && in_array($ul, ['g', 'kg', 'ml', 'l', 'ks'], true)) {
            $unit = rtrim(rtrim(number_format($am, 3, ',', ''), '0'), ',') . ' ' . $ul;
        }
        $loy = $p['loyalty']['value'] ?? null;
        $id = 'billa:' . $d['sku'];
        $out[$id] = [
            'chain'     => 'billa',
            'id'        => $id,
            'title'     => trim($d['name']),
            'subtitle'  => (string) $unit,
            'category'  => $d['parentCategories'][0][0]['name'] ?? ($d['category'] ?? null),
            'price'     => (float) $value / 100,
            'oldPrice'  => (float) $crossed / 100,
            'cardPrice' => (is_numeric($loy) && $loy > 0 && $loy < $value) ? $loy / 100 : null,
            'discount'  => isset($p['discountPercentage']) ? abs((int) $p['discountPercentage']) : null,
            'unit'      => $unit,
            'basePrice' => null,
            'validFrom' => null,
            'validTo'   => null,
            'img'       => small_img(clean_img($d['images'][0] ?? null), 'billa'),
        ];
    }
    return array_values($out);
}

function fetch_billa(): array {
    $all = [];
    for ($page = 0; $page < 20; $page++) {
        $j = json_decode(http_get('https://www.billa.cz/api/product-discovery/products?inPromotion=true&sortBy=relevance&pageSize=500&page=' . $page), true);
        if (!is_array($j) || !isset($j['results'])) throw new RuntimeException('billa: neocekavana odpoved');
        foreach (parse_billa($j['results']) as $it) $all[$it['id']] = $it;
        if (count($j['results']) < 500) break;
        sleep(1);
    }
    return array_values($all);
}

/** Globus: HTML stránky Akční nabídky (24 produktů na stránku, ?page=N). Ceny jsou ve tvaru 2990 = 29,90 Kč. */
function parse_globus(string $html): array {
    if ($html === '') return [];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $norm = fn(string $t) => trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $t)));
    $out = [];
    foreach ($xp->query('//h3/a[contains(@href,"/akcni-nabidka/p/")]') as $a) {
        $tile = $a;
        for ($i = 0; $i < 8 && $tile && !str_contains((string) $tile->getAttribute('class'), 'rounded-2.5xl'); $i++) $tile = $tile->parentNode;
        if (!$tile || !($tile instanceof DOMElement)) continue;
        $title = $norm($a->textContent);
        if (!preg_match('#-(\d{8,14})$#', parse_url($a->getAttribute('href'), PHP_URL_PATH) ?: '', $mm)) continue;
        $id = 'globus:' . $mm[1];
        $blk = $xp->query('.//div[contains(@class,"items-end") and contains(@class,"justify-between")]', $tile)->item(0);
        if (!$blk) continue;
        // ceny: prvek s <sup> = celé korun + haléře ("1 299" + "00"), přeškrtnutá je původní
        $old = null; $cur = [];
        foreach ($xp->query('.//sup', $blk) as $sup) {
            $dec = preg_replace('/\D/', '', $sup->textContent);
            $box = $sup->parentNode && $sup->parentNode->parentNode instanceof DOMElement && $sup->parentNode->textContent === $sup->textContent ? $sup->parentNode->parentNode : $sup->parentNode;
            $full = preg_replace('/\D/', '', $box->textContent);
            $int = ($dec !== '' && str_ends_with($full, $dec)) ? substr($full, 0, -strlen($dec)) : $full;
            if ($int === '') continue;
            $v = (float) $int + ((float) ($dec === '' ? '0' : $dec)) / 100;
            if ($xp->query('ancestor-or-self::del', $sup)->length) { if ($old === null) $old = $v; } else $cur[] = $v;
        }
        $text = $norm($blk->textContent);
        preg_match_all('/-(\d{1,2})\s*%/u', $text, $pm);
        if ($old === null || !$cur || !$pm[1]) continue;     // bez původní ceny a slevy to není akce
        $price = $cur[0];
        if ($price <= 0 || $old <= $price) continue;
        if (abs((1 - $price / $old) * 100 - (int) $pm[1][0]) > 3) continue;   // cena nesedí se slevou: raději vynechat než ukázat špatně
        $card = isset($cur[1]) && $cur[1] < $price ? $cur[1] : null;
        $validTo = null;
        if (preg_match('/Platné do:\s*(\d{1,2})\.\s*(\d{1,2})\./u', $text, $dm)) {
            $y = (int) date('Y');
            $cand = sprintf('%04d-%02d-%02d', $y, $dm[2], $dm[1]);
            if ($cand < date('Y-m-d', strtotime('-60 days'))) $cand = sprintf('%04d-%02d-%02d', $y + 1, $dm[2], $dm[1]);
            $validTo = $cand;
        }
        $base = null;
        foreach ($xp->query('.//span', $tile) as $sp) {
            if (preg_match('/^(\d+(?:,\d+)?)\s*Kč\s*\/\s*(\d+(?:,\d+)?\s*(?:kg|g|l|ml|ks))\b/u', $norm($sp->textContent), $bm)) { $base = $bm[2] . ' = ' . $bm[1] . ' Kč'; break; }
        }
        $out[$id] = [
            'chain' => 'globus', 'id' => $id, 'title' => $title, 'subtitle' => '', 'category' => null,
            'price' => (float) $price, 'oldPrice' => (float) $old, 'cardPrice' => $card,
            'discount' => (int) $pm[1][0], 'unit' => null, 'basePrice' => $base,
            'validFrom' => null, 'validTo' => $validTo,
            'img' => tile_img($xp, $tile, 'https://www.globus.cz'),
        ];
    }
    return array_values($out);
}

/** Stáhne více URL najednou (curl_multi), vrací pole url => tělo|null. */
function http_multi(array $urls): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept-Language: cs-CZ,cs;q=0.9']]);
        curl_multi_add_handle($mh, $ch); $hs[$u] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $res = [];
    foreach ($hs as $u => $ch) {
        $res[$u] = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 ? curl_multi_getcontent($ch) : null;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $res;
}

function fetch_globus(): array {
    $all = [];
    $base = 'https://www.globus.cz/globus/hypermarket/akcni-nabidka?page=';
    for ($p = 1; $p <= 90; $p += 4) {
        $urls = [];
        for ($k = $p; $k < $p + 4; $k++) $urls[] = $base . $k;
        $done = false;
        foreach (http_multi($urls) as $u => $body) {
            if ($body === null) { try { $body = http_get($u); } catch (Throwable $e) { logmsg('[globus] ' . $u . ': ' . $e->getMessage()); continue; } }
            if (substr_count($body, '/akcni-nabidka/p/') === 0) { $done = true; continue; }
            foreach (parse_globus($body) as $it) $all[$it['id']] = $it;
        }
        if ($done) break;
        usleep(400000);
    }
    return array_values($all);
}

function main(?string $only = null): int {
    $dataFile = dirname(__DIR__) . '/data.json';
    $prev = is_file($dataFile) ? json_decode(file_get_contents($dataFile), true) : null;
    $result = ['updated' => date('c'), 'sources' => [], 'items' => []];

    $sources = [
        'kaufland' => ['https://prodejny.kaufland.cz/nabidka/prehled.html', 'parse_kaufland'],
        'penny'    => [null, 'fetch_penny'],
        'albert'   => [null, 'fetch_albert'],
        'norma'    => [null, 'fetch_norma'],
        'coop'     => [null, 'fetch_coop'],
        'terno'    => [null, 'fetch_terno'],
        'lidl'     => [null, 'fetch_lidl'],
        'billa'    => [null, 'fetch_billa'],
        'globus'   => [null, 'fetch_globus'],
    ];
    foreach ($sources as $chain => [$url, $parser]) {
        if ($only !== null && $only !== $chain) {   // jen jeden řetězec: ostatní zůstanou z minula
            if ($prev) foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === $chain) $result['items'][] = $it;
            if (isset($prev['sources'][$chain])) $result['sources'][$chain] = $prev['sources'][$chain];
            continue;
        }
        try {
            $items = $url === null ? $parser() : $parser(http_get($url));
            if (count($items) < MIN_ITEMS[$chain]) throw new RuntimeException('podezrele malo polozek: ' . count($items));
            $result['sources'][$chain] = ['ok' => true, 'count' => count($items), 'fetched' => date('c')];
            array_push($result['items'], ...$items);
        } catch (Throwable $e) {
            logmsg("[$chain] CHYBA: " . $e->getMessage());
            $result['sources'][$chain] = ['ok' => false, 'error' => $e->getMessage()];
            // zachovat minulá data tohoto řetězce
            if ($prev) foreach ($prev['items'] ?? [] as $it) if (($it['chain'] ?? '') === $chain) $result['items'][] = $it;
            if (isset($prev['sources'][$chain]['fetched'])) $result['sources'][$chain]['fetched'] = $prev['sources'][$chain]['fetched'];
        }
        sleep(2); // šetrně k serverům
    }
    if (!$result['items']) { logmsg("Zadna data, data.json se neprepisuje"); return 1; }

    $tmp = $dataFile . '.tmp';
    file_put_contents($tmp, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $dataFile);
    echo 'OK: ' . count($result['items']) . " polozek\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv[1] ?? null));
} elseif (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    @set_time_limit(180);
    ignore_user_abort(true);
    exit(main(isset($_GET['only']) ? (string) $_GET['only'] : null));
}
