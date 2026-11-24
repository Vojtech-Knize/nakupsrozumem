<?php
/**
 * Nákup s rozumem - pozice prodejen Kaufland a Penny z OpenStreetMap (Overpass API).
 * Data (c) přispěvatelé OpenStreetMap, licence ODbL. Spouští se jednou týdně (CRON) nebo ručně.
 * Volání přes web: https://test.nakupsrozumem.cz/cron/fetch_stores.php?key=...
 * Výstup: ../stores.json  (zapisuje se atomicky; při chybě zůstane předchozí verze)
 */
if (!defined('CRON_KEY')) define('CRON_KEY', 'f728d1acca1405786d3fce1b0a3c771d');
if (!defined('UA')) define('UA', 'NakupSRozumem/0.1 (+https://nakupsrozumem.cz; pozice prodejen z OpenStreetMap)');
const ENDPOINTS = ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];

function overpass(string $query): array {
    $last = 'nic';
    foreach (ENDPOINTS as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => UA, CURLOPT_ENCODING => '',
            CURLOPT_POSTFIELDS => 'data=' . urlencode($query),
        ]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body !== false && $code === 200) {
            $j = json_decode($body, true);
            if (isset($j['elements'])) return $j['elements'];
        }
        $last = "HTTP $code pro $url";
    }
    throw new RuntimeException($last);
}

function normalize_stores(array $elements, ?string $force = null): array {
    $out = []; $seen = [];
    foreach ($elements as $e) {
        $t = $e['tags'] ?? [];
        $lat = $e['lat'] ?? ($e['center']['lat'] ?? null);
        $lon = $e['lon'] ?? ($e['center']['lon'] ?? null);
        if ($lat === null || $lon === null) continue;
        $brand = strtolower($t['brand'] ?? ($t['name'] ?? ''));
        $chain = $force ?? (str_contains($brand, 'kaufland') ? 'kaufland' : (str_contains($brand, 'penny') ? 'penny' : (str_contains($brand, 'lidl') ? 'lidl' : (str_contains($brand, 'billa') ? 'billa' : (str_contains($brand, 'globus') ? 'globus' : (str_contains($brand, 'albert') ? 'albert' : (str_contains($brand, 'norma') ? 'norma' : null)))))));
        if (!$chain) continue;
        $key = $chain . round($lat, 4) . round($lon, 4);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $street = trim(($t['addr:street'] ?? ($t['addr:place'] ?? '')) . ' ' . ($t['addr:housenumber'] ?? ($t['addr:conscriptionnumber'] ?? '')));
        $out[] = [
            'chain' => $chain,
            'name'  => ['kaufland' => 'Kaufland', 'penny' => 'Penny', 'lidl' => 'Lidl', 'billa' => 'Billa', 'globus' => 'Globus', 'albert' => 'Albert', 'norma' => 'Norma', 'coop' => 'Coop', 'terno' => 'Terno', 'dm' => 'dm', 'rossmann' => 'Rossmann', 'teta' => 'Teta', 'datart' => 'Datart', 'planeo' => 'Planeo'][$chain],
            'kind'  => in_array($chain, ['datart', 'planeo'], true) ? 'prodejna' : ((in_array($chain, ['kaufland', 'globus'], true) || ($chain === 'albert' && stripos((string) ($t['name'] ?? ''), 'hyper') !== false)) ? 'hypermarket' : 'supermarket'),
            'city'  => $t['addr:city'] ?? '',
            'addr'  => $street,
            'lat'   => round((float) $lat, 5),
            'lon'   => round((float) $lon, 5),
        ];
    }
    return $out;
}

