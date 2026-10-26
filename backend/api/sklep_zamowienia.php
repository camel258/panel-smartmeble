<?php
// Zamówienia ze sklepu internetowego (zakładka FRONTYPAX). ?sklep=frontypax&action=…
//   GET  list     – lista (status, q, od, do, strona) + liczniki statusów
//   GET  get      – szczegóły z bazy panelu + notatki ze sklepu (na żywo) + historia zmian w panelu
//   POST status   – zmiana statusu w sklepie (jak zmiana w WooCommerce – sklep wysyła klientowi swoje maile)
//   POST notatka  – notatka w zamówieniu w sklepie (prywatna albo dla klienta)
//   POST sync     – synchronizacja ręczna (tylko zmienione; pelna=1 – wszystkie)
//   POST status_zbiorczo – zmiana statusu wielu zamówień (ids[], status) – jedno zapytanie „batch” do sklepu
//   POST etap     – zaznaczenie / odznaczenie etapu produkcji (id, grupa, etap, done)
//   POST usun     – do kosza w sklepie (można przywrócić), w panelu zakładka „Kosz”
//   POST przywroc – z kosza ze statusem sprzed usunięcia
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/sklepy.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user(); if (!$me) json_err('unauthorized', 401);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$kod = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['sklep'] ?? 'frontypax'));
$s = sk_sklep($kod);
if (!$s) json_err('bad_request', 400, ['details' => 'Nieznany sklep.']);
if (!sk_can_access($pdo, $me, $kod)) json_err('forbidden', 403);
sk_schemat($pdo);

$akcja = (string)($_GET['action'] ?? 'list');
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

