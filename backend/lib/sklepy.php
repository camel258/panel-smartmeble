<?php
// Zamówienia ze sklepów internetowych (WooCommerce) w panelu – zakładka FRONTYPAX (2026-10-23).
// Połączenie przez oficjalne API WooCommerce (klucz read_write) + powiadomienia sklepu (webhook) + synchronizacja
// co 10 min (backend/cli/sklep_sync.php). Kod jest wspólny dla wielu sklepów – kolejny (np. zbudujmebel) = wpis
// w sk_sklepy() i dane w config/.env: SKLEP_<KOD>_URL, SKLEP_<KOD>_CK, SKLEP_<KOD>_CS, SKLEP_<KOD>_WEBHOOK_SECRET.
// Wymaga config/config.php.

/** Sklepy: kod => [nazwa, moduł uprawnień]. */
function sk_sklepy(): array {
  return [
    'frontypax' => ['nazwa' => 'FRONTYPAX', 'modul' => 'frontypax'],
  ];
}

function sk_sklep(string $kod): ?array {
  $s = sk_sklepy()[$kod] ?? null;
  if (!$s) return null;
  $p = 'SKLEP_' . strtoupper($kod) . '_';
  $s['kod'] = $kod;
  $s['url'] = rtrim((string)envv($p . 'URL', ''), '/');
  $s['ck'] = (string)envv($p . 'CK', '');
  $s['cs'] = (string)envv($p . 'CS', '');
  $s['secret'] = (string)envv($p . 'WEBHOOK_SECRET', '');
  return $s;
}

/** Dostęp do zakładki sklepu: Super Admin zawsze, pozostali wg „Uprawnień grup” (domyślnie Administrator). */
function sk_can_access(PDO $pdo, array $me, string $kod): bool {
  $s = sk_sklepy()[$kod] ?? null;
  if (!$s) return false;
  $rid = (int)($me['role_id'] ?? ($me['role'] ?? 0));
  if ($rid === 999) return true;
  try {
    $st = $pdo->prepare('SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code=? LIMIT 1');
    $st->execute([$rid, $s['modul']]);
    $v = $st->fetchColumn();
    if ($v !== false) return (int)$v === 1;
  } catch (Throwable $e) {}
  return $rid === 1;
}

