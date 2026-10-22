<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$id=(int)($_GET['id']??0); if($id<=0) json_err('bad_request',400);
if($id>0) acc_require_order($pdo, $me, $id); // zewnętrzny technolog – tylko swoje zamówienia
try{
  $exists=$pdo->query("SHOW TABLES LIKE 'work_schedule_entries'")->fetchColumn();
  if(!$exists){ json_ok(['items'=>[]]); }
  $sql="SELECT w.user_id,
        COALESCE(NULLIF(TRIM(u.full_name),''), NULLIF(TRIM(u.email),''), CONCAT('Użytkownik #',w.user_id)) AS monter,
        ROUND(SUM(CASE WHEN w.work_mode='workshop' THEN COALESCE(w.work_percent,100)/100 ELSE 0 END),2) AS workshop_days,
        ROUND(SUM(CASE WHEN w.work_mode='install' THEN COALESCE(w.work_percent,100)/100 ELSE 0 END),2) AS install_days
      FROM work_schedule_entries w
      LEFT JOIN users u ON u.id=w.user_id
      WHERE w.order_id=?
      GROUP BY w.user_id, monter
      HAVING workshop_days>0 OR install_days>0
      ORDER BY monter ASC";
  $st=$pdo->prepare($sql); $st->execute([$id]);
  json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
