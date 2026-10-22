<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

if(!$IS_ADMIN) json_err('forbidden',403);

$b=json_decode(file_get_contents('php://input'),true) ?: [];
$full=trim($b['full_name']??'');
$email=trim($b['email']??'');
$pass=trim($b['password']??($b['pass']??''));
$role_id=(int)($b['role_id']??2);
$is_active=isset($b['is_active']) ? (int)!!$b['is_active'] : 1;

if($full==='' || $email==='' || $pass==='') json_err('bad_request',400,['details'=>'full_name,email,password_required']);

$hash=password_hash($pass, PASSWORD_DEFAULT);
$pdo=db();
$st=$pdo->prepare("INSERT INTO users(email,password_hash,full_name,role_id,is_active) VALUES(?,?,?,?,?)");
$st->execute([$email,$hash,$full,$role_id,$is_active]);
json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
