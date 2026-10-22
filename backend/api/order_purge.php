<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);
if(!is_superadmin($me)) json_err('forbidden',403);

$pdo = db();
$raw = file_get_contents('php://input');
$in = json_decode($raw,true);
if(!is_array($in)) $in=$_POST;

$id = (int)($in['id'] ?? 0);
if($id<=0) json_err('missing',422);

// twarde usunięcie
$pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);

json_ok(['ok'=>true]);
