<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if(!in_array($role, [1,999], true)) json_err('forbidden',403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function planned_table_exists(PDO $pdo): bool {
  try { return (bool)$pdo->query("SHOW TABLES LIKE 'order_planned_payments'")->fetchColumn(); }
  catch(Throwable $e){ return false; }
}

if(!planned_table_exists($pdo)){
  if($method === 'GET') json_ok(['items'=>[]]);
  json_err('missing_table_order_planned_payments',500);
}

try{
  if($method === 'GET'){
    $orderId = (int)($_GET['id'] ?? 0);
    if($orderId <= 0) json_err('bad_request',400);
    $st = $pdo->prepare("SELECT id, order_id, amount, DATE_FORMAT(planned_date,'%Y-%m-%d') AS planned_date, description, created_at, updated_at FROM order_planned_payments WHERE order_id=? ORDER BY planned_date ASC, id ASC");
    $st->execute([$orderId]);
    json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if(!is_array($body)) $body = $_POST;
  $action = (string)($body['action'] ?? '');

  if($action === 'add'){
    $orderId = (int)($body['order_id'] ?? 0);
    $amount = (float)str_replace(',', '.', (string)($body['amount'] ?? 0));
    $date = trim((string)($body['planned_date'] ?? ''));
    $desc = trim((string)($body['description'] ?? ''));
    if($orderId<=0 || abs($amount)<0.000001 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err('bad_request',400);
    $st = $pdo->prepare("INSERT INTO order_planned_payments(order_id, amount, planned_date, description, created_by) VALUES(?,?,?,?,?)");
    $st->execute([$orderId, $amount, $date, $desc, (int)($me['id'] ?? 0)]);
    json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'update'){
    $id = (int)($body['id'] ?? 0);
    $amount = (float)str_replace(',', '.', (string)($body['amount'] ?? 0));
    $date = trim((string)($body['planned_date'] ?? ''));
    $desc = trim((string)($body['description'] ?? ''));
    if($id<=0 || abs($amount)<0.000001 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err('bad_request',400);
    $st = $pdo->prepare("UPDATE order_planned_payments SET amount=?, planned_date=?, description=?, updated_at=NOW() WHERE id=?");
    $st->execute([$amount, $date, $desc, $id]);
    json_ok(['ok'=>true]);
  }

  if($action === 'delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',400);
    $st = $pdo->prepare("DELETE FROM order_planned_payments WHERE id=?");
    $st->execute([$id]);
    json_ok(['ok'=>true]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
