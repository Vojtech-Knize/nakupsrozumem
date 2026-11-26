<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
/* Správa pro administrátora: počty uživatelů, grafy, akční nabídky podle řetězců. */
$u = require_user();
if (!$u['is_admin_eff']) out(['error' => 'forbidden'], 403);
$a = $_GET['a'] ?? '';
$pdo = db();

function day_range(int $n): array {
    $o = []; for ($i = $n - 1; $i >= 0; $i--) $o[] = date('Y-m-d', time() - $i * 86400); return $o;
}

if ($a === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $now = time(); $days = day_range(90);
    $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $cnt = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
    $reg = []; foreach ($pdo->query("SELECT date(created,'unixepoch','localtime') d, COUNT(*) c FROM users GROUP BY d") as $r) $reg[$r['d']] = (int)$r['c'];
    $before = $cnt("SELECT COUNT(*) FROM users WHERE date(created,'unixepoch','localtime') < ?", [$days[0]]);
    $series = []; $cum = $before;
    foreach ($days as $d) { $cum += $reg[$d] ?? 0; $series[] = ['d' => $d, 'new' => $reg[$d] ?? 0, 'total' => $cum]; }
    $dau = []; foreach ($pdo->query('SELECT day, COUNT(*) c FROM activity GROUP BY day') as $r) $dau[$r['day']] = (int)$r['c'];
    foreach ($series as &$s) $s['active'] = $dau[$s['d']] ?? 0; unset($s);

    // akční nabídky podle řetězců
    $chains = []; $updated = null;
    $f = __DIR__ . '/../data.json';
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true) ?: [];
        $updated = $j['updated'] ?? null; $today = date('Y-m-d');
        foreach ($j['items'] ?? [] as $it) {
            $c = $it['chain'] ?? '?';
            $chains[$c] ??= ['chain' => $c, 'items' => 0, 'valid' => 0, 'stores' => 0, 'ok' => $j['sources'][$c]['ok'] ?? null];
            $chains[$c]['items']++;
            if (($it['validFrom'] ?? '0000') <= $today && ($it['validTo'] ?? '9999') >= $today) $chains[$c]['valid']++;
        }
    }
    $sf = __DIR__ . '/../stores.json';
    if (is_file($sf)) foreach ((json_decode((string)file_get_contents($sf), true)['stores'] ?? []) as $st) { $c = $st['chain'] ?? '?'; $chains[$c] ??= ['chain' => $c, 'items' => 0, 'valid' => 0, 'stores' => 0, 'ok' => null]; $chains[$c]['stores']++; }

    $users = $pdo->query('SELECT id,email,created,last_login,last_seen,is_admin,list_code FROM users ORDER BY created DESC LIMIT 300')->fetchAll();
    $shared = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT list_code FROM users WHERE list_code IS NOT NULL GROUP BY list_code HAVING COUNT(*) > 1)')->fetchColumn();
    foreach ($users as &$r) { $r['admin'] = in_array(strtolower($r['email']), admin_emails(), true) || (int)$r['is_admin'] === 1; $r['shared'] = $r['list_code'] ? $cnt('SELECT COUNT(*) FROM users WHERE list_code=?', [$r['list_code']]) > 1 : false; unset($r['list_code'], $r['is_admin']); } unset($r);
    out([
        'totals' => ['users' => $total, 'new7' => $cnt('SELECT COUNT(*) FROM users WHERE created > ?', [$now - 7 * 86400]), 'new30' => $cnt('SELECT COUNT(*) FROM users WHERE created > ?', [$now - 30 * 86400]),
                     'active1' => $cnt('SELECT COUNT(DISTINCT user_id) FROM activity WHERE day = ?', [date('Y-m-d')]), 'active7' => $cnt('SELECT COUNT(DISTINCT user_id) FROM activity WHERE day >= ?', [$days[83]]),
                     'active30' => $cnt('SELECT COUNT(DISTINCT user_id) FROM activity WHERE day >= ?', [$days[60]]), 'shared_lists' => $shared],
        'series' => $series, 'chains' => array_values($chains), 'data_updated' => $updated, 'users' => $users, 'me' => $u['id'],
    ]);
}

/* upozorneni na problemy s daty (jen pro spravce) */
if (str_starts_with($a, 'push_')) {
    require __DIR__ . '/_push.php';
    require __DIR__ . '/../cron/status.php';
    $mine = function () use ($pdo, $u): int { $s = $pdo->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id=?'); $s->execute([$u['id']]); return (int)$s->fetchColumn(); };
    push_table();
    if ($a === 'push_info' && $_SERVER['REQUEST_METHOD'] === 'GET') out(['key' => push_keys()['pub'], 'devices' => $mine(), 'status' => nsr_status()['text']]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'bad_request'], 400);
    require_xhr();
    $ep = (string)(body_json(2000)['endpoint'] ?? '');
    if (!push_allowed_endpoint($ep)) out(['error' => 'bad_endpoint', 'message' => 'Tuhle službu upozornění aplikace nepodporuje.'], 422);
    if ($a === 'push_sub') {
        $pdo->prepare('INSERT OR REPLACE INTO push_subs(endpoint,user_id,created) VALUES(?,?,?)')->execute([$ep, $u['id'], time()]);
        out(['ok' => true, 'devices' => $mine()]);
    }
    if ($a === 'push_unsub') {
        $pdo->prepare('DELETE FROM push_subs WHERE endpoint=? AND user_id=?')->execute([$ep, $u['id']]);
        out(['ok' => true, 'devices' => $mine()]);
    }
    if ($a === 'push_test') {
        $r = push_send($ep, (int)$u['id']);
        if (!$r['sent']) out(['error' => 'send', 'message' => 'Zkušební upozornění se nepodařilo odeslat (' . ($r['codes'] ? 'odpověď ' . implode(', ', $r['codes']) : 'telefon není přihlášený') . ').'], 502);
        out(['ok' => true]);
    }
    out(['error' => 'bad_request'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_xhr();
    $in = body_json(2000); $id = (int)($in['id'] ?? 0);
    $s = $pdo->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]); $t = $s->fetch();
    if (!$t) out(['error' => 'not_found'], 404);
    if ($a === 'delete_user') {
        if ($id === (int)$u['id']) out(['error' => 'self', 'message' => 'Vlastní účet tady smazat nejde.'], 422);
        foreach (['sessions', 'activity'] as $tb) $pdo->prepare("DELETE FROM $tb WHERE user_id=?")->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        out(['ok' => true]);
    }
    if ($a === 'reset_password') {
        $al = 'abcdefghjkmnpqrstuvwxyz23456789'; $pw = '';
        for ($i = 0; $i < 10; $i++) $pw .= $al[random_int(0, strlen($al) - 1)];
        $pdo->prepare('UPDATE users SET pass=? WHERE id=?')->execute([password_hash($pw, PASSWORD_ARGON2ID), $id]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);
        out(['ok' => true, 'password' => $pw]);
    }
    if ($a === 'set_admin') {
        if ($id === (int)$u['id']) out(['error' => 'self', 'message' => 'Sám sobě práva měnit nemůžete.'], 422);
        $pdo->prepare('UPDATE users SET is_admin=? WHERE id=?')->execute([!empty($in['v']) ? 1 : 0, $id]);
        out(['ok' => true]);
    }
}
out(['error' => 'bad_request'], 400);
