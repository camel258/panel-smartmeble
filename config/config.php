<?php
// config/config.php - production loader

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
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', envv('DB_HOST','localhost'), envv('DB_NAME','smartmeble'), envv('DB_CHARSET','utf8mb4'));
  try {
    $pdo = new PDO($dsn, envv('DB_USER','root'), envv('DB_PASS',''), [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'db_connect_error','message'=>$e->getMessage()]);
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

// Minimal JWT
function b64url($data){ return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function jwt_sign($payload){
  $header = ['alg'=>'HS256','typ'=>'JWT'];
  $segments = [ b64url(json_encode($header)), b64url(json_encode($payload)) ];
  $sig = hash_hmac('sha256', implode('.', $segments), envv('APP_KEY','changeme'), true);
  $segments[] = b64url($sig);
  return implode('.', $segments);
}
function jwt_verify($jwt){
  $parts = explode('.', $jwt);
  if(count($parts)!==3) return null;
  [$h,$p,$s] = $parts;
  $calc = b64url(hash_hmac('sha256', "$h.$p", envv('APP_KEY','changeme'), true));
  if(!hash_equals($calc, $s)) return null;
  $payload = json_decode(base64_decode(strtr($p,'-_','+/')), true);
  if(!$payload) return null;
  if(isset($payload['exp']) && time() > $payload['exp']) return null;
  return $payload;
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

    // 🔸 5. Jeśli to nie JWT — spróbuj prosty Base64 JSON
    $decoded = base64_decode($jwt, true);
    if ($decoded) {
        $data = json_decode($decoded, true);
        if (is_array($data) && isset($data['uid'])) {
            return $data; // Zwracamy dane użytkownika z tokena
        }
    }

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

// Mail (uses PHP mail(); home.pl relays to SMTP)
function send_mail($to,$subject,$html){
  $from = envv('SMTP_FROM','powiadomienia@smartmeble.pl');
  $fromName = envv('SMTP_FROM_NAME','Smart Meble');
  $headers = "MIME-Version: 1.0\r\n";
  $headers.= "Content-type: text/html; charset=UTF-8\r\n";
  $headers.= "Content-Transfer-Encoding: quoted-printable\r\n";
  $headers.= "From: ".$fromName." <".$from.">\r\n";
  $encodedHtml = quoted_printable_encode($html);
  $ok = mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $encodedHtml, $headers);
  try{
    $pdo=db();
    $stmt=$pdo->prepare("INSERT INTO email_log (recipient, subject, body, sent_ok) VALUES (?,?,?,?)");
    $stmt->execute([$to,$subject,$html,$ok?1:0]);
  }catch(Throwable $e){}
  return $ok;
}
?>
