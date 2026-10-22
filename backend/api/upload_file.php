<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if(!$user) json_err('unauthorized',401);

$maxMb = (int)envv('UPLOAD_MAX_MB', 30);
$maxBytes = $maxMb * 1024 * 1024;

if(!isset($_FILES['file'])) json_err('no_file',400);
if($_FILES['file']['size'] > $maxBytes) json_err('file_too_large',413);

$orderId = (int)($_POST['order_id'] ?? 0);
if($orderId<=0) json_err('bad_request',400);

$scope = strtolower(trim((string)($_POST['file_scope'] ?? 'public')));
if(!in_array($scope, ['public','admin'], true)) $scope = 'public';

$role = api_current_role_id(db(), $user);
$canAdminFiles = in_array($role, [1, 999], true);
if($scope === 'admin' && !$canAdminFiles){
    $scope = 'public';
}

$base = api_private_files_base();
$dir = $base . '/' . api_scope_dir($scope) . '/' . $orderId;
if(!is_dir($dir) && !@mkdir($dir, 0775, true)){
    json_err('storage_unavailable',500,['details'=>'cannot_create_directory']);
}

$fname = basename($_FILES['file']['name']);
$fname = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fname);
if($fname === '') $fname = 'plik.bin';

$target = $dir . '/' . $fname;
if(!move_uploaded_file($_FILES['file']['tmp_name'], $target)) json_err('upload_failed',500);

$pdo = db();
$stmt = $pdo->prepare("INSERT INTO order_files (order_id, filename, storage_path, mime_type, uploaded_by) VALUES (?,?,?,?,?)");
$stmt->execute([
    $orderId,
    $fname,
    $target,
    $_FILES['file']['type'] ?? '',
    (int)($user['uid'] ?? $user['id'] ?? 0)
]);

json_ok([
    'ok'=>true,
    'file'=>$fname,
    'scope'=>$scope
]);
