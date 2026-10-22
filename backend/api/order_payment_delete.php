<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');
function req_json_or_post(){ $body=json_decode(file_get_contents('php://input'), true); return is_array($body)?$body:$_POST; }
function dec_or_zero($v){ if($v===null||$v==='') return '0.00'; return is_numeric($v)?number_format((float)$v,2,'.',''):'0.00'; }
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
function null_if_empty($v){ if($v===null) return null; if(is_string($v)){ $t=trim($v); return $t===''?null:$t; } return $v; }
function ensure_order_addons_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_addons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN created_by INT UNSIGNED NULL"); }catch(Throwable $e){}
}
function ensure_order_payments_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, pay_date DATE NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, description VARCHAR(255) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id), INDEX(pay_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN pay_date DATE NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN created_by INT UNSIGNED NULL"); }catch(Throwable $e){}
}
function ensure_base_component($pdo,$orderId,$baseAmount){
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT id FROM order_addons WHERE order_id=? AND description='Zamówienie główne' ORDER BY id ASC LIMIT 1");
  $q->execute([$orderId]); $row=$q->fetch(PDO::FETCH_ASSOC);
  if($row){ $pdo->prepare("UPDATE order_addons SET amount=? WHERE id=?")->execute([dec_or_zero($baseAmount),(int)$row['id']]); }
  else{ $pdo->prepare("INSERT INTO order_addons(order_id,description,amount) VALUES(?,?,?)")->execute([$orderId,'Zamówienie główne',dec_or_zero($baseAmount)]); }
}
function sum_other_components($pdo,$orderId){
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id=? AND description<>'Zamówienie główne'");
  $q->execute([$orderId]); $row=$q->fetch(PDO::FETCH_ASSOC); return (float)($row['s']??0);
}
function sum_all_components($pdo,$orderId){
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id=?");
  $q->execute([$orderId]); $row=$q->fetch(PDO::FETCH_ASSOC); return (float)($row['s']??0);
}
$me=auth_user(); if(!$me) json_err('unauthorized',401);
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $body=req_json_or_post(); $id=(int)($body['id'] ?? 0);
if($id<=0) json_err('bad_request',400);
try{
  ensure_order_payments_schema($pdo);
  $q=$pdo->prepare("SELECT id, order_id, DATE_FORMAT(pay_date,'%Y-%m-%d') AS pay_date, amount, description FROM order_payments WHERE id=? LIMIT 1");
  $q->execute([$id]);
  $row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row) json_err('not_found',404);
  acc_require_field($pdo,$me,'finance_payments','edit'); acc_require_order($pdo,$me,(int)$row['order_id']);
  $pdo->prepare("DELETE FROM order_payments WHERE id=?")->execute([$id]);
  order_audit_log($pdo, (int)$row['order_id'], 'payment_delete', 'Usunięto wpłatę', [
    'payment' => ['date'=>$row['pay_date'] ?? null, 'amount'=>$row['amount'] ?? null, 'description'=>$row['description'] ?? ''],
    'actor' => ['id'=>(int)($me['id'] ?? $me['uid'] ?? 0) ?: null, 'name'=>audit_user_name($me)]
  ], $me);
  json_ok(['ok'=>true]);
}catch(Throwable $e){ json_err('save_failed',500,['details'=>$e->getMessage()]); }
