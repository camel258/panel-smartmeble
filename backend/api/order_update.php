<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/contract_signers.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');
function req_json_or_post(){ $body = json_decode(file_get_contents('php://input'), true); return is_array($body) ? $body : $_POST; }
function null_if_empty($v){ if($v===null) return null; if(is_string($v)){ $t=trim($v); return $t==='' ? null : $t; } return $v; }
function int_or_null($v){ $v = null_if_empty($v); if($v===null) return null; return (is_numeric($v) && (int)$v>0) ? (int)$v : null; }
function dec_or_zero($v){ if($v===null || $v==='') return '0.00'; return is_numeric($v) ? number_format((float)$v,2,'.','') : '0.00'; }
function audit_user_name(array $me): string {
  $first = trim((string)($me['first_name'] ?? ''));
  $last  = trim((string)($me['last_name'] ?? ''));
  $full = trim($first . ' ' . $last);
  if($full !== '') return $full;
  foreach(['full_name','name','login','username','email'] as $k){
    $v = trim((string)($me[$k] ?? ''));
    if($v !== '') return $v;
  }
  return 'Użytkownik';
}
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db();
$body = req_json_or_post();
$id = (int)($body['id'] ?? 0); if($id<=0) json_err('bad_request',400);
try{
  $pdo->beginTransaction();

  $beforeSt = $pdo->prepare("SELECT o.*, s.name AS status_name, u.full_name AS scheduler_name FROM orders o LEFT JOIN statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id WHERE o.id=? LIMIT 1");
  $beforeSt->execute([$id]);
  $before = $beforeSt->fetch(PDO::FETCH_ASSOC);
  if(!$before) json_err('not_found',404);

  $assSt = $pdo->prepare("SELECT oa.user_id, COALESCE(NULLIF(TRIM(u.full_name),''), NULLIF(TRIM(u.email),''), CONCAT('Użytkownik #', oa.user_id)) AS name FROM order_assignees oa LEFT JOIN users u ON u.id=oa.user_id WHERE oa.order_id=? ORDER BY oa.user_id ASC");
  $assSt->execute([$id]);
  $beforeAssignees = $assSt->fetchAll(PDO::FETCH_ASSOC);

  $scheduler = int_or_null($body['scheduler_id'] ?? null);
  $clientAddress = null_if_empty($body['client_address'] ?? null);
  if($clientAddress===null){
    $street = trim((string)($body['install_street'] ?? ''));
    $no     = trim((string)($body['install_no'] ?? ''));
    $zip    = trim((string)($body['install_postcode'] ?? ($body['install_zip'] ?? '')));
    $city   = trim((string)($body['install_city'] ?? ''));
    $clientAddress = trim($street.' '.$no.', '.$zip.' '.$city);
    if(trim($clientAddress, " ,")==='') $clientAddress = null;
  }
  $postcode = null_if_empty($body['install_postcode'] ?? ($body['install_zip'] ?? null));

  $finishedBucketYear = null;
  $finishedBucketMonth = null;
  $finishedBucket = trim((string)($body['finished_bucket'] ?? ''));
  if($finishedBucket !== '' && preg_match('/^(\d{4})-(\d{2})$/', $finishedBucket, $m)){
    $finishedBucketYear = (int)$m[1];
    $finishedBucketMonth = (int)$m[2];
  }else{
    $fy = $body['finished_bucket_year'] ?? null;
    $fm = $body['finished_bucket_month'] ?? null;
    if(is_numeric($fy) && is_numeric($fm)){
      $finishedBucketYear = (int)$fy;
      $finishedBucketMonth = (int)$fm;
    }
  }
  if($finishedBucketMonth !== null && ($finishedBucketMonth < 1 || $finishedBucketMonth > 12)){
    $finishedBucketYear = null;
    $finishedBucketMonth = null;
  }
  $hasFinishBucketYear = false;
  $hasFinishBucketMonth = false;
  try{ $hasFinishBucketYear = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_year'")->fetchColumn(); }catch(Throwable $e){}
  try{ $hasFinishBucketMonth = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_month'")->fetchColumn(); }catch(Throwable $e){}

    $hasMeasureDone = false;
  try{ $hasMeasureDone = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'measure_done'")->fetchColumn(); }catch(Throwable $e){}
  $hasClientNotes = false;
  try{ $hasClientNotes = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'client_notes'")->fetchColumn(); }catch(Throwable $e){}
  if(!$hasClientNotes){ try{ $pdo->exec("ALTER TABLE orders ADD COLUMN client_notes TEXT NULL"); $hasClientNotes = true; }catch(Throwable $e){} }
  cs_ensure($pdo);
  $hasSigner = array_key_exists('contract_signer_id', $body);
  $roleId = (int)($me['role_id'] ?? ($me['role'] ?? 0));
  $hasFinishBucket = false;
  try{
    $hasFinishBucket = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_year'")->fetchColumn()
      && (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_month'")->fetchColumn();
  }catch(Throwable $e){}
  $sql = "UPDATE orders SET client_first_name=?, client_last_name=?, item_description=?, client_phone=?, client_email=?, client_address=?, install_street=?, install_no=?, install_postcode=?, install_zip=?, install_city=?, contract_date=?, install_date=?, install_date_new=?, measure_due_date=?, ";
  if($hasMeasureDone){ $sql .= "measure_done=?, "; }
  if($hasClientNotes){ $sql .= "client_notes=?, "; }
  if($hasSigner){ $sql .= "contract_signer_id=?, "; }
  if($hasFinishBucket && $roleId===999){ $sql .= "finished_bucket_year=?, finished_bucket_month=?, "; }
  $sql .= "status_id=?, scheduler_id=?, total_amount=? WHERE id=?";
  $st = $pdo->prepare($sql);
  $paramsUpd = [
    null_if_empty($body['client_first_name'] ?? null),
    null_if_empty($body['client_last_name'] ?? null),
    null_if_empty($body['item_description'] ?? null),
    null_if_empty($body['client_phone'] ?? null),
    null_if_empty($body['client_email'] ?? null),
    $clientAddress,
    null_if_empty($body['install_street'] ?? null),
    null_if_empty($body['install_no'] ?? null),
    $postcode,
    $postcode,
    null_if_empty($body['install_city'] ?? null),
    null_if_empty($body['contract_date'] ?? null),
    null_if_empty($body['install_date'] ?? null),
    null_if_empty($body['install_date_new'] ?? null),
    null_if_empty($body['measure_due_date'] ?? null),
  ];
  if($hasMeasureDone){ $paramsUpd[] = !empty($body['measure_done']) ? 1 : 0; }
  if($hasClientNotes){ $paramsUpd[] = null_if_empty($body['client_notes'] ?? null); }
  if($hasSigner){ $sid = (int)($body['contract_signer_id'] ?? 0); $paramsUpd[] = $sid > 0 ? $sid : null; }
  if($hasFinishBucket && $roleId===999){
    $finishMonthRaw = trim((string)($body['finish_bucket'] ?? ''));
    $fy = (int)($body['finished_bucket_year'] ?? 0);
    $fm = (int)($body['finished_bucket_month'] ?? 0);
    if($finishMonthRaw !== '' && preg_match('/^(\d{4})-(\d{2})$/', $finishMonthRaw, $mm)){
      $fy = (int)$mm[1];
      $fm = (int)$mm[2];
    }
    $paramsUpd[] = ($fy>=2000 && $fy<=2100) ? $fy : null;
    $paramsUpd[] = ($fm>=1 && $fm<=12) ? $fm : null;
  }
  $paramsUpd[] = int_or_null($body['status_id'] ?? null);
  $paramsUpd[] = $scheduler;
  $paramsUpd[] = dec_or_zero($body['total_amount'] ?? null);
  $paramsUpd[] = $id;
  $st->execute($paramsUpd);
  $csv = trim((string)($body['mounters'] ?? ''));
  $pdo->prepare("DELETE FROM order_assignees WHERE order_id=?")->execute([$id]);
  if($csv !== ''){
    $ids = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($x)=>$x!==''));
    $ins = $pdo->prepare("INSERT IGNORE INTO order_assignees(order_id,user_id) VALUES(?,?)");
    foreach($ids as $uid){ if(ctype_digit($uid)) $ins->execute([$id,(int)$uid]); }
  }

  $afterSt = $pdo->prepare("SELECT o.*, s.name AS status_name, u.full_name AS scheduler_name FROM orders o LEFT JOIN statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id WHERE o.id=? LIMIT 1");
  $afterSt->execute([$id]);
  $after = $afterSt->fetch(PDO::FETCH_ASSOC);

  $assSt->execute([$id]);
  $afterAssignees = $assSt->fetchAll(PDO::FETCH_ASSOC);

  $changes = [];
  $labels = [
    'client_first_name' => 'Imię klienta',
    'client_last_name' => 'Nazwisko klienta',
    'item_description' => 'Przedmiot / zakres prac',
    'client_phone' => 'Telefon',
    'client_email' => 'E-mail',
    'install_street' => 'Ulica montażu',
    'install_no' => 'Nr montażu',
    'install_zip' => 'Kod pocztowy montażu',
    'install_city' => 'Miasto montażu',
    'contract_date' => 'Data umowy',
    'install_date' => 'Data montażu',
    'install_date_new' => 'Nowa data montażu',
    'measure_due_date' => 'Termin pomiaru',
    'measure_done' => 'Pomiar wykonany',
    'client_notes' => 'Uwagi',
    'contract_signer_id' => 'Osoba podpisująca umowę (ID)',
    'finished_bucket_year' => 'Rok zakończenia',
    'finished_bucket_month' => 'Miesiąc zakończenia',
    'status_name' => 'Status',
    'scheduler_name' => 'Osoba planująca',
    'total_amount' => 'Wartość zamówienia'
  ];
  foreach($labels as $field => $label){
    $oldVal = (string)($before[$field] ?? '');
    $newVal = (string)($after[$field] ?? '');
    if($field === 'total_amount'){
      $oldVal = number_format((float)($before[$field] ?? 0), 2, '.', '');
      $newVal = number_format((float)($after[$field] ?? 0), 2, '.', '');
    }
    if($oldVal !== $newVal){
      $changes[] = ['field'=>$field,'label'=>$label,'before'=>$oldVal!==''?$oldVal:null,'after'=>$newVal!==''?$newVal:null];
    }
  }

  $beforeMounters = array_map(fn($r)=>$r['name'], $beforeAssignees ?: []);
  $afterMounters  = array_map(fn($r)=>$r['name'], $afterAssignees ?: []);
  if(implode(' | ', $beforeMounters) !== implode(' | ', $afterMounters)){
    $changes[] = ['field'=>'mounters','label'=>'Monterzy','before'=>$beforeMounters,'after'=>$afterMounters];
  }

  if($changes){
    order_audit_log($pdo, $id, 'order_update', 'Zmieniono dane zamówienia', [
      'changes'=>$changes,
      'actor'=>['id'=>(int)($me['id'] ?? $me['uid'] ?? 0) ?: null, 'name'=>audit_user_name($me)]
    ], $me);
  }

  $pdo->commit();
  json_ok(['ok'=>true]);
}catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); json_err('save_failed',500,['details'=>$e->getMessage()]); }
