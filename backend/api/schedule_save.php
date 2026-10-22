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


$me = auth_user();
if(!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function schedule_col_exists(PDO $pdo, string $col): bool {
  try{
    $st=$pdo->prepare("SHOW COLUMNS FROM work_schedule_entries LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ return false; }
}
function schedule_ensure_multi_schema(PDO $pdo): void {
  try{
    if(!schedule_col_exists($pdo,'work_percent')){
      $pdo->exec("ALTER TABLE work_schedule_entries ADD COLUMN work_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00");
    }
  }catch(Throwable $e){
    if(strpos($e->getMessage(),'Duplicate column name')===false && strpos($e->getMessage(),'1060')===false) throw $e;
  }

  // Usuwamy unikalny indeks blokujący kilka wpisów dla tego samego montera i dnia.
  try{
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $q = $pdo->prepare("
      SELECT INDEX_NAME
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA=?
        AND TABLE_NAME='work_schedule_entries'
        AND NON_UNIQUE=0
      GROUP BY INDEX_NAME
      HAVING SUM(CASE WHEN COLUMN_NAME='work_date' THEN 1 ELSE 0 END)>0
         AND SUM(CASE WHEN COLUMN_NAME='user_id' THEN 1 ELSE 0 END)>0
         AND INDEX_NAME <> 'PRIMARY'
    ");
    $q->execute([$db]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $idx){
      $safe = str_replace('`','``',(string)$idx);
      $pdo->exec("ALTER TABLE work_schedule_entries DROP INDEX `$safe`");
    }
  }catch(Throwable $e){}
}
function schedule_percent($v): float {
  $p = (float)str_replace(',','.',(string)$v);
  if($p <= 0) $p = 100.0;
  if($p > 100) $p = 100.0;
  return round($p,2);
}

function current_uid_for_schedule(array $me): int {
  foreach(['id','uid','user_id'] as $k){
    $v = (int)($me[$k] ?? 0);
    if($v > 0) return $v;
  }
  return 0;
}

$uid = current_uid_for_schedule($me);
if($uid <= 0) json_err('forbidden', 403);
if(!sm_is_admin_role($me)) json_err('forbidden',403,['details'=>'schedule_edit_admin_only']);

$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = $_POST;

$workDate = trim((string)($body['work_date'] ?? ''));
$userId = (int)($body['user_id'] ?? 0);
$orderId = (int)($body['order_id'] ?? 0);
$workMode = trim((string)($body['work_mode'] ?? 'install'));
$note = trim((string)($body['note'] ?? ''));
$clear = (int)($body['clear'] ?? 0);

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || $userId <= 0){
  json_err('bad_request', 400, ['details'=>'work_date/user_id']);
}
if(!in_array($workMode, ['install','workshop','custom','leave','day_off','sick'], true)){
  $workMode = 'install';
}

try{
  $exists = $pdo->query("SHOW TABLES LIKE 'work_schedule_entries'")->fetchColumn();
  if(!$exists){
    json_err('missing_table', 500, ['details'=>'work_schedule_entries']);
  }
  schedule_ensure_multi_schema($pdo);

  if($clear){
    $st = $pdo->prepare("DELETE FROM work_schedule_entries WHERE work_date=? AND user_id=?");
    $st->execute([$workDate, $userId]);
    json_ok(['ok'=>true, 'cleared'=>true]);
  }

  $entries = $body['entries'] ?? null;
  if(is_array($entries)){
    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM work_schedule_entries WHERE work_date=? AND user_id=?");
    $del->execute([$workDate, $userId]);

    $ins = $pdo->prepare("INSERT INTO work_schedule_entries
      (work_date, user_id, order_id, work_mode, note, work_percent, created_by, updated_by, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $saved = 0;
    $sumPercent = 0.0;

    foreach($entries as $entry){
      $eMode = trim((string)($entry['work_mode'] ?? 'install'));
      if(!in_array($eMode, ['install','workshop','custom','leave','day_off','sick'], true)) $eMode = 'install';

      $eOrderId = (int)($entry['order_id'] ?? 0);
      $eNote = trim((string)($entry['note'] ?? ''));
      $ePercent = schedule_percent($entry['work_percent'] ?? 100);

      if($eOrderId <= 0 && !in_array($eMode, ['custom','leave','day_off','sick'], true)) continue;

      $sumPercent += $ePercent;
      if($sumPercent > 100.01){
        $pdo->rollBack();
        json_err('bad_request',400,['details'=>'Suma procentów nie może przekraczać 100%.']);
      }

      $ins->execute([$workDate, $userId, $eOrderId, $eMode, $eNote, $ePercent, $uid, $uid]);
      $saved++;
    }

    $pdo->commit();
    json_ok(['ok'=>true,'saved'=>$saved,'multi'=>true]);
  }

  if($orderId <= 0 && !in_array($workMode, ['custom','leave','day_off','sick'], true)){
    json_err('bad_request', 400, ['details'=>'order_id']);
  }

  $workPercent = schedule_percent($body['work_percent'] ?? 100);

  $del = $pdo->prepare("DELETE FROM work_schedule_entries WHERE work_date=? AND user_id=?");
  $del->execute([$workDate, $userId]);

  $sql = "INSERT INTO work_schedule_entries
            (work_date, user_id, order_id, work_mode, note, work_percent, created_by, updated_by, created_at, updated_at)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
  $st = $pdo->prepare($sql);
  $st->execute([$workDate, $userId, $orderId, $workMode, $note, $workPercent, $uid, $uid]);
  json_ok(['ok'=>true]);
}catch(Throwable $e){
  json_err('save_failed', 500, ['details'=>$e->getMessage()]);
}
