<?php
require_once __DIR__ . '/common.php';
if (PHP_SAPI !== 'cli') gps_require_access();   // z przeglądarki tylko zalogowany z dostępem do GPS; cron – z wiersza poleceń
try {
    $cfg = gps_config();
    $pdo = gps_pdo();

    $devices = gps_traccar_request('GET', 'devices');
    $positions = gps_traccar_request('GET', 'positions');
    $positionMap = [];
    foreach ($positions as $position) {
        if (isset($position['deviceId'])) $positionMap[(int)$position['deviceId']] = $position;
    }

    foreach ($devices as $device) {
        $deviceId = (int)($device['id'] ?? 0);
        if ($deviceId <= 0) continue;
        $position = $positionMap[$deviceId] ?? null;
        gps_upsert_device($pdo, $device, $position);
        if (!$position) continue;

        $vehicleName = gps_device_name($device);
        $state = gps_get_event_state($pdo, $deviceId);
        $ignition = gps_bool(gps_attr($position, 'ignition', false));
        $motion = gps_bool(gps_attr($position, 'motion', false));
        $address = $position['address'] ?? null;
        $lat = $position['latitude'] ?? null;
        $lon = $position['longitude'] ?? null;
        $eventTime = isset($position['fixTime']) ? date('Y-m-d H:i:s', strtotime($position['fixTime'])) : gps_now();

        if ((int)$state['last_ignition_state'] !== $ignition) {
            gps_insert_event($pdo, [
                'traccar_event_id' => null,
                'traccar_device_id' => $deviceId,
                'event_type' => $ignition ? 'ignitionOn' : 'ignitionOff',
                'event_time' => $eventTime,
                'latitude' => $lat,
                'longitude' => $lon,
                'address' => $address,
                'title' => gps_build_title($ignition ? 'ignitionOn' : 'ignitionOff', $vehicleName),
                'description' => $address,
                'source' => 'sync',
                'extra_json' => ['position' => $position],
            ]);
            $state['last_ignition_state'] = $ignition;
        }

        if ($motion === 0) {
            if (empty($state['stop_started_at'])) {
                $state['stop_started_at'] = $eventTime;
                $state['stop_latitude'] = $lat;
                $state['stop_longitude'] = $lon;
                $state['stop_address'] = $address;
                $state['long_stop_notified_at'] = null;
            } else {
                $stopStartTs = strtotime((string)$state['stop_started_at']);
                $currentTs = strtotime($eventTime);
                $minutes = (int)floor(($currentTs - $stopStartTs) / 60);
                $threshold = (int)($cfg['gps']['long_stop_minutes'] ?? 15);
                if ($minutes >= $threshold && empty($state['long_stop_notified_at'])) {
                    gps_insert_event($pdo, [
                        'traccar_event_id' => null,
                        'traccar_device_id' => $deviceId,
                        'event_type' => 'longStop',
                        'event_time' => $eventTime,
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'address' => $address,
                        'title' => gps_build_title('longStop', $vehicleName, $minutes),
                        'description' => $address,
                        'duration_minutes' => $minutes,
                        'source' => 'sync',
                        'extra_json' => ['position' => $position],
                    ]);
                    $state['long_stop_notified_at'] = $eventTime;
                }
            }
        } else {
            $state['stop_started_at'] = null;
            $state['stop_latitude'] = null;
            $state['stop_longitude'] = null;
            $state['stop_address'] = null;
            $state['long_stop_notified_at'] = null;
        }
        $state['last_motion_state'] = $motion;
        gps_save_event_state($pdo, $deviceId, $state);
    }

    if (PHP_SAPI === 'cli') { echo "OK\n"; exit(0); }
    gps_json_ok(['success'=>true, 'message'=>'Synchronizacja zakończona']);
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
    gps_json_err('gps_sync_failed', 500, ['details'=>$e->getMessage()]);
}
