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
$name = trim($b['name'] ?? '');
$color = trim($b['color'] ?? '#3b82f6');
$sort = (int)($b['sort_order'] ?? 0);
if($id<=0 || $name==='') json_err('bad_request',400);

$pdo = db();
$st = $pdo->prepare("UPDATE order_statuses SET name=?, color=?, sort_order=? WHERE id=?");
$st->execute([$name,$color,$sort,$id]);
json_ok(['ok'=>true]);
