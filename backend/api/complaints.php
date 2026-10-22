<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if(!in_array($role, [1,999], true)) json_err('forbidden',403);

function req_data(): array {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  return is_array($json) ? $json : $_POST;
}
function current_user_id_local(array $me): int {
  foreach(['id','uid','user_id'] as $k){
    $v = (int)($me[$k] ?? 0);
    if($v > 0) return $v;
  }
  return 0;
}
function current_user_name_local(array $me): string {
  $first = trim((string)($me['first_name'] ?? ''));
  $last  = trim((string)($me['last_name'] ?? ''));
  $full = trim($first.' '.$last);
  if($full !== '') return $full;
  foreach(['full_name','name','login','username','email'] as $k){
    $v = trim((string)($me[$k] ?? ''));
    if($v !== '') return $v;
  }
  return 'Użytkownik';
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  try{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ return false; }
}
function ensure_complaint_cols(PDO $pdo): void {
  // Bezpieczna autonaprawa schematu:
  // próbujemy dodać kolumnę, ale błąd "Duplicate column name" ignorujemy.
  // Dzięki temu działa też po częściowo wykonanym SQL-u.
  $defs = [
    'complaint_active' => "ALTER TABLE orders ADD COLUMN complaint_active TINYINT(1) NOT NULL DEFAULT 0",
    'complaint_subject' => "ALTER TABLE orders ADD COLUMN complaint_subject VARCHAR(255) NULL",
    'complaint_created_at' => "ALTER TABLE orders ADD COLUMN complaint_created_at DATETIME NULL",
    'complaint_closed_at' => "ALTER TABLE orders ADD COLUMN complaint_closed_at DATETIME NULL",
  ];

  foreach($defs as $col=>$sql){
    if(col_exists($pdo, 'orders', $col)) continue;

    try{
      $pdo->exec($sql);
    }catch(Throwable $e){
      $msg = $e->getMessage();
      $code = (string)$e->getCode();

      // MySQL: 1060 Duplicate column name
      if(strpos($msg, 'Duplicate column name') !== false || strpos($msg, '1060') !== false || $code === '42S21'){
        continue;
      }

      json_err('schema_update_failed',500,[
        'details'=>'Nie udało się dodać kolumny '.$col.': '.$msg
      ]);
    }
  }
}
function add_order_comment(PDO $pdo, int $orderId, string $text, array $me): void {
  try{
    $has = $pdo->query("SHOW TABLES LIKE 'order_comments'")->fetchColumn();
    if(!$has) return;

    $cols = array_column($pdo->query("SHOW COLUMNS FROM order_comments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $cOrder = in_array('order_id',$cols,true) ? 'order_id' : null;
    $cText = in_array('text',$cols,true) ? 'text' : (in_array('comment',$cols,true) ? 'comment' : (in_array('note',$cols,true) ? 'note' : null));
    $cCreated = in_array('created_at',$cols,true) ? 'created_at' : (in_array('added_at',$cols,true) ? 'added_at' : null);
    $cAuthor = in_array('author',$cols,true) ? 'author' : null;
    $cUser = in_array('user_id',$cols,true) ? 'user_id' : (in_array('author_id',$cols,true) ? 'author_id' : null);
    if(!$cOrder || !$cText || !$cCreated) return;

    $insertCols = ["`$cOrder`","`$cText`","`$cCreated`"];
    $insertVals = ['?','?','NOW()'];
    $params = [$orderId, $text];

    if($cAuthor){
      $insertCols[] = "`$cAuthor`";
      $insertVals[] = '?';
      $params[] = current_user_name_local($me);
    }elseif($cUser){
      $insertCols[] = "`$cUser`";
      $insertVals[] = '?';
      $params[] = current_user_id_local($me) ?: null;
    }

    $sql = "INSERT INTO order_comments(".implode(',',$insertCols).") VALUES(".implode(',',$insertVals).")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
  }catch(Throwable $e){}
}
function order_select_sql(): string {
  return "SELECT
      o.id,
      o.client_first_name,
      o.client_last_name,
      o.item_description,
      DATE_FORMAT(o.contract_date,'%Y-%m-%d') AS contract_date,
      DATE_FORMAT(o.install_date,'%Y-%m-%d') AS install_date,
      DATE_FORMAT(o.install_date_new,'%Y-%m-%d') AS install_date_new,
      DATE_FORMAT(o.created_at,'%Y-%m-%d') AS created_at,
      COALESCE(o.is_finished,0) AS is_finished,
      DATE_FORMAT(o.finished_at,'%Y-%m-%d %H:%i') AS finished_at,
      COALESCE(o.complaint_active,0) AS complaint_active,
      o.complaint_subject,
      DATE_FORMAT(o.complaint_created_at,'%Y-%m-%d %H:%i') AS complaint_created_at,
      DATE_FORMAT(o.complaint_closed_at,'%Y-%m-%d %H:%i') AS complaint_closed_at,
      s.name AS status_name,
      s.color AS status_color,
      u.full_name AS scheduler_name
    FROM orders o
    LEFT JOIN order_statuses s ON s.id=o.status_id
    LEFT JOIN users u ON u.id=o.scheduler_id";
}

try{
  ensure_complaint_cols($pdo);

  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $mode = trim((string)($_GET['mode'] ?? 'active'));
    $q = trim((string)($_GET['q'] ?? ''));

    if($mode === 'search'){
      if(strlen($q) < 2) json_ok(['items'=>[]]);

      $sql = order_select_sql()."
        WHERE COALESCE(o.is_deleted,0)=0
          AND (
            CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ?
            OR COALESCE(o.client_first_name,'') LIKE ?
            OR COALESCE(o.client_last_name,'') LIKE ?
            OR COALESCE(o.item_description,'') LIKE ?
            OR COALESCE(o.client_phone,'') LIKE ?
            OR COALESCE(o.client_email,'') LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
          )
        ORDER BY COALESCE(o.install_date_new,o.install_date,o.contract_date,o.created_at) DESC, o.id DESC
        LIMIT 100";

      $like = '%'.$q.'%';
      $st = $pdo->prepare($sql);
      $st->execute([$like,$like,$like,$like,$like,$like,$like]);
      json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    $where = ["COALESCE(o.is_deleted,0)=0", "COALESCE(o.complaint_active,0)=1"];
    $params = [];

    if($q !== ''){
      $where[] = "(CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ? OR COALESCE(o.client_first_name,'') LIKE ? OR COALESCE(o.client_last_name,'') LIKE ? OR COALESCE(o.item_description,'') LIKE ? OR COALESCE(o.complaint_subject,'') LIKE ? OR CAST(o.id AS CHAR) LIKE ?)";
      $like = '%'.$q.'%';
      array_push($params,$like,$like,$like,$like,$like,$like);
    }

    $sql = order_select_sql()." WHERE ".implode(' AND ', $where)." ORDER BY o.complaint_created_at DESC, o.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  $body = req_data();
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'create'){
    $orderId = (int)($body['order_id'] ?? 0);
    $subject = trim((string)($body['subject'] ?? ''));
    if($orderId <= 0 || $subject === '') json_err('bad_request',400);

    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id FROM orders WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1");
    $st->execute([$orderId]);
    if(!$st->fetch(PDO::FETCH_ASSOC)){
      $pdo->rollBack();
      json_err('not_found',404);
    }

    $up = $pdo->prepare("UPDATE orders SET complaint_active=1, complaint_subject=?, complaint_created_at=NOW(), complaint_closed_at=NULL WHERE id=?");
    $up->execute([$subject,$orderId]);

    $comment = 'Zgłoszono reklamację - przedmiot reklamacji: '.$subject;
    add_order_comment($pdo, $orderId, $comment, $me);
    try{
      order_audit_log($pdo, $orderId, 'complaint_create', 'Zgłoszono reklamację', [
        'complaint_subject'=>$subject,
        'actor'=>['id'=>current_user_id_local($me) ?: null, 'name'=>current_user_name_local($me)]
      ], $me);
    }catch(Throwable $e){}

    $pdo->commit();
    json_ok(['ok'=>true]);
  }

  if($action === 'close'){
    $orderId = (int)($body['order_id'] ?? $body['id'] ?? 0);
    if($orderId <= 0) json_err('bad_request',400);

    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id FROM orders WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1");
    $st->execute([$orderId]);
    if(!$st->fetch(PDO::FETCH_ASSOC)){
      $pdo->rollBack();
      json_err('not_found',404);
    }

    $up = $pdo->prepare("UPDATE orders SET complaint_active=0, complaint_closed_at=NOW() WHERE id=?");
    $up->execute([$orderId]);

    add_order_comment($pdo, $orderId, 'Zakończono reklamację', $me);
    try{
      order_audit_log($pdo, $orderId, 'complaint_close', 'Zakończono reklamację', [
        'actor'=>['id'=>current_user_id_local($me) ?: null, 'name'=>current_user_name_local($me)]
      ], $me);
    }catch(Throwable $e){}

    $pdo->commit();
    json_ok(['ok'=>true]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){
  if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
