<?php
declare(strict_types=1);
/** Proxy náhledů produktů (jen image.datart.cz). Telefon si obrázky bere z našeho serveru, ne přímo od Datartu.
 *  Obrázek se uloží do cache_img/ a dál se podává odtud. Povolené jsou jen adresy tvaru https://image.datart.cz/foto/... */
$u = (string) ($_GET['u'] ?? '');
if (!preg_match('~^https://image\.datart\.cz/foto/\d+/[\w./-]{1,120}\.(jpg|jpeg|png|webp)$~i', $u)) { http_response_code(400); exit; }
$dir = dirname(__DIR__) . '/cache_img';
if (!is_dir($dir)) @mkdir($dir, 0755);
$f = $dir . '/' . sha1($u) . '.img';
$out = function (string $path, string $mime): void {
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
};
if (is_file($f) && filesize($f) > 200) $out($f, preg_match('~\.png$~i', $u) ? 'image/png' : (preg_match('~\.webp$~i', $u) ? 'image/webp' : 'image/jpeg'));
$ch = curl_init($u);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; nakupsrozumem)', CURLOPT_REFERER => 'https://www.datart.cz/']);
$d = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
if ($d === false || $code !== 200 || strncasecmp($ct, 'image/', 6) !== 0 || strlen($d) > 800000 || strlen($d) < 200) { http_response_code(404); exit; }
@file_put_contents($f . '.tmp', $d) && @rename($f . '.tmp', $f);
header('Content-Type: ' . strtolower(explode(';', $ct)[0]));
header('Cache-Control: public, max-age=2592000');
header('X-Content-Type-Options: nosniff');
echo $d;
