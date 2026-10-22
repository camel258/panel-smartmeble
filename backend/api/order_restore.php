<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if(!in_array($role, [1,999], true)) json_err('forbidden',403);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function req_data(): array {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  return is_array($json) ? $json : $_POST;
}
function current_user_id_restore(array $me): int {
  foreach(['id','uid','user_id'] as $k){
    $v = (int)($me[$k] ?? 0);
    if($v > 0) return $v;
  }
  return 0;
}
function current_user_name_restore(array $me): string {
  $first = trim((string)($me['first_name'] ?? ''));
  $last = trim((string)($me['last_name'] ?? ''));
  $full = trim($first.' '.$last);
  if($full !== '') return $full;
  foreach(['full_name','name','login','username','email'] as $k){
    $v = trim((string)($me[$k] ?? ''));
    if($v !== '') return $v;
  }
  return 'Użytkownik';
}
function add_restore_comment(PDO $pdo, int $orderId, array $me): void {
  try{
    $has = $pdo->query("SHOW TABLES LIKE 'order_comments'")->fetchColumn();
    if(!$has) return;

    $cols = array_column($pdo->query("SHOW COLUMNS FROM order_comments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $cOrder = in_array('order_id',$cols,true) ? 'order_id' : null;
    $cText = in_array('text',$cols,true) ? 'text' : (in_array('comment',$cols,true) ? 'comment' : (in_array('note',$cols,true) ? 'note' : null));
    $cCreated = in_array('created_at',$cols,true) ? 'created_at' : (in_array('added_at',$cols,true) ? 'added_at' : null);
    $cAuthor = in_array('author',$cols,true) ? 'author' : null;
    $cUser = in_array('user_id',$cols,true) ? 'user_id' : (in_array('author_id',$cols,true) ? 'author_id' : null);
    if(!$cOrder || !$cText || !$cCreated) return;

    $colsIns = ["`$cOrder`","`$cText`","`$cCreated`"];
    $vals = ['?','?','NOW()'];
    $params = [$orderId, 'Przywrócono zamówienie do aktualnych.'];
    if($cAuthor){
      $colsIns[] = "`$cAuthor`"; $vals[]='?'; $params[] = current_user_name_restore($me);
    }elseif($cUser){
      $colsIns[] = "`$cUser`"; $vals[]='?'; $params[] = current_user_id_restore($me) ?: null;
    }
    $st=$pdo->prepare("INSERT INTO order_comments(".implode(',',$colsIns).") VALUES(".implode(',',$vals).")");
    $st->execute($params);
  }catch(Throwable $e){}
}

$body = req_data();
$ids = [];
if(isset($body['ids']) && is_array($body['ids'])){
  foreach($body['ids'] as $v){ $v=(int)$v; if($v>0) $ids[]=$v; }
}else{
  $v = (int)($body['id'] ?? $_GET['id'] ?? 0);
  if($v>0) $ids[]=$v;
}
$ids = array_values(array_unique($ids));
if(!$ids) json_err('bad_request',400);

try{
  $pdo->beginTransaction();
  $updated = 0;

  $checkStatus = $pdo->prepare("SELECT o.id, o.status_id, s.name AS status_name FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id WHERE o.id=? LIMIT 1");

  foreach($ids as $id){
    $checkStatus->execute([$id]);
    $row = $checkStatus->fetch(PDO::FETCH_ASSOC);
    if(!$row) continue;

    $statusName = mb_strtolower((string)($row['status_name'] ?? ''), 'UTF-8');
    $statusFinished = (strpos($statusName, 'zakończ') !== false || strpos($statusName, 'zakoncz') !== false);

    if($statusFinished){
      $st = $pdo->prepare("UPDATE orders SET is_finished=0, finished_at=NULL, status_id=NULL WHERE id=?");
      $st->execute([$id]);
    }else{
      $st = $pdo->prepare("UPDATE orders SET is_finished=0, finished_at=NULL WHERE id=?");
      $st->execute([$id]);
    }

    add_restore_comment($pdo, $id, $me);
    try{
      order_audit_log($pdo, $id, 'order_restore', 'Przywrócono zamówienie do aktualnych', [
        'actor'=>['id'=>current_user_id_restore($me) ?: null, 'name'=>current_user_name_restore($me)]
      ], $me);
    }catch(Throwable $e){}
    $updated++;
  }

  $pdo->commit();
  json_ok(['ok'=>true,'updated'=>$updated]);
}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
