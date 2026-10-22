<?php
// Wspólne funkcje kontroli dostępu do modułu "Wycena":
// 1) tylko rola super admina (999), 2) dodatkowo wspólne hasło do modułu,
// potwierdzone tokenem HMAC ważnym dla zalogowanego użytkownika.

function wycena_password(): string {
  // hasło modułu w config/.env (WYCENA_PASSWORD) – nie w kodzie
  $p = (string)envv('WYCENA_PASSWORD', '');
  if ($p === '') json_err('server_misconfigured', 500, ['details' => 'wycena_password_missing']);
  return $p;
}

function wycena_token_for(int $uid): string {
  $secret = app_key();
  return hash_hmac('sha256', 'wycena-access-v1-'.$uid, $secret);
}

// Zwraca dane zalogowanego użytkownika, albo kończy żądanie błędem JSON.
function wycena_check_access(): array {
  $me = auth_user();
  if(!$me) json_err('unauthorized', 401);
  $roleId = (int)($me['role_id'] ?? $me['role'] ?? 0);
  if($roleId !== 999) json_err('forbidden', 403, ['details'=>'super_admin_required']);
  $uid = (int)($me['id'] ?? $me['uid'] ?? 0);
  $token = $_SERVER['HTTP_X_WYCENA_TOKEN'] ?? ($_GET['wtoken'] ?? ($_POST['wtoken'] ?? ''));
  $expected = wycena_token_for($uid);
  if(!is_string($token) || $token === '' || !hash_equals($expected, (string)$token)){
    json_err('forbidden', 403, ['details'=>'wycena_locked']);
  }
  return $me;
}
