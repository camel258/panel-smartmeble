<?php
require_once __DIR__ . '/../../config/config.php';
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
function ensure_order_addons_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_addons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN created_by INT UNSIGNED NULL"); }catch(Throwable $e){}
}
function sum_all_components($pdo,$orderId){
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id=?");
  $q->execute([$orderId]); $row=$q->fetch(PDO::FETCH_ASSOC); return (float)($row['s']??0);
}
$me=auth_user(); if(!$me) json_err('unauthorized',401);
$uid=(int)($me['id'] ?? $me['uid'] ?? 0);
$roleId=(int)($me['role_id'] ?? $me['role'] ?? 0);
if($uid>0 && !can_edit_field($uid,'finance_components')) json_err('forbidden',403,['details'=>'finance_components_edit_required']);
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$body=req_json_or_post();
$id=(int)($body['id'] ?? 0); $desc=trim((string)($body['description'] ?? '')); $amount=$body['amount'] ?? null;
if($id<=0 || $desc==='' || !is_numeric($amount)) json_err('bad_request',400);
$amountNorm=(float)$amount;
if(abs($amountNorm) < 0.000001) json_err('bad_request',400,['details'=>'Kwota nie może być zerowa']);
if($amountNorm < 0 && $uid>0 && !can_edit_field($uid,'finance_discount_edit')) json_err('forbidden',403,['details'=>'finance_discount_edit_required']);
try{
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT id, order_id, description, amount FROM order_addons WHERE id=? LIMIT 1");
  $q->execute([$id]);
  $row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row) json_err('not_found',404);
  if(trim((string)($row['description'] ?? ''))==='Zamówienie główne' && trim($desc)!=='Zamówienie główne' && $roleId!==999) json_err('forbidden',403,['details'=>'Nie można zmienić nazwy pozycji bazowej']);
  $pdo->prepare("UPDATE order_addons SET description=?, amount=? WHERE id=? LIMIT 1")->execute([$desc,dec_or_zero($amountNorm),$id]);
  $sum=sum_all_components($pdo,(int)$row['order_id']);
  $pdo->prepare("UPDATE orders SET total_amount=? WHERE id=?")->execute([dec_or_zero($sum),(int)$row['order_id']]);
  order_audit_log($pdo, (int)$row['order_id'], 'component_update', 'Edytowano składnik wartości', [
    'before' => ['description'=>$row['description'] ?? '', 'amount'=>$row['amount'] ?? null],
    'after' => ['description'=>$desc, 'amount'=>dec_or_zero($amountNorm)],
    'actor' => ['id'=>(int)($me['id'] ?? $me['uid'] ?? 0) ?: null, 'name'=>audit_user_name($me)]
  ], $me);
  json_ok(['ok'=>true]);
}catch(Throwable $e){ json_err('save_failed',500,['details'=>$e->getMessage()]); }
