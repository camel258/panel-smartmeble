<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

function current_uid(array $me): int {
  foreach(['uid','id','user_id'] as $k){
    $v = (int)($me[$k] ?? 0);
    if($v > 0) return $v;
  }
  return 0;
}

$uid = current_uid($me);
if($uid <= 0) json_err('unauthorized', 401);

$in = json_decode(file_get_contents('php://input'), true);
if(!is_array($in)) $in = $_POST;

$current = (string)($in['current_password'] ?? '');
$new = (string)($in['new_password'] ?? '');
$confirm = (string)($in['confirm_password'] ?? '');

if($current === '' || $new === '' || $confirm === '') json_err('bad_request', 400, ['details' => 'missing_fields']);
if($new !== $confirm) json_err('password_mismatch', 400);
if(strlen($new) < 6) json_err('password_too_short', 400);
if($new === $current) json_err('password_same_as_old', 400);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("SELECT id, password_hash, is_active FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if(!$user) json_err('not_found', 404);
if(isset($user['is_active']) && (int)$user['is_active'] !== 1) json_err('forbidden', 403);

$hash = (string)($user['password_hash'] ?? '');
if($hash === '' || !password_verify($current, $hash)) {
  json_err('invalid_current_password', 400);
}

$newHash = password_hash($new, PASSWORD_DEFAULT);
$up = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
$up->execute([$newHash, $uid]);

json_ok(['ok' => true, 'message' => 'password_changed']);
