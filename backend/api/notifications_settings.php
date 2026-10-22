<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/mail.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user(); if(!$me) json_err('unauthorized',401);
acc_require_admin($me);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ns_req(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }
function ns_table_exists(PDO $pdo, string $name): bool {
  $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $st->execute([$name]);
  return (bool)$st->fetchColumn();
}
function ns_ensure_schema(PDO $pdo): void {
  if(!ns_table_exists($pdo,'notification_recipients')){
    $pdo->exec("CREATE TABLE notification_recipients (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      notification_key VARCHAR(60) NOT NULL,
      email VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_key_email (notification_key, email),
      KEY idx_key (notification_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}
// Lista znanych typów powiadomień - łatwo dopisać kolejne w przyszłości.
function ns_known_types(): array {
  return [
    'correction_added' => 'Zgłoszenie nowego elementu do poprawy',
    'correction_ready'  => 'Wszystkie poprawki gotowe do montażu',
  ];
}

try{
  ns_ensure_schema($pdo);

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $rows = $pdo->query("SELECT id, notification_key, email FROM notification_recipients ORDER BY notification_key ASC, email ASC")->fetchAll(PDO::FETCH_ASSOC);
    json_ok(['ok'=>true,'items'=>$rows,'types'=>ns_known_types()]);
  }

  $body = ns_req();
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'add'){
    $key = trim((string)($body['notification_key'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    if($key==='' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('bad_request',422,['details'=>'Podaj poprawny adres e-mail.']);
    $ins = $pdo->prepare("INSERT IGNORE INTO notification_recipients(notification_key,email) VALUES(?,?)");
    $ins->execute([$key,$email]);
    json_ok(['ok'=>true]);
  }

  if($action === 'delete'){
    $rid = (int)($body['id'] ?? 0);
    if($rid<=0) json_err('bad_request',422);
    $pdo->prepare("DELETE FROM notification_recipients WHERE id=?")->execute([$rid]);
    json_ok(['ok'=>true]);
  }

  if($action === 'test_send'){
    $key = trim((string)($body['notification_key'] ?? ''));
    $types = ns_known_types();
    if($key==='' || !isset($types[$key])) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT email FROM notification_recipients WHERE notification_key=?");
    $st->execute([$key]);
    $emails = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'email');
    if(!$emails) json_err('no_recipients',422,['details'=>'Brak adresów dla tego typu powiadomienia - dodaj adres i spróbuj ponownie.']);
    $from = (string)envv('SMTP_FROM', 'powiadomienia@smartmeble.pl');
    $html = '<div style="font-family:Arial,sans-serif"><h2>Test powiadomienia</h2><p>To jest testowa wiadomość dla typu: <b>'.htmlspecialchars($types[$key]).'</b>.</p><p>Jeśli ją widzisz, wysyłka e-mail dla tego typu powiadomienia działa poprawnie.</p></div>';
    $sent = 0;
    foreach($emails as $to){ if(@send_html_mail($to, $from, '[Smart Meble] Test powiadomienia', $html)) $sent++; }
    json_ok(['ok'=>true,'sent'=>$sent,'total'=>count($emails)]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
