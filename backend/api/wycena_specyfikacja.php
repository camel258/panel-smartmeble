<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/wycena_guard.php';
require_once __DIR__ . '/../lib/wycena_data.php';
require_once __DIR__ . '/../lib/wycena_docx.php';

$me = wycena_check_access();
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'bad_request']); exit; }

try{
  wq_ensure_schema($pdo);
  $quote = wq_quote_full($pdo, $id);
  if(!$quote){ http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

  $docx = wq_generate_specyfikacja_docx($quote);

  $clientName = trim(($quote['client_last_name'] ?? '').'_'.($quote['client_first_name'] ?? ''));
  $clientName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $clientName);
  $filename = 'Specyfikacja_'.($clientName !== '_' && $clientName !== '' ? $clientName.'_' : '').'wycena_'.$id.'.docx';

  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Content-Length: '.strlen($docx));
  echo $docx;
}catch(Throwable $e){
  http_response_code(500);
  header('Content-Type: application/json');
  error_log('panel API wycena_specyfikacja: '.$e->getMessage());
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
