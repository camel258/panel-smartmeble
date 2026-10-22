<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/wycena_guard.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$roleId = (int)($me['role_id'] ?? $me['role'] ?? 0);
if($roleId !== 999) json_err('forbidden',403,['details'=>'super_admin_required']);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if(!is_array($body)) $body = $_POST;
$password = (string)($body['password'] ?? '');

if(!hash_equals(wycena_password(), $password)){
  json_err('invalid_password', 403, ['details'=>'Nieprawidłowe hasło.']);
}

$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
json_ok(['ok'=>true, 'token'=>wycena_token_for($uid)]);
