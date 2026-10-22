<?php
// config/config.php - production loader

// Strefa czasowa PHP jak na home.pl (date('Y-m-d H:i:s') zapisywane do bazy).
date_default_timezone_set('Europe/Warsaw');

function env_load() {
  static $loaded = false, $vars = [];
  if ($loaded) return $vars;
  $path = __DIR__ . '/.env';
  if (!file_exists($path)) $path = __DIR__ . '/../.env.example';
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (trim($line)==='' || str_starts_with(trim($line),'#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k,$v] = array_map('trim', explode('=', $line, 2));
    $vars[$k] = $v;
  }
  $loaded = true;
  return $vars;
}
function envv($key,$default=null){ $v = env_load(); return $v[$key] ?? $default; }

// DB
function db(){
  static $pdo=null;
  if($pdo) return $pdo;
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', envv('DB_HOST','localhost'), (int)envv('DB_PORT','3306'), envv('DB_NAME','smartmeble'), envv('DB_CHARSET','utf8mb4'));
  try {
    $pdo = new PDO($dsn, envv('DB_USER','root'), envv('DB_PASS',''), [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Jak na home.pl: czas polski w NOW()/CURRENT_TIMESTAMP i łagodny tryb SQL (VPS: MySQL 8.4 ścisły, strefa UTC).
    // Przesunięcie liczone z PHP przy połączeniu – działa bez tabel stref w MySQL i uwzględnia zmianę czasu.
    $off = (new DateTime('now', new DateTimeZone(envv('APP_TZ', 'Europe/Warsaw'))))->format('P');
    $pdo->exec("SET time_zone = '" . $off . "', sql_mode = '" . preg_replace('/[^A-Z_,]/', '', (string)envv('DB_SQL_MODE', 'NO_UNSIGNED_SUBTRACTION')) . "'");
  } catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[panel] db_connect_error: ' . $e->getMessage());   // szczegóły tylko w logu serwera
    echo json_encode(['error'=>'db_connect_error']);
    exit;
  }
  return $pdo;
}

// JSON helpers
function json_ok($data=[], $code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }
function json_err($msg='error',$code=400,$extra=[]){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['error'=>$msg],$extra)); exit; }

// CORS (same origin recommended in production)
header('Access-Control-Allow-Origin: ' . envv('BASE_URL','*'));
header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ exit; }

// Minimal JWT (HS256). Klucz APP_KEY musi być długi i losowy – bez niego panel nie wystawia ani nie przyjmuje tokenów.
function app_key(){
  $k = (string)envv('APP_KEY','');
  if (strlen($k) < 32 || $k === 'please_replace_with_long_random_string' || $k === 'changeme') {
    error_log('[panel] APP_KEY nie jest ustawiony (min. 32 znaki)');
    json_err('server_misconfigured', 500);
  }
  return $k;
}
function b64url($data){ return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function jwt_sign($payload){
  $header = ['alg'=>'HS256','typ'=>'JWT'];
  $segments = [ b64url(json_encode($header)), b64url(json_encode($payload)) ];
  $sig = hash_hmac('sha256', implode('.', $segments), app_key(), true);
  $segments[] = b64url($sig);
  return implode('.', $segments);
}
function jwt_verify($jwt){
  $parts = explode('.', $jwt);
  if(count($parts)!==3) return null;
  [$h,$p,$s] = $parts;
  $calc = b64url(hash_hmac('sha256', "$h.$p", app_key(), true));
  if(!hash_equals($calc, $s)) return null;
  $payload = json_decode(base64_decode(strtr($p,'-_','+/')), true);
  if(!$payload) return null;
  if(empty($payload['exp']) || time() > (int)$payload['exp']) return null;   // token bez daty ważności nie jest przyjmowany
  return $payload;
}
/** Token logowania: podpisany JWT z datą ważności (sekundy). */
function jwt_issue(array $claims, int $ttl = 7200){
  $now = time();
  return jwt_sign(array_merge($claims, ['iat' => $now, 'exp' => $now + $ttl]));
}
function auth_user() {
    $h = null;

    // 🔹 1. Standardowy nagłówek PHP
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
    }

    // 🔹 2. Dla serwerów z Apache (np. home.pl)
    elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') == 0) {
                $h = $value;
                break;
            }
        }
    }

    // 🔹 3. Dla serwerów CGI/FastCGI
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // Jeśli brak nagłówka Bearer, spróbuj cookie 'jwt' (home.pl czasem ucina Authorization)
    $jwt = null;
    if ($h && str_starts_with($h, 'Bearer ')) {
        $jwt = substr($h, 7);
    } elseif (!empty($_COOKIE['jwt'])) {
        $jwt = (string)$_COOKIE['jwt'];
        // jeśli ktoś zapisał cookie jako "Bearer xxx"
        if (str_starts_with($jwt, 'Bearer ')) $jwt = substr($jwt, 7);
    } else {
        return null;
    }

