<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
if($year < 2000 || $year > 2100 || $month < 1 || $month > 12){
  json_err('bad_request', 400, ['details'=>'year/month']);
}

$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));

// zewnętrzny technolog – tylko własne wpisy i wpisy przy jego zamówieniach
$scope = ''; $scopeParams = [];
if(acc_is_external_technologist($pdo, $me)){ $scope = 'AND (s.user_id=? OR o.scheduler_id=?)'; $scopeParams = [acc_uid($me), acc_uid($me)]; }

try{
  $exists = $pdo->query("SHOW TABLES LIKE 'work_schedule_entries'")->fetchColumn();
  if(!$exists){
    json_ok(['items'=>[]]);
  }

  $sql = "SELECT
            s.id,
            s.work_date,
            s.user_id,
            s.order_id,
            s.work_mode,
            s.note,
            COALESCE(s.work_percent,100) AS work_percent,
            CONCAT(COALESCE(o.client_first_name,''), CASE WHEN COALESCE(o.client_first_name,'')<>'' AND COALESCE(o.client_last_name,'')<>'' THEN ' ' ELSE '' END, COALESCE(o.client_last_name,'')) AS client_name
          FROM work_schedule_entries s
          LEFT JOIN orders o ON o.id = s.order_id
          WHERE s.work_date BETWEEN ? AND ? {$scope}
          ORDER BY s.work_date ASC, s.user_id ASC";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$start, $end], $scopeParams));
  json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}catch(Throwable $e){
  json_err('load_failed', 500, ['details'=>$e->getMessage()]);
}
