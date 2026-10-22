<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

function sm_uid(array $me): int {
  foreach(['id','uid','user_id'] as $k){ $v=(int)($me[$k]??0); if($v>0) return $v; }
  return 0;
}
function sm_role_name(PDO $pdo, array $me): string {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  if($rid<=0) return '';
  try{
    $st=$pdo->prepare("SELECT name FROM roles WHERE id=? LIMIT 1");
    $st->execute([$rid]);
    return mb_strtolower(trim((string)$st->fetchColumn()), 'UTF-8');
  }catch(Throwable $e){ return ''; }
}
function sm_is_external_technologist(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return (strpos($n,'technolog')!==false && (strpos($n,'zewn')!==false || strpos($n,'zewnętr')!==false || strpos($n,'zewnetrz')!==false));
}
function sm_is_worker_role(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return strpos($n,'pracownik')!==false;
}
function sm_is_admin_role(array $me): bool {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  return in_array($rid,[1,999],true);
}


function dec_or_zero($v){ if($v===null||$v==='') return '0.00'; return is_numeric($v)?number_format((float)$v,2,'.',''):'0.00'; }
function ensure_order_addons_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_addons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN created_by BIGINT NULL"); }catch(Throwable $e){}
}
function ensure_order_payments_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, pay_date DATE NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, description VARCHAR(255) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id), INDEX(pay_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN pay_date DATE NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN created_by BIGINT NULL"); }catch(Throwable $e){}
}

$me=auth_user(); if(!$me) json_err('unauthorized',401);
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$PROJECT_ROOT=dirname(__DIR__,2); $ERROR_LOG=$PROJECT_ROOT.'/uploads/error_log.txt';
$id=(int)($_GET['id'] ?? $_GET['order_id'] ?? 0); if($id<=0) json_err('bad_request',400);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$isExternalTechnologist=sm_is_external_technologist($pdo,$me); $isWorkerRole=sm_is_worker_role($pdo,$me);
$canSummary = $uid>0 ? (bool)can_view_field($uid,'finance_summary') : true;
$canComponents = $uid>0 ? (bool)can_view_field($uid,'finance_components') : true;
$canPayments = $uid>0 ? (bool)can_view_field($uid,'finance_payments') : true;
$canEditSummary = $uid>0 ? (bool)can_edit_field($uid,'finance_summary') : true;
$canEditComponents = $uid>0 ? (bool)can_edit_field($uid,'finance_components') : true;
$canEditPayments = $uid>0 ? (bool)can_edit_field($uid,'finance_payments') : true;
$canEditDiscounts = $uid>0 ? (bool)can_edit_field($uid,'finance_discount_edit') : true;
if($isExternalTechnologist || $isWorkerRole){ $canSummary=false; $canComponents=false; $canPayments=false; $canEditSummary=false; $canEditComponents=false; $canEditPayments=false; $canEditDiscounts=false; }

try{
  if(!$canSummary && !$canComponents && !$canPayments && !$canEditSummary && !$canEditComponents && !$canEditPayments){
    json_ok([
      'can_view_summary'=>false,
      'can_view_components'=>false,
      'can_view_payments'=>false,
      'can_edit_summary'=>false,
      'can_edit_components'=>false,
      'can_edit_payments'=>false,
      'can_edit_discounts'=>false,
      'total_amount'=>null,
      'base_amount'=>null,
      'components'=>[],
      'payments'=>[]
    ]);
  }

  $st=$pdo->prepare("SELECT total_amount FROM orders WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $order=$st->fetch(PDO::FETCH_ASSOC);
  if(!$order) json_err('not_found',404);

  $baseTotal=(float)($order['total_amount'] ?? 0);
  ensure_order_addons_schema($pdo);
  ensure_order_payments_schema($pdo);

  $components=[];
  $payments=[];

  if($canComponents){
    $q=$pdo->prepare("SELECT 
        oa.id,
        oa.description,
        oa.amount,
        CASE WHEN oa.description='Zamówienie główne' THEN 1 ELSE 0 END AS is_base,
        DATE_FORMAT(oa.created_at,'%Y-%m-%d %H:%i:%s') AS created_at,
        oa.created_by,
        COALESCE(u.full_name, u.email, CONCAT('Użytkownik #', oa.created_by)) AS created_by_name
      FROM order_addons oa
      LEFT JOIN users u ON u.id = oa.created_by
      WHERE oa.order_id=?
      ORDER BY oa.id DESC");
    $q->execute([$id]);
    $components=$q->fetchAll(PDO::FETCH_ASSOC);
  }

  if($canPayments){
    $q=$pdo->prepare("SELECT 
        op.id,
        DATE_FORMAT(op.pay_date,'%Y-%m-%d') AS paid_at,
        op.amount,
        op.description,
        DATE_FORMAT(op.created_at,'%Y-%m-%d %H:%i:%s') AS created_at,
        op.created_by,
        COALESCE(u.full_name, u.email, CONCAT('Użytkownik #', op.created_by)) AS created_by_name
      FROM order_payments op
      LEFT JOIN users u ON u.id = op.created_by
      WHERE op.order_id=?
      ORDER BY op.pay_date DESC, op.id DESC");
    $q->execute([$id]);
    $payments=$q->fetchAll(PDO::FETCH_ASSOC);
  }

  $total=$baseTotal;
  if($canSummary){
    $total=count($components)
      ? array_reduce($components, fn($a,$r)=>$a+(float)$r['amount'], 0.0)
      : $baseTotal;
  }

  json_ok([
    'can_view_summary'=>$canSummary,
    'can_view_components'=>$canComponents,
    'can_view_payments'=>$canPayments,
    'can_edit_summary'=>$canEditSummary,
    'can_edit_components'=>$canEditComponents,
    'can_edit_payments'=>$canEditPayments,
    'can_edit_discounts'=>$canEditDiscounts,
    'total_amount'=>$canSummary ? number_format($total,2,'.','') : null,
    'base_amount'=>$canSummary ? number_format($baseTotal,2,'.','') : null,
    'components'=>$canComponents ? $components : [],
    'payments'=>$canPayments ? $payments : []
  ]);
}catch(Throwable $e){
  @file_put_contents($ERROR_LOG,date('Y-m-d H:i:s')." order_finance ".$e->getMessage().PHP_EOL,FILE_APPEND);
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
