<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
/*
 *  POST ?a=register {email,password}   -> {user}     (a rovnou přihlásí)
 *  POST ?a=login    {email,password}   -> {user}
 *  POST ?a=logout                       -> {ok}
 *  POST ?a=delete   {password}          -> {ok}       (smaže účet a jeho přihlášení)
 *  GET  ?a=me                           -> {user} nebo 401
 */
const MIN_PASS = 8;

function client_key(string $email): array {
    return ['ip:' . ($_SERVER['REMOTE_ADDR'] ?? '?'), 'em:' . strtolower($email)];
}
function valid_email(string $e): bool { return strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($a === 'me' && $method === 'GET') {
    $u = current_user();
    if (!$u) out(['error' => 'auth'], 401);
    out(['user' => public_user($u)]);
}
if ($method !== 'POST') out(['error' => 'bad_request'], 400);
require_xhr();
$in = body_json(2000);

if ($a === 'register') {
    $email = trim((string)($in['email'] ?? '')); $pass = (string)($in['password'] ?? '');
    if (!valid_email($email)) out(['error' => 'email', 'message' => 'Zadejte platnou e-mailovou adresu.'], 422);
    if (mb_strlen($pass) < MIN_PASS || strlen($pass) > 200) out(['error' => 'password', 'message' => 'Heslo musí mít aspoň ' . MIN_PASS . ' znaků.'], 422);
    $keys = client_key('reg');
    if (too_many([$keys[0]], 10, 3600)) out(['error' => 'rate', 'message' => 'Příliš mnoho pokusů. Zkuste to prosím za hodinu.'], 429);
    note_attempt([$keys[0]]);
    $ex = db()->prepare('SELECT id FROM users WHERE email=?'); $ex->execute([$email]);
    if ($ex->fetch()) out(['error' => 'exists', 'message' => 'Tento e-mail už je zaregistrovaný. Zkuste se přihlásit.'], 409);
    db()->prepare('INSERT INTO users(email,pass,created,is_admin) VALUES(?,?,?,?)')
        ->execute([$email, password_hash($pass, PASSWORD_ARGON2ID), time(), 0]);
    $id = (int)db()->lastInsertId();
    start_session($id);
    $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]); $u = $s->fetch(); $u['is_admin_eff'] = is_admin_row($u);
    out(['user' => public_user($u)]);
}

if ($a === 'login') {
    $email = trim((string)($in['email'] ?? '')); $pass = (string)($in['password'] ?? '');
    $keys = client_key($email);
    if (too_many($keys, 8, 900)) out(['error' => 'rate', 'message' => 'Příliš mnoho nepovedených pokusů. Zkuste to prosím za 15 minut.'], 429);
    $s = db()->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); $u = $s->fetch();
    // vždy ověříme hash, i když účet neexistuje, aby nešlo poznat zaregistrované e-maily podle rychlosti
    $ok = $u ? password_verify($pass, $u["pass"]) : (password_hash($pass, PASSWORD_ARGON2ID) === "");
    if (!$ok) { note_attempt($keys); out(['error' => 'login', 'message' => 'E-mail nebo heslo nesedí. Zkontrolujte je prosím.'], 401); }
    if (password_needs_rehash($u['pass'], PASSWORD_ARGON2ID)) db()->prepare('UPDATE users SET pass=? WHERE id=?')->execute([password_hash($pass, PASSWORD_ARGON2ID), $u['id']]);
    start_session((int)$u['id']);
    $u['is_admin_eff'] = is_admin_row($u);
    out(['user' => public_user($u)]);
}

if ($a === 'logout') { end_session(); out(['ok' => true]); }

if ($a === 'delete') {
    $u = require_user();
    if (!password_verify((string)($in['password'] ?? ''), $u['pass'])) out(['error' => 'login', 'message' => 'Heslo nesedí.'], 401);
    db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$u['id']]);
    db()->prepare('DELETE FROM activity WHERE user_id=?')->execute([$u['id']]);
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$u['id']]);
    set_cookie('', time() - 3600);
    out(['ok' => true]);
}
out(['error' => 'bad_request'], 400);
