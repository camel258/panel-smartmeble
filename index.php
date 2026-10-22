<?php
require_once __DIR__ . '/config/config.php';
$jwt = $_COOKIE['jwt'] ?? null;
$ok = false;
if ($jwt) {
  $payload = jwt_verify($jwt);
  if ($payload && isset($payload['uid'])) $ok = true;
}
header('Location: ' . ($ok ? '/frontend/orders.html' : '/frontend/login.html'));
exit;