<?php
// Wspólne sprawdzanie uprawnień w API (te same reguły co w order_get.php / orders.php i w menu panelu):
// - zewnętrzny technolog widzi tylko zamówienia, w których jest planującym (orders.scheduler_id),
// - technolog i pracownik nie widzą finansów,
// - ustawienia (powiadomienia itp.) tylko dla ról 1 (Administrator) i 999 (Super Admin),
// - pozostałe uprawnienia z tabel role_field_permissions / user_field_permissions (can_view_field / can_edit_field).
// Wymaga wcześniej config/config.php.

function acc_uid(array $me): int {
  foreach (['id', 'uid', 'user_id'] as $k) { $v = (int)($me[$k] ?? 0); if ($v > 0) return $v; }
  return 0;
}

function acc_role_id(array $me): int {
  return (int)($me['role_id'] ?? ($me['role'] ?? 0));
}

function acc_role_name(PDO $pdo, array $me): string {
  static $cache = [];
  $rid = acc_role_id($me);
  if ($rid <= 0) return '';
  if (!isset($cache[$rid])) {
    try {
      $st = $pdo->prepare('SELECT name FROM roles WHERE id=? LIMIT 1');
      $st->execute([$rid]);
      $cache[$rid] = mb_strtolower(trim((string)$st->fetchColumn()), 'UTF-8');
    } catch (Throwable $e) { $cache[$rid] = ''; }
  }
  return $cache[$rid];
}

function acc_is_external_technologist(PDO $pdo, array $me): bool {
  $n = acc_role_name($pdo, $me);
  return strpos($n, 'technolog') !== false && (strpos($n, 'zewn') !== false || strpos($n, 'zewnetrz') !== false);
}

function acc_is_worker(PDO $pdo, array $me): bool {
  return strpos(acc_role_name($pdo, $me), 'pracownik') !== false;
}

function acc_is_admin(array $me): bool {
  return in_array(acc_role_id($me), [1, 999], true);
}

/** Uprawnienie do pola ('view' lub 'edit'), jak w order_get.php. */
function acc_can(PDO $pdo, array $me, string $field, string $mode = 'view'): bool {
  $finance = ['finance_summary', 'finance_components', 'finance_payments', 'finance_discount_edit'];
  if (in_array($field, $finance, true) && (acc_is_external_technologist($pdo, $me) || acc_is_worker($pdo, $me))) return false;
  if ($field === 'files_admin' && acc_is_external_technologist($pdo, $me)) return false;
  $uid = acc_uid($me);
  if ($uid <= 0) return false;
  try { return $mode === 'edit' ? (bool)can_edit_field($uid, $field) : (bool)can_view_field($uid, $field); }
  catch (Throwable $e) { return false; }
}

function acc_require_field(PDO $pdo, array $me, string $field, string $mode = 'view'): void {
  if (!acc_can($pdo, $me, $field, $mode)) json_err('forbidden', 403);
}

function acc_require_admin(array $me): void {
  if (!acc_is_admin($me)) json_err('forbidden', 403);
}

/** Czy użytkownik ma dostęp do zamówienia (technolog – tylko swoje). */
function acc_can_order(PDO $pdo, array $me, int $orderId): bool {
  if ($orderId <= 0) return false;
  if (!acc_is_external_technologist($pdo, $me)) return true;
  $st = $pdo->prepare('SELECT scheduler_id FROM orders WHERE id=? LIMIT 1');
  $st->execute([$orderId]);
  $sid = $st->fetchColumn();
  return $sid !== false && (int)$sid === acc_uid($me);
}

function acc_require_order(PDO $pdo, array $me, int $orderId): void {
  if (!acc_can_order($pdo, $me, $orderId)) json_err('forbidden', 403);
}
