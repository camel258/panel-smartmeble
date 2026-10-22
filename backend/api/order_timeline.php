<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? 0);
if($id <= 0) json_err('bad_request',400);

function table_exists(PDO $pdo, string $name): bool {
  try { $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$name]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  try { $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function date_only($v): string {
  $v = trim((string)$v);
  if($v === '') return '';
  return substr($v,0,10);
}
function add_event(array &$events, string $type, string $title, string $from='', string $to='', string $meta=''): void {
  $events[] = [
    'type'=>$type,
    'title'=>$title,
    'date_from'=>date_only($from),
    'date_to'=>date_only($to),
    'meta'=>$meta
  ];
}
function date_sort_key(array $e): string {
  return $e['date_from'] ?: ($e['date_to'] ?: '9999-12-31');
}

try{
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if(!$order) json_err('not_found',404);

  $events = [];

  if(!empty($order['contract_date'])){
    add_event($events, 'contract', 'PODPISANIE UMOWY', $order['contract_date'], '', '');
  }

  // Praca z grafiku — grupowanie ciągłych okresów warsztat/montaż
  if(table_exists($pdo, 'work_schedule_entries')){
    $q = $pdo->prepare("SELECT work_date, work_mode, COUNT(*) AS workers
      FROM work_schedule_entries
      WHERE order_id=? AND work_mode IN ('workshop','install')
      GROUP BY work_date, work_mode
      ORDER BY work_date ASC, work_mode ASC");
    $q->execute([$id]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $segments = [];
    foreach($rows as $r){
      $mode = (string)$r['work_mode'];
      $date = date_only($r['work_date']);
      if(!$date) continue;
      if(!isset($segments[$mode])) $segments[$mode] = [];
      $segments[$mode][] = ['date'=>$date, 'workers'=>(int)$r['workers']];
    }
    foreach($segments as $mode=>$items){
      $current = null;
      foreach($items as $it){
        if(!$current){
          $current = ['from'=>$it['date'], 'to'=>$it['date'], 'days'=>1, 'workers'=>$it['workers']];
          continue;
        }
        $prev = new DateTime($current['to']);
        $next = clone $prev; $next->modify('+1 day');
        if($it['date'] === $next->format('Y-m-d')){
          $current['to'] = $it['date'];
          $current['days']++;
          $current['workers'] += $it['workers'];
        }else{
          $title = $mode === 'workshop' ? 'PRACA NA WARSZTACIE' : 'PRACA NA MONTAŻU';
          $meta = 'Dni: '.$current['days'].' • wpisy monterów: '.$current['workers'];
          add_event($events, $mode, $title, $current['from'], $current['to'], $meta);
          $current = ['from'=>$it['date'], 'to'=>$it['date'], 'days'=>1, 'workers'=>$it['workers']];
        }
      }
      if($current){
        $title = $mode === 'workshop' ? 'PRACA NA WARSZTACIE' : 'PRACA NA MONTAŻU';
        $meta = 'Dni: '.$current['days'].' • wpisy monterów: '.$current['workers'];
        add_event($events, $mode, $title, $current['from'], $current['to'], $meta);
      }
    }
  }

  // Rozliczenie końcowe — moment kiedy suma wpłat pokrywa wartość zamówienia
  $total = (float)($order['total_amount'] ?? 0);
  if(table_exists($pdo, 'order_addons')){
    try{
      $a = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_addons WHERE order_id=?");
      $a->execute([$id]);
      $addonTotal = (float)$a->fetchColumn();
      if(abs($addonTotal) > 0.000001) $total = $addonTotal;
    }catch(Throwable $e){}
  }
  if(table_exists($pdo, 'order_payments') && $total > 0){
    $dateCol = col_exists($pdo,'order_payments','paid_at') ? 'paid_at' : (col_exists($pdo,'order_payments','payment_date') ? 'payment_date' : (col_exists($pdo,'order_payments','pay_date') ? 'pay_date' : 'created_at'));
    $p = $pdo->prepare("SELECT amount, `$dateCol` AS paid_date FROM order_payments WHERE order_id=? ORDER BY `$dateCol` ASC, id ASC");
    $p->execute([$id]);
    $sum = 0.0;
    foreach($p->fetchAll(PDO::FETCH_ASSOC) as $pay){
      $sum += (float)$pay['amount'];
      if($sum + 0.00001 >= $total){
        add_event($events, 'settlement', 'ROZLICZENIE KOŃCOWE', $pay['paid_date'], '', 'Saldo do zapłaty: 0,00 zł');
        break;
      }
    }
  }

  if((int)($order['is_finished'] ?? 0) === 1){
    $finishDate = '';
    foreach(['finished_at','completed_at','closed_at','updated_at','created_at'] as $c){
      if(isset($order[$c]) && trim((string)$order[$c]) !== ''){ $finishDate = $order[$c]; break; }
    }
    $bucket = '';
    if(!empty($order['finished_bucket_year']) && !empty($order['finished_bucket_month'])){
      $bucket = 'Miesiąc zakończenia: '.str_pad((string)$order['finished_bucket_month'],2,'0',STR_PAD_LEFT).'.'.$order['finished_bucket_year'];
    }
    add_event($events, 'finished', 'ZAKOŃCZENIE ZAMÓWIENIA', $finishDate, '', $bucket);
  }

  usort($events, fn($a,$b)=>strcmp(date_sort_key($a), date_sort_key($b)));

  $statusEvents = [];
  if(table_exists($pdo,'order_audit_log')){
    try{
      $s = $pdo->prepare("SELECT message, payload_json, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
        FROM order_audit_log
        WHERE order_id=? AND (LOWER(message) LIKE '%status%' OR LOWER(action_code) LIKE '%status%')
        ORDER BY created_at ASC, id ASC");
      $s->execute([$id]);
      foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $txt = (string)($row['message'] ?? 'Zmiana statusu');
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if(is_array($payload) && !empty($payload['changes']) && is_array($payload['changes'])){
          foreach($payload['changes'] as $ch){
            $field = strtolower((string)($ch['field'] ?? ''));
            if(strpos($field,'status') !== false){
              $txt .= ': '.($ch['from'] ?? '—').' → '.($ch['to'] ?? '—');
            }
          }
        }
        $statusEvents[] = ['created_at'=>$row['created_at'], 'text'=>$txt];
      }
    }catch(Throwable $e){}
  }

  json_ok(['events'=>$events, 'status_events'=>$statusEvents]);
}catch(Throwable $e){
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
