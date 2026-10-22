<?php
require_once __DIR__ . '/../../config/config.php';

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$role = (int)($me['role'] ?? ($me['role_id'] ?? 0));
if($role!==999) json_err('forbidden',403);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)) $data = $_POST;

$orderId = (int)($data['order_id'] ?? ($data['id'] ?? 0));
if($orderId<=0) json_err('bad_id',400);

try{
  $paths = [];
  try{
    $st=$pdo->prepare("SELECT storage_path FROM order_files WHERE order_id=?");
    $st->execute([$orderId]);
    $paths = array_column($st->fetchAll(PDO::FETCH_ASSOC),'storage_path');
  }catch(Throwable $e){}

  $pdo->beginTransaction();
  foreach(['order_files','order_assignees','order_comments','order_payments','order_addons'] as $tbl){
    try{ $pdo->prepare("DELETE FROM {$tbl} WHERE order_id=?")->execute([$orderId]); }catch(Throwable $e){}
  }
  $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$orderId]);
  $pdo->commit();

  foreach($paths as $p){ if($p && is_file($p)) @unlink($p); }

  json_ok(['ok'=>true]);
}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  $PROJECT_ROOT = dirname(__DIR__,2);
  @file_put_contents($PROJECT_ROOT.'/uploads/error_log.txt',date('Y-m-d H:i:s').' order_delete_permanent '.$e->getMessage()."
",FILE_APPEND);
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
