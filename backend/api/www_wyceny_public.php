<?php
// Publiczny punkt odbioru wycen z konfiguratora na smartmeble.pl.
// Przyjmuje WYŁĄCZNIE nowe zgłoszenia: start → file_init/chunk (pliki w kawałkach) → finish.
// Nie daje dostępu do żadnych danych panelu.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/www_wyceny.php';

// --- CORS: tylko strona Smart Meble ---
$allowed = array_map('trim', explode(',', (string)envv('WWW_QUOTES_ORIGINS', 'https://smartmeble.pl,https://www.smartmeble.pl')));
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_err('method_not_allowed', 405);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ww_ensure_schema($pdo);

$action = (string)($_GET['action'] ?? '');
$in = [];
if ($action !== 'chunk') {
  $raw = file_get_contents('php://input');
  if (strlen($raw) > 12 * 1024 * 1024) json_err('too_large', 413);
  $in = json_decode($raw, true);
  if (!is_array($in)) json_err('bad_request', 400);
}

function ww_str($v, int $max): string { return mb_substr(trim(strip_tags((string)$v)), 0, $max); }

/** Sprawdza id + token zgłoszenia (ważny 12 h, tylko dla szkicu). */
function ww_auth_request(PDO $pdo, $id, $token): array {
  $id = (int)$id;
  $token = (string)$token;
  if ($id <= 0 || !preg_match('/^[a-f0-9]{48}$/', $token)) json_err('forbidden', 403);
  $st = $pdo->prepare("SELECT * FROM www_wyceny WHERE id=? AND status='szkic' AND created_at > (NOW() - INTERVAL 12 HOUR)");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r || !hash_equals($r['token_hash'], hash('sha256', $token))) json_err('forbidden', 403);
  return $r;
}

