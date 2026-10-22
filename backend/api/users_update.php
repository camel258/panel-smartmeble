<?php
require_once __DIR__ . '/../../config/config.php';
$me = auth_user(); if(!$me) json_err('unauthorized',401);
if(($me['role'] ?? 0) != 1) json_err('forbidden',403);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$raw = json_decode(file_get_contents('php://input'), true);
$id = (int)($raw['id'] ?? 0);
$full = trim($raw['full_name'] ?? ''); $email = trim($raw['email'] ?? ''); $role = (int)($raw['role_id'] ?? 2); $pass = $raw['password'] ?? null;
if($id<=0) json_err('bad_request',400);
if($full!=='' || $email!==''){ $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=? WHERE id=?"); $stmt->execute([$full,$email,$id]); }
$pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$role,$id]);
if($pass){ $hash = password_hash($pass, PASSWORD_DEFAULT); $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$id]); }
json_ok(['ok'=>true]);