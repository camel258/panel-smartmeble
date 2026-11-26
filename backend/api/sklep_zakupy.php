<?php
// Lista materiałów do zamówienia (płyty, obrzeża, okucia) dla zamówień zbudujmebel – 2026-11-27, zgoda Kamila.
// Panel jest pośrednikiem do API sklepu /wp-json/zbudujmebel/v1/zakupy (wtyczka zbudujmebel-konfigurator ≥ 0.19.0);
// przy kilku zamówieniach płyty liczy sklep ze WSPÓLNEGO rozkroju (jedno zapytanie, nie suma list).
// Odhaczanie „mamy” – tylko w pojedynczym zamówieniu (tabela sklep_zakupy_mamy). Na liście zbiorczej pozycja jest
// „mamy”, gdy jest odhaczona we wszystkich zaznaczonych zamówieniach, w których występuje.
//   GET  ?sklep=zbudujmebel&zamowienia=615,629         → lista (+ pole mamy przy pozycjach)
//   POST ?sklep=zbudujmebel&action=mamy  {wc_id, klucz, mamy}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user(); if (!$me) json_err('unauthorized', 401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$kod = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['sklep'] ?? ''));
$s = sk_sklep($kod);
if (!$s || $kod !== 'zbudujmebel' || !sk_can_access($pdo, $me, $kod)) json_err('forbidden', 403);
if ($s['url'] === '' || $s['ck'] === '' || $s['cs'] === '' || stripos($s['url'], 'https://') !== 0) json_err('Brak danych połączenia ze sklepem.', 500);

function zk_schemat(PDO $pdo): void {
  static $ok = false; if ($ok) return; $ok = true;
  $pdo->exec("CREATE TABLE IF NOT EXISTS sklep_zakupy_mamy (
    sklep VARCHAR(30) NOT NULL, wc_id BIGINT UNSIGNED NOT NULL, klucz VARCHAR(190) NOT NULL,
    mamy TINYINT(1) NOT NULL DEFAULT 0, user_id INT UNSIGNED NULL, user_name VARCHAR(190) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sklep, wc_id, klucz)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// zapytania do sklepu równolegle; wynik: [kodHTTP, dane] dla każdego klucza
function zk_pobierz(array $s, array $zapytania): array {
  $mh = curl_multi_init(); $uchwyty = [];
  foreach ($zapytania as $k => $ids) {
    $ch = curl_init($s['url'] . '/wp-json/zbudujmebel/v1/zakupy?zamowienia=' . implode(',', $ids));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $s['ck'] . ':' . $s['cs'], CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
      CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: PanelSmartmeble/1.0']]);
    curl_multi_add_handle($mh, $ch); $uchwyty[$k] = $ch;
  }
  do { $st = curl_multi_exec($mh, $aktywne); if ($aktywne) curl_multi_select($mh, 1.0); } while ($aktywne && $st === CURLM_OK);
  $wynik = [];
  foreach ($uchwyty as $k => $ch) {
    $wynik[$k] = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)curl_multi_getcontent($ch), true)];
    curl_multi_remove_handle($mh, $ch);
  }
  curl_multi_close($mh);
  return $wynik;
}
function zk_klucze(array $d): array {
  $k = [];
  foreach (['plyty', 'obrzeza', 'okucia'] as $g) foreach ((array)($d[$g] ?? []) as $p) if (!empty($p['klucz'])) $k[] = (string)$p['klucz'];
  return $k;
}

