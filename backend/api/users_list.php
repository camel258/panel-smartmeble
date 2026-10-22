<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized', 401);
$pdo = db();
$q = trim($_GET['q'] ?? '');
$onlyMounters = (int)($_GET['only_mounters'] ?? 0) === 1;
$params = [];
$where = "WHERE u.is_active=1";
if($onlyMounters) $where .= " AND (LOWER(COALESCE(r.name,'')) LIKE '%monter%' OR u.role_id=3)";
if($q !== ''){ $where .= " AND (u.full_name LIKE ? OR u.email LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
try{
  $sql = "SELECT u.id, u.full_name, u.email, u.role_id, r.name AS role_name, u.is_active
          FROM users u LEFT JOIN roles r ON r.id=u.role_id {$where} ORDER BY u.full_name ASC LIMIT 500";
  $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as &$r){
    $full = trim((string)($r['full_name'] ?? ''));
    $parts = preg_split('/\s+/', $full, 2);
    $r['name'] = $full !== '' ? $full : ('Użytkownik '.$r['id']);
    $r['label'] = $r['name'];
    $r['first_name'] = $parts[0] ?? '';
    $r['last_name'] = $parts[1] ?? '';
    $r['login'] = $r['email'] ?? '';
  }
  unset($r);
  $roleId = (int)($me['role_id'] ?? ($me['role'] ?? 0));
  if(!in_array($roleId, [1,999], true)){
    foreach($rows as &$r){ unset($r['email']); }
    unset($r);
  }
  json_ok(['items'=>$rows]);
}catch(Throwable $e){ json_err('server_error', 500, ['details'=>$e->getMessage()]); }
