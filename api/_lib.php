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
      CREATE INDEX IF NOT EXISTS attempts_k ON attempts(k, t);');
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
