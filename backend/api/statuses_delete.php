<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

if(!$IS_ADMIN) json_err('forbidden',403);

$b = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($b['id'] ?? 0);
if($id<=0) json_err('bad_request',400);

$pdo = db();
try {
  $st = $pdo->prepare("DELETE FROM order_statuses WHERE id=?");
  $st->execute([$id]);
  json_ok(['ok'=>true]);
} catch (Throwable $e) {
  json_err('in_use',409,['details'=>$e->getMessage()]);
}
