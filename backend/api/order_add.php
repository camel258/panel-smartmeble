<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
header('Content-Type: application/json; charset=utf-8');
function ensure_order_addons_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_addons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_addons ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
}
function ensure_order_payments_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, pay_date DATE NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, description VARCHAR(255) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id), INDEX(pay_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN pay_date DATE NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE order_payments ADD COLUMN order_id BIGINT UNSIGNED NOT NULL"); }catch(Throwable $e){}
}
function ensure_base_component($pdo,$orderId,$baseAmount){
  ensure_order_addons_schema($pdo);
  $q=$pdo->prepare("SELECT id FROM order_addons WHERE order_id=? AND description='Zamówienie główne' ORDER BY id ASC LIMIT 1");
  $q->execute([$orderId]); $row=$q->fetch(PDO::FETCH_ASSOC);
  if($row){ $pdo->prepare("UPDATE order_addons SET amount=? WHERE id=?")->execute([api_dec_or_zero($baseAmount),(int)$row['id']]); }
  else{ $pdo->prepare("INSERT INTO order_addons(order_id,description,amount) VALUES(?,?,?)")->execute([$orderId,'Zamówienie główne',api_dec_or_zero($baseAmount)]); }
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
$user=auth_user(); if(!$user) json_err('unauthorized',401);
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$PROJECT_ROOT=dirname(__DIR__,2); $ERROR_LOG=$PROJECT_ROOT.'/uploads/error_log.txt';
function postv($k){ return is_string($_POST[$k] ?? null)?trim($_POST[$k]):($_POST[$k] ?? null); }
try{
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_assignees (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_order_user(order_id,user_id), INDEX(order_id), INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  ensure_order_addons_schema($pdo);
  $pdo->beginTransaction();
  $scheduler=api_int_or_null(postv('scheduler_id')); if($scheduler===null && isset($user['uid'])) $scheduler=(int)$user['uid'];
  $baseAmount=api_dec_or_zero(postv('total_amount'));
  $clientAddress=api_null_if_empty(postv('client_address'));
  if($clientAddress===null){ $street=trim((string)(postv('install_street')??'')); $no=trim((string)(postv('install_no')??'')); $zip=trim((string)(postv('install_postcode')??postv('install_zip')??'')); $city=trim((string)(postv('install_city')??'')); $clientAddress=trim($street.' '.$no.', '.$zip.' '.$city); if(trim($clientAddress,' ,')==='') $clientAddress=null; }
  $stmt=$pdo->prepare("INSERT INTO orders (client_first_name,client_last_name,item_description,client_phone,client_email,client_address,contract_date,measure_due_date,install_date,install_date_new,total_amount,deposit_amount,status_id,scheduler_id,install_street,install_no,install_postcode,install_city) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([api_null_if_empty(postv('client_first_name')),api_null_if_empty(postv('client_last_name')),api_null_if_empty(postv('item_description')),api_null_if_empty(postv('client_phone')),api_null_if_empty(postv('client_email')),$clientAddress,api_null_if_empty(postv('contract_date')),api_null_if_empty(postv('measure_due_date')),api_null_if_empty(postv('install_date')),api_null_if_empty(postv('install_date_new')),$baseAmount,api_dec_or_zero(postv('deposit_amount')),api_int_or_null(postv('status_id')),$scheduler,api_null_if_empty(postv('install_street')),api_null_if_empty(postv('install_no')),api_null_if_empty(postv('install_postcode') ?? postv('install_zip')),api_null_if_empty(postv('install_city'))]);
  $orderId=(int)$pdo->lastInsertId();

  // Domyślny miesiąc zakończenia ustawiamy z nowej daty montażu, a jeśli jej nie ma — z daty montażu.
  $finishBaseDate = api_null_if_empty(postv('install_date_new')) ?: api_null_if_empty(postv('install_date'));
  if($finishBaseDate && preg_match('/^\d{4}-\d{2}-\d{2}/', $finishBaseDate)){
    try{
      $hasFinishYear = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_year'")->fetchColumn();
      $hasFinishMonth = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'finished_bucket_month'")->fetchColumn();
      if($hasFinishYear && $hasFinishMonth){
        $fy = (int)substr($finishBaseDate,0,4);
        $fm = (int)substr($finishBaseDate,5,2);
        if($fy>=2000 && $fy<=2100 && $fm>=1 && $fm<=12){
          $pdo->prepare("UPDATE orders SET finished_bucket_year=?, finished_bucket_month=? WHERE id=?")->execute([$fy,$fm,$orderId]);
        }
      }
    }catch(Throwable $e){}
  }

  ensure_base_component($pdo,$orderId,$baseAmount);

  $depositAmount = api_dec_or_zero(postv('deposit_amount'));
  if($depositAmount > 0){
    ensure_order_payments_schema($pdo);
    $payDate = api_null_if_empty(postv('contract_date'));
    if($payDate===null){ $payDate = date('Y-m-d'); }
    $desc = 'Zaliczka';
    $cols = ['order_id','pay_date','amount','description'];
    $vals = [$orderId,$payDate,$depositAmount,$desc];
    try{
      $st = $pdo->prepare("SHOW COLUMNS FROM order_payments LIKE 'created_by'");
      $st->execute();
      if($st->fetch(PDO::FETCH_ASSOC)){ $cols[]='created_by'; $vals[]=(int)($user['uid'] ?? 0); }
    }catch(Throwable $e){}
    try{
      $st = $pdo->prepare("SHOW COLUMNS FROM order_payments LIKE 'created_by_name'");
      $st->execute();
      if($st->fetch(PDO::FETCH_ASSOC)){ $cols[]='created_by_name'; $vals[]=(string)($user['full_name'] ?? $user['name'] ?? $user['email'] ?? ''); }
    }catch(Throwable $e){}
    $sql = "INSERT INTO order_payments(".implode(',',$cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")";
    $pdo->prepare($sql)->execute($vals);
  }

  $mCsv=postv('mounters'); if($mCsv===null && isset($_POST['mounter_ids']) && is_array($_POST['mounter_ids'])) $mCsv=implode(',',array_map('strval',$_POST['mounter_ids']));
  $mCsv=trim((string)($mCsv??'')); if($mCsv!==''){ $ids=array_values(array_filter(array_map('trim',explode(',',$mCsv)),fn($x)=>$x!=='')); $ins=$pdo->prepare("INSERT IGNORE INTO order_assignees(order_id,user_id) VALUES(?,?)"); foreach($ids as $uid){ if(ctype_digit($uid)) $ins->execute([$orderId,(int)$uid]); } }
  if($pdo->inTransaction()) $pdo->commit(); json_ok(['ok'=>true,'order_id'=>$orderId]);
}catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); api_log_error('order_add', $e); json_err('save_failed',500,['details'=>$e->getMessage()]); }
