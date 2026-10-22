<?php
require_once __DIR__ . '/../../config/config.php';
$me = auth_user();
if(!$me) json_err('unauthorized',401);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if($uid>0 && !((bool)can_view_field($uid,'finance_summary') || (bool)can_view_field($uid,'finance_components') || (bool)can_view_field($uid,'finance_payments')) && !in_array($role,[1,999],true)){
  json_err('forbidden',403);
}
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function pick_col(array $have, array $preferred){ foreach($preferred as $c){ if(in_array($c, $have, true)) return $c; } return null; }
$tbl = $pdo->query("SHOW TABLES LIKE 'order_payments'")->fetchColumn();
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="rozliczenia_'.date('Ymd_His').'.xls"');
echo "<html><meta charset='utf-8'><table border='1'>";
if(!$tbl){ echo '<tr><th>Brak danych</th></tr></table></html>'; exit; }
$cols=[]; $st = $pdo->query("SHOW COLUMNS FROM order_payments"); foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $cols[] = $r['Field']; }
$colOrder = pick_col($cols, ['order_id','orderId']);
$colDate  = pick_col($cols, ['pay_date','payment_date','date','paid_at','created_at']);
$colAmt   = pick_col($cols, ['amount','value','kwota']);
$colDesc  = pick_col($cols, ['description','opis','note','title']);
if(!$colOrder || !$colDate || !$colAmt){ echo '<tr><th>Brak wymaganych kolumn w order_payments</th></tr></table></html>'; exit; }
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$where=[]; $params=[];
if($from!==''){ $where[] = "p.`$colDate` >= ?"; $params[] = $from; }
if($to!==''){ $where[] = "p.`$colDate` <= ?"; $params[] = $to; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$descSel = $colDesc ? "p.`$colDesc`" : "''";
$sql = "SELECT p.id, DATE_FORMAT(p.`$colDate`,'%Y-%m-%d') AS paid_at, p.`$colAmt` AS amount, $descSel AS label, CONCAT(o.client_first_name,' ',o.client_last_name) AS client, o.item_description FROM order_payments p JOIN orders o ON o.id = p.`$colOrder` $whereSql ORDER BY p.`$colDate` DESC, p.id DESC";
$q = $pdo->prepare($sql); $q->execute($params); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
echo '<tr><th>Data</th><th>Klient</th><th>Przedmiot zamówienia</th><th>Kwota wpłaty</th><th>Opis wpłaty</th></tr>';
$currentMonth=''; $monthSum=0.0; $grand=0.0;
foreach($rows as $r){
  $ym = substr((string)($r['paid_at'] ?? ''), 0, 7);
  if($ym !== $currentMonth){
    if($currentMonth !== '') echo '<tr><td colspan="5"><b>Suma wpłat ('.htmlspecialchars($currentMonth).'): '.htmlspecialchars(number_format($monthSum,2,',',' '))." PLN</b></td></tr>";
    $currentMonth = $ym;
    $monthSum = 0.0;
    echo '<tr><td colspan="5"><b>'.htmlspecialchars($ym ?: 'brak daty').'</b></td></tr>';
  }
  $amt = (float)($r['amount'] ?? 0);
  $monthSum += $amt;
  $grand += $amt;
  echo '<tr>';
  echo '<td>'.htmlspecialchars((string)($r['paid_at'] ?? '')).'</td>';
  echo '<td>'.htmlspecialchars((string)($r['client'] ?? '')).'</td>';
  echo '<td>'.htmlspecialchars((string)($r['item_description'] ?? '')).'</td>';
  echo '<td>'.htmlspecialchars(number_format($amt,2,',',' ')).'</td>';
  echo '<td>'.htmlspecialchars((string)($r['label'] ?? '')).'</td>';
  echo '</tr>';
}
if($currentMonth !== '') echo '<tr><td colspan="5"><b>Suma wpłat ('.htmlspecialchars($currentMonth).'): '.htmlspecialchars(number_format($monthSum,2,',',' '))." PLN</b></td></tr>";
echo '<tr><td colspan="5"><b>Suma wszystkich wpłat: '.htmlspecialchars(number_format($grand,2,',',' '))." PLN</b></td></tr>";
echo '</table></html>';
