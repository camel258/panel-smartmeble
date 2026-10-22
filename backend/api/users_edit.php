<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$role = (int)($u['role'] ?? 0);
$IS_ADMIN = ($role===1 || $role===999);
$IS_SUPER = ($role===999);

if(!$IS_ADMIN) json_err('forbidden',403);

$b=json_decode(file_get_contents('php://input'),true) ?: [];
$id=(int)($b['id']??0);
if($id<=0) json_err('bad_request',400);

$full=trim($b['full_name']??'');
$email=trim($b['email']??'');
$role_id = array_key_exists('role_id',$b) ? (int)$b['role_id'] : null;
$is_active = array_key_exists('is_active',$b) ? (int)!!$b['is_active'] : null;
$pass=trim($b['password']??($b['pass']??''));

$pdo=db();
$fields=[]; $params=[];
if($full!==''){ $fields[]="full_name=?"; $params[]=$full; }
if($email!==''){ $fields[]="email=?"; $params[]=$email; }
if($role_id!==null){ $fields[]="role_id=?"; $params[]=$role_id; }
if($is_active!==null){ $fields[]="is_active=?"; $params[]=$is_active; }
if($pass!==''){ $fields[]="password_hash=?"; $params[]=password_hash($pass, PASSWORD_DEFAULT); }

if(!$fields){ json_ok(['ok'=>true,'noop'=>true]); exit; }

$params[]=$id;
$st=$pdo->prepare("UPDATE users SET ".implode(',', $fields)." WHERE id=?");
$st->execute($params);
json_ok(['ok'=>true]);
