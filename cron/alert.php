<?php
/** Jednou denne (po 10. hodine) zkontroluje stav dat a pri problemu posle upozorneni spravci. Vola ho cron/auto.php. */
function nsr_daily_alert(): void {
    $dir = dirname(__DIR__) . '/data';
    $now = new DateTime('now', new DateTimeZone('Europe/Prague'));
    $h = (int) $now->format('G'); $day = $now->format('Y-m-d');
    if ($h < 10 || $h >= 21) return;                                  // v noci nebudit
    $f = $dir . '/alert.json';
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    if (($s['done'] ?? '') === $day) return;
    require_once dirname(__DIR__) . '/api/_push.php';
    db();                                                             // zalozi slozku data
    $lock = fopen($dir . '/alert.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $tries = ($s['day'] ?? '') === $day ? (int) ($s['tries'] ?? 0) : 0;
    if (($s['done'] ?? '') !== $day && $tries < 3) {                  // nejvys 3 pokusy denne
        $s = ['day' => $day, 'tries' => $tries + 1, 'done' => $s['done'] ?? ''];
        file_put_contents($f, json_encode($s));                       // zapsat predem, aby pad neopakoval pokus donekonecna
        require_once __DIR__ . '/status.php';
        $bad = nsr_status()['bad'];
        $r = $bad ? push_send() : null;
        if (!$bad || $r['sent'] > 0 || $r['devices'] === 0) $s['done'] = $day;
        file_put_contents($f, json_encode($s + ['checked' => $now->format('c'), 'problems' => count($bad), 'push' => $r]));
    }
    flock($lock, LOCK_UN); fclose($lock);
}
