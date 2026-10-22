<?php
require_once __DIR__ . '/common.php';

gps_require_access();

try {
    $devices = gps_traccar_request('GET', 'devices');
    $positions = gps_traccar_request('GET', 'positions');

    $posMap = [];
    foreach ($positions as $p) {
        if (isset($p['deviceId'])) $posMap[(int)$p['deviceId']] = $p;
    }

    $result = [];
    foreach ($devices as $d) {
        $deviceId = (int)($d['id'] ?? 0);
        $p = $posMap[$deviceId] ?? [];
        $attrs = $p['attributes'] ?? [];
        $lat = isset($p['latitude']) ? (float)$p['latitude'] : null;
        $lon = isset($p['longitude']) ? (float)$p['longitude'] : null;
        $result[] = [
            'id' => $deviceId,
            'name' => $d['name'] ?? null,
            'vehicle_name' => $d['name'] ?? null,
            'uniqueId' => $d['uniqueId'] ?? null,
            'status' => $d['status'] ?? 'unknown',
            'online' => (($d['status'] ?? 'unknown') === 'online') ? 1 : 0,
            'lastUpdate' => $d['lastUpdate'] ?? null,
            'latitude' => $lat,
            'longitude' => $lon,
            'address' => gps_pick_best_address($p['address'] ?? null, $lat, $lon),
            'fixTime' => $p['fixTime'] ?? null,
            'speed_kmh' => isset($p['speed']) ? gps_speed_kmh($p['speed']) : 0,
            'ignition' => !empty($attrs['ignition']) ? 1 : 0,
            'motion' => !empty($attrs['motion']) ? 1 : 0,
            'attributes' => $attrs,
        ];
    }

    json_ok(['ok' => true, 'devices' => $result]);
} catch (Throwable $e) {
    json_err('gps_overview_failed', 500, ['details' => $e->getMessage()]);
}
