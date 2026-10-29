<?php
// Wyceny ze strony www (konfigurator smartmeble.pl/konfigurator/) – wspólne funkcje.
require_once __DIR__ . '/api_helpers.php';

const WW_STATUSES = [
  'nowa'      => ['Nowa', '#b45309'],
  'w_trakcie' => ['W trakcie', '#1d4ed8'],
  'oferta'    => ['Oferta wysłana', '#047857'],
  'zamknieta' => ['Zamknięta', '#4b5563'],
  'odrzucona' => ['Odrzucona', '#991b1b'],
];
const WW_TYPES = ['kuchnia' => 'Kuchnia', 'szafa' => 'Szafa', 'garderoba' => 'Garderoba', 'lazienka' => 'Meble łazienkowe', 'rtv' => 'Szafka RTV i salon', 'regal' => 'Regały i komody', 'przedpokoj' => 'Przedpokój', 'inne' => 'Inne meble'];
const WW_EXT = ['pdf','jpg','jpeg','png','heic','webp','dwg','dxf','skp','zip','rar','7z','doc','docx','xls','xlsx','txt','mp4','mov','pro','kit','sat','step','stp'];

function ww_max_total(): int { return (int)envv('WWW_QUOTES_MAX_MB', 50) * 1024 * 1024; }
function ww_max_files(): int { return (int)envv('WWW_QUOTES_MAX_FILES', 30); }
function ww_chunk(): int { return 2 * 1024 * 1024; }

