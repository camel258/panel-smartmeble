<?php
// Zarządzanie ekipami (Grafik testowy)
// GET  -> lista ekip + użytkownicy do wyboru + pojazdy z GPS
// POST {action:'save'|'delete'|'seed_default', ...} -> tylko admin / super admin
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/crews_lib.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if (!$me) json_err('unauthorized', 401);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$isAdmin = crews_is_admin($me);

function crews_response(PDO $pdo, bool $isAdmin): void {
  $users = [];
  foreach (crews_users_map($pdo) as $u) {
    if ($u['is_active'] !== 1) continue;
    $users[] = ['id' => $u['id'], 'full_name' => $u['full_name'], 'is_mounter' => $u['role_id'] === 3 ? 1 : 0];
  }
  $vehicles = [];
  try {
    if ($pdo->query("SHOW TABLES LIKE 'gps_devices'")->fetchColumn()) {
      $vehicles = $pdo->query("SELECT DISTINCT vehicle_name FROM gps_devices WHERE vehicle_name<>'' ORDER BY vehicle_name")->fetchAll(PDO::FETCH_COLUMN);
    }
  } catch (Throwable $e) {}
  json_ok([
    'items' => crews_list($pdo, false),
    'users' => $users,
    'vehicles' => array_values(array_map('strval', $vehicles)),
    'can_edit' => $isAdmin ? 1 : 0,
  ]);
}

try {
  crews_ensure_schema($pdo);

  // lista ekip zawiera wszystkich pracowników panelu -> tylko dla administratora
  if (!$isAdmin) json_err('forbidden', 403, ['details' => 'Zarządzanie ekipami jest dostępne tylko dla administratora.']);

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    crews_response($pdo, $isAdmin);
  }

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) $body = $_POST;
  $action = (string)($body['action'] ?? 'save');

  if ($action === 'seed_default') {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM crews")->fetchColumn();
    if ($cnt === 0) {
      $ins = $pdo->prepare("INSERT INTO crews (name, extra_members, vehicle, color, sort_order, is_active) VALUES (?,?,?,?,?,1)");
      foreach (crews_default_seed() as $i => $c) $ins->execute([$c[0], $c[1], $c[2], $c[3], ($i + 1) * 10]);
    }
    crews_response($pdo, $isAdmin);
  }

  if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) json_err('bad_request', 400, ['details' => 'id']);
    $st = $pdo->prepare("SELECT COUNT(*) FROM crew_schedule WHERE crew_id=?");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
      // ekipa ma historię w grafiku -> tylko wyłączamy, żeby nie zgubić wpisów
      $pdo->prepare("UPDATE crews SET is_active=0 WHERE id=?")->execute([$id]);
    } else {
      $pdo->prepare("DELETE FROM crew_members WHERE crew_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM crews WHERE id=?")->execute([$id]);
    }
    crews_response($pdo, $isAdmin);
  }

  if ($action === 'save') {
    $id = (int)($body['id'] ?? 0);
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 100);
    $vehicle = mb_substr(trim((string)($body['vehicle'] ?? '')), 0, 120);
    $extra = mb_substr(trim((string)($body['extra_members'] ?? '')), 0, 255);
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 255);
    $color = (string)($body['color'] ?? '#3b82f6');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#3b82f6';
    $sort = (int)($body['sort_order'] ?? 0);
    $active = !empty($body['is_active']) ? 1 : 0;
    $memberIds = array_values(array_unique(array_filter(array_map('intval', (array)($body['member_ids'] ?? [])), fn($v) => $v > 0)));

    if ($name === '') json_err('bad_request', 400, ['details' => 'Podaj nazwę ekipy.']);

    // jeden pracownik = jedna aktywna ekipa
    if ($active && $memberIds) {
      $in = implode(',', array_fill(0, count($memberIds), '?'));
      $st = $pdo->prepare("SELECT cm.user_id, c.name FROM crew_members cm JOIN crews c ON c.id=cm.crew_id
                           WHERE c.is_active=1 AND cm.crew_id<>? AND cm.user_id IN ($in)");
      $st->execute(array_merge([$id], $memberIds));
      $clash = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($clash) {
        $users = crews_users_map($pdo);
        $txt = implode(', ', array_map(fn($r) => ($users[(int)$r['user_id']]['full_name'] ?? ('#' . $r['user_id'])) . ' (' . $r['name'] . ')', $clash));
        json_err('member_in_other_crew', 409, ['details' => 'Ci pracownicy są już w innej aktywnej ekipie: ' . $txt]);
      }
    }

    $pdo->beginTransaction();
    if ($id > 0) {
      $st = $pdo->prepare("UPDATE crews SET name=?, vehicle=?, color=?, extra_members=?, note=?, sort_order=?, is_active=? WHERE id=?");
      $st->execute([$name, $vehicle, $color, $extra, $note, $sort, $active, $id]);
    } else {
      if ($sort === 0) $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM crews")->fetchColumn();
      $st = $pdo->prepare("INSERT INTO crews (name, vehicle, color, extra_members, note, sort_order, is_active) VALUES (?,?,?,?,?,?,?)");
      $st->execute([$name, $vehicle, $color, $extra, $note, $sort, $active]);
      $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM crew_members WHERE crew_id=?")->execute([$id]);
    if ($memberIds) {
      $ins = $pdo->prepare("INSERT INTO crew_members (crew_id, user_id) VALUES (?,?)");
      foreach ($memberIds as $uid) $ins->execute([$id, $uid]);
    }
    $pdo->commit();
    crews_response($pdo, $isAdmin);
  }

  json_err('bad_request', 400, ['details' => 'action']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_err('server_error', 500, ['details' => $e->getMessage()]);
}
