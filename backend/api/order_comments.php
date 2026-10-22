<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/order_audit.php';
header('Content-Type: application/json; charset=utf-8');

function req_json_or_post(){ $body=json_decode(file_get_contents('php://input'), true); return is_array($body)?$body:$_POST; }

function current_user_id_for_comments(PDO $pdo, array $me): int {
  foreach(['id','uid','user_id'] as $k){
    $v = (int)($me[$k] ?? 0);
    if($v > 0) return $v;
  }
  $email = trim((string)($me['email'] ?? ''));
  if($email !== ''){
    try{
      $st = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
      $st->execute([$email]);
      $v = (int)$st->fetchColumn();
      if($v > 0) return $v;
    }catch(Throwable $e){}
  }
  return 0;
}

function current_user_author_text(array $me): string {
  $first = trim((string)($me['first_name'] ?? ''));
  $last  = trim((string)($me['last_name'] ?? ''));
  $full = trim($first . ' ' . $last);
  if($full !== '') return $full;

  foreach(['full_name','name','login','username','user_login','email','user_email'] as $k){
    $v = trim((string)($me[$k] ?? ''));
    if($v !== '') return $v;
  }

  return 'Użytkownik';
}

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = current_user_id_for_comments($pdo, $me);
$authorText = current_user_author_text($me);

$canViewNotes = $uid>0 ? (bool)can_view_field($uid,'notes') : true;
$canEditNotes = $uid>0 ? (bool)can_edit_field($uid,'notes') : true;
if(!$canViewNotes && !$canEditNotes) json_err('forbidden',403,['details'=>'notes_access_required']);

try{
  $has = $pdo->query("SHOW TABLES LIKE 'order_comments'")->fetchColumn();
  if(!$has){
    $pdo->exec("CREATE TABLE order_comments (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      author VARCHAR(255) NULL,
      text TEXT NOT NULL,
      created_at DATETIME NOT NULL,
      INDEX(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  $colsInfo = $pdo->query("SHOW COLUMNS FROM order_comments")->fetchAll(PDO::FETCH_ASSOC);
  $cols = array_column($colsInfo, 'Field');

  $cId      = in_array('id',$cols,true) ? 'id' : null;
  $cOrder   = in_array('order_id',$cols,true) ? 'order_id' : null;
  $cUser    = in_array('user_id',$cols,true) ? 'user_id' : (in_array('author_id',$cols,true) ? 'author_id' : null);
  $cAuthor  = in_array('author',$cols,true) ? 'author' : null;
  $cText    = in_array('text',$cols,true) ? 'text' : (in_array('comment',$cols,true) ? 'comment' : (in_array('note',$cols,true) ? 'note' : null));
  $cCreated = in_array('created_at',$cols,true) ? 'created_at' : (in_array('added_at',$cols,true) ? 'added_at' : null);

  if(!$cId || !$cOrder || !$cText || !$cCreated) json_err('schema_mismatch',500,['details'=>'order_comments schema not supported']);

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $orderId=(int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
    if($orderId<=0) json_err('bad_request',400);

    $authorJoin='';
    $authorSel="''";

    if($cAuthor){
      $authorSel = "COALESCE(NULLIF(TRIM(c.`$cAuthor`), ''), '')";
    } elseif($cUser){
      $uCols=[]; 
      try{ $uCols=array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }catch(Throwable $e){}
      $parts=[];
      foreach(['full_name','name','login','username','email'] as $uc){
        if(in_array($uc,$uCols,true)) $parts[] = "NULLIF(u.`$uc`, '')";
      }
      if(in_array('first_name',$uCols,true) || in_array('last_name',$uCols,true)){
        $first=in_array('first_name',$uCols,true)?"COALESCE(u.`first_name`, '')":"''";
        $last=in_array('last_name',$uCols,true)?"COALESCE(u.`last_name`, '')":"''";
        $parts[] = "NULLIF(TRIM(CONCAT($first, ' ', $last)), '')";
      }
      $authorJoin = "LEFT JOIN users u ON u.id=c.`$cUser`";
      $authorSel = $parts ? ('COALESCE(' . implode(',', $parts) . ", '')") : "''";
    }

    $sql = "SELECT
              c.`$cId` AS id,
              c.`$cText` AS text,
              DATE_FORMAT(c.`$cCreated`,'%Y-%m-%d %H:%i') AS created_at,
              $authorSel AS author,
              $authorSel AS user_name,
              $authorSel AS author_name
            FROM order_comments c
            $authorJoin
            WHERE c.`$cOrder`=?
            ORDER BY c.`$cCreated` DESC, c.`$cId` DESC";
    $q=$pdo->prepare($sql);
    $q->execute([$orderId]);
    json_ok(['can_edit'=>$canEditNotes,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if(!$canEditNotes) json_err('forbidden',403,['details'=>'notes_edit_required']);

  $body=req_json_or_post();

  if(!empty($body['delete_id'])){
    $delId=(int)$body['delete_id'];
    if($delId<=0) json_err('bad_request',400);
    $st=$pdo->prepare("DELETE FROM order_comments WHERE `$cId`=? LIMIT 1");
    $st->execute([$delId]);
    json_ok(['ok'=>true]);
  }

  $orderId=(int)($body['id'] ?? $body['order_id'] ?? 0);
  $text=trim((string)($body['text'] ?? ($body['content'] ?? '')));
  if($orderId<=0 || $text==='') json_err('bad_request',400);

  $insertCols = ["`$cOrder`", "`$cText`", "`$cCreated`"];
  $insertVals = ['?', '?', 'NOW()'];
  $params = [$orderId, $text];

  if($cAuthor){
    $insertCols[] = "`$cAuthor`";
    $insertVals[] = '?';
    $params[] = $authorText;
  } elseif($cUser){
    $insertCols[] = "`$cUser`";
    $insertVals[] = '?';
    $params[] = $uid > 0 ? $uid : null;
  }

  $sql = "INSERT INTO order_comments(" . implode(',', $insertCols) . ") VALUES(" . implode(',', $insertVals) . ")";
  $st=$pdo->prepare($sql);
  $st->execute($params);

  order_audit_log($pdo, $orderId, 'comment_add', 'Dodano uwagę', [
    'note' => ['text'=>$text],
    'actor' => ['id'=>$uid>0?$uid:null, 'name'=>$authorText]
  ], ['id'=>$uid,'full_name'=>$authorText]);

  json_ok(['ok'=>true]);
}catch(Throwable $e){
  json_err('save_failed',500,['details'=>$e->getMessage()]);
}
