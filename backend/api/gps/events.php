<?php
require_once __DIR__ . '/common.php';

gps_require_access();

function gps_parse_iso_param(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $dt = new DateTime($value);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return null;
    }
}

function gps_duration_seconds($raw): int {
    if (is_numeric($raw)) return max(0, (int)round((float)$raw / (abs((float)$raw) > 100000 ? 1000 : 1)));
    return 0;
}

try {
    $cfg = gps_cfg();

    $devices = gps_traccar_request('GET', 'devices');
    $positions = gps_traccar_request('GET', 'positions');

    $deviceMap = [];
    $deviceIds = [];
    $lastPosByDevice = [];

    foreach ($devices as $d) {
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) continue;
        $deviceMap[$id] = $d;
        $deviceIds[] = $id;
    }

    foreach ($positions as $p) {
        $did = (int)($p['deviceId'] ?? 0);
        if ($did > 0) $lastPosByDevice[$did] = $p;
    }

    $selectedDeviceId = isset($_GET['deviceId']) ? (int)$_GET['deviceId'] : 0;
    if ($selectedDeviceId > 0) {
        $deviceIds = in_array($selectedDeviceId, $deviceIds, true) ? [$selectedDeviceId] : [];
    }

    if (!$deviceIds) json_ok(['ok' => true, 'events' => []]);

    $hoursBack = (int)($cfg['defaults']['events_hours_back'] ?? 24);
    $limit = max(1, min(1000, (int)($_GET['limit'] ?? ($cfg['defaults']['events_limit'] ?? 100))));
    $type = trim((string)($_GET['type'] ?? ''));
    $from = gps_parse_iso_param($_GET['from'] ?? null);
    $to = gps_parse_iso_param($_GET['to'] ?? null);
    $hasCustomRange = ($from !== null || $to !== null);

    if ($to === null) $to = gmdate('Y-m-d\TH:i:s\Z');
    if ($from === null) $from = gmdate('Y-m-d\TH:i:s\Z', time() - ($hoursBack * 3600));

    $query = [
        'from' => $from,
        'to' => $to,
        'deviceId' => $deviceIds,
    ];

    $result = [];

    if ($type === 'deviceStopped') {
        $stops = gps_traccar_request('GET', 'reports/stops', $query);
        usort($stops, static function($a, $b) {
            return strcmp((string)($b['startTime'] ?? ''), (string)($a['startTime'] ?? ''));
        });

        if (!$hasCustomRange && $selectedDeviceId <= 0) {
            $perDeviceLimit = 30;
            $counts = [];
            $filtered = [];
            foreach ($stops as $stop) {
                $did = (int)($stop['deviceId'] ?? 0);
                if ($did <= 0) continue;
                $counts[$did] = ($counts[$did] ?? 0);
                if ($counts[$did] >= $perDeviceLimit) continue;
                $filtered[] = $stop;
                $counts[$did]++;
            }
            $stops = $filtered;
        } else {
            $stops = array_slice($stops, 0, $limit);
        }

        foreach ($stops as $stop) {
            $deviceId = (int)($stop['deviceId'] ?? 0);
            $device = $deviceMap[$deviceId] ?? [];
            $fallbackPosition = $lastPosByDevice[$deviceId] ?? [];

            $lat = isset($stop['latitude']) ? (float)$stop['latitude'] : (isset($fallbackPosition['latitude']) ? (float)$fallbackPosition['latitude'] : null);
            $lon = isset($stop['longitude']) ? (float)$stop['longitude'] : (isset($fallbackPosition['longitude']) ? (float)$fallbackPosition['longitude'] : null);

            $bestAddress = gps_pick_best_address(
                $stop['address'] ?? null,
                $lat,
                $lon,
                $fallbackPosition['address'] ?? null
            );
            if (!$bestAddress && $lat !== null && $lon !== null) {
                $bestAddress = 'Współrzędne: ' . number_format($lat, 5, '.', '') . ', ' . number_format($lon, 5, '.', '');
            }

            $durationRaw = $stop['duration'] ?? ($stop['attributes']['duration'] ?? 0);
            $result[] = [
                'id' => $stop['id'] ?? null,
                'deviceId' => $deviceId,
                'deviceName' => $device['name'] ?? ($stop['deviceName'] ?? ('Urządzenie #' . $deviceId)),
                'type' => 'deviceStopped',
                'eventTime' => $stop['startTime'] ?? null,
                'endTime' => $stop['endTime'] ?? null,
                'serverTime' => $stop['serverTime'] ?? null,
                'positionId' => $stop['positionId'] ?? null,
                'latitude' => $lat,
                'longitude' => $lon,
                'address' => $bestAddress,
                'durationSeconds' => gps_duration_seconds($durationRaw),
                'geofenceName' => null,
                'attributes' => $stop['attributes'] ?? new stdClass(),
            ];
        }
    } else {
        if ($type !== '') $query['type'] = [$type];
        $events = gps_traccar_request('GET', 'reports/events', $query);
        usort($events, static function($a, $b) {
            return strcmp((string)($b['eventTime'] ?? ''), (string)($a['eventTime'] ?? ''));
        });
        $events = array_slice($events, 0, $limit);

        $positionIds = [];
        foreach ($events as $ev) {
            if (!empty($ev['positionId'])) $positionIds[] = (int)$ev['positionId'];
        }
        $positionIds = array_values(array_unique(array_filter($positionIds)));
        $positionsMap = [];
        if ($positionIds) {
            $eventPositions = gps_traccar_request('GET', 'positions', ['id' => $positionIds]);
            foreach ($eventPositions as $p) $positionsMap[(int)$p['id']] = $p;
        }

        foreach ($events as $ev) {
            $deviceId = (int)($ev['deviceId'] ?? 0);
            $device = $deviceMap[$deviceId] ?? [];
            $position = [];
            if (!empty($ev['positionId'])) $position = $positionsMap[(int)$ev['positionId']] ?? [];
            $fallbackPosition = $lastPosByDevice[$deviceId] ?? [];

            $geofenceName = null;
            if (!empty($ev['attributes']) && is_array($ev['attributes'])) {
                $geofenceName = $ev['attributes']['geofenceName'] ?? null;
            }

            $lat = isset($position['latitude']) ? (float)$position['latitude'] : (isset($fallbackPosition['latitude']) ? (float)$fallbackPosition['latitude'] : null);
            $lon = isset($position['longitude']) ? (float)$position['longitude'] : (isset($fallbackPosition['longitude']) ? (float)$fallbackPosition['longitude'] : null);

            $bestAddress = gps_pick_best_address(
                $position['address'] ?? null,
                $lat,
                $lon,
                $fallbackPosition['address'] ?? null
            );

            if (!$bestAddress && $lat !== null && $lon !== null) {
                $bestAddress = 'Współrzędne: ' . number_format($lat, 5, '.', '') . ', ' . number_format($lon, 5, '.', '');
            }

            $result[] = [
                'id' => $ev['id'] ?? null,
                'deviceId' => $deviceId,
                'deviceName' => $device['name'] ?? ('Urządzenie #' . $deviceId),
                'type' => $ev['type'] ?? '',
                'eventTime' => $ev['eventTime'] ?? null,
                'endTime' => null,
                'serverTime' => $ev['serverTime'] ?? null,
                'positionId' => $ev['positionId'] ?? null,
                'latitude' => $lat,
                'longitude' => $lon,
                'address' => $bestAddress,
                'durationSeconds' => 0,
                'geofenceName' => $geofenceName,
                'attributes' => $ev['attributes'] ?? new stdClass(),
            ];
        }
    }

    json_ok(['ok' => true, 'events' => $result]);
} catch (Throwable $e) {
    json_err('gps_events_failed', 500, ['details' => $e->getMessage()]);
}
