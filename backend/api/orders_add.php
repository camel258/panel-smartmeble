<?php
require_once __DIR__ . '/../../config/config.php';
ini_set('display_errors', 1); error_reporting(E_ALL);
$user = auth_user(); if(!$user) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PROJECT_ROOT = dirname(__DIR__, 2); $UPLOAD_BASE  = $PROJECT_ROOT . '/uploads/orders'; $ERROR_LOG = $PROJECT_ROOT . '/uploads/error_log.txt';
if (!is_dir($UPLOAD_BASE)) { @mkdir($UPLOAD_BASE, 0775, true); }
$pdo->exec("CREATE TABLE IF NOT EXISTS order_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, filename VARCHAR(255) NOT NULL, storage_path VARCHAR(500) NOT NULL, mime_type VARCHAR(100), size_bytes BIGINT UNSIGNED DEFAULT 0, uploaded_by INT UNSIGNED, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
function post($k){ return trim($_POST[$k] ?? ''); }
$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare("INSERT INTO orders (client_first_name, client_last_name, item_description, contract_date, install_date, install_date_new, measure_due_date, total_amount, deposit_amount, status_id, scheduler_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([ post('client_first_name')?:null, post('client_last_name')?:null, post('item_description')?:null, post('contract_date')?:null, post('install_date')?:null, post('install_date_new')?:null, post('measure_due_date')?:null, strlen(post('total_amount'))?post('total_amount'):null, strlen(post('deposit_amount'))?post('deposit_amount'):null, strlen(post('status_id'))?post('status_id'):null, strlen(post('scheduler_id'))?post('scheduler_id'):null ]);
  $orderId = (int)$pdo->lastInsertId();
  if (!empty($_POST['mounters'])) { $csv = array_map('trim', explode(',', $_POST['mounters'])); $pdo->exec("CREATE TABLE IF NOT EXISTS order_assignees ( id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_order_user(order_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); $ins = $pdo->prepare("INSERT IGNORE INTO order_assignees(order_id, user_id) VALUES(?,?)"); foreach($csv as $uid){ if ($uid!=='' && ctype_digit($uid)) $ins->execute([$orderId,(int)$uid]); } }
  $allowed = ['application/pdf','image/jpeg','image/png']; $maxBytes = 100 * 1024 * 1024;
  if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $cnt = count($_FILES['files']['name']); $finfo = new finfo(FILEINFO_MIME_TYPE);
    $dir = $UPLOAD_BASE . '/' . $orderId; if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $insFile = $pdo->prepare("INSERT INTO order_files(order_id, filename, storage_path, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)");
    for ($i=0; $i<$cnt; $i++) {
      $name = $_FILES['files']['name'][$i]; $tmp  = $_FILES['files']['tmp_name'][$i]; $size = (int)$_FILES['files']['size'][$i]; $err  = (int)$_FILES['files']['error'][$i];
      if ($err !== UPLOAD_ERR_OK) continue; if ($size > $maxBytes) throw new Exception('file_too_large'); $mime = $finfo->file($tmp); if (!in_array($mime, $allowed)) throw new Exception('bad_mime');
      $safeName = preg_replace('/[^A-Za-z0-9._-]/','_', $name); $target = $dir . '/' . uniqid('', true) . '_' . $safeName;
      if (!move_uploaded_file($tmp, $target)) throw new Exception('upload_failed'); $insFile->execute([$orderId, $name, $target, $mime, $size, $user['uid'] ?? null]);
    }
  }
  $pdo->commit(); @include_once __DIR__ . '/../lib/mail.php'; send_order_email($pdo, $orderId, 'new'); json_ok(['ok'=>true, 'order_id'=>$orderId]);
} catch (Throwable $e) { $pdo->rollBack(); @file_put_contents($ERROR_LOG, date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND); json_err('save_failed', 500, ['details'=>$e->getMessage()]); }