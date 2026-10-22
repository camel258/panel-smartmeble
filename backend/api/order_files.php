<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

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


$u = auth_user();
if (!$u) json_err('unauthorized', 401);

$role = (int)($u['role_id'] ?? ($u['role'] ?? 0));
$uid = (int)($u['uid'] ?? ($u['id'] ?? 0));
$roleNamePdo = db();
$isExternalTechnologist = sm_is_external_technologist($roleNamePdo,$u);

$canDelete = in_array($role, [1, 999], true);
$canViewFiles = true;
$canViewPublicFiles = true;
$canViewAdminFiles = in_array($role, [1, 999], true);
$canUploadPublicFiles = in_array($role, [1, 3, 999], true);
$canUploadAdminFiles = in_array($role, [1, 999], true);

try {
    if ($uid > 0 && function_exists('can_view_field')) {
        $canViewFiles = (bool)can_view_field($uid, 'files');
        $canViewPublicFiles = (bool)can_view_field($uid, 'files_public');
        $canViewAdminFiles = (bool)can_view_field($uid, 'files_admin');
    }
    if ($uid > 0 && function_exists('can_edit_field')) {
        $canUploadPublicFiles = (bool)can_edit_field($uid, 'files_public') || $role === 3;
        $canUploadAdminFiles = (bool)can_edit_field($uid, 'files_admin');
        // Usuwanie plików zostawiamy tylko dla admin/superadmin.
        // Pracownik z uprawnieniem files_public może dodawać pliki ogólne, ale nie usuwać.
    }
} catch (Throwable $e) {
    // keep safe fallbacks
}
if($isExternalTechnologist){ $canViewFiles=true; $canViewPublicFiles=true; $canViewAdminFiles=false; $canUploadPublicFiles=false; $canUploadAdminFiles=false; $canDelete=false; }
if (!$canViewFiles) {
    json_ok([
        'items' => [],
        'can_view_admin_files' => false,
        'can_upload_public_files' => false,
        'can_upload_admin_files' => false,
        'can_delete_files' => false,
    ]);
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) json_err('invalid', 422);

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
function scope_dir_local(string $scope): string {
    return $scope === 'admin' ? 'orders_admin' : 'orders_public';
}
function detect_scope_from_path_local(string $path): string {
    $p = str_replace('\\','/', $path);
    if(strpos($p, '/orders_admin/') !== false) return 'admin';
    return 'public';
}
function file_kind($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) return 'image';
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) return 'xls';
    if (in_array($ext, ['doc', 'docx', 'rtf', 'odt'], true)) return 'doc';
    if (in_array($ext, ['zip', 'rar', '7z'], true)) return 'zip';
    return 'file';
}
function request_json_body(): array {
    static $decoded = null;
    if ($decoded !== null) return $decoded;
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        $decoded = [];
        return $decoded;
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $decoded = $json;
        return $decoded;
    }
    parse_str($raw, $parsed);
    $decoded = is_array($parsed) ? $parsed : [];
    return $decoded;
}
function normalize_delete_names($value): array {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) || is_numeric($v)) $out[] = (string)$v;
        }
        return $out;
    }
    if (!is_string($value) && !is_numeric($value)) return [];
    $value = trim((string)$value);
    if ($value === '') return [];
    if ($value[0] === '[') {
        $json = json_decode($value, true);
        if (is_array($json)) return normalize_delete_names($json);
    }
    if (strpos($value, ',') !== false) {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($x) => $x !== ''));
    }
    return [$value];
}
function collect_delete_names(array $body): array {
    $candidates = [
        $_GET['name'] ?? null,
        $_GET['names'] ?? null,
        $_GET['file'] ?? null,
        $_GET['files'] ?? null,
        $_POST['name'] ?? null,
        $_POST['names'] ?? null,
        $_POST['file'] ?? null,
        $_POST['files'] ?? null,
        $body['name'] ?? null,
        $body['names'] ?? null,
        $body['file'] ?? null,
        $body['files'] ?? null,
    ];

    $names = [];
    foreach ($candidates as $candidate) {
        foreach (normalize_delete_names($candidate) as $name) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique(array_filter(array_map(static function ($name) {
        $name = basename((string)$name);
        return $name === '' ? null : $name;
    }, $names))));

    return $names;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = request_json_body();
$action = $_POST['action'] ?? ($_GET['action'] ?? ($body['action'] ?? ''));

$pdo = db();
if($isExternalTechnologist){
    $stAcc=$pdo->prepare("SELECT scheduler_id FROM orders WHERE id=? LIMIT 1");
    $stAcc->execute([$id]);
    if((int)$stAcc->fetchColumn() !== $uid) json_err('forbidden',403);
}

if ($method === 'DELETE' || ($method === 'POST' && $action === 'delete')) {
    if (!$canDelete) json_err('forbidden', 403);

    $names = collect_delete_names($body);
    $deleted = [];
    $missing = [];

    // private files from DB
    if($names){
        $st = $pdo->prepare("SELECT id, filename, storage_path FROM order_files WHERE order_id = ?");
        $st->execute([$id]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $name = basename((string)$row['filename']);
            if(!in_array($name, $names, true)) continue;
            $scope = detect_scope_from_path_local((string)$row['storage_path']);
            if($scope === 'admin' && !$canViewAdminFiles) continue;
            if($scope !== 'admin' && !$canViewPublicFiles) continue;
            $path = (string)$row['storage_path'];
            if (is_file($path) && @unlink($path)) {
                $deleted[] = $name;
            }
            $pdo->prepare("DELETE FROM order_files WHERE id = ?")->execute([(int)$row['id']]);
        }
    }

    // legacy public fallback
    $legacyDir = __DIR__ . '/../../upload/orders/' . $id;
    foreach ($names as $name) {
        if(in_array($name, $deleted, true)) continue;
        $path = $legacyDir . '/' . $name;
        if (is_file($path)) {
            if (@unlink($path)) {
                $deleted[] = $name;
            }
        } else {
            $missing[] = $name;
        }
    }

    json_ok([
        'ok' => true,
        'deleted' => array_values(array_unique($deleted)),
        'missing' => array_values(array_unique($missing)),
        'received' => $names,
    ]);
    exit;
}

if ($method === 'POST') {
    if (!isset($_FILES['files'])) json_err('no_files', 422);

    $scope = strtolower(trim((string)($_POST['file_scope'] ?? 'public')));
    if(!in_array($scope, ['public','admin'], true)) $scope = 'public';
    if($scope === 'admin' && !$canUploadAdminFiles) $scope = 'public';
    if($scope !== 'admin' && !$canUploadPublicFiles) json_err('forbidden', 403);

    $base = private_files_base_local();
    $dir = $base . '/' . scope_dir_local($scope) . '/' . $id;
    if(!is_dir($dir) && !@mkdir($dir, 0775, true)){
        json_err('storage_unavailable',500,['details'=>'cannot_create_directory']);
    }

    $out = [];
    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
        if (!is_uploaded_file($tmp)) continue;

        $name = basename($_FILES['files']['name'][$i]);
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
        if ($name === '') $name = 'plik.bin';
        if (filesize($tmp) > 100 * 1024 * 1024) continue;

        $dst = $dir . '/' . $name;
        if (@move_uploaded_file($tmp, $dst)) {
            $mime = $_FILES['files']['type'][$i] ?? '';
            $pdo->prepare("INSERT INTO order_files (order_id, filename, storage_path, mime_type, uploaded_by) VALUES (?,?,?,?,?)")
                ->execute([$id, $name, $dst, $mime, (int)($u['uid'] ?? $u['id'] ?? 0)]);

            $fileId = (int)$pdo->lastInsertId();
            $kind = file_kind($name);
            $url = '../backend/api/file_download.php?file_id=' . $fileId;
            $out[] = [
                'id' => $fileId,
                'name' => $name,
                'url' => $url,
                'kind' => $kind,
                'scope' => $scope,
                'is_image' => $kind === 'image',
                'thumb' => $kind === 'image' ? ($url . '&inline=1') : null,
                'size' => (int)@filesize($dst),
            ];
        }
    }

    json_ok([
        'items' => $out,
        'can_view_public_files' => $canViewPublicFiles,
        'can_view_admin_files' => $canViewAdminFiles,
        'can_upload_public_files' => $canUploadPublicFiles,
        'can_upload_admin_files' => $canUploadAdminFiles,
        'can_delete_files' => $canDelete,
    ]);
    exit;
}

