<?php
declare(strict_types=1);
/** Týdenní e-mail s hlídanými věcmi, které jsou právě v akci.
 *  Volá ho aplikace při načtení (stejně jako auto.php) nebo cron; sám hlídá čas, zámek i to, aby uživatel nedostal víc než jeden e-mail za týden.
 *  Posílá jen mezi 8. a 19. hodinou a jen uživatelům, kteří si e-mail zapnuli. Uživatel se z něj může odhlásit odkazem v e-mailu. */
require_once __DIR__ . '/../api/_lib.php';
const DIGEST_BASE = 'https://app.nakupsrozumem.cz/';
const DIGEST_FROM = 'info@nakupsrozumem.cz';

function dg_norm(string $s): string {
    static $map = ['á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','í'=>'i','ľ'=>'l','ĺ'=>'l','ň'=>'n','ó'=>'o','ö'=>'o','ô'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z'];
    return strtr(mb_strtolower(trim($s)), $map);
}
function dg_words(string $s): array { return preg_split('/[^a-z0-9]+/', dg_norm($s), -1, PREG_SPLIT_NO_EMPTY) ?: []; }
/** Stejné pravidlo jako v aplikaci: každé hledané slovo musí být začátkem některého slova v názvu. */
function dg_match(array $item, array $tk): bool {
    $w = dg_words(($item['title'] ?? '') . ' ' . ($item['subtitle'] ?? ''));
    foreach ($tk as $t) {
        $hit = false;
        foreach ($w as $x) if (str_starts_with($x, $t)) { $hit = true; break; }
        if (!$hit) return false;
    }
    return true;
}
function dg_kc(float $v): string { return number_format($v, 2, ',', ' ') . ' Kč'; }
function dg_enc(string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

function run_digest(): int {
    date_default_timezone_set('Europe/Prague');
    $root = dirname(__DIR__);
    $today = date('Y-m-d');
    $names = ['kaufland'=>'Kaufland','penny'=>'Penny','lidl'=>'Lidl','billa'=>'Billa','globus'=>'Globus','albert'=>'Albert','norma'=>'Norma','coop'=>'Coop','terno'=>'Terno','dm'=>'dm','rossmann'=>'Rossmann','teta'=>'Teta'];
    $load = function (string $f) use ($root, $today): array {
        $j = is_file("$root/$f") ? json_decode((string)file_get_contents("$root/$f"), true) : null;
        $items = is_array($j) ? ($j['items'] ?? []) : [];
        return array_values(array_filter($items, fn($i) => (empty($i['validFrom']) || $i['validFrom'] <= $today) && (empty($i['validTo']) || $i['validTo'] >= $today) && ($i['price'] ?? 0) > 0));
    };
    $data = ['food' => $load('data.json'), 'drug' => $load('data_drug.json'), 'elec' => $load('data_elektro.json')];
    if (!$data['food']) return 0;   // data se nenačetla: raději nic neposílat

    $rows = db()->query('SELECT w.user_id, w.terms, u.email FROM watch w JOIN users u ON u.id = w.user_id WHERE w.digest = 1 AND w.last_sent < ' . (time() - 6 * 86400 - 43200) . ' LIMIT 25')->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        $favs = json_decode((string)$r['terms'], true) ?: [];
        $blocks = [];
        foreach ($favs as $f) {
            $tk = dg_words((string)($f['term'] ?? ''));
            if (!$tk) continue;
            $pool = $data[in_array(($f['cat'] ?? ''), ['drug', 'elec'], true) ? $f['cat'] : 'food'];
            $m = array_values(array_filter($pool, fn($i) => dg_match($i, $tk)));
            if (!$m) continue;
            usort($m, fn($a, $b) => $a['price'] <=> $b['price']);
            $lines = [];
            foreach (array_slice($m, 0, 3) as $i) {
                $until = !empty($i['validTo']) ? ' (do ' . (int)substr($i['validTo'], 8, 2) . '. ' . (int)substr($i['validTo'], 5, 2) . '.)' : '';
                $lines[] = '   ' . ($names[$i['chain']] ?? $i['chain']) . ': ' . trim(($i['title'] ?? '') . (!empty($i['subtitle']) ? ', ' . $i['subtitle'] : '')) . ' – ' . dg_kc((float)$i['price']) . $until;
            }
            $blocks[] = '• ' . $f['term'] . ' (' . count($m) . ' ' . (count($m) === 1 ? 'akce' : (count($m) < 5 ? 'akce' : 'akcí')) . ")\n" . implode("\n", $lines);
        }
        if (!$blocks) continue;   // nic hlídaného není v akci: e-mail neposíláme, příště zkusíme znovu
        $unsub = DIGEST_BASE . 'api/watch.php?a=unsub&u=' . (int)$r['user_id'] . '&t=' . unsub_token((int)$r['user_id']);
        $body = "Dobrý den,\n\ntyto vaše hlídané věci jsou právě v akci:\n\n" . implode("\n\n", $blocks)
              . "\n\nVšechny akce a naplánovanou cestu najdete v aplikaci:\n" . DIGEST_BASE
              . "\n\nTento e-mail chodí jednou týdně, protože jste si ho v aplikaci zapnuli. Vypnout ho můžete zde:\n" . $unsub . "\n";
        $headers = [
            'From: ' . dg_enc('Nákup s rozumem') . ' <' . DIGEST_FROM . '>',
            'List-Unsubscribe: <' . $unsub . '>',
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
        ];
        if (@mail((string)$r['email'], dg_enc('Vaše hlídané věci jsou v akci'), $body, implode("\r\n", $headers))) {
            db()->prepare('UPDATE watch SET last_sent=? WHERE user_id=?')->execute([time(), $r['user_id']]);
            $sent++;
        }
    }
    return $sent;
}

// jen při přímém volání (ne při include)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    $root = dirname(__DIR__);
    $state = $root . '/digest_state.json';
    $st = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
    $h = (int)(new DateTime('now', new DateTimeZone('Europe/Prague')))->format('G');
    if (time() - ($st['check'] ?? 0) < 6 * 3600 || $h < 8 || $h >= 19) { echo "ok\n"; exit; }
    $lock = fopen($root . '/digest.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "bezi\n"; exit; }
    $st['check'] = time();
    file_put_contents($state, json_encode($st));
    try { $n = run_digest(); echo "odeslano $n\n"; } catch (Throwable $e) { echo "chyba\n"; }
}
