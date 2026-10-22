<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';

$me = auth_user();
if(!$me) json_err('unauthorized',401);

$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if(!in_array($role,[1,999],true)) json_err('forbidden',403);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? 0);
if($id>0) acc_require_order($pdo, $me, $id); // zewnętrzny technolog – tylko swoje zamówienia
$type = trim((string)($_GET['type'] ?? ''));
$amountRaw = str_replace([' ', ','], ['', '.'], (string)($_GET['amount'] ?? '0'));
$amount = (float)$amountRaw;

if($id <= 0 || !in_array($type, ['receipt','protocol'], true) || $amount <= 0){
  json_err('bad_request',400);
}
if(!class_exists('ZipArchive')){
  json_err('server_error',500,['details'=>'Brak rozszerzenia PHP ZipArchive.']);
}

function xml_escape_doc($v): string {
  return htmlspecialchars((string)$v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}
function safe_filename_part($v): string {
  $v = trim((string)$v);
  $v = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $v);
  $v = preg_replace('/\s+/u', ' ', $v);
  return $v ?: 'Dokument';
}
function money_fmt_pl(float $amount): string {
  return number_format($amount, 2, ',', ' ');
}
function polish_plural(int $n, string $one, string $few, string $many): string {
  $nAbs = abs($n);
  $last = $nAbs % 10;
  $last2 = $nAbs % 100;
  if($nAbs === 1) return $one;
  if($last >= 2 && $last <= 4 && !($last2 >= 12 && $last2 <= 14)) return $few;
  return $many;
}
function number_words_pl_int(int $n): string {
  if($n === 0) return 'zero';
  $ones = ['', 'jeden', 'dwa', 'trzy', 'cztery', 'pięć', 'sześć', 'siedem', 'osiem', 'dziewięć'];
  $teens = ['dziesięć', 'jedenaście', 'dwanaście', 'trzynaście', 'czternaście', 'piętnaście', 'szesnaście', 'siedemnaście', 'osiemnaście', 'dziewiętnaście'];
  $tens = ['', '', 'dwadzieścia', 'trzydzieści', 'czterdzieści', 'pięćdziesiąt', 'sześćdziesiąt', 'siedemdziesiąt', 'osiemdziesiąt', 'dziewięćdziesiąt'];
  $hundreds = ['', 'sto', 'dwieście', 'trzysta', 'czterysta', 'pięćset', 'sześćset', 'siedemset', 'osiemset', 'dziewięćset'];
  $groups = [
    ['', '', ''],
    ['tysiąc', 'tysiące', 'tysięcy'],
    ['milion', 'miliony', 'milionów'],
    ['miliard', 'miliardy', 'miliardów']
  ];
  $parts = [];
  $group = 0;
  while($n > 0){
    $chunk = $n % 1000;
    if($chunk > 0){
      $chunkParts = [];
      $h = intdiv($chunk, 100);
      $r = $chunk % 100;
      if($h) $chunkParts[] = $hundreds[$h];
      if($r >= 10 && $r <= 19){
        $chunkParts[] = $teens[$r-10];
      }else{
        $t = intdiv($r, 10);
        $o = $r % 10;
        if($t) $chunkParts[] = $tens[$t];
        if($o) $chunkParts[] = $ones[$o];
      }
      if($group > 0){
        if($chunk === 1){
          $chunkParts = [$groups[$group][0]];
        }else{
          $chunkParts[] = polish_plural($chunk, $groups[$group][0], $groups[$group][1], $groups[$group][2]);
        }
      }
      array_unshift($parts, implode(' ', array_filter($chunkParts)));
    }
    $n = intdiv($n, 1000);
    $group++;
  }
  return implode(' ', array_filter($parts));
}
function amount_words_pl(float $amount): string {
  $zl = (int)floor($amount + 0.00001);
  $gr = (int)round(($amount - $zl) * 100);
  if($gr === 100){ $zl++; $gr = 0; }
  $zlWord = polish_plural($zl, 'złoty', 'złote', 'złotych');
  $grWord = polish_plural($gr, 'grosz', 'grosze', 'groszy');
  return number_words_pl_int($zl) . ' ' . $zlWord . ' ' . str_pad((string)$gr,2,'0',STR_PAD_LEFT) . '/100 ' . $grWord;
}
function replace_docx_placeholders(string $template, string $out, array $values): void {
  $zip = new ZipArchive();
  if($zip->open($template) !== true) throw new Exception('Nie można otworzyć szablonu DOCX.');
  $new = new ZipArchive();
  if($new->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('Nie można utworzyć dokumentu DOCX.');
  $idx = 0;
  for($i=0; $i<$zip->numFiles; $i++){
    $name = $zip->getNameIndex($i);
    $data = $zip->getFromIndex($i);
    if($data === false) $data = '';
    if(preg_match('/^word\/.*\.xml$/', $name)){
      $data = preg_replace_callback('/(>)(X{5,})(<)/u', function($m) use (&$idx, $values){
        $val = $values[$idx] ?? '';
        $idx++;
        return $m[1] . xml_escape_doc($val) . $m[3];
      }, $data);
    }
    $new->addFromString($name, $data);
  }
  $zip->close();
  $new->close();
}

try{
  $sql = "SELECT o.*, s.name AS status_name
          FROM orders o
          LEFT JOIN order_statuses s ON s.id=o.status_id
          WHERE o.id=? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$id]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if(!$o) json_err('not_found',404);

  $clientName = trim(trim((string)($o['client_first_name'] ?? '')) . ' ' . trim((string)($o['client_last_name'] ?? '')));
  if($clientName === '') $clientName = 'Klient';
  $street = trim((string)($o['client_address'] ?? ''));
  $installStreet = trim((string)($o['install_street'] ?? ''));
  $installNo = trim((string)($o['install_no'] ?? ''));
  if($street === '') $street = trim($installStreet . ($installNo !== '' ? ' ' . $installNo : ''));
  $zipCode = trim((string)(($o['install_zip'] ?? '') !== '' ? $o['install_zip'] : ($o['install_postcode'] ?? '')));
  $cityName = trim((string)($o['install_city'] ?? ''));
  // Pole client_address bywa już pełnym adresem (z kodem i miastem) - nie dopisuj ich drugi raz.
  $street = trim(preg_replace('/\s+,/u', ',', $street));
  $addZip = ($zipCode !== '' && mb_stripos($street, $zipCode) === false) ? $zipCode : '';
  $addCity = ($cityName !== '' && mb_stripos($street, $cityName) === false) ? $cityName : '';
  $zipCity = trim($addZip . ' ' . $addCity);
  $phone = trim((string)($o['client_phone'] ?? ''));
  $email = trim((string)($o['client_email'] ?? ''));
  $clientDataParts = array_filter([$clientName, $street, $zipCity, $phone ? 'tel. '.$phone : '', $email]);
  $clientData = implode(', ', $clientDataParts);

  $item = trim((string)($o['item_description'] ?? ''));
  if($item === '') $item = 'Zabudowy zgodnie z zamówieniem';
  $date = date('d.m.Y');
  $amountFmt = money_fmt_pl($amount);
  $amountWords = amount_words_pl($amount);

  $baseDir = __DIR__ . '/../templates/order_documents';
  if($type === 'receipt'){
    $template = $baseDir . '/pokwitowanie.docx';
    $values = [$date, $date, $clientData, $clientData, $item, $item, $amountFmt, $amountWords];
    $docName = safe_filename_part($clientName) . ' POKWITOWANIE ' . date('Y-m-d') . '.docx';
  }else{
    $template = $baseDir . '/protokol_odbioru.docx';
    $values = [$date, $clientData, $clientData, $amountFmt];
    $docName = safe_filename_part($clientName) . ' PROTOKÓŁ ' . date('Y-m-d') . '.docx';
  }
  if(!is_file($template)) json_err('template_missing',500,['details'=>'Brak szablonu: '.$template]);

  $tmp = tempnam(sys_get_temp_dir(), 'smart_doc_');
  if($tmp === false) json_err('server_error',500,['details'=>'Nie można utworzyć pliku tymczasowego.']);
  $tmpDocx = $tmp . '.docx';
  @unlink($tmp);

  replace_docx_placeholders($template, $tmpDocx, $values);

  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($docName));
  header('Content-Length: ' . filesize($tmpDocx));
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  readfile($tmpDocx);
  @unlink($tmpDocx);
  exit;
}catch(Throwable $e){
  json_err('server_error',500,['details'=>$e->getMessage()]);
}
