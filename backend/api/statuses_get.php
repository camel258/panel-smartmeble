<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT id, name, color, sort_order FROM order_statuses WHERE id=?");
$st->execute([$id]);
$item = $st->fetch(PDO::FETCH_ASSOC);
if(!$item) json_err('not_found',404);
json_ok(['item'=>$item]);
