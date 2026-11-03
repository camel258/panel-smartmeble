<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/contract_signers.php';
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

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
cs_ensure($pdo);
if(sm_is_worker_role($pdo,$me) || sm_is_external_technologist($pdo,$me) || ((int)($me['role_id'] ?? ($me['role'] ?? 0))===3)){ json_err('forbidden',403); }
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$canFinance = $uid>0 ? ((bool)can_view_field($uid,'finance_summary') || (bool)can_view_field($uid,'finance_components') || (bool)can_view_field($uid,'finance_payments')) : true;
$limit=max(1,min(1000,(int)($_GET['limit']??500))); $offset=max(0,(int)($_GET['offset']??0));
$q=trim($_GET['q']??''); $status=trim($_GET['status']??''); $year=trim($_GET['year']??''); $month=trim($_GET['month']??''); $from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
$where=["COALESCE(o.is_deleted,0)=0", "(COALESCE(o.is_finished,0)=1 OR o.finished_at IS NOT NULL OR LOWER(COALESCE(s.name,'')) LIKE '%zakończ%' OR LOWER(COALESCE(s.name,'')) LIKE '%zakoncz%')"];
$params=[];
if($q!==''){ $where[]="(CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ? OR COALESCE(o.item_description,'') LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
if($status!==''){ $where[]="o.status_id=?"; $params[]=(int)$status; }
if($year!==''){ $where[]="COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))=?"; $params[]=(int)$year; }
if($month!==''){ $where[]="COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))=?"; $params[]=(int)$month; }
if($from!==''){ $where[]="DATE(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)) >= ?"; $params[]=$from; }
if($to!==''){ $where[]="DATE(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)) <= ?"; $params[]=$to; }
$whereSql='WHERE '.implode(' AND ',$where);
$sql="SELECT o.id,o.client_first_name,o.client_last_name,o.item_description,DATE_FORMAT(o.contract_date,'%Y-%m-%d') contract_date,DATE_FORMAT(o.install_date,'%Y-%m-%d') install_date,DATE_FORMAT(o.install_date_new,'%Y-%m-%d') install_date_new,DATE_FORMAT(o.created_at,'%Y-%m-%d') created_at,o.total_amount,o.deposit_amount,o.status_id,s.name AS status_name,s.color AS status_color,o.scheduler_id,u.full_name AS scheduler_name,o.contract_signer_id,cs.full_name AS contract_signer_name,
COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) AS finished_bucket_year,
COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) AS finished_bucket_month,
DATE_FORMAT(o.finished_at,'%Y-%m-%d %H:%i:%s') finished_at
FROM orders o
LEFT JOIN order_statuses s ON s.id=o.status_id
LEFT JOIN users u ON u.id=o.scheduler_id
LEFT JOIN contract_signers cs ON cs.id=o.contract_signer_id
$whereSql
ORDER BY COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) ASC,
COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) ASC,
COALESCE(o.install_date_new,o.install_date,o.contract_date,o.created_at) ASC,
o.id ASC LIMIT $limit OFFSET $offset";
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
if($rows){
  $ids=array_column($rows,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
  try{

$m=$pdo->prepare("
  SELECT a.order_id,
         GROUP_CONCAT(
           COALESCE(NULLIF(TRIM(us.full_name),''), NULLIF(TRIM(us.email),''), CONCAT('Użytkownik ', us.id))
           ORDER BY COALESCE(NULLIF(TRIM(us.full_name),''), NULLIF(TRIM(us.email),''), us.id)
           SEPARATOR ', '
         ) names
  FROM order_assignees a
  JOIN users us ON us.id=a.user_id
  WHERE a.order_id IN ($in)
  GROUP BY a.order_id
");


    $m->execute($ids); $map=[]; foreach($m->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[(int)$r['order_id']]=$r['names']; }
  } catch(Throwable $e){ $map=[]; }
  $addonMap=[]; $payMap=[];
  try{ $hasAddons=$pdo->query("SHOW TABLES LIKE 'order_addons'")->fetchColumn(); }catch(Throwable $e){ $hasAddons=false; }
  try{ $hasPays=$pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn(); }catch(Throwable $e){ $hasPays=false; }
  if($hasAddons){ $a=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id IN ($in) GROUP BY order_id"); $a->execute($ids); foreach($a->fetchAll(PDO::FETCH_ASSOC) as $r){ $addonMap[(int)$r['order_id']] = (float)$r['s']; } }
  if($hasPays){ $p=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_payments WHERE order_id IN ($in) GROUP BY order_id"); $p->execute($ids); foreach($p->fetchAll(PDO::FETCH_ASSOC) as $r){ $payMap[(int)$r['order_id']] = (float)$r['s']; } }
  foreach($rows as &$r){ $oid=(int)$r['id']; $r['mounters']=isset($map[$oid])?explode(', ',$map[$oid]):[]; $sum=isset($addonMap[$oid])?(float)$addonMap[$oid]:(float)($r['total_amount']??0); $paid=isset($payMap[$oid])?(float)$payMap[$oid]:0.0; $r['total_with_addons']=$sum; $r['paid_sum']=$paid; $r['remaining']=$sum-$paid; $r['contract_signer_initials']=cs_initials((string)($r['contract_signer_name'] ?? '')); }
}
json_ok(['items'=>$rows,'can_view_finance'=>$canFinance]);
