<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

if(!$IS_ADMIN) json_err('forbidden',403);

$b = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($b['name'] ?? '');
$color = trim($b['color'] ?? '#3b82f6');
$sort = (int)($b['sort_order'] ?? 0);
if($name==='') json_err('bad_request',400, ['details'=>'name_required']);

$pdo = db();
$st = $pdo->prepare("INSERT INTO order_statuses(name,color,sort_order) VALUES(?,?,?)");
$st->execute([$name,$color,$sort]);
json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
