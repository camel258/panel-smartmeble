<?php
// SmartMeble Panel – auth_login.php (2026-10-22: podpisany JWT z datą ważności, ciasteczko tylko po HTTPS)
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => 'db_connect_error']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

$email = trim($body['email'] ?? '');
$password = (string)($body['password'] ?? '');
$remember = !empty($body['remember']);

if ($email === '' || $password === '') {
    echo json_encode(['error' => 'invalid_credentials']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, full_name, email, role_id, password_hash, is_active FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int)$user['is_active'] !== 1) {
    echo json_encode(['error' => 'invalid_credentials']);
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    echo json_encode(['error' => 'invalid_credentials']);
    exit;
}

// Tworzymy payload tokena
$claims = [
    'uid'   => (int)$user['id'],
    'role'  => (int)$user['role_id'],
    'email' => $user['email'],
    'name'  => $user['full_name']
];

// Podpisany JWT (config.php) – 7 dni z „zapamiętaj mnie”, inaczej 2 godziny
$ttl = $remember ? 7 * 24 * 60 * 60 : 2 * 60 * 60;
$token = jwt_issue($claims, $ttl);

// Cookie JWT – tylko HTTPS, niedostępne dla JavaScriptu
setcookie(
    'jwt',
    $token,
    [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'domain'   => 'panel.smartmeble.pl',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]
);

// Odpowiedź JSON
echo json_encode([
    'success' => true,
    'user' => [
        'id'    => $user['id'],
        'email' => $user['email'],
        'name'  => $user['full_name'],
        'role'  => $user['role_id']
    ],
    'token' => $token
]);