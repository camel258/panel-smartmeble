<?php
function ensure_order_audit_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS order_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    action_code VARCHAR(50) NOT NULL,
    message VARCHAR(255) NOT NULL,
    payload_json LONGTEXT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_created (order_id, created_at),
    KEY idx_action_code (action_code),
    KEY idx_user_id (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function order_audit_user_payload(?array $user): ?array {
  if(!$user) return null;
  $uid = isset($user['uid']) ? (int)$user['uid'] : (isset($user['id']) ? (int)$user['id'] : null);
  $first = trim((string)($user['first_name'] ?? ''));
  $last  = trim((string)($user['last_name'] ?? ''));
  $full  = trim($first . ' ' . $last);
  if($full === '') $full = trim((string)($user['full_name'] ?? $user['name'] ?? $user['login'] ?? $user['username'] ?? $user['email'] ?? ''));
  return ['id'=>$uid ?: null, 'name'=>$full !== '' ? $full : null];
}
function order_audit_log(PDO $pdo, int $orderId, string $actionCode, string $message, ?array $payload=null, ?array $user=null): void {
  if($orderId<=0) return;
  try{
    // Uwaga: CREATE TABLE IF NOT EXISTS w MySQL potrafi zrobić implicit commit.
    // Dlatego w aktywnej transakcji NIE odpalamy ensure_order_audit_schema().
    // Zakładamy, że tabela istnieje z wcześniejszego patcha/migracji.
    if (!$pdo->inTransaction()) {
      ensure_order_audit_schema($pdo);
    }
    $u = order_audit_user_payload($user);
    $uid = (int)($u['id'] ?? 0);
    $uname = trim((string)($u['name'] ?? ''));
    $st = $pdo->prepare("INSERT INTO order_audit_log(order_id,action_code,message,payload_json,user_id,user_name) VALUES(?,?,?,?,?,?)");
    $st->execute([
      $orderId,
      $actionCode,
      $message,
      $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
      $uid > 0 ? $uid : null,
      $uname !== '' ? $uname : null
    ]);
  }catch(Throwable $e){}
}
