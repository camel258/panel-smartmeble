<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
$isAdmin = in_array($role,[1,999],true);
function bt_req(){ $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }
function bt_table_exists(PDO $pdo, string $name): bool { $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"); $st->execute([$name]); return (bool)$st->fetchColumn(); }
function bt_ensure_schema(PDO $pdo): void {
  if(!bt_table_exists($pdo,'boss_tasks')){
    $pdo->exec("CREATE TABLE boss_tasks (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      assigned_user_id BIGINT UNSIGNED NOT NULL,
      assigned_user_name VARCHAR(190) NULL,
      due_date DATE NULL,
      priority VARCHAR(20) NOT NULL DEFAULT 'normalny',
      status VARCHAR(20) NOT NULL DEFAULT 'nowe',
      completed_comment TEXT NULL,
      created_by BIGINT UNSIGNED NULL,
      created_by_name VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      KEY idx_assignee_status (assigned_user_id, status),
      KEY idx_active_due (is_active, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}
try{
  bt_ensure_schema($pdo);
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $in = bt_req();
    $action = trim((string)($in['action'] ?? ''));
    if($action==='create'){
      if(!$isAdmin) json_err('forbidden',403);
      $title = trim((string)($in['title'] ?? ''));
      $description = trim((string)($in['description'] ?? ''));
      $assigned = (int)($in['assigned_user_id'] ?? 0);
      $due = trim((string)($in['due_date'] ?? '')) ?: null;
      $priority = trim((string)($in['priority'] ?? 'normalny')) ?: 'normalny';
      if($title==='' || $assigned<=0) json_err('bad_request',422,['details'=>'title_assignee_required']);
      $ust = $pdo->prepare("SELECT full_name,email FROM users WHERE id=? LIMIT 1");
      $ust->execute([$assigned]);
      $u = $ust->fetch(PDO::FETCH_ASSOC);
      if(!$u) json_err('not_found',404,['details'=>'assignee_not_found']);
      $meName = trim((string)($me['full_name'] ?? $me['email'] ?? ('Użytkownik #'.$uid)));
      $assignedName = trim((string)($u['full_name'] ?: $u['email']));
      $st = $pdo->prepare("INSERT INTO boss_tasks(title,description,assigned_user_id,assigned_user_name,due_date,priority,status,created_by,created_by_name) VALUES(?,?,?,?,?,?,?,?,?)");
      $st->execute([$title,$description,$assigned,$assignedName,$due,$priority,'nowe',$uid,$meName]);
      json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }
    if($action==='update_status'){
      $id = (int)($in['id'] ?? 0);
      $status = trim((string)($in['status'] ?? ''));
      $allowed = ['nowe','w trakcie','zrobione','anulowane'];
      if($id<=0 || !in_array($status,$allowed,true)) json_err('bad_request',422);
      $st = $pdo->prepare("SELECT * FROM boss_tasks WHERE id=? AND is_active=1 LIMIT 1");
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if(!$row) json_err('not_found',404);
      if(!$isAdmin && (int)$row['assigned_user_id']!==$uid) json_err('forbidden',403);
      $comment = trim((string)($in['completed_comment'] ?? ''));
      $up = $pdo->prepare("UPDATE boss_tasks SET status=?, completed_comment=CASE WHEN ?<>'' THEN ? ELSE completed_comment END WHERE id=? LIMIT 1");
      $up->execute([$status,$comment,$comment,$id]);
      json_ok(['ok'=>true]);
    }
    json_err('bad_request',400,['details'=>'unknown_action']);
  }

  $items = [];
  if($isAdmin){
    $st = $pdo->query("SELECT id,title,description,assigned_user_id,assigned_user_name,DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date,priority,status,completed_comment,created_by_name,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at FROM boss_tasks WHERE is_active=1 ORDER BY FIELD(status,'nowe','w trakcie','zrobione','anulowane'), COALESCE(due_date,'9999-12-31') ASC, id DESC LIMIT 40");
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("SELECT id,title,description,assigned_user_id,assigned_user_name,DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date,priority,status,completed_comment,created_by_name,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at FROM boss_tasks WHERE is_active=1 AND assigned_user_id=? ORDER BY FIELD(status,'nowe','w trakcie','zrobione','anulowane'), COALESCE(due_date,'9999-12-31') ASC, id DESC LIMIT 40");
    $st->execute([$uid]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
  }
  $users = [];
  if($isAdmin){
    $st = $pdo->query("SELECT id, full_name, email FROM users WHERE is_active=1 ORDER BY full_name ASC");
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
  }
  json_ok(['ok'=>true,'items'=>$items,'users'=>$users]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
