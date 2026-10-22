<?php
require_once __DIR__ . '/../../config/config.php';

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
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$role=(int)($me['role_id'] ?? ($me['role'] ?? 0));
$isExternalTechnologist=sm_is_external_technologist($pdo,$me); $isWorkerRole=sm_is_worker_role($pdo,$me);
$canFinance = ($uid>0 ? ((bool)can_view_field($uid,'finance_summary') || (bool)can_view_field($uid,'finance_components') || (bool)can_view_field($uid,'finance_payments')) : in_array($role,[1,999],true)) && !$isExternalTechnologist && !$isWorkerRole;
$state=trim($_GET['state']??'current');
if(($isWorkerRole || $isExternalTechnologist || $role===3) && $state==='finished') json_err('forbidden',403);
$q=trim($_GET['q']??''); $status=trim($_GET['status']??''); $year=trim($_GET['year']??''); $month=trim($_GET['month']??''); $from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
$where=[]; $params=[];
if($state==='finished'){
  $where[]="COALESCE(o.is_deleted,0)=0";
  $where[]="(COALESCE(o.is_finished,0)=1 OR o.finished_at IS NOT NULL OR LOWER(COALESCE(s.name,'')) LIKE '%zakończ%' OR LOWER(COALESCE(s.name,'')) LIKE '%zakoncz%')";
}elseif($state==='deleted'){
  $where[]="COALESCE(o.is_deleted,0)=1";
}else{
  $where[]="COALESCE(o.is_deleted,0)=0";
  $where[]="COALESCE(o.is_finished,0)=0";
}
if($isExternalTechnologist){ $where[]="o.scheduler_id=?"; $params[]=$uid; }
$dateExpr = ($state==='finished') ? "DATE(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))" : (($state==='deleted') ? "DATE(COALESCE(o.deleted_at,o.created_at))" : "COALESCE(o.install_date_new,o.install_date,o.contract_date)");
if($q!==''){ $where[]="(CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ? OR COALESCE(o.item_description,'') LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
if($status!==''){ $where[]="o.status_id=?"; $params[]=(int)$status; }
if($year!==''){
  if($state==='finished') $where[]="COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))=?";
  else $where[]="YEAR($dateExpr)=?";
  $params[]=(int)$year;
}
if($month!==''){
  if($state==='finished') $where[]="COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))=?";
  else $where[]="MONTH($dateExpr)=?";
  $params[]=(int)$month;
}
if($from!==''){ $where[]="$dateExpr >= ?"; $params[]=$from; }
if($to!==''){ $where[]="$dateExpr <= ?"; $params[]=$to; }
$whereSql='WHERE '.implode(' AND ',$where);
$orderBy = $state==='finished'
  ? "COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) ASC, COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at))) ASC, COALESCE(o.install_date_new,o.install_date,o.contract_date,o.created_at) ASC, o.id ASC"
  : (($state==='deleted') ? "DATE(COALESCE(o.deleted_at,o.created_at)) ASC, o.id ASC" : "COALESCE(o.install_date_new,o.install_date,o.contract_date) ASC, o.id ASC");
$sql="SELECT o.id,o.client_first_name,o.client_last_name,o.item_description,DATE_FORMAT(o.contract_date,'%Y-%m-%d') contract_date,DATE_FORMAT(o.install_date,'%Y-%m-%d') install_date,DATE_FORMAT(o.install_date_new,'%Y-%m-%d') install_date_new,DATE_FORMAT(o.created_at,'%Y-%m-%d') created_at,o.total_amount,o.status_id,s.name AS status_name,u.full_name AS scheduler_name,DATE_FORMAT(o.finished_at,'%Y-%m-%d') finished_at,o.finished_bucket_year,o.finished_bucket_month,o.real_year,o.real_month,DATE_FORMAT(o.deleted_at,'%Y-%m-%d') deleted_at,o.deleted_reason FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id {$whereSql} ORDER BY {$orderBy}";
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
if($rows){
  $ids=array_column($rows,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
  $m=$pdo->prepare("SELECT a.order_id, GROUP_CONCAT(us.full_name ORDER BY us.full_name SEPARATOR ', ') names FROM order_assignees a JOIN users us ON us.id=a.user_id WHERE a.order_id IN ($in) GROUP BY a.order_id");
  $m->execute($ids); $mmap=[]; foreach($m->fetchAll(PDO::FETCH_ASSOC) as $r){ $mmap[(int)$r['order_id']]=$r['names']; }
  $addonMap=[]; $payMap=[];
  if($pdo->query("SHOW TABLES LIKE 'order_addons'")->fetchColumn()){ $a=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id IN ($in) GROUP BY order_id"); $a->execute($ids); foreach($a->fetchAll(PDO::FETCH_ASSOC) as $r){ $addonMap[(int)$r['order_id']] = (float)$r['s']; } }
  if($pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn()){ $p=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_payments WHERE order_id IN ($in) GROUP BY order_id"); $p->execute($ids); foreach($p->fetchAll(PDO::FETCH_ASSOC) as $r){ $payMap[(int)$r['order_id']] = (float)$r['s']; } }
  foreach($rows as &$r){ $oid=(int)$r['id']; $r['mounters']=$mmap[$oid]??''; $r['total_with_addons']=$addonMap[$oid]??(float)($r['total_amount']??0); $r['paid_sum']=$payMap[$oid]??0; $r['remaining']=$r['total_with_addons']-$r['paid_sum']; }
  unset($r);
}
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="zamowienia_'.$state.'_'.date('Ymd_His').'.xls"');
$cols=['ID','Klient','Przedmiot','Status','Data umowy','Data montażu','Nowa data','Rozpiska','Monterzy'];
if($canFinance){ $cols=array_merge($cols,['Wartość','Wpłacono','Pozostało']); }
if($state==='finished'){ $cols=array_merge($cols,['Miesiąc zakończenia','Data zakończenia']); }
if($state==='deleted'){ $cols=array_merge($cols,['Powód usunięcia','Data usunięcia']); }
$monthNames=[1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
$groupLabel = function(array $r) use ($state, $monthNames): string {
  if($state==='finished'){
    $src = (string)($r['finished_at'] ?: $r['install_date_new'] ?: $r['install_date'] ?: $r['contract_date'] ?: $r['created_at']);
    $y=(int)($r['finished_bucket_year'] ?: $r['real_year'] ?: substr($src,0,4));
    $m=(int)($r['finished_bucket_month'] ?: $r['real_month'] ?: substr($src,5,2));
    return trim(($monthNames[$m] ?? '').' '.$y);
  }
  if($state==='deleted'){
    return substr((string)($r['deleted_at'] ?: $r['created_at']), 0, 7);
  }
  $src = (string)($r['install_date_new'] ?: $r['install_date'] ?: $r['contract_date']);
  $y=(int)substr($src,0,4); $m=(int)substr($src,5,2);
  return trim(($monthNames[$m] ?? '').' '.$y);
};
echo "<html><meta charset='utf-8'><table border='1'>";
$printHeader = function() use ($cols){ echo '<tr>'; foreach($cols as $c) echo '<th>'.htmlspecialchars($c).'</th>'; echo '</tr>'; };
$currentGroup = null;
foreach($rows as $r){
  $group = $groupLabel($r);
  if($group !== $currentGroup){
    $currentGroup = $group;
    echo '<tr><td colspan="'.count($cols).'" style="background:#e5e7eb;font-weight:700">'.htmlspecialchars($group ?: 'Bez miesiąca').'</td></tr>';
    $printHeader();
  }
  $client=trim(($r['client_first_name']??'').' '.($r['client_last_name']??''));
  $vals=[(string)$r['id'],$client,$r['item_description']??'',$r['status_name']??'',$r['contract_date']??'',$r['install_date']??'',$r['install_date_new']??'',$r['scheduler_name']??'',$r['mounters']??''];
  if($canFinance){ $vals=array_merge($vals,[number_format((float)$r['total_with_addons'],2,',',' '),number_format((float)$r['paid_sum'],2,',',' '),number_format((float)$r['remaining'],2,',',' ')]); }
  if($state==='finished'){ $vals=array_merge($vals,[($r['finished_bucket_month']??'').'/'.($r['finished_bucket_year']??''),$r['finished_at']??'']); }
  if($state==='deleted'){ $vals=array_merge($vals,[$r['deleted_reason']??'',$r['deleted_at']??'']); }
  echo '<tr>'; foreach($vals as $v) echo '<td>'.htmlspecialchars((string)$v).'</td>'; echo '</tr>';
}
echo '</table></html>';