/** Tabele (raz – sprawdzenie po information_schema tylko, gdy brak w pamięci procesu). */
function sk_schemat(PDO $pdo): void {
  static $ok = false;
  if ($ok) return;
  $pdo->exec("CREATE TABLE IF NOT EXISTS sklep_zamowienia (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sklep VARCHAR(30) NOT NULL,
    wc_id BIGINT UNSIGNED NOT NULL,
    numer VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL,
    data_utworzenia DATETIME NULL,
    data_zmiany DATETIME NULL,
    klient VARCHAR(190) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    telefon VARCHAR(60) NOT NULL DEFAULT '',
    miasto VARCHAR(120) NOT NULL DEFAULT '',
    wartosc DECIMAL(12,2) NOT NULL DEFAULT 0,
    waluta VARCHAR(8) NOT NULL DEFAULT 'PLN',
    platnosc VARCHAR(120) NOT NULL DEFAULT '',
    dostawa VARCHAR(160) NOT NULL DEFAULT '',
    pozycji INT UNSIGNED NOT NULL DEFAULT 0,
    dane LONGTEXT NULL,
    zsynchronizowano DATETIME NULL,
    UNIQUE KEY uniq_sklep_wc (sklep, wc_id),
    KEY idx_sklep_status (sklep, status),
    KEY idx_sklep_data (sklep, data_utworzenia)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS sklep_zamowienia_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sklep VARCHAR(30) NOT NULL,
    wc_id BIGINT UNSIGNED NOT NULL,
    akcja VARCHAR(40) NOT NULL,
    opis VARCHAR(500) NOT NULL DEFAULT '',
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(190) NULL,
    utworzono TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_zam (sklep, wc_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS sklep_ustawienia (
    sklep VARCHAR(30) NOT NULL, klucz VARCHAR(60) NOT NULL, wartosc LONGTEXT NULL,
    PRIMARY KEY (sklep, klucz)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $ok = true;
}

function sk_ustawienie(PDO $pdo, string $sklep, string $klucz, $domyslna = null) {
  $st = $pdo->prepare('SELECT wartosc FROM sklep_ustawienia WHERE sklep=? AND klucz=?');
  $st->execute([$sklep, $klucz]);
  $v = $st->fetchColumn();
  return $v === false ? $domyslna : $v;
}
function sk_zapisz_ustawienie(PDO $pdo, string $sklep, string $klucz, $wartosc): void {
  $pdo->prepare('INSERT INTO sklep_ustawienia(sklep,klucz,wartosc) VALUES(?,?,?) ON DUPLICATE KEY UPDATE wartosc=VALUES(wartosc)')->execute([$sklep, $klucz, (string)$wartosc]);
}

/**
 * Zapytanie do API WooCommerce sklepu. Zwraca [kod HTTP, dane (tablica), nagłówki] albo rzuca RuntimeException.
 */
function sk_api(array $s, string $metoda, string $sciezka, ?array $body = null, array $query = []): array {
  if ($s['url'] === '' || $s['ck'] === '' || $s['cs'] === '') throw new RuntimeException('Brak danych połączenia ze sklepem ' . $s['nazwa'] . ' (config/.env).');
  $url = $s['url'] . '/wp-json/wc/v3/' . ltrim($sciezka, '/') . ($query ? '?' . http_build_query($query) : '');
  $ch = curl_init($url);
  $naglowki = [];
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $metoda,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $s['ck'] . ':' . $s['cs'],
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_TIMEOUT => 40,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: PanelSmartmeble/1.0'],
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$naglowki) {
      $p = explode(':', $h, 2);
      if (count($p) === 2) $naglowki[strtolower(trim($p[0]))] = trim($p[1]);
      return strlen($h);
    },
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
  $odp = curl_exec($ch);
  $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $blad = curl_error($ch);
  curl_close($ch);
  if ($odp === false) throw new RuntimeException('Sklep nie odpowiada: ' . $blad);
  $dane = json_decode((string)$odp, true);
  if ($kod >= 400) {
    $msg = is_array($dane) ? (string)($dane['message'] ?? '') : '';
    throw new RuntimeException('Sklep zwrócił błąd ' . $kod . ($msg !== '' ? ': ' . $msg : ''));
  }
  return [$kod, is_array($dane) ? $dane : [], $naglowki];
}

/** Tekst z wartości meta WooCommerce (ceny jako HTML z encjami → „132,85 zł”). */
function sk_tekst($v): string {
  if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
  $t = html_entity_decode(strip_tags((string)$v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $t)));
}

/** Zamówienie z API → dane do zapisania (tylko to, czego panel potrzebuje). */
function sk_z_api(array $o): array {
  $b = (array)($o['billing'] ?? []);
  $sh = (array)($o['shipping'] ?? []);
  $pozycje = [];
  foreach ((array)($o['line_items'] ?? []) as $li) {
    $meta = [];
    foreach ((array)($li['meta_data'] ?? []) as $m) {
      if (str_starts_with((string)($m['key'] ?? ''), '_')) continue;
      $meta[] = ['k' => sk_tekst($m['display_key'] ?? $m['key']), 'v' => sk_tekst($m['display_value'] ?? $m['value'] ?? '')];
    }
    $pozycje[] = [
      'nazwa' => sk_tekst($li['name'] ?? ''), 'ilosc' => (float)($li['quantity'] ?? 0), 'wartosc' => (float)($li['total'] ?? 0) + (float)($li['total_tax'] ?? 0),
      'sku' => (string)($li['sku'] ?? ''), 'meta' => $meta, 'obraz' => (string)($li['image']['src'] ?? ''),
    ];
  }
  $dostawa = [];
  foreach ((array)($o['shipping_lines'] ?? []) as $l) $dostawa[] = ['nazwa' => sk_tekst($l['method_title'] ?? ''), 'wartosc' => (float)($l['total'] ?? 0) + (float)($l['total_tax'] ?? 0)];
  $nip = '';
  foreach ((array)($o['meta_data'] ?? []) as $m) {
    if (preg_match('/nip|vat_?number|tax_?id/i', (string)($m['key'] ?? '')) && trim((string)($m['value'] ?? '')) !== '') { $nip = sk_tekst($m['value']); break; }
  }
  $klient = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
  return [
    'wc_id' => (int)$o['id'],
    'numer' => (string)($o['number'] ?? $o['id']),
    'status' => (string)($o['status'] ?? ''),
    'data_utworzenia' => sk_data($o['date_created'] ?? null),
    'data_zmiany' => sk_data($o['date_modified'] ?? null),
    'klient' => mb_substr($klient !== '' ? $klient : (string)($b['company'] ?? ''), 0, 190),
    'email' => mb_substr((string)($b['email'] ?? ''), 0, 190),
    'telefon' => mb_substr((string)($b['phone'] ?? ''), 0, 60),
    'miasto' => mb_substr((string)(($sh['city'] ?? '') !== '' ? $sh['city'] : ($b['city'] ?? '')), 0, 120),
    'wartosc' => (float)($o['total'] ?? 0),
    'waluta' => (string)($o['currency'] ?? 'PLN'),
    'platnosc' => mb_substr(sk_tekst($o['payment_method_title'] ?? ''), 0, 120),
    'dostawa' => mb_substr(implode(', ', array_column($dostawa, 'nazwa')), 0, 160),
    'pozycji' => count($pozycje),
    'dane' => json_encode([
      'faktura' => ['firma' => (string)($b['company'] ?? ''), 'nip' => $nip, 'adres' => trim(($b['address_1'] ?? '') . ' ' . ($b['address_2'] ?? '')), 'kod' => (string)($b['postcode'] ?? ''), 'miasto' => (string)($b['city'] ?? ''), 'kraj' => (string)($b['country'] ?? '')],
      'wysylka' => ['osoba' => trim(($sh['first_name'] ?? '') . ' ' . ($sh['last_name'] ?? '')), 'firma' => (string)($sh['company'] ?? ''), 'adres' => trim(($sh['address_1'] ?? '') . ' ' . ($sh['address_2'] ?? '')), 'kod' => (string)($sh['postcode'] ?? ''), 'miasto' => (string)($sh['city'] ?? ''), 'kraj' => (string)($sh['country'] ?? ''), 'telefon' => (string)($sh['phone'] ?? '')],
      'pozycje' => $pozycje,
      'dostawa' => $dostawa,
      'rabat' => (float)($o['discount_total'] ?? 0) + (float)($o['discount_tax'] ?? 0),
      'uwagi_klienta' => sk_tekst($o['customer_note'] ?? ''),
      'oplacone' => sk_data($o['date_paid'] ?? null),
      'transakcja' => (string)($o['transaction_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE),
  ];
}

function sk_data($v): ?string {
  if (!$v) return null;
  $t = strtotime((string)$v);   // API podaje czas sklepu (Europe/Warsaw) bez strefy – jak w panelu
  return $t ? date('Y-m-d H:i:s', $t) : null;
}

/** Zapis / aktualizacja zamówienia; zwraca true, gdy coś się zmieniło. */
function sk_zapisz(PDO $pdo, string $sklep, array $o): bool {
  $d = sk_z_api($o);
  $st = $pdo->prepare('SELECT status, data_zmiany, dane FROM sklep_zamowienia WHERE sklep=? AND wc_id=?');
  $st->execute([$sklep, $d['wc_id']]);
  $byl = $st->fetch(PDO::FETCH_ASSOC);
  $kol = array_keys($d);
  $sql = 'INSERT INTO sklep_zamowienia (sklep,' . implode(',', $kol) . ',zsynchronizowano) VALUES (?,' . implode(',', array_fill(0, count($kol), '?')) . ',NOW())'
    . ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($k) => "$k=VALUES($k)", $kol)) . ',zsynchronizowano=NOW()';
  $pdo->prepare($sql)->execute(array_merge([$sklep], array_values($d)));
  return !$byl || $byl['status'] !== $d['status'] || $byl['data_zmiany'] !== $d['data_zmiany'] || $byl['dane'] !== $d['dane'];
}

/**
 * Synchronizacja: wszystkie zamówienia ($pelna) albo zmienione od ostatniej synchronizacji (z zapasem 10 min).
 * Zwraca [pobrano, zmienionych].
 */
function sk_synchronizuj(PDO $pdo, string $kod, bool $pelna = false): array {
  $s = sk_sklep($kod);
  if (!$s) throw new RuntimeException('Nieznany sklep ' . $kod);
  sk_schemat($pdo);
  $start = date('Y-m-d\TH:i:s');
  $od = $pelna ? null : sk_ustawienie($pdo, $kod, 'ostatnia_synchronizacja');
  $q = ['per_page' => 100, 'orderby' => 'modified', 'order' => 'asc', 'status' => 'any'];
  if ($od) $q['modified_after'] = date('Y-m-d\TH:i:s', strtotime($od) - 600);
  $pobrano = 0; $zmian = 0;
  for ($strona = 1; $strona <= 500; $strona++) {
    $q['page'] = $strona;
    [, $lista, $h] = sk_api($s, 'GET', 'orders', null, $q);
    foreach ($lista as $o) { $pobrano++; if (sk_zapisz($pdo, $kod, $o)) $zmian++; }
    if ($strona >= (int)($h['x-wp-totalpages'] ?? 1) || count($lista) < 100) break;
  }
  if ($pelna) {   // zamówienia usunięte w sklepie (np. testowe) – nie pokazujemy ich dalej w panelu
    $ids = [];
    for ($strona = 1; $strona <= 500; $strona++) {
      [, $lista, $h] = sk_api($s, 'GET', 'orders', null, ['per_page' => 100, 'page' => $strona, 'status' => 'any', '_fields' => 'id']);
      foreach ($lista as $o) $ids[] = (int)$o['id'];
      if ($strona >= (int)($h['x-wp-totalpages'] ?? 1) || count($lista) < 100) break;
    }
    if ($ids) {
      $in = implode(',', array_map('intval', $ids));
      $zmian += $pdo->exec("DELETE FROM sklep_zamowienia WHERE sklep=" . $pdo->quote($kod) . " AND wc_id NOT IN ($in)");
    }
  }
  sk_zapisz_ustawienie($pdo, $kod, 'ostatnia_synchronizacja', $start);
  return [$pobrano, $zmian];
}

/** Statusy sklepu (slug => nazwa) – z API, pamiętane 1 h. */
function sk_statusy(PDO $pdo, string $kod): array {
  sk_schemat($pdo);
  $zapis = json_decode((string)sk_ustawienie($pdo, $kod, 'statusy', ''), true);
  if (is_array($zapis) && ($zapis['t'] ?? 0) > time() - 3600 && !empty($zapis['s'])) return $zapis['s'];
  try {
    [, $lista] = sk_api(sk_sklep($kod), 'GET', 'reports/orders/totals');
    $s = [];
    foreach ($lista as $r) if (!empty($r['slug'])) $s[(string)$r['slug']] = sk_tekst($r['name'] ?? $r['slug']);
    if ($s) { sk_zapisz_ustawienie($pdo, $kod, 'statusy', json_encode(['t' => time(), 's' => $s], JSON_UNESCAPED_UNICODE)); return $s; }
  } catch (Throwable $e) {
    error_log('sklepy statusy ' . $kod . ': ' . $e->getMessage());
  }
  return is_array($zapis) && !empty($zapis['s']) ? $zapis['s'] : [
    'pending' => 'Oczekujące na płatność', 'processing' => 'W trakcie realizacji', 'on-hold' => 'Wstrzymane',
    'completed' => 'Zrealizowane', 'cancelled' => 'Anulowane', 'refunded' => 'Zwrócone', 'failed' => 'Nieudane',
  ];
}

function sk_log(PDO $pdo, string $sklep, int $wc_id, string $akcja, string $opis, array $me): void {
  $nazwa = trim((string)($me['name'] ?? ($me['full_name'] ?? ($me['email'] ?? ''))));
  $pdo->prepare('INSERT INTO sklep_zamowienia_log(sklep,wc_id,akcja,opis,user_id,user_name) VALUES(?,?,?,?,?,?)')
    ->execute([$sklep, $wc_id, $akcja, mb_substr($opis, 0, 500), (int)($me['uid'] ?? ($me['id'] ?? 0)) ?: null, $nazwa ?: null]);
}
