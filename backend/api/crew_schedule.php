<?php
// Grafik testowy - przydział ekip do warsztatu (order_id=0) lub montażu u klienta
// GET  ?year=2026&month=10                       -> ekipy, zamówienia, wpisy z miesiąca
// GET  ?action=search&from=2026-08&to=2026-12&q=&finished=0  -> zamówienia do dodania (przycisk "+")
// POST {action:'set', work_date, crew_id, order_id, on:true|false}
// POST {action:'clear', work_date, order_id}            (czyści komórkę)
// POST {action:'pin'|'unpin', ym:'2026-10', order_id}   (zamówienie z innego miesiąca w grafiku)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/crews_lib.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if (!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$isAdmin = crews_is_admin($me);

function cs_col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}

function cs_day_entries(PDO $pdo, string $date): array {
  $st = $pdo->prepare("SELECT DATE_FORMAT(work_date,'%Y-%m-%d') AS work_date, crew_id, order_id FROM crew_schedule WHERE work_date=? ORDER BY crew_id");
  $st->execute([$date]);
  return array_map(fn($r) => ['work_date' => $r['work_date'], 'crew_id' => (int)$r['crew_id'], 'order_id' => (int)$r['order_id']], $st->fetchAll(PDO::FETCH_ASSOC));
}

function cs_valid_ym(string $ym): bool {
  return (bool)preg_match('/^(20[2-9]\d|2100)-(0[1-9]|1[0-2])$/', $ym);
}

// Wspólne fragmenty SQL dla zamówień (kolumny różnią się między wersjami bazy)
function cs_order_sql(PDO $pdo): array {
  $hasMeasureDone = cs_col_exists($pdo, 'orders', 'measure_done');
  $hasNotes = cs_col_exists($pdo, 'orders', 'client_notes');
  $hasBucket = cs_col_exists($pdo, 'orders', 'finished_bucket_year') && cs_col_exists($pdo, 'orders', 'finished_bucket_month');
  $hasFinished = cs_col_exists($pdo, 'orders', 'is_finished');
  $hasDeleted = cs_col_exists($pdo, 'orders', 'is_deleted');
  $hasCity = cs_col_exists($pdo, 'orders', 'install_city');

  // ta sama logika miesiąca co na liście "Zamówienia aktualne"
  $dateExpr = $hasBucket
    ? "COALESCE(STR_TO_DATE(CONCAT(o.finished_bucket_year,'-',LPAD(o.finished_bucket_month,2,'0'),'-01'),'%Y-%m-%d'),o.install_date_new,o.install_date,o.contract_date)"
    : "COALESCE(o.install_date_new,o.install_date,o.contract_date)";
  $finishedExpr = "(" . ($hasFinished ? "COALESCE(o.is_finished,0)=1 OR " : "")
    . "EXISTS (SELECT 1 FROM order_statuses sx WHERE sx.id=o.status_id AND LOWER(COALESCE(sx.name,'')) LIKE '%zakończ%'))";

  $select = "SELECT o.id, o.client_first_name, o.client_last_name, o.item_description,
      " . ($hasNotes ? "o.client_notes" : "NULL AS client_notes") . ",
      " . ($hasCity ? "o.install_city" : "NULL AS install_city") . ",
      DATE_FORMAT(o.install_date,'%Y-%m-%d') AS install_date,
      DATE_FORMAT(o.install_date_new,'%Y-%m-%d') AS install_date_new,
      DATE_FORMAT(o.measure_due_date,'%Y-%m-%d') AS measure_due_date,
      " . ($hasMeasureDone ? "COALESCE(o.measure_done,0)" : "0") . " AS measure_done,
      DATE_FORMAT($dateExpr,'%Y-%m') AS group_month,
      CASE WHEN $finishedExpr THEN 1 ELSE 0 END AS is_finished,
      s.name AS status_name, s.color AS status_color
    FROM orders o
    LEFT JOIN order_statuses s ON s.id=o.status_id";

  return [
    'select' => $select,
    'date' => $dateExpr,
    'not_deleted' => $hasDeleted ? "COALESCE(o.is_deleted,0)=0" : "1=1",
    'active' => "NOT $finishedExpr",
  ];
}

function cs_map_order(array $r): array {
  $name = trim(trim((string)$r['client_first_name']) . ' ' . trim((string)$r['client_last_name']));
  return [
    'id' => (int)$r['id'],
    'client' => $name !== '' ? $name : ('Zamówienie #' . $r['id']),
    'item_description' => (string)($r['item_description'] ?? ''),
    'client_notes' => (string)($r['client_notes'] ?? ''),
    'install_city' => (string)($r['install_city'] ?? ''),
    'install_date' => $r['install_date'],
    'install_date_new' => $r['install_date_new'],
    'measure_due_date' => $r['measure_due_date'],
    'measure_done' => (int)$r['measure_done'],
    'group_month' => (string)$r['group_month'],
    'is_finished' => (int)$r['is_finished'],
    'status_name' => (string)($r['status_name'] ?? ''),
    'status_color' => (string)($r['status_color'] ?? ''),
  ];
}

