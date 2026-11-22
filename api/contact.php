<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
/*
 *  POST {email, message, web}  -> {ok:true}
 *  Pošle zprávu z formuláře „Napište nám“ na adresu provozovatele (Reply-To = e-mail odesílatele).
 *  Pole „web“ je past na roboty (člověk ho nevidí a nevyplní).
 */
const TO = 'vojtechknize@gmail.com';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'bad_request'], 400);
require_xhr();
$in = body_json(6000);

if (trim((string)($in['web'] ?? '')) !== '') out(['ok' => true]);   // robot: tváříme se, že je hotovo

$email = trim((string)($in['email'] ?? ''));
$msg = trim((string)($in['message'] ?? ''));
if (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
    out(['error' => 'email', 'message' => 'Zadejte platnou e-mailovou adresu, ať vám můžeme odpovědět.'], 422);
$len = mb_strlen($msg);
if ($len < 5) out(['error' => 'message', 'message' => 'Napište prosím, s čím vám můžeme pomoci.'], 422);
if ($len > 2000) out(['error' => 'message', 'message' => 'Zpráva je moc dlouhá (nejvýše 2000 znaků).'], 422);

$key = 'contact:' . ($_SERVER['REMOTE_ADDR'] ?? '?');
if (too_many([$key], 5, 3600)) out(['error' => 'rate', 'message' => 'Dnes jste nám už několikrát psali. Zkuste to prosím později.'], 429);
note_attempt([$key]);

$u = current_user();
$host = (string)($_SERVER['HTTP_HOST'] ?? 'app.nakupsrozumem.cz');
$body = "Zpráva z aplikace Nákup s rozumem\n\n"
      . "Od: $email\n"
      . 'Přihlášený uživatel: ' . ($u ? $u['email'] : 'ne') . "\n"
      . 'Čas: ' . date('Y-m-d H:i:s') . "\n\n"
      . "----\n" . $msg . "\n";
$enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
$headers = [
    'From: ' . $enc('Nákup s rozumem') . ' <info@nakupsrozumem.cz>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
$ok = @mail(TO, $enc('Nákup s rozumem – zpráva od uživatele'), $body, implode("\r\n", $headers));
if (!$ok) out(['error' => 'send', 'message' => 'Zprávu se nepodařilo odeslat. Napište nám prosím přímo na ' . TO . '.'], 502);
out(['ok' => true]);
