<?php

function api_request_data(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : $_POST;
}

function api_null_if_empty($value) {
    if ($value === null) return null;
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
    return $value;
}

function api_int_or_null($value): ?int {
    $value = api_null_if_empty($value);
    if ($value === null) return null;
    return (is_numeric($value) && (int)$value > 0) ? (int)$value : null;
}

function api_dec_or_zero($value): string {
    if ($value === null || $value === '') return '0.00';
    return is_numeric($value) ? number_format((float)$value, 2, '.', '') : '0.00';
}

function api_current_role_id(PDO $pdo, array $user): int {
    $roleId = (int)($user['role_id'] ?? ($user['role'] ?? 0));
    if ($roleId > 0) return $roleId;

    $userId = (int)($user['id'] ?? ($user['uid'] ?? 0));
    if ($userId > 0) {
        try {
            $st = $pdo->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1');
            $st->execute([$userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
        }
    }
    return 0;
}

function api_role_name(PDO $pdo, int $roleId): string {
    if ($roleId <= 0) return '';
    try {
        $cols = array_column($pdo->query('SHOW COLUMNS FROM roles')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['name', 'title', 'label'] as $column) {
            if (in_array($column, $cols, true)) {
                $st = $pdo->prepare("SELECT `$column` FROM roles WHERE id=? LIMIT 1");
                $st->execute([$roleId]);
                return strtolower(trim((string)$st->fetchColumn()));
            }
        }
    } catch (Throwable $e) {
    }
    return '';
}

function api_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function api_is_permissions_admin(PDO $pdo, array $user): bool {
    $roleId = api_current_role_id($pdo, $user);
    if (in_array($roleId, [1, 999], true)) return true;

    $roleName = api_role_name($pdo, $roleId);
    if ($roleName !== '' && (strpos($roleName, 'super') !== false || strpos($roleName, 'admin') !== false)) {
        return true;
    }

    try {
        if (api_table_exists($pdo, 'role_module_permissions')) {
            $st = $pdo->prepare("SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code='permissions' LIMIT 1");
            $st->execute([$roleId]);
            if ((int)$st->fetchColumn() === 1) return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}

/**
 * Katalog plików prywatnych (orders_admin, orders_public, wyceny_www). Na VPS poza katalogiem strony:
 * PRIVATE_FILES_DIR w config/.env (np. /var/www/panel.smartmeble.pl/_private_files).
 */
function api_private_files_base(): string {
    $env = function_exists('envv') ? (string)envv('PRIVATE_FILES_DIR', '') : '';
    if ($env !== '' && is_dir($env)) return rtrim($env, '/');
    $candidates = [
        realpath(__DIR__ . '/../../_private_files'),
        realpath(__DIR__ . '/../_private_files'),
    ];
    foreach ($candidates as $candidate) {
        if ($candidate && is_dir($candidate)) return $candidate;
    }
    return dirname(__DIR__, 2) . '/private_files';
}

/**
 * Ścieżka pliku z bazy (order_files.storage_path) po przeniesieniu serwera: w bazie są ścieżki z home.pl,
 * np. /_private_files/orders_admin/12/umowa.pdf – szukamy części od „_private_files/” w nowym katalogu.
 * Zwraca istniejącą ścieżkę w katalogu plików prywatnych albo pusty tekst.
 */
function api_resolve_storage_path(string $stored): string {
    $base = api_private_files_base();
    $stored = str_replace('\\', '/', $stored);
    $cands = [$stored];
    $pos = strpos($stored, '_private_files/');
    if ($pos !== false) $cands[] = $base . '/' . substr($stored, $pos + strlen('_private_files/'));
    if (preg_match('#/((?:orders_admin|orders_public|wyceny_www)/.+)$#', $stored, $m)) $cands[] = $base . '/' . $m[1];
    $rb = realpath($base);
    foreach ($cands as $c) {
        $rp = realpath($c);
        if ($rp && $rb && is_file($rp) && strpos($rp, rtrim($rb, '/') . '/') === 0) return $rp;
    }
    return '';
}

/** Stare pliki z upload/orders/<id> – na VPS przeniesione do katalogu prywatnego (legacy_upload/orders). */
function api_legacy_orders_dir(int $orderId): string {
    $priv = api_private_files_base() . '/legacy_upload/orders/' . $orderId;
    if (is_dir($priv)) return $priv;
    return dirname(__DIR__, 2) . '/upload/orders/' . $orderId;
}

/** Typy plików, które wolno pokazać w przeglądarce (inline); reszta zawsze do pobrania. */
function api_safe_inline_mime(string $path, string $fallback = ''): ?string {
    $mime = '';
    try { $fi = new finfo(FILEINFO_MIME_TYPE); $mime = (string)$fi->file($path); } catch (Throwable $e) {}
    if ($mime === '') $mime = $fallback;
    $ok = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    return in_array($mime, $ok, true) ? $mime : null;
}

function api_scope_dir(string $scope): string {
    return $scope === 'admin' ? 'orders_admin' : 'orders_public';
}

function api_error_log_path(): string {
    return dirname(__DIR__, 2) . '/uploads/error_log.txt';
}

function api_log_error(string $context, Throwable $e): void {
    @file_put_contents(api_error_log_path(), date('Y-m-d H:i:s') . ' ' . $context . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// ---- Pliki: pamięć przeglądarki i miniatury (2026-11-08) ----
// Nagłówki pamięci podręcznej przeglądarki (tylko u zalogowanego użytkownika – "private"). Gdy przeglądarka ma już
// aktualną wersję (If-None-Match), odpowiada 304 bez wysyłania pliku i kończy skrypt.
function api_file_cache_headers(string $path, int $maxAge = 604800): void {
    $etag = '"' . dechex((int)@filesize($path)) . '-' . dechex((int)@filemtime($path)) . '"';
    header('Cache-Control: private, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)@filemtime($path)) . ' GMT');
    header_remove('Pragma'); header_remove('Expires');
    $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag)) { http_response_code(304); exit; }
}

// Miniatura zdjęcia (JPEG, dłuższy bok 320 px) zapisana w <pliki prywatne>/_miniatury. Wysyła ją i zwraca true;
// zwraca false, gdy plik nie jest zdjęciem albo nie da się go zmniejszyć (wtedy wołający wysyła oryginał).
function api_send_thumb(string $path, int $max = 320): bool {
    $mime = api_safe_inline_mime($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || !function_exists('imagecreatetruecolor')) return false;
    $dir = api_private_files_base() . '/_miniatury';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
    $cache = $dir . '/' . sha1((string)realpath($path) . '|' . (int)@filemtime($path) . '|' . (int)@filesize($path) . '|' . $max) . '.jpg';
    if (!is_file($cache)) {
        $info = @getimagesize($path);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 30000000) return false;   // >30 Mpx – za duże dla pamięci PHP
        try {
            $src = @imagecreatefromstring((string)file_get_contents($path));
            if (!$src) return false;
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {   // zdjęcia z telefonu: obrót wg EXIF
                $o = (int)(@exif_read_data($path)['Orientation'] ?? 1);
                if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
            }
            $w = imagesx($src); $h = imagesy($src); $s = min(1, $max / max($w, $h));
            $tw = max(1, (int)round($w * $s)); $th = max(1, (int)round($h * $s));
            $dst = imagecreatetruecolor($tw, $th);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // przezroczyste PNG na białym tle
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            $tmp = $cache . '.' . getmypid() . '.tmp';
            $ok = imagejpeg($dst, $tmp, 78) && @rename($tmp, $cache);
            imagedestroy($src); imagedestroy($dst);
            if (!$ok) { @unlink($tmp); return false; }
        } catch (Throwable $e) { api_log_error('miniatura', $e); return false; }
    }
    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline; filename="miniatura.jpg"');
    header('X-Content-Type-Options: nosniff');
    api_file_cache_headers($cache, 2592000);
    header('Content-Length: ' . filesize($cache));
    readfile($cache);
    return true;
}