$items = [];

// private files from DB
try{
    $st = $pdo->prepare("SELECT id, filename, storage_path, mime_type FROM order_files WHERE order_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $scope = detect_scope_from_path_local((string)$row['storage_path']);
        if($scope === 'admin' && !$canViewAdminFiles) continue;
        if($scope !== 'admin' && !$canViewPublicFiles) continue;

        $name = (string)$row['filename'];
        $kind = file_kind($name);
        $url = '../backend/api/file_download.php?file_id=' . (int)$row['id'];
        $items[] = [
            'id' => (int)$row['id'],
            'name' => $name,
            'url' => $url,
            'kind' => $kind,
            'scope' => $scope,
            'is_image' => $kind === 'image',
            'thumb' => $kind === 'image' ? ($url . '&inline=1') : null,
            'size' => is_file((string)$row['storage_path']) ? (int)@filesize((string)$row['storage_path']) : 0,
        ];
    }
}catch(Throwable $e){
    // ignore DB issues, keep legacy fallback
}

// legacy public fallback
$legacyDir = __DIR__ . '/../../upload/orders/' . $id;
$legacyWeb = '/upload/orders/' . $id;
if($canViewPublicFiles && is_dir($legacyDir)){
    foreach (glob($legacyDir . '/*') ?: [] as $f) {
        if (is_file($f)) {
            $name = basename($f);
            $already = false;
            foreach($items as $it){
                if(($it['name'] ?? '') === $name) { $already = true; break; }
            }
            if($already) continue;

            $kind = file_kind($name);
            $items[] = [
                'id' => null,
                'name' => $name,
                'url' => $legacyWeb . '/' . rawurlencode($name),
                'kind' => $kind,
                'scope' => 'public',
                'is_image' => $kind === 'image',
                'thumb' => $kind === 'image' ? ($legacyWeb . '/' . rawurlencode($name)) : null,
                'size' => (int)@filesize($f),
                'legacy' => true,
            ];
        }
    }
}

usort($items, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
json_ok([
    'items' => $items,
    'can_view_public_files' => $canViewPublicFiles,
    'can_view_admin_files' => $canViewAdminFiles,
    'can_upload_public_files' => $canUploadPublicFiles,
    'can_upload_admin_files' => $canUploadAdminFiles,
    'can_delete_files' => $canDelete,
]);
