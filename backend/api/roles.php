<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $rows = $pdo->query("SELECT id, name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  json_ok(['items'=>$rows]);
} catch (Throwable $e) {
  $PROJECT_ROOT = dirname(__DIR__, 2);
  $LOG = $PROJECT_ROOT . '/uploads/error_log.txt';
  @file_put_contents($LOG, date('Y-m-d H:i:s')." roles.php ".$e->getMessage().PHP_EOL, FILE_APPEND);
  json_err('server_error', 500, ['details'=>$e->getMessage()]);
}
