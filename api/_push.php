<?php
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';
/* Upozorneni do telefonu spravce (Web Push). Posila se prazdna zprava podepsana klicem VAPID;
 * text si service worker po doruceni nacte z cron/status.php. Klic vznikne pri prvnim pouziti v chranene slozce data. */

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
