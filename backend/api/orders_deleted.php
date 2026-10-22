<?php
require_once __DIR__ . '/../../config/config.php';

$me = auth_user();
if(!$me) json_err('unauthorized',401);

// akceptuj różne klucze zwracane przez auth_user()
$roleId = 0;
if (isset($me['role_id'])) $roleId = (int)$me['role_id'];
else if (isset($me['role'])) $roleId = (int)$me['role'];

if($roleId !== 999){
  json_err('forbidden',403);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try{
  $stmt=$pdo->query("
    SELECT o.id, o.client_first_name, o.client_last_name, o.item_description,
           DATE_FORMAT(o.contract_date,'%Y-%m-%d') contract_date,
           DATE_FORMAT(o.install_date,'%Y-%m-%d') install_date,
           DATE_FORMAT(o.install_date_new,'%Y-%m-%d') install_date_new,
           o.total_amount, o.deposit_amount,
           o.deleted_reason,
           DATE_FORMAT(o.deleted_at,'%Y-%m-%d %H:%i:%s') deleted_at
    FROM orders o
    WHERE COALESCE(o.is_deleted,0)=1
    ORDER BY o.deleted_at DESC, o.id DESC
  ");
  $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  json_ok(['items'=>$rows, 'role_id'=>$roleId]);
}catch(Throwable $e){
  $PROJECT_ROOT = dirname(__DIR__,2);
  @file_put_contents($PROJECT_ROOT.'/uploads/error_log.txt',date('Y-m-d H:i:s').' orders_deleted '.$e->getMessage()."\n",FILE_APPEND);
  json_err('server_error',500);
}
