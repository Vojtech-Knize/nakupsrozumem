<?php
declare(strict_types=1);
/* Společné funkce: databáze (SQLite), relace, odpovědi. */
const DATA_DIR = __DIR__ . '/../data';
const SESSION_DAYS = 400;
const COOKIE = 'nsr_s';

function out(array $a, int $c = 200): never {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function now_ms(): int { return (int)(microtime(true) * 1000); }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
        @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE COLLATE NOCASE, pass TEXT NOT NULL,
        created INTEGER NOT NULL, last_login INTEGER, last_seen INTEGER, is_admin INTEGER NOT NULL DEFAULT 0, list_code TEXT);
      CREATE TABLE IF NOT EXISTS sessions(
        token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created INTEGER NOT NULL, last_used INTEGER NOT NULL);
      CREATE INDEX IF NOT EXISTS sessions_user ON sessions(user_id);
      CREATE TABLE IF NOT EXISTS activity(day TEXT NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(day, user_id));
      CREATE TABLE IF NOT EXISTS attempts(k TEXT NOT NULL, t INTEGER NOT NULL);
      CREATE INDEX IF NOT EXISTS attempts_k ON attempts(k, t);
      CREATE TABLE IF NOT EXISTS watch(
        user_id INTEGER PRIMARY KEY, terms TEXT NOT NULL, digest INTEGER NOT NULL DEFAULT 0, last_sent INTEGER NOT NULL DEFAULT 0);');
    return $pdo;
}

function admin_emails(): array {
    $f = DATA_DIR . '/admin_emails.txt';
    if (!is_file($f)) return [];
    return array_values(array_filter(array_map(fn($l) => strtolower(trim($l)), file($f, FILE_IGNORE_NEW_LINES) ?: [])));
}
function is_admin_row(array $u): bool {
    return (int)$u['is_admin'] === 1 || in_array(strtolower($u['email']), admin_emails(), true);
}

function require_xhr(): void {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'nsr') out(['error' => 'bad_request'], 400);
}
function body_json(int $max = 60000): array {
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) out(['error' => 'too_big'], 413);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function set_cookie(string $val, int $exp): void {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie(COOKIE, $val, ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
function start_session(int $uid): void {
    $tok = bin2hex(random_bytes(32));
    $now = time();
    db()->prepare('INSERT INTO sessions(token,user_id,created,last_used) VALUES(?,?,?,?)')->execute([hash('sha256', $tok), $uid, $now, $now]);
    db()->prepare('UPDATE users SET last_login=?, last_seen=? WHERE id=?')->execute([$now, $now, $uid]);
    set_cookie($tok, 0);   // relační cookie: zmizí se zavřením prohlížeče
}
function end_session(): void {
    $tok = $_COOKIE[COOKIE] ?? '';
    if ($tok !== '') db()->prepare('DELETE FROM sessions WHERE token=?')->execute([hash('sha256', $tok)]);
    set_cookie('', time() - 3600);
}
/** Přihlášený uživatel nebo null. Zároveň zapíše dnešní aktivitu (pro graf aktivních uživatelů). */
function current_user(): ?array {
    $tok = $_COOKIE[COOKIE] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $tok)) return null;
    $h = hash('sha256', $tok);
    $s = db()->prepare('SELECT u.*, s.created AS s_created, s.last_used AS s_used FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
    $s->execute([$h]);
    $u = $s->fetch();
    if (!$u) return null;
    $now = time();
    if ($now - (int)$u['s_created'] > SESSION_DAYS * 86400) { db()->prepare('DELETE FROM sessions WHERE token=?')->execute([$h]); return null; }
    if ($now - (int)$u['s_used'] > 3600) {
        db()->prepare('UPDATE sessions SET last_used=? WHERE token=?')->execute([$now, $h]);
        db()->prepare('UPDATE users SET last_seen=? WHERE id=?')->execute([$now, $u['id']]);
        set_cookie($tok, 0);
    }
    db()->prepare('INSERT OR IGNORE INTO activity(day,user_id) VALUES(?,?)')->execute([date('Y-m-d'), $u['id']]);
    $u['is_admin_eff'] = is_admin_row($u);
    return $u;
}
function require_user(): array {
    $u = current_user();
    if (!$u) out(['error' => 'auth'], 401);
    return $u;
}
function public_user(array $u): array {
    return ['id' => (int)$u['id'], 'email' => $u['email'], 'list_code' => $u['list_code'], 'admin' => (bool)$u['is_admin_eff']];
}

function too_many(array $keys, int $limit, int $window): bool {
    $since = time() - $window;
    foreach ($keys as $k) {
        $s = db()->prepare('SELECT COUNT(*) FROM attempts WHERE k=? AND t>?'); $s->execute([$k, $since]);
        if ((int)$s->fetchColumn() >= $limit) return true;
    }
    return false;
}
function note_attempt(array $keys): void {
    foreach ($keys as $k) db()->prepare('INSERT INTO attempts(k,t) VALUES(?,?)')->execute([$k, time()]);
    if (random_int(1, 30) === 1) db()->prepare('DELETE FROM attempts WHERE t<?')->execute([time() - 86400]);
}

/** Tajný klíč pro podpis odkazů (odhlášení z e-mailu); vznikne při prvním použití. */
function secret_key(): string {
    $f = DATA_DIR . '/secret.key';
    if (!is_file($f)) { db(); @file_put_contents($f, bin2hex(random_bytes(32))); }
    return (string) @file_get_contents($f);
}
function unsub_token(int $uid): string { return hash_hmac('sha256', 'unsub:' . $uid, secret_key()); }

/** Stav dat (jen cte): "STAV: OK" nebo "STAV: PROBLEM" a seznam retezcu, ktere jsou stare, skoncily chybou
 *  nebo maji nezvykle malo akci. Zobrazuje ho sprava a cron/auto.php?stav=1. */
function nsr_status(): array {
    $tz = date_default_timezone_get();
    date_default_timezone_set('Europe/Prague');
    @ini_set('memory_limit', '256M');
    $root = dirname(__DIR__);                         // slozka aplikace
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

/* Upozorneni do telefonu spravce (Web Push). Posila se prazdna zprava podepsana klicem VAPID;
 * text si service worker po doruceni nacte z cron/auto.php?stav=1. Klic vznikne pri prvnim pouziti v chranene slozce data. */

function push_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function push_table(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS push_subs(endpoint TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created INTEGER NOT NULL)');
}

/** ['pem' => soukromy klic, 'pub' => verejny klic v base64url] */
function push_keys(): array {
    db();                                             // zalozi chranenou slozku data
    $f = DATA_DIR . '/vapid.json';
    $k = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!empty($k['pem']) && !empty($k['pub'])) return $k;
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res || !openssl_pkey_export($res, $pem)) throw new RuntimeException('klic pro upozorneni nejde vytvorit');
    $ec = openssl_pkey_get_details($res)['ec'];
    $pub = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    $k = ['pem' => $pem, 'pub' => push_b64($pub)];
    if (file_put_contents($f, json_encode($k), LOCK_EX) === false) throw new RuntimeException('klic pro upozorneni nejde ulozit');
    return $k;
}

