<?php
// Eksport zaznaczonych zamówień z listy do .xlsx (operacja zbiorcza na liście).
// GET ?ids=1,2,3&state=current|finished
// Kolumny jak na liście; kwoty tylko gdy lista pokazuje je danej roli
// (aktualne: jak orders.php – admin, finished: jak orders_finished.php – uprawnienia finansowe).
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/contract_signers.php';
require_once __DIR__ . '/../lib/xlsx_writer.php';

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$isExt = acc_is_external_technologist($pdo,$me); $isWorker = acc_is_worker($pdo,$me);
$state = ($_GET['state'] ?? 'current') === 'finished' ? 'finished' : 'current';
if($state==='finished' && ($isExt || $isWorker || acc_role_id($me)===3)) json_err('forbidden',403);
$canFinance = $state==='finished'
  ? (acc_can($pdo,$me,'finance_summary') || acc_can($pdo,$me,'finance_components') || acc_can($pdo,$me,'finance_payments'))
  : (acc_is_admin($me) && !$isExt && !$isWorker);

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn($v)=>$v>0)));
if(!$ids) json_err('no_ids',400);
if(count($ids) > 2000) json_err('too_many',400);
$in = implode(',', array_fill(0, count($ids), '?'));
$where = ["o.id IN ($in)", "COALESCE(o.is_deleted,0)=0"]; $params = $ids;
if($isExt){ $where[] = "o.scheduler_id=?"; $params[] = acc_uid($me); }

