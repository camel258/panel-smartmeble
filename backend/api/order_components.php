<?php
require_once __DIR__ . '/../../config/config.php';

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// składniki wartości
$pdo->exec("CREATE TABLE IF NOT EXISTS order_components (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if($_SERVER['REQUEST_METHOD']==='GET'){
  $id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
  if($id<=0) json_err('bad_request', 400);
  $st = $pdo->prepare("SELECT id, description, amount, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at FROM order_components WHERE order_id=? ORDER BY id DESC");
  $st->execute([$id]);
  json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = $_POST;

$orderId = (int)($body['id'] ?? $body['order_id'] ?? 0);
$desc = trim((string)($body['description'] ?? ''));
$amount = $body['amount'] ?? null;

if($orderId<=0 || $desc==='' || !is_numeric($amount)) json_err('bad_request', 400);

$ins = $pdo->prepare("INSERT INTO order_components(order_id, description, amount) VALUES(?,?,?)");
$ins->execute([$orderId, $desc, (string)$amount]);
json_ok(['ok'=>true]);
