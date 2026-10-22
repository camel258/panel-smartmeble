<?php
// Ekipy montażowe + Grafik testowy (przydział ekip do warsztatu / montażu u klienta)
// Tabele zakładane automatycznie przy pierwszym wywołaniu API (tak jak reszta panelu).

function crews_uid(array $me): int {
  foreach (['id', 'uid', 'user_id'] as $k) { $v = (int)($me[$k] ?? 0); if ($v > 0) return $v; }
  return 0;
}

function crews_role_id(array $me): int {
  return (int)($me['role_id'] ?? ($me['role'] ?? 0));
}

function crews_is_admin(array $me): bool {
  return in_array(crews_role_id($me), [1, 999], true);
}

function crews_ensure_schema(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS crews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    vehicle VARCHAR(120) NOT NULL DEFAULT '',
    color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
    extra_members VARCHAR(255) NOT NULL DEFAULT '',
    note VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_active_sort (is_active, sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS crew_members (
    crew_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (crew_id, user_id),
    KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // order_id = 0 oznacza WARSZTAT
  $pdo->exec("CREATE TABLE IF NOT EXISTS crew_schedule (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_date DATE NOT NULL,
    crew_id INT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    members_snapshot VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_day_crew_order (work_date, crew_id, order_id),
    KEY idx_date (work_date),
    KEY idx_order (order_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // zamówienia z innych miesięcy dodane ręcznie do grafiku danego miesiąca
  $pdo->exec("CREATE TABLE IF NOT EXISTS crew_schedule_pins (
    ym CHAR(7) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ym, order_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $done = true;
}

function crews_users_map(PDO $pdo): array {
  $map = [];
  try {
    $st = $pdo->query("SELECT id, full_name, role_id, COALESCE(is_active,1) AS is_active FROM users ORDER BY full_name");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $map[(int)$r['id']] = [
        'id' => (int)$r['id'],
        'full_name' => (string)$r['full_name'],
        'role_id' => (int)$r['role_id'],
        'is_active' => (int)$r['is_active'],
      ];
    }
  } catch (Throwable $e) {}
  return $map;
}

// Lista ekip z członkami. $onlyActive=false zwraca też wyłączone.
function crews_list(PDO $pdo, bool $onlyActive = false): array {
  crews_ensure_schema($pdo);
  $sql = "SELECT id, name, vehicle, color, extra_members, note, sort_order, is_active FROM crews"
       . ($onlyActive ? " WHERE is_active=1" : "")
       . " ORDER BY is_active DESC, sort_order ASC, id ASC";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return [];

  $users = crews_users_map($pdo);
  $mem = [];
  foreach ($pdo->query("SELECT crew_id, user_id FROM crew_members")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $mem[(int)$m['crew_id']][] = (int)$m['user_id'];
  }

  $out = [];
  foreach ($rows as $r) {
    $cid = (int)$r['id'];
    $ids = $mem[$cid] ?? [];
    $names = [];
    foreach ($ids as $uid) { if (isset($users[$uid])) $names[] = $users[$uid]['full_name']; }
    $extra = trim((string)$r['extra_members']);
    $all = $names;
    if ($extra !== '') $all[] = $extra;
    $out[] = [
      'id' => $cid,
      'name' => (string)$r['name'],
      'vehicle' => (string)$r['vehicle'],
      'color' => (string)$r['color'],
      'extra_members' => $extra,
      'note' => (string)$r['note'],
      'sort_order' => (int)$r['sort_order'],
      'is_active' => (int)$r['is_active'],
      'member_ids' => $ids,
      'member_names' => $names,
      'members_label' => implode(', ', $all),
    ];
  }
  return $out;
}

function crews_default_seed(): array {
  return [
    ['Ekipa 1', 'Alex, Dominik', 'szary Ducato', '#64748b'],
    ['Ekipa 2', 'Piotr Tułaza, Sławek', 'prywatny', '#3b82f6'],
    ['Ekipa 3', 'Tomasz Jaworski, Aleksander P.', 'Ducato Mateusza', '#f59e0b'],
    ['Ekipa 4', 'Tomasz Kudłaty, Kacper', 'Mercedes', '#22c55e'],
    ['Ekipa 5', 'Wojtek, Artur', 'prywatny Artura', '#a855f7'],
    ['Ekipa 6', 'Jakub, Michał (Anglia?)', 'lodówka', '#06b6d4'],
    ['Ekipa 7', 'Karol, Mateusz', 'biały Ducato', '#ec4899'],
  ];
}

function crews_valid_date(string $d): bool {
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
  return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
