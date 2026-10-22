<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
function oc_req(){ $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }
function oc_table_exists(PDO $pdo, string $name): bool { $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"); $st->execute([$name]); return (bool)$st->fetchColumn(); }
function oc_ensure_schema(PDO $pdo): void {
  if(!oc_table_exists($pdo,'order_checklists')){
    $pdo->exec("CREATE TABLE order_checklists (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      item_code VARCHAR(50) NOT NULL,
      item_label VARCHAR(190) NOT NULL,
      is_done TINYINT(1) NOT NULL DEFAULT 0,
      note VARCHAR(255) NULL,
      done_by BIGINT UNSIGNED NULL,
      done_by_name VARCHAR(190) NULL,
      done_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_order_item (order_id, item_code),
      KEY idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}
function oc_defaults(): array {
  return [
    'umowa'                 => 'Podpisana umowa',
    'pomiar'                => 'Pomiar',
    'rozpiska'              => 'Rozpiska',
    'okucia'                => 'Zamówione okucia',
    'plyty_zamowione'       => 'Zamówione płyty',
    'plyty_ciete'           => 'Pocięte płyty',
    'plyty_oklejone'        => 'Oklejone płyty',
    'fronty'                => 'Zamówione fronty',
    'zamontowane_warsztat'  => 'Zamontowane na warsztacie',
    'gotowe_montaz'         => 'Gotowe do montażu',
    'gotowe_transport'      => 'Gotowe do transportu',
    'w_trakcie_montazu'     => 'W trakcie montażu',
  ];
}
try{
  oc_ensure_schema($pdo);
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $in = oc_req();
    $orderId = (int)($in['order_id'] ?? 0);
    $code = trim((string)($in['item_code'] ?? ''));
    $isDone = isset($in['is_done']) ? (int)!!$in['is_done'] : 0;
    $note = trim((string)($in['note'] ?? ''));
    $defaults = oc_defaults();
    if($orderId<=0 || !isset($defaults[$code])) json_err('bad_request',422);
    acc_require_order($pdo, $me, $orderId);
    $meName = trim((string)($me['full_name'] ?? $me['email'] ?? ('Użytkownik #'.$uid)));
    $st = $pdo->prepare("INSERT INTO order_checklists(order_id,item_code,item_label,is_done,note,done_by,done_by_name,done_at) VALUES(?,?,?,?,?,?,?,CASE WHEN ?=1 THEN NOW() ELSE NULL END) ON DUPLICATE KEY UPDATE item_label=VALUES(item_label), is_done=VALUES(is_done), note=VALUES(note), done_by=VALUES(done_by), done_by_name=VALUES(done_by_name), done_at=CASE WHEN VALUES(is_done)=1 THEN NOW() ELSE NULL END");
    $st->execute([$orderId,$code,$defaults[$code],$isDone,$note,$uid,$meName,$isDone]);
    json_ok(['ok'=>true]);
  }
  $orderId = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);
  if($orderId<=0) json_err('bad_request',422);
  acc_require_order($pdo, $me, $orderId);
  $defaults = oc_defaults();
  $st = $pdo->prepare("SELECT item_code,item_label,is_done,note,done_by_name,DATE_FORMAT(done_at,'%Y-%m-%d %H:%i') AS done_at FROM order_checklists WHERE order_id=?");
  $st->execute([$orderId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach($rows as $r) $map[$r['item_code']] = $r;
  $items = [];
  foreach($defaults as $code=>$label){
    $r = $map[$code] ?? [];
    $items[] = ['item_code'=>$code,'item_label'=>$label,'is_done'=>(int)($r['is_done'] ?? 0),'note'=>(string)($r['note'] ?? ''),'done_by_name'=>(string)($r['done_by_name'] ?? ''),'done_at'=>(string)($r['done_at'] ?? '')];
  }
  json_ok(['ok'=>true,'items'=>$items]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
