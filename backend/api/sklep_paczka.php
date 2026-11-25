<?php
// Pliki produkcyjne pozycji zamówienia ze sklepu zbudujmebel (2026-11-25): panel jest pośrednikiem do API sklepu
// (wtyczka zbudujmebel-konfigurator ≥ 0.17.0, /wp-json/zbudujmebel/v1/paczki/...). Sklep stoi na innym serwerze,
// klucze API WooCommerce zostają w config/.env – do przeglądarki trafia tylko plik.
//   ?sklep=zbudujmebel&id=<zamówienie WC>&lista=1                       → JSON: pliki każdej pozycji
//   ?sklep=zbudujmebel&id=<zamówienie WC>&item=<pozycja>&rodzaj=<rodzaj> → plik (PDF/ZIP)
// rodzaje: cnc, rozkroj, wiercenie, instrukcja, paczka (pełny ZIP). Link otwierany w przeglądarce – token w adresie
// jak w file_download.php.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';

const SP_RODZAJE = ['cnc', 'rozkroj', 'wiercenie', 'instrukcja', 'paczka'];
$lista = !empty($_GET['lista']);
function sp_blad(int $kod, string $tekst, bool $lista) {
  if ($lista) json_err($tekst, $kod);
  http_response_code($kod); header('Content-Type: text/plain; charset=utf-8'); exit($tekst);
}

if (empty($_COOKIE['jwt']) && !empty($_GET['token']) && is_string($_GET['token'])) $_COOKIE['jwt'] = (string)$_GET['token'];
$me = auth_user();
if (!$me) sp_blad(401, 'Brak autoryzacji – zaloguj się do panelu.', $lista);
$pdo = db();
$kod = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['sklep'] ?? ''));
$s = sk_sklep($kod);
if (!$s || $kod !== 'zbudujmebel' || !sk_can_access($pdo, $me, $kod)) sp_blad(403, 'Brak dostępu.', $lista);

$wcId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT numer FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
$st->execute([$kod, $wcId]);
$numer = $st->fetchColumn();
if ($numer === false) sp_blad(404, 'Nie ma takiego zamówienia.', $lista);

$sciezka = 'paczki/' . $wcId;
if (!$lista) {
  $item = (int)($_GET['item'] ?? 0);
  $rodzaj = (string)($_GET['rodzaj'] ?? 'paczka');
  if ($item <= 0 || !in_array($rodzaj, SP_RODZAJE, true)) sp_blad(400, 'Zły adres pliku.', false);
  $sciezka .= '/' . $item . '/' . $rodzaj;
}

// zapytanie do sklepu (Basic Auth kluczami WooCommerce, tylko HTTPS)
if ($s['url'] === '' || $s['ck'] === '' || $s['cs'] === '' || stripos($s['url'], 'https://') !== 0) sp_blad(500, 'Brak danych połączenia ze sklepem.', $lista);
$naglowki = [];
$ch = curl_init($s['url'] . '/wp-json/zbudujmebel/v1/' . $sciezka);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_USERPWD => $s['ck'] . ':' . $s['cs'],
  CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
  CURLOPT_TIMEOUT => 120,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_HTTPHEADER => ['User-Agent: PanelSmartmeble/1.0'],
  CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$naglowki) {
    $p = explode(':', $h, 2);
    if (count($p) === 2) $naglowki[strtolower(trim($p[0]))] = trim($p[1]);
    return strlen($h);
  },
]);
$odp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$bladCurl = curl_error($ch);
if ($odp === false) { error_log('[panel] sklep_paczka: ' . $bladCurl); sp_blad(502, 'Sklep nie odpowiada – spróbuj za chwilę.', $lista); }
if ($http === 404) sp_blad(404, 'Pliku jeszcze nie ma (powstaje po opłaceniu zamówienia).', $lista);
if ($http !== 200) { error_log('[panel] sklep_paczka: HTTP ' . $http . ' dla ' . $sciezka); sp_blad(502, 'Sklep zwrócił błąd ' . $http . '.', $lista); }

if ($lista) {
  $d = json_decode((string)$odp, true);
  $poz = [];
  foreach ((array)($d['pozycje'] ?? []) as $p) {
    $pliki = [];
    foreach ((array)($p['pliki'] ?? []) as $f) {
      if (!in_array($f['rodzaj'] ?? '', SP_RODZAJE, true)) continue;
      $pliki[] = ['rodzaj' => $f['rodzaj'], 'etykieta' => (string)($f['etykieta'] ?? $f['rodzaj']), 'rozmiar' => (int)($f['rozmiar'] ?? 0), 'zmieniony' => (string)($f['zmieniony'] ?? '')];
    }
    $poz[(string)(int)($p['item_id'] ?? 0)] = $pliki;
  }
  json_ok(['ok' => true, 'pozycje' => $poz]);
}

// plik: tylko PDF albo ZIP; nazwa z numeru zamówienia w panelu i rodzaju, nie z odpowiedzi sklepu
$typ = strtolower(trim(explode(';', (string)($naglowki['content-type'] ?? ''))[0]));
$rozszerzenie = ['application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'][$typ] ?? null;
if ($rozszerzenie === null) { error_log('[panel] sklep_paczka: nieoczekiwany typ ' . $typ); sp_blad(502, 'Sklep zwrócił nieoczekiwany plik.', false); }
$nazwa = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'zamowienie-' . $numer . '-' . $item . '-' . $rodzaj) . '.' . $rozszerzenie;
header('Content-Type: ' . ($rozszerzenie === 'pdf' ? 'application/pdf' : 'application/zip'));
header('Content-Length: ' . strlen($odp));
header('Content-Disposition: ' . ($rozszerzenie === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $nazwa . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $odp;
