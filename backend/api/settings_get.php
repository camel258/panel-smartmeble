<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u=auth_user(); if(!$u) json_err('unauthorized',401);
$pdo=db();
$rows=$pdo->query("SELECT `key`,`value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
json_ok(['settings'=>$rows]);
