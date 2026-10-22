<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
$debugMode = !empty($_GET['debug']);
if($id <= 0) json_err('bad_request',400);

function os_columns(PDO $pdo, string $table): array {
  try{
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
  }catch(Throwable $e){
    return [];
  }
}
function os_pick_col(array $cols, array $candidates): string {
  foreach($candidates as $c){
    if(in_array($c, $cols, true)) return $c;
  }
  return '';
}
function os_norm_text(string $v): string {
  $v = mb_strtolower(trim($v), 'UTF-8');
  return str_replace(['ą','ć','ę','ł','ń','ó','ś','ź','ż'], ['a','c','e','l','n','o','s','z','z'], $v);
}
function os_norm_mode(string $mode): string {
  $m = os_norm_text($mode);

  if(in_array($m, ['workshop','warsztat','pracownia','produkcja'], true)) return 'workshop';
  if(in_array($m, ['install','montaz','montaż','monta','mont'], true)) return 'install';

  if(strpos($m, 'warsztat') !== false || strpos($m, 'workshop') !== false || strpos($m, 'produkcj') !== false) return 'workshop';
  if(strpos($m, 'montaz') !== false || strpos($m, 'install') !== false) return 'install';

  return '';
}
function os_table_list(PDO $pdo): array {
  try{
    $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
    return array_map(static fn($r)=>(string)$r[0], $rows);
  }catch(Throwable $e){
    return [];
  }
}
function os_is_schedule_like(string $table): bool {
  $t = os_norm_text($table);
  foreach(['schedule','grafik','work','praca','entry','entries','calendar','kalendarz'] as $needle){
    if(strpos($t, $needle) !== false) return true;
  }
  return false;
}

try{
  $tables = os_table_list($pdo);
  $debug = [
    'requested_order_id' => $id,
    'checked_tables' => [],
    'chosen_table' => null,
    'chosen_columns' => null,
  ];

  $best = null;

  foreach($tables as $table){
    if(!os_is_schedule_like($table)) continue;

    $cols = os_columns($pdo, $table);
    if(!$cols) continue;

    $orderCol = os_pick_col($cols, ['order_id','orderId','id_order','zamowienie_id','orderID']);
    $userCol  = os_pick_col($cols, ['user_id','userId','mounter_id','monter_id','employee_id','worker_id','pracownik_id']);
    $dateCol  = os_pick_col($cols, ['work_date','date','day','entry_date','schedule_date','data','data_pracy']);
    $modeCol  = os_pick_col($cols, ['work_mode','mode','type','work_type','kind','work_kind','rodzaj','typ']);

    $info = [
      'table' => $table,
      'columns' => $cols,
      'picked' => [
        'order' => $orderCol,
        'user' => $userCol,
        'date' => $dateCol,
        'mode' => $modeCol,
      ],
      'count_for_order' => null,
    ];

    if($orderCol && $userCol && $dateCol && $modeCol){
      try{
        $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$orderCol` = ?");
        $st->execute([$id]);
        $count = (int)$st->fetchColumn();
        $info['count_for_order'] = $count;

        if($count > 0 && $best === null){
          $best = [
            'table' => $table,
            'order_col' => $orderCol,
            'user_col' => $userCol,
            'date_col' => $dateCol,
            'mode_col' => $modeCol,
            'count' => $count,
          ];
        }
      }catch(Throwable $e){
        $info['error'] = $e->getMessage();
      }
    }

    $debug['checked_tables'][] = $info;
  }

  if($best === null){
    json_ok(['items'=>[], 'debug'=>$debug]);
  }

  $table = $best['table'];
  $orderCol = $best['order_col'];
  $userCol = $best['user_col'];
  $dateCol = $best['date_col'];
  $modeCol = $best['mode_col'];

  $debug['chosen_table'] = $table;
  $debug['chosen_columns'] = [
    'order' => $orderCol,
    'user' => $userCol,
    'date' => $dateCol,
    'mode' => $modeCol,
  ];

  $sql = "
    SELECT
      s.`$dateCol` AS work_date,
      s.`$modeCol` AS work_mode,
      s.`$userCol` AS user_id,
      COALESCE(NULLIF(TRIM(u.full_name),''), NULLIF(TRIM(u.email),''), CONCAT('Użytkownik #', s.`$userCol`)) AS worker_name
    FROM `$table` s
    LEFT JOIN users u ON u.id = s.`$userCol`
    WHERE s.`$orderCol` = ?
    ORDER BY s.`$dateCol` ASC, worker_name ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$id]);

  $grouped = [];
  $rawRows = [];
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
    if($debugMode) $rawRows[] = $row;

    $mode = os_norm_mode((string)($row['work_mode'] ?? ''));
    if($mode === '') continue;

    $date = substr((string)($row['work_date'] ?? ''), 0, 10);
    if($date === '') continue;

    $key = $date . '|' . $mode;
    if(!isset($grouped[$key])){
      $grouped[$key] = [
        'work_date' => $date,
        'work_mode' => $mode,
        'workers' => [],
      ];
    }

    $name = trim((string)($row['worker_name'] ?? ''));
    if($name !== '' && !in_array($name, $grouped[$key]['workers'], true)){
      $grouped[$key]['workers'][] = $name;
    }
  }

  $items = array_values($grouped);
  usort($items, static function($a,$b){
    $c = strcmp((string)$a['work_date'], (string)$b['work_date']);
    if($c !== 0) return $c;
    $wa = $a['work_mode'] === 'workshop' ? 1 : 2;
    $wb = $b['work_mode'] === 'workshop' ? 1 : 2;
    return $wa <=> $wb;
  });

  if($debugMode){
    $debug['raw_rows_from_chosen_table'] = $rawRows;
    json_ok(['items'=>$items, 'debug'=>$debug]);
  }

  json_ok(['items'=>$items]);
}catch(Throwable $e){
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