// 🔸 4. Najpierw spróbuj klasyczny JWT
    if (function_exists('jwt_verify')) {
        try {
            $u = jwt_verify($jwt);
            if ($u) return $u;
        } catch (Throwable $e) {
            // Ignorujemy błędy JWT i próbujemy Base64
        }
    }

    // 🔸 5. (usunięte 2026-10-22) Niepodpisany token Base64 JSON NIE jest przyjmowany – można go było podrobić.

    // 🔸 6. Jeśli nic nie przeszło — nieautoryzowany
    return null;
}

// Field-level permissions
function can_view_field($userId, $field) {
  $pdo = db();
  $stmt=$pdo->prepare("SELECT allow_view FROM user_field_permissions WHERE user_id=? AND field_code=?");
  $stmt->execute([$userId,$field]);
  $r=$stmt->fetch();
  if($r) return (bool)$r['allow_view'];
  $stmt=$pdo->prepare("SELECT rfp.allow_view FROM role_field_permissions rfp JOIN users u ON u.role_id=rfp.role_id WHERE u.id=? AND rfp.field_code=?");
  $stmt->execute([$userId,$field]);
  $r=$stmt->fetch();
  return $r ? (bool)$r['allow_view'] : true;
}
function can_edit_field($userId, $field) {
  $pdo = db();
  $stmt=$pdo->prepare("SELECT allow_edit FROM user_field_permissions WHERE user_id=? AND field_code=?");
  $stmt->execute([$userId,$field]);
  $r=$stmt->fetch();
  if($r) return (bool)$r['allow_edit'];
  $stmt=$pdo->prepare("SELECT rfp.allow_edit FROM role_field_permissions rfp JOIN users u ON u.role_id=rfp.role_id WHERE u.id=? AND rfp.field_code=?");
  $stmt->execute([$userId,$field]);
  $r=$stmt->fetch();
  return $r ? (bool)$r['allow_edit'] : true;
}

// Wysyłka przez SMTP (dane w config/.env: SMTP_HOST/PORT/USER/PASS). Na VPS nie ma serwera pocztowego,
// więc zamiast mail() używamy PHPMailer (pakiet libphp-phpmailer); bez niego – awaryjnie mail().
function smtp_send($to, $from, $fromName, $subject, $html){
  $auto = '/usr/share/php/libphp-phpmailer/autoload.php';
  if (!is_file($auto) || envv('SMTP_HOST','') === '') {
    $hdr = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\nFrom: ".$fromName." <".$from.">\r\n";
    return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', quoted_printable_encode($html), $hdr);
  }
  require_once $auto;
  try {
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP();
    $m->Host = envv('SMTP_HOST');
    $m->Port = (int)envv('SMTP_PORT', 587);
    $m->SMTPAuth = true;
    $m->Username = envv('SMTP_USER');
    $m->Password = envv('SMTP_PASS');
    $m->SMTPSecure = ((int)envv('SMTP_PORT', 587) === 465) ? 'ssl' : 'tls';
    $m->CharSet = 'UTF-8';
    $m->setFrom(envv('SMTP_FROM', $from), $fromName);
    foreach (array_filter(array_map('trim', explode(',', (string)$to))) as $addr) $m->addAddress($addr);
    $m->isHTML(true);
    $m->Subject = $subject;
    $m->Body = $html;
    return $m->send();
  } catch (Throwable $e) {
    error_log('[panel] smtp: ' . $e->getMessage());
    return false;
  }
}

// Mail
function send_mail($to,$subject,$html){
  $from = envv('SMTP_FROM','powiadomienia@smartmeble.pl');
  $fromName = envv('SMTP_FROM_NAME','Smart Meble');
  $headers = "MIME-Version: 1.0\r\n";
  $headers.= "Content-type: text/html; charset=UTF-8\r\n";
  $headers.= "Content-Transfer-Encoding: quoted-printable\r\n";
  $headers.= "From: ".$fromName." <".$from.">\r\n";
  $ok = smtp_send($to, $from, $fromName, $subject, $html);
  try{
    $pdo=db();
    $stmt=$pdo->prepare("INSERT INTO email_log (recipient, subject, body, sent_ok) VALUES (?,?,?,?)");
    $stmt->execute([$to,$subject,$html,$ok?1:0]);
  }catch(Throwable $e){}
  return $ok;
}
?>
