<?php
require_once __DIR__ . '/../../config/config.php';

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$role = (int)($me['role'] ?? ($me['role_id'] ?? 0));
$canFinance = ($role===1 || $role===999);
if(!$canFinance) json_err('forbidden',403);

function body_json(){
  $raw = file_get_contents('php://input');
  $j = json_decode($raw,true);
  return is_array($j)?$j:[];
}

try{
  if($method==='GET'){
    $orderId = (int)($_GET['id'] ?? 0);
    if($orderId<=0) json_err('bad_id',400);
    $st=$pdo->prepare("SELECT id, description, amount, created_at FROM order_addons WHERE order_id=? ORDER BY id ASC");
    $st->execute([$orderId]);
    json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if($method==='POST'){
    $data = $_POST;
    if(empty($data)) $data = body_json();
    $action = $data['action'] ?? 'add';

    if($action==='delete'){
      if($role!==999) json_err('forbidden',403);
      $aid = (int)($data['addon_id'] ?? 0);
      if($aid<=0) json_err('bad_id',400);
      $pdo->prepare("DELETE FROM order_addons WHERE id=?")->execute([$aid]);
      json_ok(['ok'=>true]);
    }

    $orderId = (int)($data['order_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0);
    $desc = trim((string)($data['description'] ?? ''));

    if($orderId<=0 || $desc==='') json_err('missing_fields',400);

    $ins=$pdo->prepare("INSERT INTO order_addons(order_id, description, amount, created_by) VALUES(?,?,?,?)");
    $ins->execute([$orderId, $desc, $amount, (int)($me['uid'] ?? $me['id'] ?? null)]);
    json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  json_err('method_not_allowed',405);

}catch(Throwable $e){
  $PROJECT_ROOT = dirname(__DIR__,2);
  @file_put_contents($PROJECT_ROOT.'/uploads/error_log.txt',date('Y-m-d H:i:s').' order_addons '.$e->getMessage()."\n",FILE_APPEND);
  json_err('server_error',500);
}
