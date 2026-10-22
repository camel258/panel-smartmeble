<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/order_audit.php';
require_once __DIR__ . '/../lib/access.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// zewnętrzny technolog – tylko jego zamówienia; bez uprawnień do finansów – bez kwot w historii zmian
$techScope = acc_is_external_technologist($pdo, $me) ? ' AND o.scheduler_id=' . acc_uid($me) : '';
$canFinance = acc_can($pdo, $me, 'finance_summary', 'view');

function table_exists_news(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}
function ensure_manual_news_table(PDO $pdo): void {
  if(table_exists_news($pdo,'manual_news')) return;
  $pdo->exec("CREATE TABLE manual_news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    content TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_active_created (is_active, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try{
  ensure_manual_news_table($pdo);

  $recentOrders = [];
  try{
    $st = $pdo->query("SELECT o.id,
      CONCAT(TRIM(COALESCE(o.client_first_name,'')),' ',TRIM(COALESCE(o.client_last_name,''))) AS client_name,
      COALESCE(o.item_description,'') AS item_description,
      DATE_FORMAT(o.created_at,'%Y-%m-%d %H:%i') AS created_at,
      DATE_FORMAT(o.contract_date,'%Y-%m-%d') AS contract_date,
      DATE_FORMAT(COALESCE(o.install_date_new,o.install_date),'%Y-%m-%d') AS install_date,
      COALESCE(s.name,'—') AS status_name
      FROM orders o
      LEFT JOIN order_statuses s ON s.id=o.status_id
      WHERE COALESCE(o.is_deleted,0)=0{$techScope}
      ORDER BY o.created_at DESC, o.id DESC
      LIMIT 12");
    $recentOrders = $st->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){}

  $toPlan = [];
  try{
    $sql = "SELECT o.id,
      CONCAT(TRIM(COALESCE(o.client_first_name,'')),' ',TRIM(COALESCE(o.client_last_name,''))) AS client_name,
      COALESCE(o.item_description,'') AS item_description,
      DATE_FORMAT(o.contract_date,'%Y-%m-%d') AS contract_date,
      DATE_FORMAT(o.install_date,'%Y-%m-%d') AS install_date,
      DATE_FORMAT(o.install_date_new,'%Y-%m-%d') AS install_date_new,
      COALESCE(s.name,'—') AS status_name,
      COUNT(oa.user_id) AS assignees_count
      FROM orders o
      LEFT JOIN order_statuses s ON s.id=o.status_id
      LEFT JOIN order_assignees oa ON oa.order_id=o.id
      WHERE COALESCE(o.is_deleted,0)=0
        AND COALESCE(o.is_finished,0)=0{$techScope}
      GROUP BY o.id
      HAVING assignees_count = 0
         OR ((install_date IS NULL OR install_date='') AND (install_date_new IS NULL OR install_date_new=''))
      ORDER BY COALESCE(o.install_date_new,o.install_date,o.contract_date) ASC, o.id DESC
      LIMIT 20";
    $toPlan = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){}

  function news_fmt_val($v): string {
    if(is_array($v)){
      $parts = [];
      foreach($v as $x){ $t = trim((string)$x); if($t !== '') $parts[] = $t; }
      return $parts ? implode(', ', $parts) : '—';
    }
    if($v === null) return '—';
    $t = trim((string)$v);
    return $t !== '' ? $t : '—';
  }
  function news_change_summary(array $row): string {
    $payload = null;
    if(!empty($row['payload_json'])){
      $payload = json_decode((string)$row['payload_json'], true);
      if(!is_array($payload)) $payload = null;
    }
    if(!$payload) return '';
    $parts = [];

    if(!empty($payload['changes']) && is_array($payload['changes'])){
      foreach($payload['changes'] as $ch){
        if(!is_array($ch)) continue;
        $label = trim((string)($ch['label'] ?? $ch['field'] ?? 'Zmiana'));
        $before = array_key_exists('after',$ch) ? ($ch['before'] ?? null) : ($ch['from'] ?? null);
        $after  = array_key_exists('after',$ch) ? ($ch['after'] ?? null) : ($ch['to'] ?? null);
        $parts[] = $label . ': ' . news_fmt_val($before) . ' → ' . news_fmt_val($after);
      }
    }
    if(!empty($payload['component']) && is_array($payload['component'])){
      $c = $payload['component'];
      if(trim((string)($c['description'] ?? '')) !== '') $parts[] = 'Składnik: ' . trim((string)$c['description']);
      if(isset($c['amount']) && $c['amount'] !== '') $parts[] = 'Kwota: ' . trim((string)$c['amount']);
    }
    if(!empty($payload['payment']) && is_array($payload['payment'])){
      $p = $payload['payment'];
      if(trim((string)($p['description'] ?? '')) !== '') $parts[] = 'Wpłata: ' . trim((string)$p['description']);
      if(isset($p['amount']) && $p['amount'] !== '') $parts[] = 'Kwota: ' . trim((string)$p['amount']);
      $dt = trim((string)($p['date'] ?? $p['pay_date'] ?? ''));
      if($dt !== '') $parts[] = 'Data wpłaty: ' . $dt;
    }
    if(trim((string)($payload['description'] ?? '')) !== '') $parts[] = (($row['action_code'] ?? '') === 'order_payment_add' ? 'Wpłata: ' : 'Opis: ') . trim((string)$payload['description']);
    if(isset($payload['amount']) && $payload['amount'] !== '') $parts[] = 'Kwota: ' . trim((string)$payload['amount']);
    if(trim((string)($payload['pay_date'] ?? '')) !== '') $parts[] = 'Data wpłaty: ' . trim((string)$payload['pay_date']);
    if(trim((string)($payload['text'] ?? '')) !== '') $parts[] = 'Treść: ' . trim((string)$payload['text']);

    $parts = array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x), $parts))));
    return implode(' • ', array_slice($parts, 0, 3));
  }

  $recentChanges = [];
  try{
    ensure_order_audit_schema($pdo);
    $st = $pdo->query("SELECT l.id, l.order_id, l.action_code, l.message, l.user_name, l.payload_json,
      CONCAT(TRIM(COALESCE(o.client_first_name,'')),' ',TRIM(COALESCE(o.client_last_name,''))) AS client_name,
      DATE_FORMAT(l.created_at,'%Y-%m-%d %H:%i') AS created_at
      FROM order_audit_log l
      LEFT JOIN orders o ON o.id=l.order_id
      WHERE COALESCE(o.is_deleted,0)=0{$techScope}
      ORDER BY l.created_at DESC, l.id DESC
      LIMIT 20");
    $recentChanges = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach($recentChanges as &$rc){
      $rc['client_name'] = trim((string)($rc['client_name'] ?? ''));
      if(!$canFinance){
        $pl = json_decode((string)($rc['payload_json'] ?? ''), true);
        if(is_array($pl)){
          unset($pl['component'], $pl['payment'], $pl['amount'], $pl['pay_date']);
          if(preg_match('/^(payment|component)/', (string)($rc['action_code'] ?? ''))) unset($pl['description']);
          if(!empty($pl['changes']) && is_array($pl['changes'])){
            $pl['changes'] = array_values(array_filter($pl['changes'], fn($ch)=>!in_array((string)($ch['field'] ?? ''), ['total_amount','deposit_amount'], true)));
          }
          $rc['payload_json'] = json_encode($pl);
        }
      }
      $rc['detail'] = news_change_summary($rc);
      unset($rc['payload_json']); // surowe dane zmian nie są potrzebne w przeglądarce
    }
    unset($rc);
  }catch(Throwable $e){}

  $manualNews = [];
  try{
    $st = $pdo->query("SELECT id, title, content, COALESCE(created_by_name,'Administrator') AS created_by_name,
      DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
      FROM manual_news
      WHERE is_active=1
      ORDER BY created_at DESC, id DESC
      LIMIT 20");
    $manualNews = $st->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){}

  json_ok([
    'ok'=>true,
    'counters'=>[
      'to_plan'=>count($toPlan),
      'recent_orders'=>count($recentOrders),
      'recent_changes'=>count($recentChanges),
      'manual_news'=>count($manualNews),
    ],
    'to_plan'=>$toPlan,
    'recent_orders'=>$recentOrders,
    'recent_changes'=>$recentChanges,
    'manual_news'=>$manualNews,
  ]);
}catch(Throwable $e){
  json_err('feed_error',500,['details'=>$e->getMessage()]);
}
