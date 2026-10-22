<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/contract_signers.php';

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
$role=(int)($me['role_id'] ?? ($me['role'] ?? 0)); $uid=sm_uid($me); $isExternalTechnologist=sm_is_external_technologist($pdo,$me); $isWorkerRole=sm_is_worker_role($pdo,$me); $canFinance=in_array($role,[1,999],true) && !$isExternalTechnologist && !$isWorkerRole;
$limit=max(1,min(1000,(int)($_GET['limit']??300))); $offset=max(0,(int)($_GET['offset']??0));
$q=trim($_GET['q']??''); $status=trim($_GET['status']??''); $year=trim($_GET['year']??''); $month=trim($_GET['month']??''); $from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
$where=["COALESCE(o.is_deleted,0)=0","COALESCE(o.is_finished,0)=0","NOT EXISTS (SELECT 1 FROM order_statuses sx WHERE sx.id=o.status_id AND LOWER(COALESCE(sx.name,'')) LIKE '%zakończ%')"]; $params=[]; if($isExternalTechnologist){ $where[]="o.scheduler_id=?"; $params[]=$uid; } $dateExpr="COALESCE(STR_TO_DATE(CONCAT(o.finished_bucket_year,'-',LPAD(o.finished_bucket_month,2,'0'),'-01'),'%Y-%m-%d'),o.install_date_new,o.install_date,o.contract_date)";
if($q!==''){ $where[]="(CONCAT(o.client_first_name,' ',o.client_last_name) LIKE ? OR o.item_description LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
if($status!==''){ $where[]="o.status_id=?"; $params[]=(int)$status; }
if($year!==''){ $where[]="YEAR($dateExpr)=?"; $params[]=(int)$year; }
if($month!==''){ $where[]="MONTH($dateExpr)=?"; $params[]=(int)$month; }
if($from!==''){ $where[]="$dateExpr >= ?"; $params[]=$from; }
if($to!==''){ $where[]="$dateExpr <= ?"; $params[]=$to; }
$whereSql='WHERE '.implode(' AND ',$where);
$hasMeasureDone = false;
try{ $hasMeasureDone = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'measure_done'")->fetchColumn(); }catch(Throwable $e){}
$measureDoneSql = $hasMeasureDone ? "COALESCE(o.measure_done,0) AS measure_done" : "0 AS measure_done";
cs_ensure($pdo);
$hasClientNotes = false;
try{ $hasClientNotes = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'client_notes'")->fetchColumn(); }catch(Throwable $e){}
if(!$hasClientNotes){ try{ $pdo->exec("ALTER TABLE orders ADD COLUMN client_notes TEXT NULL"); $hasClientNotes = true; }catch(Throwable $e){} }
$clientNotesSql = $hasClientNotes ? "o.client_notes" : "NULL AS client_notes";
$sql="SELECT o.id,o.client_first_name,o.client_last_name,o.item_description,{$clientNotesSql},o.contract_signer_id,cs.full_name AS contract_signer_name,DATE_FORMAT(o.contract_date,'%Y-%m-%d') contract_date,DATE_FORMAT(o.install_date,'%Y-%m-%d') install_date,DATE_FORMAT(o.install_date_new,'%Y-%m-%d') install_date_new,DATE_FORMAT(o.measure_due_date,'%Y-%m-%d') measure_due_date,{$measureDoneSql},o.finished_bucket_year,o.finished_bucket_month,DATE_FORMAT(o.created_at,'%Y-%m-%d') created_at,o.total_amount,o.deposit_amount,o.status_id,s.name AS status_name,s.color AS status_color,o.scheduler_id,u.full_name AS scheduler_name FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id LEFT JOIN contract_signers cs ON cs.id=o.contract_signer_id {$whereSql} ORDER BY $dateExpr ASC,o.id ASC LIMIT {$limit} OFFSET {$offset}";
$st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
if($rows){
 $ids=array_column($rows,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
 $m=$pdo->prepare("SELECT a.order_id,GROUP_CONCAT(us.full_name ORDER BY us.full_name SEPARATOR ', ') names FROM order_assignees a JOIN users us ON us.id=a.user_id WHERE a.order_id IN ($in) GROUP BY a.order_id");
 $m->execute($ids); $map=[]; foreach($m->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[(int)$r['order_id']]=$r['names']; }
 $addonMap=[]; $payMap=[]; $hasAddons=$pdo->query("SHOW TABLES LIKE 'order_addons'")->fetchColumn(); $hasPays=$pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn();
 if($hasAddons){ $a=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id IN ($in) GROUP BY order_id"); $a->execute($ids); foreach($a->fetchAll(PDO::FETCH_ASSOC) as $r){ $addonMap[(int)$r['order_id']] = (float)$r['s']; } }
 if($hasPays){ $p=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_payments WHERE order_id IN ($in) GROUP BY order_id"); $p->execute($ids); foreach($p->fetchAll(PDO::FETCH_ASSOC) as $r){ $payMap[(int)$r['order_id']] = (float)$r['s']; } }
 $checklistMap=[];
 try{
  $hasChecklist=$pdo->query("SHOW TABLES LIKE 'order_checklists'")->fetchColumn();
  if($hasChecklist){
    $cl=$pdo->prepare("SELECT order_id, COUNT(*) AS done_count, GROUP_CONCAT(item_code SEPARATOR ',') AS done_codes FROM order_checklists WHERE order_id IN ($in) AND is_done=1 GROUP BY order_id");
    $cl->execute($ids);
    foreach($cl->fetchAll(PDO::FETCH_ASSOC) as $r){ $checklistMap[(int)$r['order_id']] = ['done'=>(int)$r['done_count'],'codes'=>(string)$r['done_codes']]; }
  }
 }catch(Throwable $e){}
 $wmMap=[];
 try{
  $hasSched=$pdo->query("SHOW TABLES LIKE 'work_schedule_entries'")->fetchColumn();
  if($hasSched){
    $wm=$pdo->prepare("SELECT order_id,
      MAX(CASE WHEN work_mode='workshop' THEN 1 ELSE 0 END) AS has_workshop,
      MAX(CASE WHEN work_mode='install' THEN 1 ELSE 0 END) AS has_install
      FROM work_schedule_entries
      WHERE order_id IN ($in)
      GROUP BY order_id");
    $wm->execute($ids);
    foreach($wm->fetchAll(PDO::FETCH_ASSOC) as $r){
      $wmMap[(int)$r['order_id']] = [
        'has_workshop'=>(int)$r['has_workshop'],
        'has_install'=>(int)$r['has_install']
      ];
    }
  }
 }catch(Throwable $e){}
 foreach($rows as &$r){ $oid=(int)$r['id']; $r['mounters']=isset($map[$oid])?explode(', ',$map[$oid]):[]; $sum=isset($addonMap[$oid])?(float)$addonMap[$oid]:(float)($r['total_amount']??0); $paid=isset($payMap[$oid])?(float)$payMap[$oid]:0.0; $r['total_with_addons']=$sum; $r['paid_sum']=$paid; $r['remaining']=$sum-$paid; $r['has_workshop_schedule']=(int)($wmMap[$oid]['has_workshop'] ?? 0); $r['has_install_schedule']=(int)($wmMap[$oid]['has_install'] ?? 0); $r['contract_signer_initials']=cs_initials((string)($r['contract_signer_name'] ?? '')); $r['checklist_done']=(int)($checklistMap[$oid]['done'] ?? 0); $r['checklist_codes']=(string)($checklistMap[$oid]['codes'] ?? ''); if(!$canFinance){ $r['total_amount']=null; $r['deposit_amount']=null; $r['total_with_addons']=null; $r['paid_sum']=null; $r['remaining']=null; } }
}
json_ok(['items'=>$rows,'can_view_finance'=>$canFinance]);
?>