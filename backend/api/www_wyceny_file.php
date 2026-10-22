<?php
// Pobieranie / podgląd plików z wycen www (tylko dla zalogowanych z dostępem do zakładki).
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/www_wyceny.php';

// link otwierany w przeglądarce – token w adresie, tak jak w file_download.php
if (empty($_COOKIE['jwt']) && !empty($_GET['token']) && is_string($_GET['token'])) {
  $_COOKIE['jwt'] = (string)$_GET['token'];
}
$me = auth_user();
if (!$me) { http_response_code(401); exit('Brak autoryzacji.'); }
$pdo = db();
if (!ww_can_access($pdo, $me)) { http_response_code(403); exit('Brak dostępu.'); }

$fid = (int)($_GET['file'] ?? 0);
$st = $pdo->prepare("SELECT f.* FROM www_wyceny_files f JOIN www_wyceny w ON w.id=f.request_id WHERE f.id=? AND f.complete=1 AND w.status<>'szkic'");
$st->execute([$fid]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('Nie znaleziono pliku.'); }

$path = api_private_files_base() . '/wyceny_www/' . (int)$f['request_id'] . '/' . basename($f['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje na serwerze.'); }

$ext = strtolower(pathinfo($f['orig_name'], PATHINFO_EXTENSION));
$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$inline = !empty($_GET['inline']) && isset($mimes[$ext]);
$name = preg_replace('/[\r\n"]+/', '_', $f['orig_name']);

header('Content-Type: ' . ($inline ? $mimes[$ext] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($f['orig_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
