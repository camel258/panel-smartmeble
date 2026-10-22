<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$role = api_current_role_id($pdo, $me);
if(!in_array($role, [1,2,999], true)) json_err('forbidden', 403);

$body = api_request_data();
$srcId = (int)($body['order_id'] ?? 0);
if($srcId <= 0) json_err('bad_request', 400);
$parts = is_array($body['parts'] ?? null) ? $body['parts'] : [];
$wClient = !empty($parts['client']);
$wDates  = !empty($parts['dates']);
$wAmount = !empty($parts['amounts']);
$wItem   = !empty($parts['item']);

try{
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
  $st->execute([$srcId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if(!$o) json_err('not_found', 404);

  $cols = []; $vals = [];
  $put = function($c, $v) use (&$cols, &$vals){ $cols[] = $c; $vals[] = $v; };

  if($wClient){
    foreach(['client_first_name','client_last_name','client_phone','client_email','client_address','install_street','install_no','install_postcode','install_city','client_notes'] as $c){
      if(array_key_exists($c, $o)) $put($c, $o[$c]);
    }
    if(array_key_exists('install_zip', $o)) $put('install_zip', $o['install_zip']);
  }
  if($wDates){
    foreach(['contract_date','measure_due_date','install_date','install_date_new','scheduler_id','contract_signer_id'] as $c){
      if(array_key_exists($c, $o)) $put($c, $o[$c]);
    }
  }
  if($wItem && array_key_exists('item_description', $o)) $put('item_description', $o['item_description']);
  if($wAmount) $put('total_amount', $o['total_amount']);

  // Status: kopiujemy, ale nie statusy poprawek (10/11)
  $sid = $o['status_id'] ?? null;
  if($sid !== null && !in_array((int)$sid, [10,11], true)) $put('status_id', $sid);

  // Osoba rozpisująca: jeśli nie kopiujemy terminów, ustaw bieżącego użytkownika
  if(!$wDates && isset($me['uid'])) $put('scheduler_id', (int)$me['uid']);

  // Miesiąc zakończenia z daty montażu
  if($wDates){
    $fb = $o['install_date_new'] ?: $o['install_date'];
    if($fb && preg_match('/^(\d{4})-(\d{2})/', $fb, $m)){
      try{
        $hasY = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_year'")->fetchColumn();
        $hasM = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_month'")->fetchColumn();
        if($hasY && $hasM){ $put('finished_bucket_year', (int)$m[1]); $put('finished_bucket_month', (int)$m[2]); }
      }catch(Throwable $e){}
    }
  }

  $pdo->beginTransaction();
  if($cols){
    $sql = "INSERT INTO orders (".implode(',', $cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")";
    $pdo->prepare($sql)->execute($vals);
  }else{
    $pdo->exec("INSERT INTO orders () VALUES ()");
  }
  $newId = (int)$pdo->lastInsertId();

  // Składniki wartości
  $baseAmount = $wAmount ? (float)$o['total_amount'] : 0;
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_addons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  }catch(Throwable $e){}
  $pdo->prepare("INSERT INTO order_addons(order_id,description,amount) VALUES(?,?,?)")->execute([$newId,'Zamówienie główne',$baseAmount]);
  if($wAmount){
    $q = $pdo->prepare("SELECT description, amount FROM order_addons WHERE order_id=? AND description<>'Zamówienie główne' ORDER BY id ASC");
    $q->execute([$srcId]);
    $ins = $pdo->prepare("INSERT INTO order_addons(order_id,description,amount) VALUES(?,?,?)");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ins->execute([$newId, $r['description'], $r['amount']]);
  }

  // Monterzy razem z terminami
  if($wDates){
    try{
      $q = $pdo->prepare("SELECT user_id FROM order_assignees WHERE order_id=?");
      $q->execute([$srcId]);
      $ins = $pdo->prepare("INSERT IGNORE INTO order_assignees(order_id,user_id) VALUES(?,?)");
      foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid) $ins->execute([$newId, (int)$uid]);
    }catch(Throwable $e){}
  }
  $pdo->commit();

  $labels = [];
  if($wClient) $labels[] = 'dane klienta';
  if($wDates) $labels[] = 'terminy';
  if($wAmount) $labels[] = 'kwoty';
  if($wItem) $labels[] = 'przedmiot zamówienia';
  $lab = $labels ? implode(', ', $labels) : 'puste zamówienie';
  order_audit_log($pdo, $newId, 'duplicated', 'Utworzono przez duplikację zamówienia #'.$srcId.' ('.$lab.')', ['source_order_id'=>$srcId,'parts'=>$labels], $me);
  order_audit_log($pdo, $srcId, 'duplicated_to', 'Zduplikowano do zamówienia #'.$newId.' ('.$lab.')', ['new_order_id'=>$newId,'parts'=>$labels], $me);

  json_ok(['ok'=>true, 'order_id'=>$newId]);
}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  json_err('duplicate_failed', 500, ['details'=>$e->getMessage()]);
}
