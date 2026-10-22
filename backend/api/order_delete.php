<?php
require_once __DIR__ . '/../../config/config.php';
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$role = (int)($me['role_id'] ?? $me['role'] ?? 0);
if($role !== 999) json_err('forbidden',403);

$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = $_POST;

$id = (int)($body['id'] ?? 0);
$reason = trim((string)($body['reason'] ?? ''));
if($id<=0 || $reason==='') json_err('bad_request',400);

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try{
  $st = $pdo->prepare("UPDATE orders
    SET is_deleted=1,
        deleted_at=NOW(),
        deleted_reason=?
    WHERE id=?");
  $st->execute([$reason,$id]);
  json_ok(['ok'=>true]);
}catch(Throwable $e){
  json_err('save_failed',500,['details'=>$e->getMessage()]);
}