function main_stores(?string $only = null): int {
    $file = dirname(__DIR__) . '/stores.json';
    $brands = ['kaufland' => 'Kaufland', 'penny' => 'Penny', 'lidl' => 'Lidl', 'billa' => 'Billa', 'globus' => 'Globus', 'albert' => 'Albert', 'norma' => 'Norma', 'coop' => 'Coop', 'terno' => 'Terno', 'dm' => 'dm', 'rossmann' => 'Rossmann', 'teta' => 'Teta', 'datart' => 'Datart', 'planeo' => 'Planeo'];
    if ($only !== null && !isset($brands[$only])) { echo "neznamy retezec\n"; return 1; }
    $names = $only ? [$brands[$only]] : array_values(array_diff_key($brands, ['dm' => 1, 'rossmann' => 1, 'teta' => 1, 'datart' => 1, 'planeo' => 1]));
    $re = '(' . implode('|', $names) . ')';
    if (in_array($only, ['datart', 'planeo'], true)) {   // elektro: obchody typu electronics/appliance/computer apod.
        $names = ['datart' => ['Datart', 'DATART'], 'planeo' => ['Planeo Elektro', 'PLANEO Elektro', 'Planeo', 'PLANEO']][$only];
        $parts = '';
        foreach ($names as $n) $parts .= 'nwr["shop"~"^(electronics|appliance|computer|mobile_phone|hifi|houseware|doityourself)$"]["brand"="' . $n . '"](area.cz);nwr["shop"~"^(electronics|appliance|computer|mobile_phone|hifi|houseware|doityourself)$"]["name"="' . $n . '"](area.cz);';
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(' . $parts . ');out center tags;';
    } elseif (in_array($only, ['dm', 'rossmann', 'teta'], true)) {   // drogerie: obchody typu chemist/cosmetics
        $names = ['dm' => ['dm', 'dm drogerie markt', 'dm-drogerie markt'], 'rossmann' => ['Rossmann', 'ROSSMANN'], 'teta' => ['Teta', 'Teta drogerie', 'TETA drogerie']][$only];
        $parts = '';
        foreach ($names as $n) $parts .= 'nwr["shop"~"^(chemist|cosmetics)$"]["brand"="' . $n . '"](area.cz);nwr["shop"~"^(chemist|cosmetics)$"]["name"="' . $n . '"](area.cz);';
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(' . $parts . ');out center tags;';
    } elseif ($only === 'globus') {
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(nwr["brand"="Globus"]["shop"~"^(supermarket|department_store|mall)$"](area.cz);nwr["name"="Globus"]["shop"~"^(supermarket|department_store|mall)$"](area.cz););out center tags;';
    } elseif ($only === 'albert') {
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(nwr["shop"="supermarket"]["brand"="Albert"](area.cz);nwr["shop"="supermarket"]["name"="Albert"](area.cz);nwr["shop"="supermarket"]["name"="Albert Hypermarket"](area.cz););out center tags;';
    } elseif ($only === 'coop' || $only === 'terno') {
        $rx = $only === 'coop' ? '^(coop|jednota)' : '^(terno|coop|jednota)';
        $box = $only === 'coop' ? '49.45,13.45,50.6,15.55' : '48.55,13.35,49.75,15.25';   // Středočeský / Jihočeský kraj (obdélník)
        $q = '[out:json][timeout:90][bbox:' . $box . '];(nwr["shop"~"^(supermarket|convenience|general)$"]["brand"~"' . $rx . '",i];nwr["shop"~"^(supermarket|convenience|general)$"]["name"~"' . $rx . '",i];);out center tags;';
    } elseif ($only === 'norma') {
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(nwr["shop"~"^(supermarket|convenience|discount)$"]["brand"~"^norma$",i](area.cz);nwr["shop"~"^(supermarket|convenience|discount)$"]["name"~"^norma$",i](area.cz););out center tags;';
    } elseif ($only) {   // přesná shoda je mnohem rychlejší než regulární výraz
        $n = $names[0];
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(nwr["shop"="supermarket"]["brand"="' . $n . '"](area.cz);nwr["shop"="supermarket"]["name"="' . $n . '"](area.cz););out center tags;';
    } else {
        $q = '[out:json][timeout:90];area["ISO3166-1"="CZ"]->.cz;(nwr["shop"="supermarket"]["brand"~"^' . $re . '"](area.cz);nwr["shop"="supermarket"]["name"~"^' . $re . '"](area.cz););out center tags;';
    }
    try {
        $stores = normalize_stores(overpass($q), in_array($only, ['coop', 'terno', 'dm', 'rossmann', 'teta', 'datart', 'planeo'], true) ? $only : null);
        if (count($stores) < ($only === 'globus' ? 8 : (in_array($only, ['datart', 'planeo'], true) ? 15 : (in_array($only, ['norma', 'coop', 'terno', 'dm', 'rossmann', 'teta'], true) ? 20 : ($only ? 50 : 200))))) throw new RuntimeException('podezrele malo prodejen: ' . count($stores));
    } catch (Throwable $e) {
        echo 'CHYBA: ' . $e->getMessage() . "\n";
        return 1;
    }
    if ($only !== null && is_file($file)) {   // dotažení jednoho řetězce: ostatní zůstanou
        $old = json_decode(file_get_contents($file), true)['stores'] ?? [];
        $stores = array_merge(array_values(array_filter($old, fn($s) => ($s['chain'] ?? '') !== $only)), $stores);
    }
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode(['updated' => date('c'), 'source' => 'OpenStreetMap (ODbL)', 'stores' => $stores], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
    echo 'OK: ' . count($stores) . " prodejen\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main_stores($argv[1] ?? null));
} elseif (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    @set_time_limit(300);
    ignore_user_abort(true);
    ob_start();
    $rc = main_stores(isset($_GET['only']) ? (string) $_GET['only'] : null);
    $o = ob_get_clean();
    echo $o;
    exit($rc);
}
