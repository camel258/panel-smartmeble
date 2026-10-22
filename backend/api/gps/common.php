<?php
require_once __DIR__ . '/../../../config/config.php';

function gps_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function gps_require_user(): array {
    $u = auth_user();
    if (!$u) json_err('unauthorized', 401);
    return $u;
}

function gps_current_role(PDO $pdo, array $u): int {
    $rid = (int)($u['role_id'] ?? ($u['role'] ?? 0));
    if ($rid > 0) return $rid;
    $uid = (int)($u['id'] ?? ($u['uid'] ?? 0));
    if ($uid > 0) {
        $st = $pdo->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1');
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    }
    return 0;
}

function gps_default_access(int $role, string $module): int {
    if ($role === 999) return 1;
    if ($role === 1) return !in_array($module, ['orders_deleted', 'permissions'], true);
    return in_array($module, ['dashboard','orders_current','orders_finished','schedule'], true) ? 1 : 0;
}

function gps_require_access(): array {
    $u = gps_require_user();
    $pdo = db();
    $role = gps_current_role($pdo, $u);
    $allow = gps_default_access($role, 'gps_monitoring');
    try {
        $st = $pdo->prepare('SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code=? LIMIT 1');
        $st->execute([$role, 'gps_monitoring']);
        $row = $st->fetchColumn();
        if ($row !== false) $allow = (int)$row;
    } catch (Throwable $e) {}
    if (!$allow) json_err('forbidden', 403, ['details' => 'gps_monitoring_required']);
    return $u;
}

function gps_build_query(array $query): string {
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$item);
            }
        } else {
            $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
    }
    return implode('&', $parts);
}

function gps_http_build_url(string $endpoint, array $query = []): string {
    $cfg = gps_cfg();
    $url = rtrim($cfg['traccar']['base_url'], '/') . '/' . ltrim($endpoint, '/');
    if ($query) {
        $qs = gps_build_query($query);
        if ($qs !== '') $url .= '?' . $qs;
    }
    return $url;
}

function gps_traccar_request(string $method, string $endpoint, array $query = [], $body = null) {
    $cfg = gps_cfg();
    $ch = curl_init(gps_http_build_url($endpoint, $query));
    $headers = [
        'Accept: application/json',
        'User-Agent: SmartMebleGPS/2.2'
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERPWD => $cfg['traccar']['username'] . ':' . $cfg['traccar']['password'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => (int)($cfg['traccar']['timeout'] ?? 20),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (!$cfg['traccar']['verify_ssl']) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('CURL error: ' . $err);
    if ($status >= 400) throw new RuntimeException('Traccar HTTP ' . $status . ': ' . $resp);
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : $resp;
}

function gps_speed_kmh($knots): float {
    return round(((float)$knots) * 1.852, 2);
}

function gps_coords_cache_key(?float $lat, ?float $lon): ?string {
    if ($lat === null || $lon === null) return null;
    return number_format($lat, 4, '.', '') . '_' . number_format($lon, 4, '.', '');
}

function gps_cache_dir(): string {
    $dir = __DIR__ . '/cache/geocode';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function gps_cache_file(string $key): string {
    return gps_cache_dir() . '/' . md5($key) . '.json';
}

function gps_cache_read(string $key, int $ttlSeconds): ?array {
    $file = gps_cache_file($key);
    if (!is_file($file)) return null;
    if ((time() - filemtime($file)) > $ttlSeconds) return null;
    $raw = @file_get_contents($file);
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : null;
}

function gps_cache_write(string $key, array $payload): void {
    @file_put_contents(gps_cache_file($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function gps_build_short_address(array $payload): ?string {
    $addr = $payload['address'] ?? [];
    $parts = [];
    $road = $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['path'] ?? null;
    $house = $addr['house_number'] ?? null;
    $suburb = $addr['suburb'] ?? $addr['neighbourhood'] ?? null;
    $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? null;

    if ($road) $parts[] = trim($road . ($house ? ' ' . $house : ''));
    if ($suburb && $suburb !== $city) $parts[] = $suburb;
    if ($city) $parts[] = $city;

    if ($parts) return implode(', ', array_values(array_unique(array_filter($parts))));

    if (!empty($payload['display_name'])) {
        $display = explode(',', (string)$payload['display_name']);
        $display = array_map('trim', array_slice($display, 0, 3));
        return implode(', ', array_filter($display));
    }

    return null;
}

function gps_reverse_geocode(?float $lat, ?float $lon, bool $allowNetwork = true): ?string {
    $cfg = gps_cfg();
    if ($lat === null || $lon === null) return null;

    $cacheKey = gps_coords_cache_key($lat, $lon);
    if ($cacheKey) {
        $cached = gps_cache_read($cacheKey, (int)($cfg['geocoder']['cache_ttl_seconds'] ?? 2592000));
        if ($cached && !empty($cached['address'])) return (string)$cached['address'];
    }

    if (!$allowNetwork) return null;
    if (($cfg['geocoder']['provider'] ?? 'nominatim') !== 'nominatim') return null;

    static $networkLookups = 0;
    $maxLookups = (int)($cfg['geocoder']['max_network_lookups_per_request'] ?? 5);
    if ($networkLookups >= $maxLookups) return null;
    $networkLookups++;

    $params = [
        'format' => 'jsonv2',
        'lat' => $lat,
        'lon' => $lon,
        'zoom' => (int)($cfg['geocoder']['zoom'] ?? 17),
        'addressdetails' => 1,
        'accept-language' => $cfg['geocoder']['language'] ?? 'pl',
    ];
    $url = ($cfg['geocoder']['base_url'] ?? 'https://nominatim.openstreetmap.org/reverse') . '?' . gps_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($cfg['geocoder']['timeout'] ?? 2),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ' . ($cfg['geocoder']['user_agent'] ?? 'SmartMebleGPS/2.2 (panel@smartmeble.pl)'),
        ],
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $status >= 400) return null;
    $json = json_decode($resp, true);
    if (!is_array($json)) return null;

    $address = gps_build_short_address($json);
    if ($cacheKey && $address) {
        gps_cache_write($cacheKey, [
            'address' => $address,
            'lat' => $lat,
            'lon' => $lon,
            'saved_at' => date('c'),
        ]);
    }
    return $address;
}

function gps_pick_best_address(?string $preferred, ?float $lat, ?float $lon, ?string $fallback = null): ?string {
    if ($preferred !== null && trim($preferred) !== '' && trim($preferred) !== 'Brak adresu') {
        return trim($preferred);
    }
    if ($fallback !== null && trim($fallback) !== '' && trim($fallback) !== 'Brak adresu') {
        return trim($fallback);
    }
    $cached = gps_reverse_geocode($lat, $lon, false);
    if ($cached) return $cached;
    return gps_reverse_geocode($lat, $lon, true);
}
