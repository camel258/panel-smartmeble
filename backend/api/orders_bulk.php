<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
require_once __DIR__ . '/../lib/contract_signers.php';
$user = auth_user(); if(!$user) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$body = api_request_data(); $action = $body['action'] ?? ''; $ids = $body['ids'] ?? [];
$role = api_current_role_id($pdo,$user); $isAdmin = in_array($role, [1,999], true); if(!$isAdmin) json_err('forbidden',403);
if (!is_array($ids) || empty($ids)) json_err('bad_request',400); $ids = array_values(array_filter($ids, fn($v)=>ctype_digit((string)$v))); if (empty($ids)) json_err('bad_request',400);
$in = implode(',', array_fill(0, count($ids), '?'));
if ($action === 'set_status') { $status_id=(int)($body['status_id']??0); if($status_id<=0) json_err('bad_request',400); $stmt=$pdo->prepare("UPDATE orders SET status_id = ? WHERE id IN ($in)"); $stmt->execute(array_merge([$status_id],$ids)); json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }
elseif ($action === 'finish') { $year=(int)($body['year']??0); $month=(int)($body['month']??0); if($year<2000||$year>2100||$month<1||$month>12) json_err('bad_request',400); $stmt=$pdo->prepare("UPDATE orders SET is_finished=1, finished_at=NOW(), finished_bucket_year=?, finished_bucket_month=? WHERE id IN ($in)"); $stmt->execute(array_merge([$year,$month],$ids)); json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }
elseif ($action === 'unfinish') { $stmt=$pdo->prepare("UPDATE orders SET is_finished=0, finished_at=NULL, finished_bucket_year=NULL, finished_bucket_month=NULL WHERE id IN ($in)"); $stmt->execute($ids); json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }
elseif ($action === 'delete') { $reason=trim((string)($body['reason'] ?? 'Usunięcie zbiorcze')); $stmt=$pdo->prepare("UPDATE orders SET is_deleted=1, deleted_at=NOW(), deleted_reason=? WHERE id IN ($in)"); $stmt->execute(array_merge([$reason],$ids)); json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }
elseif ($action === 'restore') { $stmt=$pdo->prepare("UPDATE orders SET is_deleted=0, deleted_at=NULL, deleted_reason=NULL WHERE id IN ($in)"); $stmt->execute($ids); json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }
elseif ($action === 'purge') { if($role!==999) json_err('forbidden',403); $pdo->beginTransaction(); try{ try{$st=$pdo->prepare("SELECT storage_path FROM order_files WHERE order_id IN ($in)"); $st->execute($ids); $paths=array_column($st->fetchAll(PDO::FETCH_ASSOC),'storage_path');}catch(Throwable $e){$paths=[];} foreach(['order_files','order_assignees','order_comments','order_payments','order_addons'] as $tbl){ try{ $pdo->prepare("DELETE FROM {$tbl} WHERE order_id IN ($in)")->execute($ids); }catch(Throwable $e){} } $stmt=$pdo->prepare("DELETE FROM orders WHERE id IN ($in)"); $stmt->execute($ids); $pdo->commit(); foreach($paths as $p){ if($p && ($p = api_resolve_storage_path((string)$p)) !== '') @unlink($p); } json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]); }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); json_err('server_error',500,['details'=>$e->getMessage()]); } }
elseif ($action === 'assign_mounters') { $csv=$body['mounters'] ?? ''; $idsUsers=array_filter(array_map('trim', explode(',', $csv)), fn($v)=>ctype_digit((string)$v)); if(empty($idsUsers)) json_err('bad_request',400); try{ $pdo->exec("CREATE TABLE order_assignees ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_order_user(order_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); }catch(Throwable $e){} $ins=$pdo->prepare("INSERT IGNORE INTO order_assignees(order_id,user_id) VALUES(?,?)"); foreach($ids as $oid){ foreach($idsUsers as $uid){ $ins->execute([(int)$oid,(int)$uid]); } } json_ok(['ok'=>true]); }
elseif ($action === 'set_signer') {
  cs_ensure($pdo);
  $signerId = (int)($body['signer_id'] ?? 0);
  if($signerId > 0){
    $chk = $pdo->prepare("SELECT COUNT(*) FROM contract_signers WHERE id=?"); $chk->execute([$signerId]);
    if(!(int)$chk->fetchColumn()) json_err('bad_request',400);
    $stmt = $pdo->prepare("UPDATE orders SET contract_signer_id=? WHERE id IN ($in)");
    $stmt->execute(array_merge([$signerId], $ids));
  } else {
    $stmt = $pdo->prepare("UPDATE orders SET contract_signer_id=NULL WHERE id IN ($in)");
    $stmt->execute($ids);
  }
  json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]);
}
elseif ($action === 'measure_done') {
  $hasMeasureDone = false;
  try{
    $st = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'measure_done'");
    $st->execute();
    $hasMeasureDone = (bool)$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ $hasMeasureDone = false; }
  if(!$hasMeasureDone) json_err('missing_measure_done_column',500);
  $stmt = $pdo->prepare("UPDATE orders SET measure_done=1 WHERE id IN ($in)");
  $stmt->execute($ids);
  json_ok(['ok'=>true,'updated'=>$stmt->rowCount()]);
}
else json_err('unsupported_action',400);
