<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';

$me = auth_user();
if(!$me) json_err('unauthorized', 401);
acc_require_admin($me); // stary endpoint (panel używa order_finance.php) – tylko administrator

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// wpłaty
$pdo->exec("CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(order_id),
  INDEX(pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if($_SERVER['REQUEST_METHOD']==='GET'){
  $id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
  if($id>0) acc_require_order($pdo, $me, $id); // zewnętrzny technolog – tylko swoje zamówienia
  if($id<=0) json_err('bad_request', 400);
  $st = $pdo->prepare("SELECT id, DATE_FORMAT(pay_date,'%Y-%m-%d') pay_date, amount, description, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at FROM order_payments WHERE order_id=? ORDER BY pay_date DESC, id DESC");
  $st->execute([$id]);
  json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = $_POST;

$orderId = (int)($body['id'] ?? $body['order_id'] ?? 0);
if($orderId>0) acc_require_order($pdo, $me, $orderId); // zewnętrzny technolog – tylko swoje zamówienia
$payDate = trim((string)($body['pay_date'] ?? $body['date'] ?? ''));
$amount = $body['amount'] ?? null;
$desc = trim((string)($body['description'] ?? ''));

if($orderId<=0 || $payDate==='' || !is_numeric($amount) || $desc==='') json_err('bad_request', 400);

$ins = $pdo->prepare("INSERT INTO order_payments(order_id, pay_date, amount, description) VALUES(?,?,?,?)");
$ins->execute([$orderId, $payDate, (string)$amount, $desc]);
json_ok(['ok'=>true]);
