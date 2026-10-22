<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/octet-stream');

function sm_uid(array $me): int {
  foreach(['id','uid','user_id'] as $k){ $v=(int)($me[$k]??0); if($v>0) return $v; }
  return 0;
}
function sm_role_name(PDO $pdo, array $me): string {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  if($rid<=0) return '';
  try{
    $st=$pdo->prepare("SELECT name FROM roles WHERE id=? LIMIT 1");
    $st->execute([$rid]);
    return mb_strtolower(trim((string)$st->fetchColumn()), 'UTF-8');
  }catch(Throwable $e){ return ''; }
}
function sm_is_external_technologist(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return (strpos($n,'technolog')!==false && (strpos($n,'zewn')!==false || strpos($n,'zewnętr')!==false || strpos($n,'zewnetrz')!==false));
}
function sm_is_worker_role(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return strpos($n,'pracownik')!==false;
}
function sm_is_admin_role(array $me): bool {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  return in_array($rid,[1,999],true);
}


// Fallback dla pobierania plików z linku otwieranego w przeglądarce:
// jeśli frontend dołączy token w query string, podstaw go jako cookie JWT
// zanim wywołamy auth_user().
if (empty($_COOKIE['jwt']) && !empty($_GET['token']) && is_string($_GET['token'])) {
    $_COOKIE['jwt'] = (string)$_GET['token'];
}

$user = auth_user();
if(!$user) json_err('unauthorized',401);

$uid = (int)($user['uid'] ?? $user['id'] ?? 0);

function private_files_base_local(): string {
    $candidates = [
        realpath(__DIR__ . '/../../../_private_files'),
        realpath(__DIR__ . '/../../_private_files'),
    ];
    foreach($candidates as $c){
        if($c && is_dir($c)) return $c;
    }
    return dirname(dirname(dirname(__DIR__))) . '/private_files';
}

function path_starts_with_local(string $path, string $base): bool {
    $rp = realpath($path);
    $rb = realpath($base);
    if(!$rp || !$rb) return false;
    $rp = str_replace('\\','/',$rp);
    $rb = rtrim(str_replace('\\','/',$rb),'/') . '/';
    return strpos($rp, $rb) === 0;
}

function detect_scope_from_path_local(string $path): string {
    $p = str_replace('\\','/',$path);
    if(strpos($p, '/orders_admin/') !== false) return 'admin';
    return 'public';
}

$canViewPublicFiles = true;
$canViewAdminFiles = false;

try {
    if ($uid > 0 && function_exists('can_view_field')) {
        $canViewPublicFiles = (bool)can_view_field($uid, 'files_public');
        $canViewAdminFiles  = (bool)can_view_field($uid, 'files_admin');
    }
} catch (Throwable $e) {}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$isExternalTechnologist = sm_is_external_technologist($pdo,$user);
if($isExternalTechnologist){ $canViewPublicFiles=true; $canViewAdminFiles=false; }

$file_id = (int)($_GET['file_id'] ?? 0);
if ($file_id<=0) json_err('bad_request',400);

$stmt = $pdo->prepare("SELECT * FROM order_files WHERE id = ?");
$stmt->execute([$file_id]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$f) json_err('not_found',404);
if($isExternalTechnologist){
    $stAcc=$pdo->prepare("SELECT scheduler_id FROM orders WHERE id=? LIMIT 1");
    $stAcc->execute([(int)($f['order_id'] ?? 0)]);
    if((int)$stAcc->fetchColumn() !== $uid) json_err('forbidden',403);
}

$path = (string)($f['storage_path'] ?? '');
if ($path === '' || !is_file($path)) json_err('not_found',404);

$scope = detect_scope_from_path_local($path);

if($scope === 'admin' && !$canViewAdminFiles){
    json_err('forbidden',403);
}
if($scope !== 'admin' && !$canViewPublicFiles){
    json_err('forbidden',403);
}

$privateBase = private_files_base_local();
$legacyBases = [
    dirname(dirname(__DIR__)) . '/upload',
    dirname(__DIR__) . '/upload',
];

$allowed = path_starts_with_local($path, $privateBase);
if(!$allowed){
    foreach($legacyBases as $b){
        if(path_starts_with_local($path, $b)){ $allowed = true; break; }
    }
}
if(!$allowed) json_err('forbidden',403);

$inline = isset($_GET['inline']);
$mime = (string)($f['mime_type'] ?: 'application/octet-stream');

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.basename((string)$f['filename']).'"');

readfile($path);
exit;