function ww_ensure_schema(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $pdo->exec("CREATE TABLE IF NOT EXISTS www_wyceny (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NOT NULL DEFAULT '',
    token_hash CHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'szkic',
    path VARCHAR(20) NOT NULL DEFAULT '',
    furniture_type VARCHAR(20) NOT NULL DEFAULT '',
    client_name VARCHAR(190) NOT NULL DEFAULT '',
    client_phone VARCHAR(60) NOT NULL DEFAULT '',
    client_email VARCHAR(190) NOT NULL DEFAULT '',
    client_city VARCHAR(120) NOT NULL DEFAULT '',
    client_postcode VARCHAR(20) NOT NULL DEFAULT '',
    deadline VARCHAR(60) NOT NULL DEFAULT '',
    source VARCHAR(80) NOT NULL DEFAULT '',
    message TEXT NULL,
    other_json TEXT NULL,
    config_json LONGTEXT NULL,
    summary_json LONGTEXT NULL,
    agd TINYINT(1) NOT NULL DEFAULT 0,
    files_declared INT UNSIGNED NOT NULL DEFAULT 0,
    consent_at DATETIME NULL,
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    assigned_user_id INT UNSIGNED NULL,
    linked_quote_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY st (status), KEY ip (ip_hash, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // dla tabeli utworzonej wcześniejszą wersją
  try { if (!$pdo->query("SHOW COLUMNS FROM www_wyceny LIKE 'source'")->fetch()) $pdo->exec("ALTER TABLE www_wyceny ADD COLUMN source VARCHAR(80) NOT NULL DEFAULT '' AFTER deadline"); } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS www_wyceny_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'upload',
    orig_name VARCHAR(255) NOT NULL DEFAULT '',
    stored_name VARCHAR(120) NOT NULL DEFAULT '',
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    chunks_total INT UNSIGNED NOT NULL DEFAULT 1,
    chunks_done INT UNSIGNED NOT NULL DEFAULT 0,
    complete TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY req (request_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS www_wyceny_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(190) NOT NULL DEFAULT '',
    kind VARCHAR(10) NOT NULL DEFAULT 'note',
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY req (request_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $done = true;
}

/** Katalog plików zgłoszenia – poza publicznym katalogiem, obok plików zamówień. */
function ww_dir(int $id): string {
  $base = api_private_files_base() . '/wyceny_www';
  if (!is_dir($base)) {
    @mkdir($base, 0775, true);
    @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
  }
  $dir = $base . '/' . $id;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function ww_ip_hash(): string {
  return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)envv('APP_KEY', 'smartmeble'));
}

function ww_rm_dir(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir . '/' . $f;
    is_dir($p) ? ww_rm_dir($p) : @unlink($p);
  }
  @rmdir($dir);
}

/** Usuwa porzucone szkice (rozpoczęte, niewysłane) starsze niż 2 dni. */
function ww_cleanup_drafts(PDO $pdo): void {
  $ids = $pdo->query("SELECT id FROM www_wyceny WHERE status='szkic' AND created_at < (NOW() - INTERVAL 2 DAY) LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($ids as $id) {
    ww_rm_dir(api_private_files_base() . '/wyceny_www/' . (int)$id);
    $pdo->prepare('DELETE FROM www_wyceny_files WHERE request_id=?')->execute([(int)$id]);
    $pdo->prepare('DELETE FROM www_wyceny_notes WHERE request_id=?')->execute([(int)$id]);
    $pdo->prepare('DELETE FROM www_wyceny WHERE id=?')->execute([(int)$id]);
  }
}

function ww_user_name(PDO $pdo, array $me): string {
  $uid = (int)($me['id'] ?? $me['uid'] ?? $me['user_id'] ?? 0);
  if ($uid > 0) {
    try {
      $st = $pdo->prepare('SELECT full_name FROM users WHERE id=?');
      $st->execute([$uid]);
      $n = trim((string)$st->fetchColumn());
      if ($n !== '') return $n;
    } catch (Throwable $e) {}
  }
  return (string)($me['full_name'] ?? $me['email'] ?? 'Użytkownik');
}

/** Dostęp do zakładki: admin/superadmin zawsze, inne role wg „Uprawnień grup” (moduł www_quotes). */
function ww_can_access(PDO $pdo, array $me): bool {
  $role = api_current_role_id($pdo, $me);
  if (in_array($role, [1, 999], true)) return true;
  try {
    if (api_table_exists($pdo, 'role_module_permissions')) {
      $st = $pdo->prepare("SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code='www_quotes' LIMIT 1");
      $st->execute([$role]);
      return (int)$st->fetchColumn() === 1;
    }
  } catch (Throwable $e) {}
  return false;
}

function ww_esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Powiadomienia e-mail po wysłaniu zgłoszenia. */
function ww_send_mails(PDO $pdo, array $r): void {
  require_once __DIR__ . '/mail.php';
  $cfg  = mail_settings($pdo);
  $from = $cfg['mail_from'] ?: 'powiadomienia@smartmeble.pl';
  $to   = (string)envv('WWW_QUOTES_NOTIFY', 'info@smartmeble.pl');
  $base = rtrim((string)envv('BASE_URL', 'https://panel.smartmeble.pl'), '/');
  $url  = $base . '/frontend/wyceny_www.html?id=' . (int)$r['id'];
  $type = $r['path'] === 'pliki' ? 'Pliki / projekt klienta' : (WW_TYPES[$r['furniture_type']] ?? 'Konfigurator');

  $nf = $pdo->prepare("SELECT COUNT(*) FROM www_wyceny_files WHERE request_id=? AND kind='upload' AND complete=1");
  $nf->execute([(int)$r['id']]);
  $files = (int)$nf->fetchColumn();

  $html = '<h2>Nowa wycena ze strony: ' . ww_esc($r['number']) . '</h2>'
    . '<p><b>' . ww_esc($r['client_name']) . '</b><br>Tel. ' . ww_esc($r['client_phone']) . ($r['client_email'] ? '<br>' . ww_esc($r['client_email']) : '')
    . ($r['client_city'] ? '<br>' . ww_esc(trim($r['client_postcode'] . ' ' . $r['client_city'])) : '') . '</p>'
    . '<p>Rodzaj: <b>' . ww_esc($type) . '</b>' . ($r['deadline'] ? '<br>Termin: ' . ww_esc($r['deadline']) : '') . (!empty($r['source']) ? '<br>Skąd o nas wie: ' . ww_esc($r['source']) : '') . '<br>Pliki: ' . $files
    . ($r['agd'] ? '<br><b>Klient chce też ofertę AGD/RTV</b>' : '') . '</p>'
    . ($r['message'] ? '<p>Wiadomość:<br>' . nl2br(ww_esc($r['message'])) . '</p>' : '');
  $tpl  = @file_get_contents(__DIR__ . '/mail_template.html') ?: '<html><body>{{content_html}}<br><a href="{{cta_url}}">Otwórz</a></body></html>';
  $body = str_replace('Otwórz zamówienie', 'Otwórz wycenę w panelu', render_template($tpl, ['content_html' => $html, 'cta_url' => $url]));
  send_html_mail($to, $from, '[Smart Meble] Nowa wycena ' . $r['number'] . ': ' . $r['client_name'], $body);

  if ($r['client_email'] && filter_var($r['client_email'], FILTER_VALIDATE_EMAIL)) {
    $c = '<p>Dzień dobry,</p><p>dziękujemy za przesłanie zapytania o wycenę mebli. Numer zgłoszenia: <b>' . ww_esc($r['number']) . '</b>.</p>'
      . '<p>Nasz technolog przejrzy projekt i przygotuje bezpłatną wycenę – zwykle w ciągu 2–3 dni roboczych. Jeśli będzie potrzebował dodatkowych informacji, zadzwoni. Szczegółowy pomiar robimy po złożeniu zamówienia.</p>'
      . '<p>W razie pytań: tel. 531 241 110, e-mail info@smartmeble.pl.</p><p>Zespół Smart Meble<br>SMART MEBLE Sp. z o.o., ul. Gdyńska 111, 80-209 Chwaszczyno</p>';
    send_html_mail($r['client_email'], $from, 'Smart Meble – potwierdzenie zgłoszenia ' . $r['number'], '<html><body>' . $c . '</body></html>');
  }
}
