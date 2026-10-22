<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u=auth_user(); if(!$u) json_err('unauthorized',401);
$id=(int)($_GET['id']??0); if($id<=0) json_err('invalid_id',422);
$pdo=db(); $st=$pdo->prepare("SELECT id,full_name,email,role_id,is_active FROM users WHERE id=?");
$st->execute([$id]);
$it=$st->fetch(PDO::FETCH_ASSOC); if(!$it) json_err('not_found',404);
json_ok(['item'=>$it]);
