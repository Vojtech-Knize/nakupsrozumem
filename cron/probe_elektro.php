<?php
/**
 * Nákup s rozumem - ZKUŠEBNÍ skript pro kartu Elektro (Datart, Planeo).
 * Nic neukládá. Pro každou zkušební adresu zjistí: smí ji robots.txt číst, vrátí ji server (HTTP kód, velikost,
 * ochrana proti robotům), jak vypadá karta produktu (výřez HTML kolem první ceny) a jak funguje stránkování.
 * Výstup je prostý text; zkopírujte ho zpět, podle něj se napíše stahování.
 * Volání: cron/probe_elektro.php?key=...   (volitelně &url=https://www.datart.cz/... pro vlastní adresu)
 */
require_once __DIR__ . '/fetch_akce.php';

const PROBE_TARGETS = [
    'https://www.datart.cz/vyprodej-poslednich-kusu',
    'https://www.planeo.cz/black-off',
    'https://www.planeo.cz/letaky',
    'https://www.planeo.cz/planeo-dny-elektricka-topidla',
];

/** Pravidla Disallow/Allow pro User-agent: * z robots.txt. */
function probe_robots(string $origin): array {
    $r = ['dis' => [], 'allow' => [], 'raw' => null];
    $ch = curl_init($origin . '/robots.txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA]);
    $b = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r['raw'] = $code;
    if ($b === false || $code !== 200) return $r;
    $star = false;
    foreach (preg_split('/\R/', $b) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if ($line === '') continue;
        if (preg_match('/^user-agent:\s*(.*)$/i', $line, $m)) { $star = trim($m[1]) === '*'; continue; }
        if (!$star) continue;
        if (preg_match('/^disallow:\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') $r['dis'][] = trim($m[1]);
        if (preg_match('/^allow:\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') $r['allow'][] = trim($m[1]);
    }
    return $r;
}
function probe_match(string $rule, string $path): bool {
    $re = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '~')) . '~';
    return (bool) preg_match($re, $path);
}
function probe_allowed(array $rb, string $path): string {
    $best = null; $bestLen = -1; $verdict = 'povoleno';
    foreach ($rb['dis'] as $d) if (probe_match($d, $path) && strlen($d) > $bestLen) { $best = $d; $bestLen = strlen($d); $verdict = 'ZAKAZANO (' . $d . ')'; }
    foreach ($rb['allow'] as $a) if (probe_match($a, $path) && strlen($a) >= $bestLen) { $bestLen = strlen($a); $verdict = 'povoleno (' . $a . ')'; }
    return $verdict;
}

function probe_page(string $url): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept-Language: cs-CZ,cs;q=0.9']]);
    $b = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); $err = curl_error($ch); curl_close($ch);
    logmsg("HTTP $code" . ($err ? " ($err)" : '') . ($final !== $url ? " -> $final" : '') . ', ' . ($b === false ? 0 : strlen($b)) . ' B');
    if ($b === false || $code !== 200) return;
    $prot = [];
    foreach (['cloudflare' => 'Cloudflare', 'cf-chl' => 'Cloudflare challenge', 'akamai' => 'Akamai', 'captcha' => 'captcha', 'Just a moment' => 'Just a moment'] as $k => $n) if (stripos($b, $k) !== false) $prot[] = $n;
    logmsg('Znacky ochrany: ' . ($prot ? implode(', ', $prot) : 'zadne'));
    logmsg('Vyskytu "Kč": ' . preg_match_all('/Kč/u', $b) . '; JSON-LD Product: ' . substr_count($b, '"@type":"Product"') . substr_count($b, '"@type": "Product"') . '; <article>: ' . substr_count($b, '<article') . '; data-product: ' . substr_count($b, 'data-product'));
    if (preg_match_all('~<(?:a|link)[^>]+(?:rel="next"|class="[^"]*pag[^"]*")[^>]*href="([^"]+)"|href="([^"]*[?&](?:page|strana|p)=\d+[^"]*)"~i', $b, $m)) {
        $links = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
        logmsg('Stránkování (prvních 6 odkazů): ' . implode(' | ', array_slice($links, 0, 6)));
    } else logmsg('Stránkování: odkazy nenalezeny');
    if (preg_match('/<title>(.*?)<\/title>/si', $b, $mt)) logmsg('Title: ' . trim(html_entity_decode($mt[1])));
    $p = preg_match('/\d[\d\s\x{a0}]*\s*Kč/u', $b, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
    if ($p === false) { logmsg('Zadna cena v HTML (stranka se patrne dokresluje JavaScriptem).'); return; }
    // Najdi začátek nejbližší dlaždice: poslední výskyt <li/<article/<div class=...product před cenou
    $start = max(0, $p - 1800);
    $chunk = substr($b, $start, 3600);
    $chunk = preg_replace('~<(script|style|svg)\b.*?</\1>~si', '', $chunk);
    $chunk = preg_replace('/\s+/', ' ', $chunk);
    logmsg("--- Výřez HTML kolem první ceny (3600 B) ---\n" . $chunk . "\n--- konec výřezu ---");
}

function probe_main(): void {
    $targets = isset($_GET['url']) && preg_match('~^https://www\.(datart|planeo)\.cz/~', (string) $_GET['url']) ? [(string) $_GET['url']] : PROBE_TARGETS;
    $rbs = [];
    foreach ($targets as $u) {
        $pu = parse_url($u); $origin = $pu['scheme'] . '://' . $pu['host'];
        logmsg("\n=========== $u");
        if (!isset($rbs[$origin])) { $rbs[$origin] = probe_robots($origin); logmsg("robots.txt $origin: HTTP {$rbs[$origin]['raw']}, pravidel Disallow: " . count($rbs[$origin]['dis'])); }
        $path = ($pu['path'] ?? '/') . (isset($pu['query']) ? '?' . $pu['query'] : '');
        $v = probe_allowed($rbs[$origin], $path);
        logmsg("robots.txt pro $path: $v");
        if (str_starts_with($v, 'ZAKAZANO')) { logmsg('Preskakuji (robots.txt zakazuje).'); continue; }
        probe_page($u);
        usleep(1500000);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    probe_main();
} elseif (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    @set_time_limit(300);
    probe_main();
}
