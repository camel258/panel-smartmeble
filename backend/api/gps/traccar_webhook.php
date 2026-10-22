<?php
require_once __DIR__ . '/common.php';
try {
    $cfg = gps_config();
    $incomingToken = $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
    if ($incomingToken !== (string)($cfg['security']['webhook_token'] ?? '')) {
        gps_json_err('unauthorized', 401);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) gps_json_err('invalid_json', 400);

    $pdo = gps_pdo();
    $event = $payload['event'] ?? $payload;
    $device = $payload['device'] ?? [];
    $position = $payload['position'] ?? [];

    $traccarEventId = isset($event['id']) ? (int)$event['id'] : null;
    if ($traccarEventId && gps_event_exists($pdo, $traccarEventId)) {
        gps_json_ok(['success'=>true, 'message'=>'Already processed']);
    }

    $deviceId = (int)($event['deviceId'] ?? ($device['id'] ?? 0));
    if ($deviceId <= 0) gps_json_err('missing_device_id', 400);
    if ($device) gps_upsert_device($pdo, $device, $position ?: null);

    $vehicleName = $device ? gps_device_name($device) : ('Urządzenie #' . $deviceId);
    $eventType = (string)($event['type'] ?? 'unknown');
    $eventTime = isset($event['eventTime']) ? date('Y-m-d H:i:s', strtotime($event['eventTime'])) : gps_now();

    gps_insert_event($pdo, [
        'traccar_event_id' => $traccarEventId,
        'traccar_device_id' => $deviceId,
        'event_type' => $eventType,
        'event_time' => $eventTime,
        'server_time' => isset($event['serverTime']) ? date('Y-m-d H:i:s', strtotime($event['serverTime'])) : null,
        'latitude' => $position['latitude'] ?? null,
        'longitude' => $position['longitude'] ?? null,
        'address' => $position['address'] ?? null,
        'title' => gps_build_title($eventType, $vehicleName),
        'description' => $position['address'] ?? null,
        'source' => 'webhook',
        'extra_json' => $payload,
    ]);
    gps_json_ok(['success'=>true, 'message'=>'OK']);
} catch (Throwable $e) {
    gps_json_err('gps_webhook_failed', 500, ['details'=>$e->getMessage()]);
}