try {
  zk_schemat($pdo);
  if (($_GET['action'] ?? '') === 'mamy') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('bad_request', 405);
    $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $wc = (int)($b['wc_id'] ?? 0); $klucz = trim((string)($b['klucz'] ?? ''));
    if ($wc <= 0 || $klucz === '' || mb_strlen($klucz) > 190) json_err('bad_request', 400);
    $st = $pdo->prepare('SELECT 1 FROM sklep_zamowienia WHERE sklep=? AND wc_id=?'); $st->execute([$kod, $wc]);
    if (!$st->fetchColumn()) json_err('Nie ma takiego zamówienia.', 404);
    $uid = (int)($me['uid'] ?? $me['id'] ?? 0); $kto = trim((string)($me['name'] ?? $me['full_name'] ?? ''));
    $pdo->prepare('INSERT INTO sklep_zakupy_mamy(sklep,wc_id,klucz,mamy,user_id,user_name) VALUES(?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE mamy=VALUES(mamy), user_id=VALUES(user_id), user_name=VALUES(user_name)')
      ->execute([$kod, $wc, $klucz, !empty($b['mamy']) ? 1 : 0, $uid ?: null, $kto !== '' ? $kto : null]);
    json_ok(['ok' => true, 'kto' => $kto, 'kiedy' => date('Y-m-d H:i')]);
  }

  $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['zamowienia'] ?? ''))), fn($v) => $v > 0)));
  if (!$ids || count($ids) > 100) json_err('Wybierz od 1 do 100 zamówień.', 400);
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT wc_id, numer FROM sklep_zamowienia WHERE sklep=? AND wc_id IN ($in)");
  $st->execute(array_merge([$kod], $ids));
  $numery = $st->fetchAll(PDO::FETCH_KEY_PAIR);
  $ids = array_values(array_filter($ids, fn($i) => isset($numery[$i])));
  if (!$ids) json_err('Nie ma takich zamówień.', 404);

  // lista główna; przy kilku zamówieniach dodatkowo lista każdego z osobna (żeby wiedzieć, gdzie występuje pozycja)
  $zap = ['razem' => $ids];
  if (count($ids) > 1) foreach ($ids as $i) $zap[$i] = [$i];
  $odp = zk_pobierz($s, $zap);
  [$http, $d] = $odp['razem'];
  if ($http === 422) json_ok(['ok' => true, 'puste' => true, 'komunikat' => 'W wybranych zamówieniach nie ma mebli do policzenia.']);
  if ($http !== 200 || !is_array($d) || empty($d['ok'])) { error_log('[panel] sklep_zakupy: HTTP ' . $http); json_err('Sklep nie zwrócił listy (błąd ' . $http . ') – spróbuj za chwilę.', 502); }

  // gdzie występuje każda pozycja
  $gdzie = [];
  if (count($ids) === 1) { foreach (zk_klucze($d) as $k) $gdzie[$k] = [$ids[0]]; }
  else foreach ($ids as $i) { [$h, $di] = $odp[$i]; if ($h === 200 && is_array($di)) foreach (zk_klucze($di) as $k) $gdzie[$k][] = $i; }

  $st = $pdo->prepare("SELECT wc_id, klucz, mamy, user_name, DATE_FORMAT(updated_at,'%Y-%m-%d %H:%i') kiedy FROM sklep_zakupy_mamy WHERE sklep=? AND wc_id IN ($in)");
  $st->execute(array_merge([$kod], $ids));
  $mamy = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mamy[(int)$r['wc_id']][$r['klucz']] = $r;

  foreach (['plyty', 'obrzeza', 'okucia'] as $g) {
    foreach ($d[$g] as &$p) {
      $k = (string)($p['klucz'] ?? ''); $w = $gdzie[$k] ?? [];
      $odh = array_filter($w, fn($i) => !empty($mamy[$i][$k]['mamy']));
      $p['zamowienia'] = array_map(fn($i) => (string)$numery[$i], $w);
      $p['mamy'] = $w && count($odh) === count($w);
      $p['mamy_czesc'] = count($odh) > 0 && count($odh) < count($w);
      if (count($ids) === 1 && $p['mamy']) { $p['kto'] = $mamy[$ids[0]][$k]['user_name'] ?? ''; $p['kiedy'] = $mamy[$ids[0]][$k]['kiedy'] ?? ''; }
    }
    unset($p);
  }
  json_ok(['ok' => true, 'zamowienia' => array_map(fn($i) => ['wc_id' => $i, 'numer' => (string)$numery[$i]], $ids), 'jedno' => count($ids) === 1,
    'plyty' => $d['plyty'] ?? [], 'obrzeza' => $d['obrzeza'] ?? [], 'okucia' => $d['okucia'] ?? [],
    'zapasObrzeza' => $d['zapasObrzeza'] ?? null, 'bledy' => $d['bledy'] ?? [], 'pominiete' => $d['pominiete'] ?? []]);
} catch (Throwable $e) {
  json_err('zakupy_failed', 500, ['details' => $e->getMessage()]);
}
