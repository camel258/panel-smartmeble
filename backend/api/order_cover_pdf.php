<?php
require_once __DIR__ . '/../../config/config.php';
$me = auth_user();
if(!$me){ http_response_code(401); exit('unauthorized'); }
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); exit('bad_request'); }
$st = $pdo->prepare("SELECT client_first_name,client_last_name,item_description,client_phone,client_email FROM orders WHERE id=? LIMIT 1");
$st->execute([$id]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); exit('not_found'); }
function pdf_text($s){
  $s = iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s ?: '');
  return str_replace(['\\','(',')',"\r", "\n"],['\\\\','\\(','\\)', '', ' '],$s);
}
$lines = [
  'SMART MEBLE',
  'Okladka zamowienia',
  '',
  'Klient: '.trim(($o['client_first_name'] ?? '').' '.($o['client_last_name'] ?? '')),
  'Przedmiot: '.($o['item_description'] ?? ''),
  'Telefon: '.($o['client_phone'] ?? ''),
  'Email: '.($o['client_email'] ?? ''),
  '',
  'Nr zamowienia: '.$id,
  'Wygenerowano: '.date('Y-m-d H:i')
];
$stream = "BT\n/F1 22 Tf\n50 780 Td (".pdf_text(array_shift($lines)).") Tj\n";
$stream .= "/F1 16 Tf\n0 -30 Td (".pdf_text(array_shift($lines)).") Tj\n";
$stream .= "/F1 12 Tf\n";
foreach($lines as $line){ $stream .= "0 -24 Td (".pdf_text($line).") Tj\n"; }
$stream .= "ET";
$objs = [];
$objs[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
$objs[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
$objs[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>endobj\n";
$objs[] = "4 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
$objs[] = "5 0 obj<< /Length ".strlen($stream)." >>stream\n{$stream}\nendstream\nendobj\n";
$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach($objs as $obj){ $offsets[] = strlen($pdf); $pdf .= $obj; }
$xrefPos = strlen($pdf);
$pdf .= "xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
for($i=1;$i<=count($objs);$i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
$pdf .= "trailer<< /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="smartmeble_zamowienie_'.$id.'.pdf"');
echo $pdf;