cs_ensure($pdo);
$col = function(string $c) use ($pdo): bool { try{ return (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE ".$pdo->quote($c))->fetchColumn(); }catch(Throwable $e){ return false; } };
$notesSql = $col('client_notes') ? 'o.client_notes' : 'NULL AS client_notes';
$measureDoneSql = $col('measure_done') ? 'COALESCE(o.measure_done,0) AS measure_done' : '0 AS measure_done';
$bucketY = "COALESCE(o.finished_bucket_year,o.real_year,YEAR(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))";
$bucketM = "COALESCE(o.finished_bucket_month,o.real_month,MONTH(COALESCE(o.finished_at,o.install_date_new,o.install_date,o.contract_date,o.created_at)))";
$orderBy = $state==='finished'
  ? "$bucketY ASC, $bucketM ASC, COALESCE(o.install_date_new,o.install_date,o.contract_date,o.created_at) ASC, o.id ASC"
  : "COALESCE(STR_TO_DATE(CONCAT(o.finished_bucket_year,'-',LPAD(o.finished_bucket_month,2,'0'),'-01'),'%Y-%m-%d'),o.install_date_new,o.install_date,o.contract_date) ASC, o.id ASC";
$sql = "SELECT o.id,o.client_first_name,o.client_last_name,o.item_description,{$notesSql},cs.full_name AS contract_signer_name,
  DATE_FORMAT(o.contract_date,'%Y-%m-%d') contract_date,DATE_FORMAT(o.install_date,'%Y-%m-%d') install_date,DATE_FORMAT(o.install_date_new,'%Y-%m-%d') install_date_new,
  DATE_FORMAT(o.measure_due_date,'%Y-%m-%d') measure_due_date,{$measureDoneSql},o.total_amount,s.name AS status_name,u.full_name AS scheduler_name,
  {$bucketY} AS bucket_year,{$bucketM} AS bucket_month,DATE_FORMAT(o.finished_at,'%Y-%m-%d') finished_at
  FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id LEFT JOIN contract_signers cs ON cs.id=o.contract_signer_id
  WHERE ".implode(' AND ',$where)." ORDER BY {$orderBy}";
$st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
if(!$rows) json_err('not_found',404);

$rid = array_column($rows,'id'); $rin = implode(',', array_fill(0, count($rid), '?'));
$mmap = []; $addonMap = []; $payMap = [];
$m = $pdo->prepare("SELECT a.order_id,GROUP_CONCAT(us.full_name ORDER BY us.full_name SEPARATOR ', ') names FROM order_assignees a JOIN users us ON us.id=a.user_id WHERE a.order_id IN ($rin) GROUP BY a.order_id");
$m->execute($rid); foreach($m->fetchAll(PDO::FETCH_ASSOC) as $r){ $mmap[(int)$r['order_id']] = $r['names']; }
if($canFinance){
  if($pdo->query("SHOW TABLES LIKE 'order_addons'")->fetchColumn()){ $a=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_addons WHERE order_id IN ($rin) GROUP BY order_id"); $a->execute($rid); foreach($a->fetchAll(PDO::FETCH_ASSOC) as $r){ $addonMap[(int)$r['order_id']] = (float)$r['s']; } }
  if($pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn()){ $p=$pdo->prepare("SELECT order_id,COALESCE(SUM(amount),0) s FROM order_payments WHERE order_id IN ($rin) GROUP BY order_id"); $p->execute($rid); foreach($p->fetchAll(PDO::FETCH_ASSOC) as $r){ $payMap[(int)$r['order_id']] = (float)$r['s']; } }
}

$head = ['Nr','Status','Klient','Przedmiot','Uwagi','Data umowy','Data montażu','Nowa data','Termin pomiaru','Pomiar wykonany','Podpisał'];
$widths = [7,22,26,40,30,12,12,12,14,10,22];
if($canFinance){ array_push($head,'Wartość','Wpłacono','Pozostało'); array_push($widths,14,14,14); }
array_push($head,'Rozpiska','Monterzy'); array_push($widths,20,30);
if($state==='finished'){ array_push($head,'Miesiąc zakończenia','Data zakończenia'); array_push($widths,12,14); }

$out = [$head]; $sumT = 0.0; $sumP = 0.0;
foreach($rows as $r){
  $oid = (int)$r['id'];
  $line = [$oid, $r['status_name'] ?? '', trim(($r['client_first_name'] ?? '').' '.($r['client_last_name'] ?? '')), $r['item_description'] ?? '', $r['client_notes'] ?? '',
    ['t'=>'date','v'=>$r['contract_date']], ['t'=>'date','v'=>$r['install_date']], ['t'=>'date','v'=>$r['install_date_new']],
    ['t'=>'date','v'=>$r['measure_due_date']], (int)$r['measure_done'] ? 'tak' : 'nie', $r['contract_signer_name'] ?? ''];
  if($canFinance){
    $total = $addonMap[$oid] ?? (float)($r['total_amount'] ?? 0); $paid = $payMap[$oid] ?? 0.0;
    $sumT += $total; $sumP += $paid;
    array_push($line, ['t'=>'money','v'=>$total], ['t'=>'money','v'=>$paid], ['t'=>'money','v'=>$total-$paid]);
  }
  array_push($line, $r['scheduler_name'] ?? '', $mmap[$oid] ?? '');
  if($state==='finished'){ array_push($line, sprintf('%02d.%04d', (int)$r['bucket_month'], (int)$r['bucket_year']), ['t'=>'date','v'=>$r['finished_at']]); }
  $out[] = $line;
}
if($canFinance){
  $sum = array_fill(0, count($head), null); $sum[0] = 'Suma'; $fi = array_search('Wartość', $head, true);
  $sum[$fi] = ['t'=>'money','v'=>$sumT]; $sum[$fi+1] = ['t'=>'money','v'=>$sumP]; $sum[$fi+2] = ['t'=>'money','v'=>$sumT-$sumP];
  $out[] = $sum;
}

$data = xlsx_build($state==='finished' ? 'Zakończone' : 'Zamówienia', $out, ['widths'=>$widths, 'bold_last'=>$canFinance]);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="zamowienia_'.($state==='finished'?'zakonczone':'aktualne').'_'.date('Ymd_His').'.xlsx"');
header('Content-Length: '.strlen($data));
header('Cache-Control: no-store');
echo $data;
