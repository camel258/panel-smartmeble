<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
require_once __DIR__ . '/../lib/order_audit.php';
require_once __DIR__ . '/../lib/mail.php';
header('Content-Type: application/json; charset=utf-8');

// ID statusów w tabeli order_statuses (ustalone w panelu przez administratora).
const CORR_STATUS_PILNE_POPRAWKI = 10;      // PILNE-POPRAWKI
const CORR_STATUS_UMOWIC_PO_POPRAWKACH = 11; // UMÓWIĆ PO POPRAWKACH

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);

function corr_req(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }
function corr_user_name(array $me, int $uid): string {
  $full = trim((string)($me['full_name'] ?? ''));
  if($full !== '') return $full;
  foreach(['name','login','username','email'] as $k){
    $v = trim((string)($me[$k] ?? ''));
    if($v !== '') return $v;
  }
  return 'Użytkownik #'.$uid;
}
function corr_table_exists(PDO $pdo, string $name): bool {
  $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $st->execute([$name]);
  return (bool)$st->fetchColumn();
}
function corr_ensure_schema(PDO $pdo): void {
  if(!corr_table_exists($pdo,'order_correction_items')){
    $pdo->exec("CREATE TABLE order_correction_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(255) NOT NULL DEFAULT '',
      description TEXT NULL,
      stage_zgloszone TINYINT(1) NOT NULL DEFAULT 1,
      stage_zamowione TINYINT(1) NOT NULL DEFAULT 0,
      stage_wyciete TINYINT(1) NOT NULL DEFAULT 0,
      stage_oklejone TINYINT(1) NOT NULL DEFAULT 0,
      stage_przygotowane TINYINT(1) NOT NULL DEFAULT 0,
      stage_gotowe TINYINT(1) NOT NULL DEFAULT 0,
      created_by_name VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } else {
    // Migracja z wcześniejszej wersji tabeli (miała tylko pole "description").
    try{ $pdo->exec("ALTER TABLE order_correction_items ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
    try{ $pdo->exec("ALTER TABLE order_correction_items MODIFY description TEXT NULL"); }catch(Throwable $e){}
    try{ $pdo->exec("UPDATE order_correction_items SET name=description WHERE (name='' OR name IS NULL) AND description IS NOT NULL AND description<>''"); }catch(Throwable $e){}
  }
}
function corr_ensure_files_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100),
    size_bytes BIGINT UNSIGNED DEFAULT 0,
    uploaded_by INT UNSIGNED,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(order_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try{ $pdo->exec("ALTER TABLE order_files ADD COLUMN correction_item_id BIGINT UNSIGNED NULL"); }catch(Throwable $e){}
}
function corr_stage_defs(): array {
  // kod => [kolumna w bazie, etykieta]
  return [
    'zgloszone'     => ['stage_zgloszone','Zgłoszone'],
    'zamowione'     => ['stage_zamowione','Zamówione'],
    'wyciete'       => ['stage_wyciete','Wycięte'],
    'oklejone'      => ['stage_oklejone','Oklejone'],
    'przygotowane'  => ['stage_przygotowane','Przygotowane do montażu'],
    'gotowe'        => ['stage_gotowe','Gotowe do montażu'],
  ];
}
function corr_row_out(array $row, array $files=[]): array {
  $stages = [];
  foreach(corr_stage_defs() as $code=>$def){ $stages[$code] = (int)($row[$def[0]] ?? 0); }
  return [
    'id'=>(int)$row['id'],
    'order_id'=>(int)$row['order_id'],
    'name'=>(string)($row['name'] ?? ''),
    'description'=>(string)($row['description'] ?? ''),
    'stages'=>$stages,
    'files'=>$files,
    'created_by_name'=>(string)($row['created_by_name'] ?? ''),
    'created_at'=>(string)($row['created_at'] ?? ''),
  ];
}
function corr_all_done(PDO $pdo, int $orderId): bool {
  $st = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(stage_gotowe),0) done FROM order_correction_items WHERE order_id=?");
  $st->execute([$orderId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $total = (int)($r['total'] ?? 0);
  $done = (int)($r['done'] ?? 0);
  return $total>0 && $total===$done;
}
function corr_private_base(): string { return api_private_files_base(); }   // config/.env PRIVATE_FILES_DIR
function corr_store_uploaded_file(PDO $pdo, int $orderId, int $itemId, array $fileArr, int $uploaderId): ?array {
  if(empty($fileArr['tmp_name']) || !is_uploaded_file($fileArr['tmp_name'])) return null;
  if((int)($fileArr['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
  if((int)($fileArr['size'] ?? 0) > 30*1024*1024) return null; // limit 30 MB
  $base = corr_private_base();
  $dir = $base . '/orders_public/' . $orderId;
  if(!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
  $name = basename((string)$fileArr['name']);
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
  if($name === '') $name = 'zdjecie.jpg';
  $dst = $dir . '/' . uniqid('corr_', true) . '_' . $name;
  if(!@move_uploaded_file($fileArr['tmp_name'], $dst)) return null;
  $mime = $fileArr['type'] ?? 'application/octet-stream';
  try{ $finfo = new finfo(FILEINFO_MIME_TYPE); $detected = $finfo->file($dst); if($detected) $mime = $detected; }catch(Throwable $e){}
  $pdo->prepare("INSERT INTO order_files(order_id,filename,storage_path,mime_type,size_bytes,uploaded_by,correction_item_id) VALUES(?,?,?,?,?,?,?)")
    ->execute([$orderId, $name, $dst, $mime, (int)@filesize($dst), $uploaderId ?: null, $itemId]);
  $fid = (int)$pdo->lastInsertId();
  $isImg = strpos((string)$mime,'image/')===0;
  $url = '../backend/api/file_download.php?file_id='.$fid;
  return ['id'=>$fid,'name'=>$name,'url'=>$url,'is_image'=>$isImg,'thumb'=>$isImg?($url.'&inline=1'):null];
}
function corr_files_for_items(PDO $pdo, array $itemIds): array {
  if(!$itemIds) return [];
  $in = implode(',', array_fill(0,count($itemIds),'?'));
  $st = $pdo->prepare("SELECT id, correction_item_id, filename, mime_type FROM order_files WHERE correction_item_id IN ($in) ORDER BY id ASC");
  $st->execute($itemIds);
  $map = [];
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
    $iid = (int)$r['correction_item_id'];
    $isImg = strpos((string)$r['mime_type'],'image/')===0;
    $url = '../backend/api/file_download.php?file_id='.(int)$r['id'];
    $map[$iid][] = ['id'=>(int)$r['id'],'name'=>$r['filename'],'url'=>$url,'is_image'=>$isImg,'thumb'=>$isImg?($url.'&inline=1'):null];
  }
  return $map;
}
function corr_stage_badges_html(array $stages): string {
  $out = '';
  foreach(corr_stage_defs() as $code=>$def){
    $done = !empty($stages[$code]);
    $bg = $done ? '#16a34a' : '#e9eaec';
    $fg = $done ? '#ffffff' : '#6b7280';
    $out .= '<span style="display:inline-block;margin:2px 5px 2px 0;padding:4px 10px;border-radius:999px;background:'.$bg.';color:'.$fg.';font-size:11px;font-weight:600;font-family:Arial,Helvetica,sans-serif;">'
          . ($done ? '&#10003; ' : '') . htmlspecialchars($def[1]) . '</span>';
  }
  return $out;
}
function corr_item_card_html(array $row, bool $highlight=false): string {
  $border = $highlight ? '#2563eb' : '#e5e7eb';
  $bg = $highlight ? '#eff6ff' : '#ffffff';
  $stages = [];
  foreach(corr_stage_defs() as $code=>$def){ $stages[$code] = (int)($row[$def[0]] ?? 0); }
  return '<div style="border:1px solid '.$border.';background:'.$bg.';border-radius:10px;padding:14px 16px;margin-bottom:10px;font-family:Arial,Helvetica,sans-serif;">'
    . ($highlight ? '<div style="display:inline-block;background:#2563eb;color:#ffffff;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 9px;border-radius:999px;margin-bottom:8px;">Nowo dodane</div><br>' : '')
    . '<div style="font-weight:700;font-size:15px;color:#111827;margin-bottom:2px;">'.htmlspecialchars($row['name'] ?? '').'</div>'
    . (!empty($row['description']) ? '<div style="color:#4b5563;font-size:13px;margin-bottom:10px;">'.nl2br(htmlspecialchars($row['description'])).'</div>' : '<div style="margin-bottom:10px;"></div>')
    . '<div>'.corr_stage_badges_html($stages).'</div>'
    . '</div>';
}
function corr_all_items_html(PDO $pdo, int $orderId, ?int $highlightId=null): string {
  $st = $pdo->prepare("SELECT * FROM order_correction_items WHERE order_id=? ORDER BY id ASC");
  $st->execute([$orderId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows) return '<p style="color:#6b7280;font-family:Arial,Helvetica,sans-serif;font-size:13px;">Brak elementów do poprawy.</p>';
  $html = '';
  foreach($rows as $row){ $html .= corr_item_card_html($row, $highlightId !== null && (int)$row['id'] === $highlightId); }
  return $html;
}
function corr_other_items_html(PDO $pdo, int $orderId, int $excludeId): string {
  $st = $pdo->prepare("SELECT * FROM order_correction_items WHERE order_id=? AND id<>? ORDER BY id ASC");
  $st->execute([$orderId, $excludeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows) return '<p style="color:#9a3412;font-family:Arial,Helvetica,sans-serif;font-size:13px;margin:0;">Brak innych zgłoszonych elementów do poprawy w tym zamówieniu.</p>';
  $html = '';
  foreach($rows as $row){ $html .= corr_item_card_html($row, false); }
  return $html;
}
function corr_order_summary(PDO $pdo, int $orderId): array {
  $st = $pdo->prepare("SELECT o.client_first_name, o.client_last_name, o.client_phone, o.client_email, o.item_description, u.full_name AS scheduler_name FROM orders o LEFT JOIN users u ON u.id=o.scheduler_id WHERE o.id=? LIMIT 1");
  $st->execute([$orderId]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
function corr_order_info_box_html(array $o): string {
  $clientName = trim(($o['client_first_name'] ?? '').' '.($o['client_last_name'] ?? ''));
  $contactParts = [];
  if(!empty($o['client_phone'])) $contactParts[] = 'tel. '.htmlspecialchars($o['client_phone']);
  if(!empty($o['client_email'])) $contactParts[] = htmlspecialchars($o['client_email']);
  $contact = $contactParts ? implode(' &nbsp;•&nbsp; ', $contactParts) : '—';
  $item = !empty($o['item_description']) ? nl2br(htmlspecialchars($o['item_description'])) : '—';
  $scheduler = !empty($o['scheduler_name']) ? htmlspecialchars($o['scheduler_name']) : '—';
  $row = function(string $label, string $value): string {
    return '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;font-size:12px;white-space:nowrap;vertical-align:top;">'.$label.'</td><td style="padding:4px 0;color:#111827;font-size:13px;">'.$value.'</td></tr>';
  };
  $rows = $row('Klient', htmlspecialchars($clientName !== '' ? $clientName : '—'))
    . $row('Kontakt', $contact)
    . $row('Przedmiot', $item)
    . $row('Osoba rozpisująca', $scheduler);
  return '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-family:Arial,Helvetica,sans-serif;">'
    .'<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:8px;">Informacje o zamówieniu</div>'
    .'<table style="width:100%;border-collapse:collapse;">'.$rows.'</table>'
    .'</div>';
}
function corr_mail_wrap(string $title, string $bodyHtml, string $ctaUrl): string {
  return '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px 12px;">'
    .'<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">'
      .'<div style="background:#111827;color:#ffffff;padding:20px 24px;">'
        .'<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#b7d331;font-weight:700;">Smart Meble — panel zamówień</div>'
        .'<div style="font-size:19px;font-weight:700;margin-top:6px;">'.htmlspecialchars($title).'</div>'
      .'</div>'
      .'<div style="padding:22px 24px;">'
        .$bodyHtml
      .'</div>'
      .'<div style="padding:18px 24px;border-top:1px solid #e5e7eb;">'
        .'<a href="'.htmlspecialchars($ctaUrl).'" style="display:inline-block;background:#b7d331;color:#0b0f14;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">Otwórz zamówienie w panelu</a>'
      .'</div>'
    .'</div>'
  .'</div>';
}
function corr_send_notification(PDO $pdo, int $orderId, int $newItemId, string $itemName, string $itemDesc, array $me): void {
  try{
    $st = $pdo->prepare("SELECT email FROM notification_recipients WHERE notification_key='correction_added'");
    $st->execute();
    $emails = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'email');
    if(!$emails) return;
    $orderInfo = corr_order_summary($pdo, $orderId);
    $clientName = trim(($orderInfo['client_first_name'] ?? '').' '.($orderInfo['client_last_name'] ?? ''));
    $baseUrl = rtrim((string)envv('BASE_URL',''), '/');
    $link = $baseUrl !== '' ? ($baseUrl.'/frontend/order_details.html?id='.$orderId) : ('order_details.html?id='.$orderId);
    $who = trim((string)($me['full_name'] ?? $me['email'] ?? 'Użytkownik'));

    $newItemSt = $pdo->prepare("SELECT * FROM order_correction_items WHERE id=? LIMIT 1");
    $newItemSt->execute([$newItemId]);
    $newItemRow = $newItemSt->fetch(PDO::FETCH_ASSOC) ?: ['name'=>$itemName,'description'=>$itemDesc,'stage_zgloszone'=>1];

    $body = '<p style="font-size:14px;color:#111827;margin:0 0 4px;">Zamówienie: <b>'.htmlspecialchars($clientName).'</b></p>'
      .'<p style="font-size:13px;color:#6b7280;margin:0 0 18px;">Zgłosił: '.htmlspecialchars($who).' • Status zamówienia ustawiony automatycznie na <b>PILNE-POPRAWKI</b>.</p>'
      .corr_order_info_box_html($orderInfo)
      .'<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:10px;">Nowo zgłoszony element</div>'
      .corr_item_card_html($newItemRow, true)
      .'<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:16px;margin-top:22px;">'
        .'<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9a3412;margin-bottom:10px;">Pozostałe poprawki w tym zamówieniu</div>'
        .corr_other_items_html($pdo, $orderId, $newItemId)
      .'</div>';
    $html = corr_mail_wrap('Nowy element do poprawy', $body, $link);
    $from = (string)envv('SMTP_FROM', 'powiadomienia@smartmeble.pl');
    foreach($emails as $to){ @send_html_mail($to, $from, '[Smart Meble] Nowy element do poprawy', $html); }
  }catch(Throwable $e){}
}

function corr_send_ready_notification(PDO $pdo, int $orderId): void {
  try{
    $st = $pdo->prepare("SELECT email FROM notification_recipients WHERE notification_key='correction_ready'");
    $st->execute();
    $emails = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'email');
    if(!$emails) return;
    $orderInfo = corr_order_summary($pdo, $orderId);
    $clientName = trim(($orderInfo['client_first_name'] ?? '').' '.($orderInfo['client_last_name'] ?? ''));
    $baseUrl = rtrim((string)envv('BASE_URL',''), '/');
    $link = $baseUrl !== '' ? ($baseUrl.'/frontend/order_details.html?id='.$orderId) : ('order_details.html?id='.$orderId);
    $body = '<p style="font-size:14px;color:#111827;margin:0 0 4px;">Zamówienie: <b>'.htmlspecialchars($clientName).'</b></p>'
      .'<p style="font-size:13px;color:#6b7280;margin:0 0 18px;">Wszystkie zgłoszone elementy do poprawy są gotowe. Status zamówienia ustawiony automatycznie na <b>UMÓWIĆ PO POPRAWKACH</b>.</p>'
      .corr_order_info_box_html($orderInfo)
      .'<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:10px;">Lista poprawek w tym zamówieniu</div>'
      .corr_all_items_html($pdo, $orderId, null);
    $html = corr_mail_wrap('Poprawki gotowe do montażu', $body, $link);
    $from = (string)envv('SMTP_FROM', 'powiadomienia@smartmeble.pl');
    foreach($emails as $to){ @send_html_mail($to, $from, '[Smart Meble] Poprawki gotowe do montażu', $html); }
  }catch(Throwable $e){}
}
function corr_maybe_mark_ready(PDO $pdo, int $orderId, array $me): void {
  if(!corr_all_done($pdo, $orderId)) return;
  try{
    $st = $pdo->prepare("SELECT status_id FROM orders WHERE id=? LIMIT 1");
    $st->execute([$orderId]);
    $curStatus = (int)$st->fetchColumn();
    if($curStatus === CORR_STATUS_UMOWIC_PO_POPRAWKACH) return; // już ustawione — nie wysyłaj powiadomienia drugi raz
    $pdo->prepare("UPDATE orders SET status_id=? WHERE id=?")->execute([CORR_STATUS_UMOWIC_PO_POPRAWKACH, $orderId]);
    order_audit_log($pdo, $orderId, 'status_auto', 'Wszystkie elementy do poprawy gotowe — status ustawiony automatycznie na "UMÓWIĆ PO POPRAWKACH"', [], $me);
    corr_send_ready_notification($pdo, $orderId);
  }catch(Throwable $e){}
}

try{
  corr_ensure_schema($pdo);
  $stageDefs = corr_stage_defs();
  $canViewCorrections = $uid>0 ? (bool)can_view_field($uid,'corrections') : true;
  $canEditCorrections = $uid>0 ? (bool)can_edit_field($uid,'corrections') : true;

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $orderId = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);
    if($orderId<=0) json_err('bad_request',422);
    if(!$canViewCorrections) json_ok(['ok'=>true,'items'=>[]]);
    $st = $pdo->prepare("SELECT * FROM order_correction_items WHERE order_id=? ORDER BY id ASC");
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Samo-naprawa: jeśli wszystkie elementy są już gotowe a status nie został wcześniej
    // przestawiony (np. przez błąd w poprzedniej wersji kodu), zrób to teraz.
    corr_maybe_mark_ready($pdo, $orderId, $me);
    corr_ensure_files_schema($pdo);
    $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
    $filesMap = corr_files_for_items($pdo, $ids);
    $items = array_map(function($row) use ($filesMap){ return corr_row_out($row, $filesMap[(int)$row['id']] ?? []); }, $rows);
    json_ok(['ok'=>true,'items'=>$items]);
  }

  $body = corr_req();
  if(in_array(trim((string)($body['action'] ?? '')), ['add','upload_file','delete_file','toggle_stage','update_name','delete'], true) && !$canEditCorrections){
    json_err('forbidden',403,['details'=>'corrections_edit_required']);
  }
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'add'){
    $orderId = (int)($body['order_id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $desc = trim((string)($body['description'] ?? ''));
    if($orderId<=0 || $name==='') json_err('bad_request',422,['details'=>'Podaj nazwę elementu.']);
    $creatorName = corr_user_name($me,$uid);
    $ins = $pdo->prepare("INSERT INTO order_correction_items(order_id,name,description,stage_zgloszone,created_by_name) VALUES(?,?,?,1,?)");
    $ins->execute([$orderId,$name,$desc,$creatorName]);
    $newId = (int)$pdo->lastInsertId();

    $uploadedFile = null;
    if(!empty($_FILES['file']) && is_array($_FILES['file'])){
      corr_ensure_files_schema($pdo);
      $uploadedFile = corr_store_uploaded_file($pdo, $orderId, $newId, $_FILES['file'], $uid);
    }

    try{ $pdo->prepare("UPDATE orders SET status_id=? WHERE id=?")->execute([CORR_STATUS_PILNE_POPRAWKI, $orderId]); }catch(Throwable $e){}

    try{
      order_audit_log($pdo, $orderId, 'correction_add', 'Dodano element do poprawy: '.$name, ['name'=>$name,'description'=>$desc], $me);
      order_audit_log($pdo, $orderId, 'status_auto', 'Status ustawiony automatycznie na "PILNE-POPRAWKI" po dodaniu elementu do poprawy', [], $me);
    }catch(Throwable $e){}

    corr_send_notification($pdo, $orderId, $newId, $name, $desc, $me);

    json_ok(['ok'=>true,'id'=>$newId,'file'=>$uploadedFile]);
  }

  if($action === 'upload_file'){
    $itemId = (int)($body['id'] ?? 0);
    if($itemId<=0 || empty($_FILES['file'])) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT order_id FROM order_correction_items WHERE id=? LIMIT 1");
    $st->execute([$itemId]);
    $orderId = (int)$st->fetchColumn();
    if($orderId<=0) json_err('not_found',404);
    corr_ensure_files_schema($pdo);
    $file = corr_store_uploaded_file($pdo, $orderId, $itemId, $_FILES['file'], $uid);
    if(!$file) json_err('upload_failed',500);
    json_ok(['ok'=>true,'file'=>$file]);
  }

  if($action === 'delete_file'){
    $fileId = (int)($body['file_id'] ?? 0);
    if($fileId<=0) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT storage_path FROM order_files WHERE id=? AND correction_item_id IS NOT NULL LIMIT 1");
    $st->execute([$fileId]);
    $path = $st->fetchColumn();
    if($path === false) json_err('not_found',404);
    if($path && ($path = api_resolve_storage_path((string)$path)) !== '') @unlink($path);
    $pdo->prepare("DELETE FROM order_files WHERE id=?")->execute([$fileId]);
    json_ok(['ok'=>true]);
  }

  if($action === 'toggle_stage'){
    $itemId = (int)($body['id'] ?? 0);
    $stage = trim((string)($body['stage'] ?? ''));
    $isDone = isset($body['is_done']) ? (int)!!$body['is_done'] : null;
    if($itemId<=0 || !isset($stageDefs[$stage])) json_err('bad_request',422);
    $col = $stageDefs[$stage][0];

    $st = $pdo->prepare("SELECT id, order_id, name FROM order_correction_items WHERE id=? LIMIT 1");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row) json_err('not_found',404);

    $cur = $pdo->prepare("SELECT `$col` FROM order_correction_items WHERE id=?");
    $cur->execute([$itemId]);
    $curVal = (int)$cur->fetchColumn();

    $newVal = $isDone !== null ? $isDone : ($curVal ? 0 : 1);
    $up = $pdo->prepare("UPDATE order_correction_items SET `$col`=? WHERE id=?");
    $up->execute([$newVal,$itemId]);

    try{
      order_audit_log($pdo, (int)$row['order_id'], 'correction_stage', ($newVal ? 'Zaznaczono' : 'Cofnięto').' etap "'.$stageDefs[$stage][1].'" dla poprawki: '.$row['name'], ['stage'=>$stage,'is_done'=>$newVal], $me);
    }catch(Throwable $e){}

    corr_maybe_mark_ready($pdo, (int)$row['order_id'], $me);

    json_ok(['ok'=>true]);
  }

  if($action === 'update_name'){
    $itemId = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $desc = trim((string)($body['description'] ?? ''));
    if($itemId<=0 || $name==='') json_err('bad_request',422);
    $pdo->prepare("UPDATE order_correction_items SET name=?, description=? WHERE id=?")->execute([$name,$desc,$itemId]);
    json_ok(['ok'=>true]);
  }

  if($action === 'delete'){
    $itemId = (int)($body['id'] ?? 0);
    if($itemId<=0) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT order_id, name FROM order_correction_items WHERE id=? LIMIT 1");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row) json_err('not_found',404);

    try{
      corr_ensure_files_schema($pdo);
      $fq = $pdo->prepare("SELECT storage_path FROM order_files WHERE correction_item_id=?");
      $fq->execute([$itemId]);
      foreach($fq->fetchAll(PDO::FETCH_ASSOC) as $fr){ if(!empty($fr['storage_path']) && ($rp = api_resolve_storage_path((string)$fr['storage_path'])) !== '' && $fr['storage_path'] = $rp) @unlink($fr['storage_path']); }
      $pdo->prepare("DELETE FROM order_files WHERE correction_item_id=?")->execute([$itemId]);
    }catch(Throwable $e){}

    $pdo->prepare("DELETE FROM order_correction_items WHERE id=?")->execute([$itemId]);
    try{ order_audit_log($pdo, (int)$row['order_id'], 'correction_delete', 'Usunięto element do poprawy: '.$row['name'], [], $me); }catch(Throwable $e){}

    if(corr_all_done($pdo, (int)$row['order_id'])){
      corr_maybe_mark_ready($pdo, (int)$row['order_id'], $me);
    }

    json_ok(['ok'=>true]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