/** Posilat jen na znamé služby prohlížečů, nikam jinam. */
function push_allowed_endpoint(string $ep): bool {
    $p = parse_url($ep);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port']) || strlen($ep) > 1500) return false;
    return (bool) preg_match('/^(fcm\.googleapis\.com|android\.googleapis\.com|[a-z0-9.-]+\.push\.apple\.com|[a-z0-9.-]+\.push\.services\.mozilla\.com|[a-z0-9.-]+\.notify\.windows\.com)$/i', $p['host']);
}

/** Podpis ES256 z formatu DER na 64 bajtu r||s. */
function push_der_to_raw(string $der): string {
    $i = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
    $out = '';
    for ($n = 0; $n < 2; $n++) {
        $len = ord($der[$i + 1]);
        $out .= str_pad(ltrim(substr($der, $i + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $i += 2 + $len;
    }
    return $out;
}

function push_auth_header(string $endpoint): string {
    $k = push_keys();
    $p = parse_url($endpoint);
    $data = push_b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
          . push_b64((string) json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600, 'sub' => 'https://app.nakupsrozumem.cz'], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($data, $sig, $k['pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('podpis upozorneni selhal');
    return 'Authorization: vapid t=' . $data . '.' . push_b64(push_der_to_raw($sig)) . ', k=' . $k['pub'];
}

/** Posle upozorneni na zarizeni soucasnych spravcu (volitelne jen na jedno). Neplatna prihlaseni smaze. */
function push_send(?string $endpoint = null, ?int $userId = null): array {
    push_table();
    $rows = db()->query('SELECT p.endpoint, p.user_id, u.email, u.is_admin FROM push_subs p JOIN users u ON u.id = p.user_id')->fetchAll();
    $r = ['devices' => 0, 'sent' => 0, 'codes' => []];
    foreach ($rows as $row) {
        if (!is_admin_row($row)) continue;
        if ($endpoint !== null && $row['endpoint'] !== $endpoint) continue;
        if ($userId !== null && (int) $row['user_id'] !== $userId) continue;
        if (!push_allowed_endpoint($row['endpoint'])) continue;
        $r['devices']++;
        $c = curl_init($row['endpoint']);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => [push_auth_header($row['endpoint']), 'TTL: 86400', 'Urgency: high', 'Content-Length: 0']]);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($code >= 200 && $code < 300) { $r['sent']++; continue; }
        $r['codes'][] = $code;
        if ($code === 404 || $code === 410) db()->prepare('DELETE FROM push_subs WHERE endpoint=?')->execute([$row['endpoint']]);
    }
    return $r;
}

/** Jednou denne (po 10. hodine) zkontroluje stav dat a pri problemu posle upozorneni spravci. Vola ho cron/auto.php. */
function nsr_daily_alert(): void {
    $dir = dirname(__DIR__) . '/data';
    $now = new DateTime('now', new DateTimeZone('Europe/Prague'));
    $h = (int) $now->format('G'); $day = $now->format('Y-m-d');
    if ($h < 10 || $h >= 21) return;                                  // v noci nebudit
    $f = $dir . '/alert.json';
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    if (($s['done'] ?? '') === $day) return;
    db();                                                             // zalozi slozku data
    $lock = fopen($dir . '/alert.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $tries = ($s['day'] ?? '') === $day ? (int) ($s['tries'] ?? 0) : 0;
    if (($s['done'] ?? '') !== $day && $tries < 3) {                  // nejvys 3 pokusy denne
        $s = ['day' => $day, 'tries' => $tries + 1, 'done' => $s['done'] ?? ''];
        file_put_contents($f, json_encode($s));                       // zapsat predem, aby pad neopakoval pokus donekonecna
        $bad = nsr_status()['bad'];
        $r = $bad ? push_send() : null;
        if (!$bad || $r['sent'] > 0 || $r['devices'] === 0) $s['done'] = $day;
        file_put_contents($f, json_encode($s + ['checked' => $now->format('c'), 'problems' => count($bad), 'push' => $r]));
    }
    flock($lock, LOCK_UN); fclose($lock);
}
