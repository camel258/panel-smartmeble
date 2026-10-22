<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$roleId = 0;
if(isset($me['role_id'])) $roleId = (int)$me['role_id'];
else if(isset($me['role'])) $roleId = (int)$me['role'];

if(!in_array($roleId,[1,999],true)) json_err('forbidden',403);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$PROJECT_ROOT = dirname(__DIR__,2);
$LOG = $PROJECT_ROOT . '/uploads/error_log.txt';

function pick_col($cols, $cands){
  foreach($cands as $c){ if(in_array($c,$cols,true)) return $c; }
  return null;
}

try{
  $tbl = $pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn();
  if(!$tbl){
    json_ok(['items'=>[]]);
  }

  $cols = [];
  $st = $pdo->query("SHOW COLUMNS FROM order_payments");
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $cols[] = $r['Field']; }

  $colOrder = pick_col($cols, ['order_id','orderId']);
  $colDate  = pick_col($cols, ['pay_date','payment_date','date','paid_at','created_at']);
  $colAmt   = pick_col($cols, ['amount','value','kwota']);
  $colDesc  = pick_col($cols, ['description','opis','note','title']);

  if(!$colOrder || !$colDate || !$colAmt){
    @file_put_contents($LOG, date('Y-m-d H:i:s')." payments_list missing_columns ".json_encode($cols).PHP_EOL, FILE_APPEND);
    json_ok(['items'=>[]]);
  }

  $from = $_GET['from'] ?? '';
  $to   = $_GET['to'] ?? '';
  $where = [];
  $params = [];
  if($from!==''){ $where[] = "p.`$colDate` >= ?"; $params[] = $from; }
  if($to!==''){   $where[] = "p.`$colDate` <= ?"; $params[] = $to; }
  $where[] = 'COALESCE(o.is_deleted,0)=0';
  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  $descSel = $colDesc ? "p.`$colDesc`" : "''";

  $sql = "
    SELECT p.id,
           DATE_FORMAT(p.`$colDate`,'%Y-%m-%d') AS pay_date,
           DATE_FORMAT(p.`$colDate`,'%Y-%m-%d') AS paid_at,
           p.`$colAmt` AS amount,
           $descSel AS description,
           $descSel AS label,
           o.id AS order_id,
           CONCAT(o.client_first_name,' ',o.client_last_name) AS client,
           o.item_description
    FROM order_payments p
    JOIN orders o ON o.id = p.`$colOrder`
    $whereSql
    ORDER BY p.`$colDate` DESC, p.id DESC
  ";

  $q = $pdo->prepare($sql);
  $q->execute($params);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  json_ok(['items'=>$rows]);
}catch(Throwable $e){
  @file_put_contents($LOG, date('Y-m-d H:i:s')." payments_list ".$e->getMessage().PHP_EOL, FILE_APPEND);
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
