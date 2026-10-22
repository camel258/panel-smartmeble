<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/wycena_guard.php';
header('Content-Type: application/json; charset=utf-8');

$me = wycena_check_access();
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function wm_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS wycena_materials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'm2',
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function wm_req(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }

try{
  wm_ensure_schema($pdo);

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $rows = $pdo->query("SELECT * FROM wycena_materials ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    json_ok(['ok'=>true,'items'=>$rows]);
  }

  $body = wm_req();
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'add'){
    $name = trim((string)($body['name'] ?? ''));
    $unit = trim((string)($body['unit'] ?? 'm2')) ?: 'm2';
    $price = (float)($body['unit_price'] ?? 0);
    if($name==='') json_err('bad_request',422,['details'=>'Podaj nazwę materiału.']);
    $pdo->prepare("INSERT INTO wycena_materials(name,unit,unit_price) VALUES(?,?,?)")->execute([$name,$unit,$price]);
    json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'update'){
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $unit = trim((string)($body['unit'] ?? 'm2')) ?: 'm2';
    $price = (float)($body['unit_price'] ?? 0);
    $active = isset($body['is_active']) ? (int)!!$body['is_active'] : 1;
    if($id<=0 || $name==='') json_err('bad_request',422);
    $pdo->prepare("UPDATE wycena_materials SET name=?,unit=?,unit_price=?,is_active=? WHERE id=?")->execute([$name,$unit,$price,$active,$id]);
    json_ok(['ok'=>true]);
  }

  if($action === 'delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $pdo->prepare("DELETE FROM wycena_materials WHERE id=?")->execute([$id]);
    json_ok(['ok'=>true]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
