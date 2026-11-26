<?php
/** Kontrola stavu dat (jen cte, nic nemeni): vypise "STAV: OK" nebo "STAV: PROBLEM" a seznam retezcu,
 *  ktere jsou stare, skoncily chybou nebo maji nezvykle malo akci. Pouziva ji administrace,
 *  upozorneni spravci (cron/alert.php) a externi hlidani. */
function nsr_status(): array {
    $tz = date_default_timezone_get();
    date_default_timezone_set('Europe/Prague');
    @ini_set('memory_limit', '256M');
    $root = dirname(__DIR__);
    // soubor => [retezec => [nazev, nejmensi obvykly pocet polozek]] (zhruba polovina bezneho stavu)
    $check = [
        'data.json' => ['kaufland' => ['Kaufland', 500], 'penny' => ['Penny', 250], 'lidl' => ['Lidl', 100], 'billa' => ['Billa', 1500], 'globus' => ['Globus', 400],
                        'albert' => ['Albert', 400], 'norma' => ['Norma', 60], 'coop' => ['Coop', 20], 'terno' => ['Terno', 15]],
        'data_drug.json' => ['rossmann' => ['Rossmann', 2000], 'teta' => ['Teta', 1500]],
        'data_elektro.json' => ['datart' => ['Datart', 5000], 'planeo' => ['Planeo', 900]],
    ];
    $now = time(); $today = date('Y-m-d', $now);
    $bad = []; $lines = [];
    foreach ($check as $file => $chains) {
        $d = is_file("$root/$file") ? json_decode((string) file_get_contents("$root/$file"), true) : null;
        if (!is_array($d)) { $bad[] = "$file: soubor s daty chybí nebo nejde přečíst"; continue; }
        $live = [];                                                       // polozky, kterym akce jeste neskoncila
        foreach ($d['items'] ?? [] as $it) if (empty($it['validTo']) || $it['validTo'] >= $today) $live[$it['chain'] ?? ''] = ($live[$it['chain'] ?? ''] ?? 0) + 1;
        foreach ($chains as $c => [$name, $min]) {
            $s = $d['sources'][$c] ?? null;
            if (!$s) { $bad[] = "$name: v datech úplně chybí"; continue; }
            $t = strtotime((string) ($s['fetched'] ?? '')) ?: 0;
            $n = (int) ($s['count'] ?? 0); $v = $live[$c] ?? 0;
            $lines[] = sprintf('%s: %d akcí, z toho platných nebo budoucích %d, staženo %s', $name, $n, $v, $t ? date('j. n. H:i', $t) : '?');
            $why = [];
            if (($s['ok'] ?? true) === false) $why[] = 'poslední stažení skončilo chybou';
            if ($now - $t > 48 * 3600) $why[] = 'data jsou stará (staženo ' . ($t ? date('j. n.', $t) : 'nikdy') . ')';
            if ($n < $min) $why[] = "jen $n akcí (obvykle aspoň $min)";
            elseif ($v < $min / 2) $why[] = "jen $v akcí ještě platí";
            if ($why) $bad[] = "$name: " . implode(', ', $why);
        }
        unset($d);
    }
    $text = $bad ? "STAV: PROBLEM (" . count($bad) . ")\n- " . implode("\n- ", $bad) . "\n" : "STAV: OK\n";
    $text .= "\nZkontrolováno " . date('j. n. Y H:i', $now) . "\n" . implode("\n", $lines) . "\n";
    date_default_timezone_set($tz);
    return ['bad' => $bad, 'text' => $text];
}
// vystup jen pri primem otevreni; pri vlozeni z jineho skriptu se nic nevypisuje
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    echo nsr_status()['text'];
}
