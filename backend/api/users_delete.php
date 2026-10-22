<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

if(!$IS_SUPER) json_err('forbidden',403);

$b=json_decode(file_get_contents('php://input'),true) ?: [];
$id=(int)($b['id']??0);
if($id<=0) json_err('bad_request',400);

$pdo=db();
$st=$pdo->prepare("DELETE FROM users WHERE id=?");
$st->execute([$id]);
json_ok(['ok'=>true]);
