<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$roleId = 0;
if(isset($me['role_id'])) $roleId = (int)$me['role_id'];
else if(isset($me['role'])) $roleId = (int)$me['role'];
if(!in_array($roleId, [1,999], true)) json_err('forbidden', 403);

$in = read_json();

$email = strtolower(trim((string)($in['email'] ?? '')));
$full_name = trim((string)($in['full_name'] ?? ''));
$password = (string)($in['password'] ?? '');
$new_role_id = isset($in['role_id']) ? (int)$in['role_id'] : null;
$is_active = isset($in['is_active']) ? (int)$in['is_active'] : 1;

if($email==='' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('bad_email', 400);
if($full_name==='') json_err('bad_name', 400);
if(strlen($password) < 6) json_err('bad_password', 400);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$PROJECT_ROOT = dirname(__DIR__, 2);
$LOG = $PROJECT_ROOT . '/uploads/error_log.txt';

try{
  $chk = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $chk->execute([$email]);
  if($chk->fetch()) json_err('email_exists', 409);

  $hash = password_hash($password, PASSWORD_DEFAULT);
  $st = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role_id, is_active) VALUES (?,?,?,?,?)");
  $st->execute([$email, $hash, $full_name, $new_role_id, $is_active ? 1 : 0]);
  $id = (int)$pdo->lastInsertId();

  json_ok(['ok'=>true, 'id'=>$id]);
}catch(Throwable $e){
  @file_put_contents($LOG, date('Y-m-d H:i:s')." users_create ".$e->getMessage().PHP_EOL, FILE_APPEND);
  json_err('server_error', 500, ['details'=>$e->getMessage()]);
}