try {
  /* ---------- 1. start: dane klienta ---------- */
  if ($action === 'start') {
    if (!empty($in['website'])) json_err('validation', 422); // honeypot
    $c = is_array($in['client'] ?? null) ? $in['client'] : [];
    $name  = ww_str($c['name'] ?? '', 190);
    $phone = ww_str($c['phone'] ?? '', 60);
    $email = ww_str($c['email'] ?? '', 190);
    if (mb_strlen($name) < 2 || strlen(preg_replace('/\D/', '', $phone)) < 9 || empty($in['consent'])) json_err('validation', 422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('validation', 422);
    $path = ($in['path'] ?? '') === 'konfigurator' ? 'konfigurator' : 'pliki';
    $type = (string)($in['type'] ?? '');
    if (!isset(WW_TYPES[$type])) $type = '';

    // limit: 6 zgłoszeń z jednego adresu IP na godzinę
    $ip = ww_ip_hash();
    $rl = $pdo->prepare('SELECT COUNT(*) FROM www_wyceny WHERE ip_hash=? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= (int)envv('WWW_QUOTES_RATE', 6)) json_err('rate_limited', 429);

    // zapowiedziane pliki – wstępna kontrola limitów
    $decl = is_array($in['files'] ?? null) ? $in['files'] : [];
    $sum = 0;
    foreach ($decl as $f) $sum += (int)($f['size'] ?? 0);
    if (count($decl) > ww_max_files() || $sum > ww_max_total()) json_err('too_large', 413);

    $token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO www_wyceny (token_hash,status,path,furniture_type,client_name,client_phone,client_email,client_city,client_postcode,deadline,source,message,agd,files_declared,consent_at,ip_hash,user_agent)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)")->execute([
      hash('sha256', $token), 'szkic', $path, $type, $name, $phone, $email,
      ww_str($c['city'] ?? '', 120), ww_str($c['postcode'] ?? '', 20), ww_str($c['deadline'] ?? '', 60), ww_str($c['source'] ?? '', 80),
      ww_str($in['message'] ?? '', 5000), empty($in['agd']) ? 0 : 1, count($decl), $ip,
      mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
    ]);
    $id = (int)$pdo->lastInsertId();
    $number = 'W-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE www_wyceny SET number=? WHERE id=?')->execute([$number, $id]);
    if (mt_rand(1, 20) === 1) ww_cleanup_drafts($pdo);
    json_ok(['ok' => true, 'id' => $id, 'token' => $token, 'number' => $number]);
  }

  /* ---------- 2. file_init: zapowiedź pliku ---------- */
  if ($action === 'file_init') {
    $r = ww_auth_request($pdo, $in['id'] ?? 0, $in['token'] ?? '');
    $name = ww_str(basename((string)($in['name'] ?? '')), 255);
    $size = (int)($in['size'] ?? 0);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $size <= 0 || !in_array($ext, WW_EXT, true)) json_err('bad_file_type', 422);
    $q = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(size),0) FROM www_wyceny_files WHERE request_id=? AND kind='upload'");
    $q->execute([(int)$r['id']]);
    [$cnt, $sum] = $q->fetch(PDO::FETCH_NUM);
    if ((int)$cnt + 1 > ww_max_files() || (int)$sum + $size > ww_max_total()) json_err('too_large', 413);
    $chunks = (int)ceil($size / ww_chunk());
    if ((int)($in['chunks'] ?? 0) !== $chunks) json_err('bad_request', 400);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    $pdo->prepare('INSERT INTO www_wyceny_files (request_id,kind,orig_name,stored_name,size,chunks_total) VALUES (?,?,?,?,?,?)')
      ->execute([(int)$r['id'], 'upload', $name, $stored, $size, $chunks]);
    ww_dir((int)$r['id']);
    json_ok(['ok' => true, 'file_id' => (int)$pdo->lastInsertId(), 'chunk_size' => ww_chunk()]);
  }

  /* ---------- 3. chunk: kawałek pliku (po kolei, ponowienie jest bezpieczne) ---------- */
  if ($action === 'chunk') {
    $r = ww_auth_request($pdo, $_POST['id'] ?? 0, $_POST['token'] ?? '');
    $fid = (int)($_POST['file_id'] ?? 0);
    $idx = (int)($_POST['index'] ?? -1);
    $st = $pdo->prepare('SELECT * FROM www_wyceny_files WHERE id=? AND request_id=?');
    $st->execute([$fid, (int)$r['id']]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) json_err('not_found', 404);
    if ($idx < (int)$f['chunks_done']) json_ok(['ok' => true, 'dup' => true]);   // już przyjęty
    if ($idx !== (int)$f['chunks_done'] || $idx >= (int)$f['chunks_total']) json_err('bad_order', 409);
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) json_err('upload_failed', 400);
    $len = (int)$_FILES['chunk']['size'];
    $expected = ($idx === (int)$f['chunks_total'] - 1) ? (int)$f['size'] - $idx * ww_chunk() : ww_chunk();
    if ($len !== $expected) json_err('bad_chunk', 400);

    $part = ww_dir((int)$r['id']) . '/' . $f['stored_name'] . '.part';
    $out = fopen($part, $idx === 0 ? 'wb' : 'ab');
    $src = fopen($_FILES['chunk']['tmp_name'], 'rb');
    if (!$out || !$src) json_err('storage_unavailable', 500);
    stream_copy_to_stream($src, $out);
    fclose($src); fclose($out);
    $done = $idx + 1;
    if ($done === (int)$f['chunks_total']) {
      clearstatcache();
      if (filesize($part) !== (int)$f['size']) { @unlink($part); json_err('size_mismatch', 400); }
      rename($part, ww_dir((int)$r['id']) . '/' . $f['stored_name']);
      $pdo->prepare('UPDATE www_wyceny_files SET chunks_done=?, complete=1 WHERE id=?')->execute([$done, $fid]);
    } else {
      $pdo->prepare('UPDATE www_wyceny_files SET chunks_done=? WHERE id=?')->execute([$done, $fid]);
    }
    json_ok(['ok' => true]);
  }

  /* ---------- 4. finish: projekt, zrzuty 3D, wysłanie ---------- */
  if ($action === 'finish') {
    $r = ww_auth_request($pdo, $in['id'] ?? 0, $in['token'] ?? '');
    $id = (int)$r['id'];
    $q = $pdo->prepare("SELECT COUNT(*) FROM www_wyceny_files WHERE request_id=? AND kind='upload' AND complete=0");
    $q->execute([$id]);
    if ((int)$q->fetchColumn() > 0) json_err('files_incomplete', 409);

    $config  = isset($in['config']) && is_array($in['config']) ? json_encode($in['config'], JSON_UNESCAPED_UNICODE) : null;
    $summary = isset($in['summary']) && is_array($in['summary']) ? json_encode($in['summary'], JSON_UNESCAPED_UNICODE) : null;
    $other   = isset($in['other']) && is_array($in['other']) ? json_encode(array_map(function ($v) { return ww_str($v, 3000); }, $in['other']), JSON_UNESCAPED_UNICODE) : null;
    if ($config !== null && strlen($config) > 2000000) json_err('too_large', 413);

    // zrzuty 3D (JPEG w base64)
    $renders = is_array($in['renders'] ?? null) ? array_slice($in['renders'], 0, 4) : [];
    foreach ($renders as $k => $d) {
      if (!is_string($d) || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $d, $m)) continue;
      $bin = base64_decode($m[1], true);
      if ($bin === false || strlen($bin) > 3 * 1024 * 1024 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") continue;
      $stored = 'render_' . ($k + 1) . '_' . bin2hex(random_bytes(6)) . '.jpg';
      file_put_contents(ww_dir($id) . '/' . $stored, $bin);
      $pdo->prepare("INSERT INTO www_wyceny_files (request_id,kind,orig_name,stored_name,size,chunks_total,chunks_done,complete) VALUES (?,?,?,?,?,1,1,1)")
        ->execute([$id, 'render', 'widok_3d_' . ($k + 1) . '.jpg', $stored, strlen($bin)]);
    }

    $pdo->prepare("UPDATE www_wyceny SET status='nowa', submitted_at=NOW(), token_hash='', config_json=?, summary_json=?, other_json=? WHERE id=?")
      ->execute([$config, $summary, $other, $id]);
    $r['status'] = 'nowa';
    try { ww_send_mails($pdo, $r); } catch (Throwable $e) { api_log_error('www_wyceny mail', $e); }
    json_ok(['ok' => true, 'number' => $r['number']]);
  }

  json_err('unsupported_action', 400);
} catch (Throwable $e) {
  api_log_error('www_wyceny_public', $e);
  json_err('server_error', 500);
}
