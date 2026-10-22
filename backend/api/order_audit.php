<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$orderId = (int)($_GET['id'] ?? 0);
if($orderId<=0) json_err('bad_request',400);
$pdo = db();
try{
  ensure_order_audit_schema($pdo);
  $st = $pdo->prepare("SELECT id, action_code, message, payload_json, user_id, user_name, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at FROM order_audit_log WHERE order_id=? ORDER BY id DESC LIMIT 200");
  $st->execute([$orderId]);
  $items = [];
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
    $payload = null;
    if(!empty($row['payload_json'])){
      $decoded = json_decode($row['payload_json'], true);
      if(is_array($decoded)) $payload = $decoded;
    }
    $row['payload'] = $payload;
    unset($row['payload_json']);
    $items[] = $row;
  }
  json_ok(['items'=>$items]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
