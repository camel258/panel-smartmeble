<?php
require_once __DIR__ . '/../../config/config.php';
$me = auth_user(); if(!$me) json_err('unauthorized',401);
if(($me['role'] ?? 0) != 1) json_err('forbidden',403);
$pdo = db();
$raw = json_decode(file_get_contents('php://input'), true);
$id=(int)($raw['id'] ?? 0); $name = trim($raw['name'] ?? ''); $color = trim($raw['color'] ?? ''); $sort = (int)($raw['sort_order'] ?? 100);
if($id<=0) json_err('bad_request',400);
$pdo->prepare("UPDATE order_statuses SET name=?, color=?, sort_order=? WHERE id=?")->execute([$name,$color,$sort,$id]);
json_ok(['ok'=>true]);