try {
  if ($akcja === 'list') {
    $statusy = sk_statusy($pdo, $kod);
    $where = ['sklep=?']; $par = [$kod];
    $status = (string)($_GET['status'] ?? 'processing');
    if ($status === 'all') $where[] = "status<>'trash'";
    elseif ($status !== '') { $where[] = 'status=?'; $par[] = $status; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
      $where[] = '(numer LIKE ? OR klient LIKE ? OR email LIKE ? OR telefon LIKE ? OR miasto LIKE ? OR dane LIKE ?)';
      for ($i = 0; $i < 6; $i++) $par[] = '%' . $q . '%';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['od'] ?? ''))) { $where[] = 'data_utworzenia >= ?'; $par[] = $_GET['od'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['do'] ?? ''))) { $where[] = 'data_utworzenia <= ?'; $par[] = $_GET['do'] . ' 23:59:59'; }
    $na = 50; $strona = max(1, (int)($_GET['strona'] ?? 1));
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM sklep_zamowienia WHERE $w"); $st->execute($par); $razem = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT wc_id, numer, status, status_przed_koszem, DATE_FORMAT(data_utworzenia,'%Y-%m-%d %H:%i') data, klient, email, telefon, miasto, wartosc, waluta, platnosc, dostawa, pozycji, grupy
      FROM sklep_zamowienia WHERE $w ORDER BY data_utworzenia DESC, wc_id DESC LIMIT $na OFFSET " . (($strona - 1) * $na));
    $st->execute($par);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $etapy = sk_etapy_zamowien($pdo, $kod, array_column($items, 'wc_id'));
    foreach ($items as &$it) { $it['etapy'] = $etapy[(int)$it['wc_id']] ?? (object)[]; $it['grupy'] = array_values(array_filter(explode(',', (string)$it['grupy']))); }
    unset($it);
    $liczniki = [];
    foreach ($pdo->query('SELECT status, COUNT(*) n FROM sklep_zamowienia WHERE sklep=' . $pdo->quote($kod) . ' GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) $liczniki[$r['status']] = (int)$r['n'];
    json_ok(['ok' => true, 'items' => $items, 'etapy_def' => sk_etapy($kod), 'razem' => $razem, 'strona' => $strona, 'na_strone' => $na,
      'statusy' => $statusy, 'liczniki' => $liczniki, 'ostatnia_synchronizacja' => sk_ustawienie($pdo, $kod, 'ostatnia_synchronizacja'), 'sklep' => ['nazwa' => $s['nazwa'], 'url' => $s['url']]]);
  }

  $wc_id = (int)($_GET['id'] ?? ($body['id'] ?? 0));

  if ($akcja === 'get') {
    $st = $pdo->prepare('SELECT * FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
    $st->execute([$kod, $wc_id]);
    $z = $st->fetch(PDO::FETCH_ASSOC);
    if (!$z) json_err('not_found', 404);
    $z['dane'] = json_decode((string)$z['dane'], true);
    $notatki = []; $blad = '';
    try {
      [, $lista] = sk_api($s, 'GET', "orders/$wc_id/notes");
      foreach ($lista as $n) $notatki[] = ['data' => sk_data($n['date_created'] ?? null), 'tresc' => sk_tekst($n['note'] ?? ''), 'dla_klienta' => !empty($n['customer_note']), 'autor' => (string)($n['author'] ?? '')];
    } catch (Throwable $e) { $blad = 'Nie udało się pobrać notatek ze sklepu.'; error_log('sklepy notatki: ' . $e->getMessage()); }
    $st = $pdo->prepare("SELECT akcja, opis, user_name, DATE_FORMAT(utworzono,'%Y-%m-%d %H:%i') data FROM sklep_zamowienia_log WHERE sklep=? AND wc_id=? ORDER BY id DESC LIMIT 50");
    $st->execute([$kod, $wc_id]);
    $historia = $st->fetchAll(PDO::FETCH_ASSOC);
    $z['grupy'] = array_values(array_filter(explode(',', (string)$z['grupy'])));
    $st = $pdo->prepare("SELECT grupa, etap, user_name, DATE_FORMAT(utworzono,'%Y-%m-%d %H:%i') data FROM sklep_etapy WHERE sklep=? AND wc_id=?");
    $st->execute([$kod, $wc_id]);
    $etapy = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $etapy[$e['grupa']][$e['etap']] = ['kto' => $e['user_name'], 'kiedy' => $e['data']];
    json_ok(['ok' => true, 'item' => $z, 'etapy' => (object)$etapy, 'etapy_def' => sk_etapy($kod), 'notatki' => $notatki, 'notatki_blad' => $blad, 'historia' => $historia, 'statusy' => sk_statusy($pdo, $kod),
      'link_sklep' => $s['url'] . '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $wc_id]);
  }

  if (!$post) json_err('method_not_allowed', 405);

  if ($akcja === 'status') {
    $nowy = (string)($body['status'] ?? '');
    $statusy = sk_statusy($pdo, $kod);
    if (!isset($statusy[$nowy]) || $nowy === 'trash') json_err('bad_request', 422, ['details' => 'Nieznany status.']);   // do kosza – przycisk „Usuń”
    $st = $pdo->prepare('SELECT status FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
    $st->execute([$kod, $wc_id]);
    $stary = $st->fetchColumn();
    if ($stary === false) json_err('not_found', 404);
    [, $o] = sk_api($s, 'PUT', "orders/$wc_id", ['status' => $nowy]);
    sk_zapisz($pdo, $kod, $o);
    sk_log($pdo, $kod, $wc_id, 'status', ($statusy[$stary] ?? $stary) . ' → ' . ($statusy[$nowy] ?? $nowy), $me);
    json_ok(['ok' => true, 'status' => (string)($o['status'] ?? $nowy)]);
  }

  if ($akcja === 'notatka') {
    $tresc = trim((string)($body['tresc'] ?? ''));
    if ($tresc === '' || mb_strlen($tresc) > 2000) json_err('bad_request', 422, ['details' => 'Wpisz treść notatki (do 2000 znaków).']);
    $dla = !empty($body['dla_klienta']);
    $kto = trim((string)($me['name'] ?? ($me['email'] ?? '')));
    sk_api($s, 'POST', "orders/$wc_id/notes", ['note' => $tresc . ($kto !== '' && !$dla ? "\n— " . $kto . ' (panel)' : ''), 'customer_note' => $dla]);
    sk_log($pdo, $kod, $wc_id, 'notatka', ($dla ? 'Do klienta: ' : '') . mb_substr($tresc, 0, 300), $me);
    json_ok(['ok' => true]);
  }

  if ($akcja === 'status_zbiorczo') {
    $nowy = (string)($body['status'] ?? '');
    $statusy = sk_statusy($pdo, $kod);
    if (!isset($statusy[$nowy]) || $nowy === 'trash') json_err('bad_request', 422, ['details' => 'Nieznany status.']);   // do kosza – przycisk „Usuń”
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])))));
    if (!$ids || count($ids) > 200) json_err('bad_request', 422, ['details' => 'Zaznacz od 1 do 200 zamówień.']);
    $in = implode(',', $ids);
    $stare = [];
    foreach ($pdo->query("SELECT wc_id, status FROM sklep_zamowienia WHERE sklep=" . $pdo->quote($kod) . " AND wc_id IN ($in) AND status<>'trash'")->fetchAll(PDO::FETCH_ASSOC) as $r) $stare[(int)$r['wc_id']] = $r['status'];
    $zmieniono = 0; $bledy = [];
    foreach (array_chunk(array_keys($stare), 100) as $paczka) {
      [, $odp] = sk_api($s, 'POST', 'orders/batch', ['update' => array_map(fn($id) => ['id' => $id, 'status' => $nowy], $paczka)]);
      foreach ((array)($odp['update'] ?? []) as $o) {
        if (!empty($o['error'])) { $bledy[] = '#' . (int)($o['id'] ?? 0) . ': ' . sk_tekst($o['error']['message'] ?? 'błąd'); continue; }
        sk_zapisz($pdo, $kod, $o);
        sk_log($pdo, $kod, (int)$o['id'], 'status', ($statusy[$stare[(int)$o['id']]] ?? '') . ' → ' . ($statusy[$nowy] ?? $nowy) . ' (zbiorczo)', $me);
        $zmieniono++;
      }
    }
    json_ok(['ok' => true, 'zmieniono' => $zmieniono, 'z' => count($ids), 'bledy' => $bledy]);
  }

  if ($akcja === 'etap') {
    $grupa = (string)($body['grupa'] ?? ''); $etap = (string)($body['etap'] ?? '');
    $def = sk_etapy($kod);
    if (!isset($def[$grupa]['etapy'][$etap])) json_err('bad_request', 422, ['details' => 'Nieznany etap.']);
    $st = $pdo->prepare('SELECT status, grupy FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
    $st->execute([$kod, $wc_id]);
    $z = $st->fetch(PDO::FETCH_ASSOC);
    if (!$z) json_err('not_found', 404);
    $kto = trim((string)($me['name'] ?? ($me['email'] ?? '')));
    if (!empty($body['done'])) {
      $pdo->prepare('INSERT IGNORE INTO sklep_etapy(sklep,wc_id,grupa,etap,user_id,user_name) VALUES(?,?,?,?,?,?)')->execute([$kod, $wc_id, $grupa, $etap, (int)($me['uid'] ?? 0) ?: null, $kto ?: null]);
    } else {
      $pdo->prepare('DELETE FROM sklep_etapy WHERE sklep=? AND wc_id=? AND grupa=? AND etap=?')->execute([$kod, $wc_id, $grupa, $etap]);
    }
    sk_log($pdo, $kod, $wc_id, 'etap', (empty($body['done']) ? 'odznaczono: ' : '') . $def[$grupa]['etapy'][$etap] . ' (' . ($def[$grupa]['nazwa'] ?? $grupa) . ')', $me);
    // „Wysłane” we wszystkich rodzajach zamówienia → panel proponuje status „Zrealizowane”
    $grupy = array_values(array_filter(explode(',', (string)$z['grupy']))) ?: [$grupa];
    $wyslane = $pdo->prepare("SELECT COUNT(DISTINCT grupa) FROM sklep_etapy WHERE sklep=? AND wc_id=? AND etap='wyslane' AND grupa IN (" . implode(',', array_fill(0, count($grupy), '?')) . ')');
    $wyslane->execute(array_merge([$kod, $wc_id], $grupy));
    $wszystko = (int)$wyslane->fetchColumn() === count($grupy);
    json_ok(['ok' => true, 'zaproponuj_zrealizowane' => $wszystko && !empty($body['done']) && $etap === 'wyslane' && $z['status'] !== 'completed']);
  }

  if ($akcja === 'usun') {
    $st = $pdo->prepare('SELECT status FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
    $st->execute([$kod, $wc_id]);
    $stary = $st->fetchColumn();
    if ($stary === false) json_err('not_found', 404);
    if ($stary === 'trash') json_err('bad_request', 422, ['details' => 'Zamówienie jest już w koszu.']);
    sk_api($s, 'DELETE', "orders/$wc_id");   // bez force – kosz w sklepie (30 dni)
    $pdo->prepare("UPDATE sklep_zamowienia SET status='trash', status_przed_koszem=?, zsynchronizowano=NOW() WHERE sklep=? AND wc_id=?")->execute([$stary, $kod, $wc_id]);
    sk_log($pdo, $kod, $wc_id, 'kosz', 'Przeniesiono do kosza (było: ' . (sk_statusy($pdo, $kod)[$stary] ?? $stary) . ')', $me);
    json_ok(['ok' => true]);
  }

  if ($akcja === 'przywroc') {
    $st = $pdo->prepare('SELECT status, status_przed_koszem FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
    $st->execute([$kod, $wc_id]);
    $z = $st->fetch(PDO::FETCH_ASSOC);
    if (!$z || $z['status'] !== 'trash') json_err('bad_request', 422, ['details' => 'Zamówienia nie ma w koszu.']);
    $statusy = sk_statusy($pdo, $kod);
    $cel = (string)($body['status'] ?? ($z['status_przed_koszem'] ?: 'on-hold'));
    if (!isset($statusy[$cel])) $cel = 'on-hold';
    [, $o] = sk_api($s, 'PUT', "orders/$wc_id", ['status' => $cel]);
    sk_zapisz($pdo, $kod, $o);
    sk_log($pdo, $kod, $wc_id, 'kosz', 'Przywrócono z kosza ze statusem ' . ($statusy[$cel] ?? $cel), $me);
    json_ok(['ok' => true, 'status' => (string)($o['status'] ?? $cel)]);
  }

  if ($akcja === 'sync') {
    [$pobrano, $zmian] = sk_synchronizuj($pdo, $kod, !empty($body['pelna']));
    json_ok(['ok' => true, 'pobrano' => $pobrano, 'zmian' => $zmian]);
  }

  json_err('unsupported_action', 400);
} catch (RuntimeException $e) {
  json_err('shop_error', 424, ['details' => $e->getMessage()]);   // 424 – komunikat sklepu widoczny dla użytkownika (5xx ukrywa szczegóły)
}
