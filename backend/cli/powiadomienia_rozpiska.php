<?php
// Powiadomienia mailowe dla osoby rozpisującej (decyzja Kamila 2026-11-18).
// Cron co 2 min (konto panel): czyta NOWE wpisy historii zmian (order_audit_log) i nowe pliki (order_files),
// grupuje je po zamówieniu i wysyła jeden mail do osoby rozpisującej (orders.scheduler_id).
// Nie wysyła: autorowi zmiany (gdy sam jest rozpisującym), dla zamówień zakończonych/usuniętych, finansów.
// Wpisy młodsze niż 3 min czekają – kilka szybkich zmian idzie w jednym mailu.
//   php backend/cli/powiadomienia_rozpiska.php            – wysyłka
//   php backend/cli/powiadomienia_rozpiska.php --pokaz    – tylko pokaż, co by poszło (nic nie wysyła, nie przesuwa stanu)
//   … --pokaz --od=<id> [--od-pliku=<id>]                – podgląd od wskazanego wpisu historii / pliku
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/mail.php';

$pokaz = in_array('--pokaz', $argv, true);
$lock = fopen(sys_get_temp_dir() . '/panel-powiadomienia-rozpiska.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
const PR_ZWLOKA_MIN = 3;
const PR_PANEL_URL = 'https://panel.smartmeble.pl/frontend/order_details.html?id=';

function pr_stan(PDO $pdo, string $k, string $poczatek): int {
  $st = $pdo->prepare('SELECT wartosc FROM powiadomienia_rozpiska_stan WHERE klucz=?');
  $st->execute([$k]);
  $v = $st->fetchColumn();
  if ($v !== false) return (int)$v;
  $v = (int)$pdo->query($poczatek)->fetchColumn();   // pierwsze uruchomienie: od teraz, bez zaległości
  $pdo->prepare('INSERT INTO powiadomienia_rozpiska_stan(klucz,wartosc) VALUES(?,?)')->execute([$k, $v]);
  return $v;
}
function pr_zapisz_stan(PDO $pdo, string $k, int $v): void {
  $pdo->prepare('UPDATE powiadomienia_rozpiska_stan SET wartosc=? WHERE klucz=?')->execute([$v, $k]);
}
function pr_ile_zmian(int $n): string {
  if ($n === 1) return 'nowa zmiana';
  $d = $n % 10; $s = $n % 100;
  return $n . (($d >= 2 && $d <= 4 && ($s < 12 || $s > 14)) ? ' zmiany' : ' zmian');
}
function pr_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pr_wartosc($v): string {
  if (is_array($v)) $v = implode(', ', $v);
  $v = trim((string)$v);
  if ($v === '') return '<i>(puste)</i>';
  if (mb_strlen($v) > 400) $v = mb_substr($v, 0, 400) . '…';
  return nl2br(pr_h($v));
}

// pola zamówienia, o których informujemy (bez finansów i pól technicznych)
$POLA = ['status_name','item_description','client_notes','install_date','install_date_new','measure_due_date','measure_done',
  'contract_date','scheduler_name','mounters','client_first_name','client_last_name','client_phone','client_email',
  'client_address','install_street','install_no','install_postcode','install_zip','install_city'];

$lastAudit = pr_stan($pdo, 'audit_id', 'SELECT COALESCE(MAX(id),0) FROM order_audit_log');
$lastFile  = pr_stan($pdo, 'file_id', 'SELECT COALESCE(MAX(id),0) FROM order_files');
// test: --pokaz --od=<id wpisu historii> --od-pliku=<id pliku> – podgląd od wskazanego miejsca
foreach ($argv as $arg) { if ($pokaz && preg_match('/^--od=(\d+)$/', $arg, $m)) $lastAudit = (int)$m[1]; if ($pokaz && preg_match('/^--od-pliku=(\d+)$/', $arg, $m)) $lastFile = (int)$m[1]; }
$granica = date('Y-m-d H:i:s', time() - PR_ZWLOKA_MIN * 60);

$zdarzenia = [];   // order_id => [ ['autor_id'=>, 'autor'=>, 'kiedy'=>, 'html'=>], ... ]
$nowyAudit = $lastAudit; $nowyFile = $lastFile;

$st = $pdo->prepare("SELECT id, order_id, action_code, message, payload_json, user_id, user_name, created_at FROM order_audit_log
  WHERE id>? AND created_at<=? ORDER BY id LIMIT 3000");
$st->execute([$lastAudit, $granica]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $nowyAudit = max($nowyAudit, (int)$r['id']);
  $p = json_decode((string)$r['payload_json'], true) ?: [];
  $html = '';
  switch ($r['action_code']) {
    case 'comment_add':
      $html = '<b>Nowa uwaga:</b><br>' . pr_wartosc($p['note']['text'] ?? '');
      break;
    case 'order_update':
      $linie = [];
      foreach ((array)($p['changes'] ?? []) as $c) {
        if (!in_array($c['field'] ?? '', $POLA, true)) continue;
        $linie[] = '<b>' . pr_h($c['label'] ?? $c['field']) . ':</b> ' . pr_wartosc($c['before'] ?? '') . ' → ' . pr_wartosc($c['after'] ?? '');
      }
      $html = implode('<br>', $linie);
      break;
    case 'status_auto': case 'status_bulk': case 'correction_add': case 'correction_delete':
    case 'complaint_create': case 'complaint_close': case 'duplicated':
      $html = pr_h($r['message']);
      if ($r['action_code'] === 'correction_add' && !empty($p['description'])) $html .= '<br>' . pr_wartosc($p['description']);
      break;
  }
  if ($html === '') continue;
  $zdarzenia[(int)$r['order_id']][] = ['autor_id'=>(int)$r['user_id'], 'autor'=>(string)$r['user_name'], 'kiedy'=>$r['created_at'], 'html'=>$html];
}

$st = $pdo->prepare("SELECT f.id, f.order_id, f.filename, f.uploaded_by, f.uploaded_at, f.correction_item_id, u.full_name
  FROM order_files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE f.id>? AND f.uploaded_at<=? ORDER BY f.id LIMIT 3000");
$st->execute([$lastFile, $granica]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $nowyFile = max($nowyFile, (int)$r['id']);
  $zdarzenia[(int)$r['order_id']][] = ['autor_id'=>(int)$r['uploaded_by'], 'autor'=>(string)$r['full_name'], 'kiedy'=>$r['uploaded_at'],
    'html'=>'<b>Dodano plik' . ($r['correction_item_id'] ? ' (do poprawki)' : '') . ':</b> ' . pr_h($r['filename'])];
}

$tpl = file_get_contents(__DIR__ . '/../lib/mail_template.html');
$cfg = mail_settings_bezpieczne($pdo);
$wyslano = 0;
foreach ($zdarzenia as $orderId => $lista) {
  $st = $pdo->prepare("SELECT o.id, o.client_first_name, o.client_last_name, o.scheduler_id, o.is_finished, o.is_deleted, s.name AS status_name,
      u.full_name AS rozp_imie, u.email AS rozp_email, COALESCE(u.is_active,1) AS rozp_aktywny
    FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id WHERE o.id=?");
  $st->execute([$orderId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o || (int)$o['is_finished'] === 1 || (int)$o['is_deleted'] === 1) continue;
  if (mb_stripos((string)$o['status_name'], 'zakończ') !== false) continue;
  $email = trim((string)$o['rozp_email']);
  if (!$o['scheduler_id'] || !(int)$o['rozp_aktywny'] || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
  $lista = array_values(array_filter($lista, fn($z) => $z['autor_id'] !== (int)$o['scheduler_id']));   // własnych zmian nie wysyłamy
  if (!$lista) continue;

  $klient = trim($o['client_first_name'] . ' ' . $o['client_last_name']);
  $temat = '[Smart Meble] Zamówienie #' . $orderId . ($klient !== '' ? ' – ' . $klient : '') . ': ' . pr_ile_zmian(count($lista));
  $tresc = '<h2>Zmiany w zamówieniu, które rozpisujesz</h2>'
    . '<div>Zamówienie <b>#' . $orderId . '</b>' . ($klient !== '' ? ' – <b>' . pr_h($klient) . '</b>' : '') . '</div>'
    . '<div>Aktualny status: <b>' . pr_h($o['status_name'] ?: '—') . '</b></div>';
  foreach ($lista as $z) {
    $tresc .= '<div style="margin-top:14px;padding:10px 12px;border-left:3px solid #3b82f6;background:#f5f7fb">'
      . '<div style="color:#666;font-size:12px">' . pr_h(date('d.m.Y H:i', strtotime($z['kiedy']))) . ' · ' . pr_h($z['autor'] !== '' ? $z['autor'] : 'nieznany użytkownik') . '</div>'
      . '<div style="margin-top:4px">' . $z['html'] . '</div></div>';
  }
  $html = render_template($tpl, ['content_html' => $tresc, 'cta_url' => PR_PANEL_URL . $orderId]);

  if ($pokaz) { echo "DO: {$o['rozp_imie']} <{$email}> | {$temat} | zdarzeń: " . count($lista) . "\n"; continue; }
  $ok = false; $blad = null;
  try { $ok = (bool)send_html_mail($email, $cfg['mail_from'], $temat, $html); } catch (Throwable $e) { $blad = $e->getMessage(); }
  $pdo->prepare('INSERT INTO powiadomienia_rozpiska_log(order_id,user_id,email,temat,zdarzen,wyslano,blad) VALUES(?,?,?,?,?,?,?)')
    ->execute([$orderId, (int)$o['scheduler_id'], $email, mb_substr($temat, 0, 250), count($lista), $ok ? 1 : 0, $blad !== null ? mb_substr($blad, 0, 250) : ($ok ? null : 'smtp_send=false')]);
  if ($ok) $wyslano++;
  echo date('Y-m-d H:i:s') . ' #' . $orderId . ' → ' . $email . ' (' . count($lista) . ') ' . ($ok ? 'OK' : 'BŁĄD') . "\n";
}

if (!$pokaz) { pr_zapisz_stan($pdo, 'audit_id', $nowyAudit); pr_zapisz_stan($pdo, 'file_id', $nowyFile); }

// email_settings może nie istnieć (brak tabeli na nowym serwerze) – wtedy nadawca z .env
function mail_settings_bezpieczne(PDO $pdo): array {
  try { $c = mail_settings($pdo); } catch (Throwable $e) { $c = []; }
  $from = trim((string)($c['mail_from'] ?? '')) ?: envv('SMTP_FROM', 'powiadomienia@smartmeble.pl');
  return ['mail_from' => $from];
}
