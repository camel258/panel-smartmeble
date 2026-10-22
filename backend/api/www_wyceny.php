<?php
// Zakładka „Wyceny do zrobienia” – API dla zalogowanych użytkowników panelu.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/www_wyceny.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if (!$me) json_err('unauthorized', 401);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!ww_can_access($pdo, $me)) json_err('forbidden', 403);
ww_ensure_schema($pdo);

$uid   = (int)($me['id'] ?? $me['uid'] ?? $me['user_id'] ?? 0);
$role  = api_current_role_id($pdo, $me);
$super = $role === 999;

function ww_get(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT w.*, u.full_name AS assigned_name FROM www_wyceny w LEFT JOIN users u ON u.id=w.assigned_user_id WHERE w.id=? AND w.status<>'szkic'");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function ww_log(PDO $pdo, int $id, int $uid, string $name, string $kind, string $note): void {
  $pdo->prepare('INSERT INTO www_wyceny_notes (request_id,user_id,user_name,kind,note) VALUES (?,?,?,?,?)')->execute([$id, $uid ?: null, $name, $kind, $note]);
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'list');

    if ($action === 'count') {
      $n = (int)$pdo->query("SELECT COUNT(*) FROM www_wyceny WHERE status='nowa'")->fetchColumn();
      json_ok(['ok' => true, 'new' => $n]);
    }

    if ($action === 'list') {
      ww_cleanup_drafts($pdo);
      $status = (string)($_GET['status'] ?? 'open');
      $q = trim((string)($_GET['q'] ?? ''));
      $where = ["w.status<>'szkic'"]; $params = [];
      if ($status === 'open') $where[] = "w.status IN ('nowa','w_trakcie')";
      elseif (isset(WW_STATUSES[$status])) { $where[] = 'w.status=?'; $params[] = $status; }
      if ($q !== '') {
        $where[] = '(w.client_name LIKE ? OR w.client_phone LIKE ? OR w.client_email LIKE ? OR w.client_city LIKE ? OR w.number LIKE ?)';
        for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
      }
      $sql = "SELECT w.id,w.number,w.status,w.path,w.furniture_type,w.client_name,w.client_phone,w.client_email,w.client_city,w.deadline,w.source,w.agd,
                DATE_FORMAT(w.submitted_at,'%Y-%m-%d %H:%i') submitted_at, u.full_name assigned_name, w.linked_quote_id,
                (SELECT COUNT(*) FROM www_wyceny_files f WHERE f.request_id=w.id AND f.kind='upload') files_count
              FROM www_wyceny w LEFT JOIN users u ON u.id=w.assigned_user_id
              WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(w.status,'nowa','w_trakcie','oferta','zamknieta','odrzucona'), w.submitted_at DESC LIMIT 500";
      $st = $pdo->prepare($sql); $st->execute($params);
      $counts = [];
      foreach ($pdo->query("SELECT status, COUNT(*) n FROM www_wyceny WHERE status<>'szkic' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[$c['status']] = (int)$c['n'];
      json_ok(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC), 'counts' => $counts, 'statuses' => WW_STATUSES, 'types' => WW_TYPES]);
    }

    if ($action === 'get') {
      $id = (int)($_GET['id'] ?? 0);
      $r = ww_get($pdo, $id);
      if (!$r) json_err('not_found', 404);
      unset($r['token_hash'], $r['ip_hash']);
      $f = $pdo->prepare('SELECT id,kind,orig_name,size,complete,created_at FROM www_wyceny_files WHERE request_id=? AND complete=1 ORDER BY kind DESC, id');
      $f->execute([$id]);
      $n = $pdo->prepare('SELECT id,user_name,kind,note,DATE_FORMAT(created_at,\'%Y-%m-%d %H:%i\') created_at FROM www_wyceny_notes WHERE request_id=? ORDER BY id DESC');
      $n->execute([$id]);
      $users = $pdo->query('SELECT id, full_name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
      $r['config']  = $r['config_json'] ? json_decode($r['config_json'], true) : null;
      $r['summary'] = $r['summary_json'] ? json_decode($r['summary_json'], true) : null;
      $r['other']   = $r['other_json'] ? json_decode($r['other_json'], true) : null;
      unset($r['config_json'], $r['summary_json'], $r['other_json']);
      json_ok(['ok' => true, 'item' => $r, 'files' => $f->fetchAll(PDO::FETCH_ASSOC), 'notes' => $n->fetchAll(PDO::FETCH_ASSOC),
        'users' => $users, 'statuses' => WW_STATUSES, 'types' => WW_TYPES, 'can_delete' => $super]);
    }
    json_err('unsupported_action', 400);
  }

  $in = api_request_data();
  $action = (string)($in['action'] ?? '');
  $id = (int)($in['id'] ?? 0);
  $r = ww_get($pdo, $id);
  if (!$r) json_err('not_found', 404);
  $name = ww_user_name($pdo, $me);

  if ($action === 'status') {
    $s = (string)($in['status'] ?? '');
    if (!isset(WW_STATUSES[$s])) json_err('bad_request', 422);
    if ($s !== $r['status']) {
      $pdo->prepare('UPDATE www_wyceny SET status=? WHERE id=?')->execute([$s, $id]);
      ww_log($pdo, $id, $uid, $name, 'status', 'Status: ' . WW_STATUSES[$r['status']][0] . ' → ' . WW_STATUSES[$s][0]);
    }
    json_ok(['ok' => true]);
  }

  if ($action === 'assign') {
    $u = (int)($in['user_id'] ?? 0) ?: null;
    $pdo->prepare('UPDATE www_wyceny SET assigned_user_id=? WHERE id=?')->execute([$u, $id]);
    $un = '—';
    if ($u) { $q = $pdo->prepare('SELECT full_name FROM users WHERE id=?'); $q->execute([$u]); $un = (string)$q->fetchColumn(); }
    ww_log($pdo, $id, $uid, $name, 'status', 'Przypisano: ' . $un);
    if ($u && $r['status'] === 'nowa') {
      $pdo->prepare("UPDATE www_wyceny SET status='w_trakcie' WHERE id=?")->execute([$id]);
    }
    json_ok(['ok' => true]);
  }

  if ($action === 'note') {
    $note = trim((string)($in['note'] ?? ''));
    if ($note === '') json_err('bad_request', 422);
    ww_log($pdo, $id, $uid, $name, 'note', mb_substr($note, 0, 5000));
    json_ok(['ok' => true]);
  }

  // Przenosi dane klienta do modułu „Wycena” (wycena_quotes) – dalej liczysz materiały i robisz ofertę jak zwykle.
  if ($action === 'to_quote') {
    if ($r['linked_quote_id']) json_ok(['ok' => true, 'quote_id' => (int)$r['linked_quote_id']]);
    require_once __DIR__ . '/../lib/wycena_data.php';
    wq_ensure_schema($pdo);
    $parts = preg_split('/\s+/', trim($r['client_name']), 2);
    $pdo->prepare("INSERT INTO wycena_quotes (quote_date, client_first_name, client_last_name, client_phone, client_email, install_postcode, install_city, source, created_by_name, created_by_id)
      VALUES (CURDATE(),?,?,?,?,?,?,?,?,?)")->execute([
      $parts[0] ?? '', $parts[1] ?? '', $r['client_phone'], $r['client_email'], $r['client_postcode'], $r['client_city'],
      'Strona www ' . $r['number'], $name, $uid ?: null
    ]);
    $qid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE www_wyceny SET linked_quote_id=? WHERE id=?')->execute([$qid, $id]);
    if ($r['status'] === 'nowa') $pdo->prepare("UPDATE www_wyceny SET status='w_trakcie' WHERE id=?")->execute([$id]);
    ww_log($pdo, $id, $uid, $name, 'status', 'Przeniesiono do modułu Wycena (#' . $qid . ')');
    json_ok(['ok' => true, 'quote_id' => $qid]);
  }

  if ($action === 'delete') {
    if (!$super) json_err('forbidden', 403);
    ww_rm_dir(api_private_files_base() . '/wyceny_www/' . $id);
    $pdo->prepare('DELETE FROM www_wyceny_files WHERE request_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM www_wyceny_notes WHERE request_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM www_wyceny WHERE id=?')->execute([$id]);
    json_ok(['ok' => true]);
  }

  json_err('unsupported_action', 400);
} catch (Throwable $e) {
  api_log_error('www_wyceny', $e);
  json_err('server_error', 500);
}