try {
  crews_ensure_schema($pdo);

  if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'search') {
    if (!$isAdmin) json_err('forbidden', 403, ['details' => 'Tylko administrator może dodawać zamówienia do grafiku.']);
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    $q = trim((string)($_GET['q'] ?? ''));
    $withFinished = !empty($_GET['finished']);
    $all = !empty($_GET['all']);
    if (!$all && (!cs_valid_ym($from) || !cs_valid_ym($to))) json_err('bad_request', 400, ['details' => 'from/to']);

    $o = cs_order_sql($pdo);
    $where = [$o['not_deleted']];
    $params = [];
    if (!$withFinished) $where[] = $o['active'];
    if (!$all) {
      $where[] = "{$o['date']} BETWEEN ? AND ?";
      $params[] = $from . '-01';
      $params[] = date('Y-m-t', strtotime($to . '-01'));
    }
    if ($q !== '') {
      $where[] = "(CONCAT(COALESCE(o.client_first_name,''),' ',COALESCE(o.client_last_name,'')) LIKE ? OR o.item_description LIKE ? OR o.id = ?)";
      $params[] = "%$q%"; $params[] = "%$q%"; $params[] = ctype_digit($q) ? (int)$q : 0;
    }
    $sql = $o['select'] . " WHERE " . implode(' AND ', $where) . " ORDER BY {$o['date']} DESC, o.id DESC LIMIT 400";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = array_map('cs_map_order', $st->fetchAll(PDO::FETCH_ASSOC));
    json_ok(['items' => $items, 'limited' => count($items) >= 400 ? 1 : 0]);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) json_err('bad_request', 400, ['details' => 'year/month']);

    $ym = sprintf('%04d-%02d', $year, $month);
    $first = $ym . '-01';
    $last = date('Y-m-t', strtotime($first));

    $o = cs_order_sql($pdo);
    // aktywne z tego miesiąca (jak w "Zamówienia aktualne") + dodane przyciskiem "+" (pins)
    // + mające wpisy ekip w tym miesiącu
    // (dwa ostatnie pokazujemy nawet, jeśli zamówienie jest już zakończone)
    $sql = $o['select'] . "
      WHERE {$o['not_deleted']}
        AND (
          ({$o['active']} AND {$o['date']} BETWEEN ? AND ?)
          OR o.id IN (SELECT order_id FROM crew_schedule_pins WHERE ym=?)
          OR o.id IN (SELECT DISTINCT order_id FROM crew_schedule WHERE work_date BETWEEN ? AND ? AND order_id>0)
        )
      ORDER BY COALESCE(o.install_date_new,o.install_date,o.contract_date) ASC, o.id ASC
      LIMIT 800";
    $st = $pdo->prepare($sql);
    $st->execute([$first, $last, $ym, $first, $last]);
    $orders = array_map('cs_map_order', $st->fetchAll(PDO::FETCH_ASSOC));

    $st = $pdo->prepare("SELECT order_id FROM crew_schedule_pins WHERE ym=?");
    $st->execute([$ym]);
    $pinned = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $pdo->prepare("SELECT DATE_FORMAT(work_date,'%Y-%m-%d') AS work_date, crew_id, order_id FROM crew_schedule WHERE work_date BETWEEN ? AND ? ORDER BY work_date, crew_id");
    $st->execute([$first, $last]);
    $entries = array_map(fn($r) => ['work_date' => $r['work_date'], 'crew_id' => (int)$r['crew_id'], 'order_id' => (int)$r['order_id']], $st->fetchAll(PDO::FETCH_ASSOC));

    $allCrews = crews_list($pdo, false);

    if (!$isAdmin) {
      // Monter / pracownik / technolog widzi tylko:
      // - zamówienia, do których jest przypisany (order_assignees, jak w "Moje zamówienia"),
      //   lub w których jest osobą rozpisującą (scheduler_id),
      // - zamówienia i warsztat, na które w tym miesiącu wpisana jest jego ekipa.
      $uid = crews_uid($me);
      $myCrews = [];
      foreach ($allCrews as $c) { if (in_array($uid, $c['member_ids'], true)) $myCrews[] = $c['id']; }

      $allowed = [];
      $st = $pdo->prepare("SELECT order_id FROM order_assignees WHERE user_id=?");
      $st->execute([$uid]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) $allowed[(int)$oid] = true;
      $st = $pdo->prepare("SELECT id FROM orders WHERE scheduler_id=?");
      $st->execute([$uid]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) $allowed[(int)$oid] = true;
      foreach ($entries as $e) { if ($e['order_id'] > 0 && in_array($e['crew_id'], $myCrews, true)) $allowed[$e['order_id']] = true; }

      $orders = array_values(array_filter($orders, fn($r) => isset($allowed[$r['id']])));
      $entries = array_values(array_filter($entries, fn($e) =>
        ($e['order_id'] > 0 && isset($allowed[$e['order_id']])) ||
        ($e['order_id'] === 0 && in_array($e['crew_id'], $myCrews, true))
      ));
      $pinned = array_values(array_filter($pinned, fn($id) => isset($allowed[$id])));
      $visible = array_unique(array_merge($myCrews, array_column($entries, 'crew_id')));
      $crews = array_values(array_filter($allCrews, fn($c) => in_array($c['id'], $visible, true)));
    } else {
      // aktywne ekipy + wyłączone, które mają wpisy w tym miesiącu
      $used = array_unique(array_column($entries, 'crew_id'));
      $crews = array_values(array_filter($allCrews, fn($c) => $c['is_active'] === 1 || in_array($c['id'], $used, true)));
    }

    json_ok([
      'year' => $year, 'month' => $month,
      'crews' => $crews,
      'orders' => $orders,
      'pinned' => $pinned,
      'entries' => $entries,
      'can_edit' => $isAdmin ? 1 : 0,
    ]);
  }

  if (!$isAdmin) json_err('forbidden', 403, ['details' => 'Tylko administrator może zmieniać grafik.']);

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) $body = $_POST;
  $action = (string)($body['action'] ?? 'set');
  $orderId = (int)($body['order_id'] ?? 0);
  if ($orderId < 0) json_err('bad_request', 400, ['details' => 'order_id']);

  if ($orderId > 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE id=?");
    $st->execute([$orderId]);
    if (!(int)$st->fetchColumn()) json_err('not_found', 404, ['details' => 'Nie ma takiego zamówienia.']);
  }

  if ($action === 'pin' || $action === 'unpin') {
    $ym = (string)($body['ym'] ?? '');
    if (!cs_valid_ym($ym) || $orderId <= 0) json_err('bad_request', 400, ['details' => 'ym/order_id']);
    if ($action === 'pin') {
      $pdo->prepare("INSERT IGNORE INTO crew_schedule_pins (ym, order_id, created_by) VALUES (?,?,?)")->execute([$ym, $orderId, crews_uid($me) ?: null]);
    } else {
      $pdo->prepare("DELETE FROM crew_schedule_pins WHERE ym=? AND order_id=?")->execute([$ym, $orderId]);
    }
    json_ok(['ok' => true]);
  }

  $date = trim((string)($body['work_date'] ?? ''));
  if (!crews_valid_date($date)) json_err('bad_request', 400, ['details' => 'work_date']);

  if ($action === 'clear') {
    $pdo->prepare("DELETE FROM crew_schedule WHERE work_date=? AND order_id=?")->execute([$date, $orderId]);
    json_ok(['ok' => true, 'entries' => cs_day_entries($pdo, $date)]);
  }

  if ($action === 'set') {
    $crewId = (int)($body['crew_id'] ?? 0);
    $on = !empty($body['on']);
    if ($crewId <= 0) json_err('bad_request', 400, ['details' => 'crew_id']);

    if ($on) {
      $crew = null;
      foreach (crews_list($pdo, false) as $c) { if ($c['id'] === $crewId) { $crew = $c; break; } }
      if (!$crew) json_err('not_found', 404, ['details' => 'Nie ma takiej ekipy.']);
      $st = $pdo->prepare("INSERT IGNORE INTO crew_schedule (work_date, crew_id, order_id, members_snapshot, created_by) VALUES (?,?,?,?,?)");
      $st->execute([$date, $crewId, $orderId, mb_substr($crew['members_label'], 0, 255), crews_uid($me) ?: null]);
    } else {
      $pdo->prepare("DELETE FROM crew_schedule WHERE work_date=? AND crew_id=? AND order_id=?")->execute([$date, $crewId, $orderId]);
    }
    json_ok(['ok' => true, 'entries' => cs_day_entries($pdo, $date)]);
  }

  json_err('bad_request', 400, ['details' => 'action']);
} catch (Throwable $e) {
  json_err('server_error', 500, ['details' => $e->getMessage()]);
}
