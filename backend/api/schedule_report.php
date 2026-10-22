<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function sr_is_weekend(string $date): bool { return (int)date('N', strtotime($date)) >= 6; }
function sr_mode_label(string $mode): string {
  return ['install'=>'Montaż','workshop'=>'Warsztat','custom'=>'Inne prace','leave'=>'Urlop','day_off'=>'Wolne','sick'=>'L4 / Zwolnienie'][$mode] ?? $mode;
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $userId <= 0) json_err('bad_request', 400);
if(strtotime($from) > strtotime($to)) json_err('bad_request', 400);

try{
  $users = $pdo->query("SELECT id, COALESCE(NULLIF(TRIM(full_name),''), NULLIF(TRIM(email),''), CONCAT('Użytkownik #',id)) AS name FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  $exists = $pdo->query("SHOW TABLES LIKE 'work_schedule_entries'")->fetchColumn();
  if(!$exists) json_ok(['users'=>$users,'summary'=>[],'special'=>[],'empty_workdays'=>[],'totals'=>['leave'=>0,'day_off'=>0,'sick'=>0,'custom'=>0]]);

  $st = $pdo->prepare("SELECT s.work_date, s.user_id, s.order_id, s.work_mode, s.note,
      COALESCE(s.work_percent,100) AS work_percent,
      CONCAT(COALESCE(o.client_first_name,''), CASE WHEN COALESCE(o.client_first_name,'')<>'' AND COALESCE(o.client_last_name,'')<>'' THEN ' ' ELSE '' END, COALESCE(o.client_last_name,'')) AS client_name,
      o.item_description
    FROM work_schedule_entries s
    LEFT JOIN orders o ON o.id=s.order_id
    WHERE s.user_id=? AND s.work_date BETWEEN ? AND ?
    ORDER BY s.work_date ASC, s.id ASC");
  $st->execute([$userId, $from, $to]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $byDate=[]; $summary=[]; $special=[]; $totals=['leave'=>0,'day_off'=>0,'sick'=>0,'custom'=>0];
  foreach($rows as $r){
    $date = (string)$r['work_date']; $byDate[$date]=true; $mode=(string)($r['work_mode'] ?? 'install'); $dayPart = round(((float)($r['work_percent'] ?? 100))/100,2);
    if(in_array($mode, ['leave','day_off','sick','custom'], true)){
      $key=$mode.'|'.trim((string)($r['note'] ?? ''));
      if(!isset($special[$key])) $special[$key]=['type'=>$mode,'label'=>sr_mode_label($mode),'note'=>trim((string)($r['note'] ?? '')),'days'=>0,'dates'=>[]];
      $special[$key]['days'] += $dayPart; $special[$key]['dates'][]=$date; if(isset($totals[$mode])) $totals[$mode] += $dayPart; continue;
    }
    $orderId=(int)($r['order_id'] ?? 0); $key='order|'.$orderId.'|'.$mode;
    if(!isset($summary[$key])) $summary[$key]=['order_id'=>$orderId,'client_name'=>trim((string)($r['client_name'] ?? '')),'item_description'=>(string)($r['item_description'] ?? ''),'mode'=>$mode,'label'=>sr_mode_label($mode),'days'=>0,'dates'=>[]];
    $summary[$key]['days'] += $dayPart; $summary[$key]['dates'][]=$date;
  }
  $empty=[]; $cur=strtotime($from); $end=strtotime($to);
  while($cur <= $end){ $d=date('Y-m-d',$cur); if(!sr_is_weekend($d) && empty($byDate[$d])) $empty[]=$d; $cur=strtotime('+1 day',$cur); }
  json_ok(['users'=>$users,'summary'=>array_values($summary),'special'=>array_values($special),'empty_workdays'=>$empty,'totals'=>$totals]);
}catch(Throwable $e){ json_err('report_failed',500,['details'=>$e->getMessage()]); }
