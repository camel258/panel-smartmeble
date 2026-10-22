<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)($me['id'] ?? ($me['uid'] ?? 0));
$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
$year = max(2024, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$first = sprintf('%04d-%02d-01', $year, $month);
$last = date('Y-m-t', strtotime($first));

$mounters = [];
if($role===3){
  $st = $pdo->prepare("SELECT id, full_name FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if($row) $mounters[] = ['id'=>(int)$row['id'],'full_name'=>(string)$row['full_name']];
}else{
  try{
    $st = $pdo->query("SELECT id, full_name FROM users WHERE is_active=1 AND role_id=3 ORDER BY full_name");
    $mounters = array_map(fn($r)=>['id'=>(int)$r['id'],'full_name'=>(string)$r['full_name']], $st->fetchAll(PDO::FETCH_ASSOC));
  }catch(Throwable $e){ $mounters = []; }
}

if(empty($mounters)) json_ok(['year'=>$year,'month'=>$month,'mounters'=>[],'items'=>[]]);

$mounterIds = array_column($mounters,'id');
$in = implode(',', array_fill(0, count($mounterIds), '?'));
$params = array_merge([$first, $last], $mounterIds);

$sql = "SELECT a.user_id, o.id AS order_id, DATE_FORMAT(COALESCE(o.install_date_new,o.install_date),'%Y-%m-%d') AS work_date, CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) AS client_name, o.item_description, 'montaz' AS work_kind
        FROM order_assignees a
        JOIN orders o ON o.id=a.order_id
        WHERE COALESCE(o.is_deleted,0)=0
          AND COALESCE(o.is_finished,0)=0
          AND COALESCE(o.install_date_new,o.install_date) BETWEEN ? AND ?
          AND a.user_id IN ($in)
        ORDER BY work_date, client_name";
$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll(PDO::FETCH_ASSOC);
foreach($items as &$it){ $it['user_id']=(int)$it['user_id']; $it['order_id']=(int)$it['order_id']; }
unset($it);
json_ok(['year'=>$year,'month'=>$month,'mounters'=>$mounters,'items'=>$items,'mode'=>($role===3?'mine':'all')]);
