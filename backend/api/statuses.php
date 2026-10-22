<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

$pdo = db();
$st = $pdo->query("SELECT id, name, color, sort_order FROM order_statuses ORDER BY sort_order IS NULL, sort_order, name");
json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
