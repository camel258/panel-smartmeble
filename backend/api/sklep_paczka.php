<?php
// Paczka produkcyjna pozycji zamówienia ze sklepu (zbudujmebel, K.25): ZIP z formatkami, DXF, okuciami, etykietami
// i instrukcją. Plik tworzy wtyczka sklepu po opłaceniu (/var/www/zbudujmebel.pl/paczki-produkcyjne – poza stroną,
// ten sam serwer). ?sklep=zbudujmebel&id=<zamówienie>&item=<pozycja>; link otwierany w przeglądarce – token w adresie
// jak w file_download.php. Nazwę pliku bierzemy z danych zamówienia w panelu, nie z adresu.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';

if (empty($_COOKIE['jwt']) && !empty($_GET['token']) && is_string($_GET['token'])) $_COOKIE['jwt'] = (string)$_GET['token'];
$me = auth_user();
if (!$me) { http_response_code(401); exit('Brak autoryzacji – zaloguj się do panelu.'); }
$pdo = db();
$kod = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['sklep'] ?? ''));
$s = sk_sklep($kod);
if (!$s || !sk_can_access($pdo, $me, $kod)) { http_response_code(403); exit('Brak dostępu.'); }
if ($s['paczki'] === '' || !is_dir($s['paczki'])) { http_response_code(404); exit('Paczki produkcyjne nie są skonfigurowane dla tego sklepu.'); }

$st = $pdo->prepare('SELECT numer, dane FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
$st->execute([$kod, (int)($_GET['id'] ?? 0)]);
$z = $st->fetch(PDO::FETCH_ASSOC);
if (!$z) { http_response_code(404); exit('Nie ma takiego zamówienia.'); }
$plik = '';
foreach ((array)(json_decode((string)$z['dane'], true)['pozycje'] ?? []) as $p) {
  if ((int)($p['item_id'] ?? 0) === (int)($_GET['item'] ?? 0)) $plik = basename((string)($p['paczka'] ?? ''));
}
$sciezka = $plik !== '' ? realpath($s['paczki'] . '/' . $plik) : false;
if (!$sciezka || strpos($sciezka, realpath($s['paczki']) . '/') !== 0 || !is_file($sciezka)) { http_response_code(404); exit('Paczka jeszcze nie powstała (tworzy się po opłaceniu zamówienia).'); }

$nazwa = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'zamowienie-' . $z['numer'] . '-' . $plik);
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($sciezka));
header('Content-Disposition: attachment; filename="' . $nazwa . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($sciezka);
