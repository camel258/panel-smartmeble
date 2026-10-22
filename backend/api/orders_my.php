<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function resolve_user_id(PDO $pdo, array $me): int {
  foreach (['id','uid','user_id'] as $key) {
    $val = (int)($me[$key] ?? 0);
    if ($val > 0) return $val;
  }

  $email = trim((string)($me['email'] ?? ''));
  if ($email !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $id = (int)($row['id'] ?? 0);
    if ($id > 0) return $id;
  }

  $login = trim((string)($me['login'] ?? ''));
  if ($login !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE login = ? LIMIT 1");
    $st->execute([$login]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $id = (int)($row['id'] ?? 0);
    if ($id > 0) return $id;
  }

  return 0;
}

$uid = resolve_user_id($pdo, $me);
if($uid <= 0) json_err('unauthorized', 401);

$q = trim($_GET['q'] ?? '');

$sql = "
SELECT
  o.id,
  o.client_first_name,
  o.client_last_name,
  o.item_description,
  DATE_FORMAT(o.contract_date,'%Y-%m-%d') AS contract_date,
  DATE_FORMAT(o.install_date,'%Y-%m-%d') AS install_date,
  DATE_FORMAT(o.install_date_new,'%Y-%m-%d') AS install_date_new,
  s.name AS status_name,
  s.color AS status_color
FROM orders o
LEFT JOIN order_statuses s ON s.id = o.status_id
WHERE COALESCE(o.is_deleted,0)=0
  AND COALESCE(o.is_finished,0)=0
  AND NOT EXISTS (
    SELECT 1
    FROM order_statuses sx
    WHERE sx.id = o.status_id
      AND LOWER(COALESCE(sx.name,'')) LIKE '%zakończ%'
  )
  AND EXISTS (
    SELECT 1
    FROM order_assignees a
    WHERE a.order_id = o.id
      AND a.user_id = ?
  )
";

$params = [$uid];

if($q !== ''){
  $sql .= " AND (CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ? OR o.item_description LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$sql .= " ORDER BY COALESCE(o.install_date_new,o.install_date,o.contract_date,'9999-12-31') ASC, o.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

json_ok([
  'items' => $rows,
  'resolved_user_id' => $uid
]);
