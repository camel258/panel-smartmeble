<?php
// Generuje "Załącznik nr 1 - Specyfikacja prac stolarskich" jako prawdziwy plik .docx,
// bez żadnych zewnętrznych bibliotek PHP - korzysta z gotowego szkieletu pliku Word
// (backend/lib/wycena_docx/skeleton) i dokleja wygenerowaną treść do word/document.xml.

function wq_docx_xml_escape(string $s): string {
  return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function wq_docx_money(float $v): string {
  $v = round($v, 2);
  if(abs($v - round($v)) < 0.005){
    return number_format($v, 0, ',', ' ').' zł';
  }
  return number_format($v, 2, ',', ' ').' zł';
}

function wq_docx_plain_p(string $text, string $spacingXml = '', bool $bold = false, bool $center = false, int $size = 22): string {
  $rPr = ($bold ? '<w:b/><w:bCs/>' : '').'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>';
  $jc = $center ? '<w:jc w:val="center"/>' : '';
  $pPr = ($spacingXml || $jc) ? ('<w:pPr>'.$spacingXml.$jc.'</w:pPr>') : '';
  return '<w:p>'.$pPr.'<w:r><w:rPr>'.$rPr.'</w:rPr><w:t xml:space="preserve">'.wq_docx_xml_escape($text).'</w:t></w:r></w:p>';
}

function wq_docx_bullet_p(string $text, int $size = 22): string {
  return '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr>'
    .'<w:r><w:rPr><w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.wq_docx_xml_escape($text).'</w:t></w:r></w:p>';
}

function wq_docx_build_body(array $quote): string {
  $body = wq_docx_plain_p('Załącznik NR 1');
  $body .= wq_docx_plain_p('Specyfikacja prac stolarskich', '<w:spacing w:before="200" w:after="300"/>', false, true, 40);

  foreach(($quote['furniture'] ?? []) as $f){
    $name = trim((string)($f['name'] ?? '')) ?: 'Bez nazwy';
    $body .= wq_docx_plain_p($name.':', '<w:spacing w:before="240" w:after="80"/>', true);
    foreach(($f['specs'] ?? []) as $s){
      $text = trim((string)($s['text'] ?? ''));
      if($text === '') continue;
      $body .= wq_docx_bullet_p($text);
    }
    $body .= wq_docx_plain_p(wq_docx_money((float)($f['final_price'] ?? 0)), '<w:spacing w:before="100" w:after="200"/>');
  }

  if(empty($quote['furniture'])){
    $body .= wq_docx_plain_p('Brak pozycji w wycenie.', '<w:spacing w:before="200"/>');
  }

  return $body;
}

// Zwraca binarną zawartość gotowego pliku .docx.
function wq_generate_specyfikacja_docx(array $quote): string {
  if(!class_exists('ZipArchive')){
    throw new RuntimeException('Rozszerzenie PHP ZipArchive jest wymagane do wygenerowania pliku Word.');
  }
  $skelDir = __DIR__ . '/wycena_docx/skeleton';
  $headXml = file_get_contents(__DIR__ . '/wycena_docx/doc_head.xml');
  $tailXml = file_get_contents(__DIR__ . '/wycena_docx/doc_tail.xml');
  if($headXml === false || $tailXml === false){
    throw new RuntimeException('Brak plików szablonu specyfikacji.');
  }

  $documentXml = $headXml . wq_docx_build_body($quote) . $tailXml;

  $tmpFile = tempnam(sys_get_temp_dir(), 'wq_docx_');
  $zip = new ZipArchive();
  if($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true){
    throw new RuntimeException('Nie udało się utworzyć pliku docx.');
  }

  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skelDir, FilesystemIterator::SKIP_DOTS));
  foreach($iterator as $file){
    if($file->isDir()) continue;
    $localPath = str_replace('\\', '/', substr($file->getPathname(), strlen($skelDir) + 1));
    $zip->addFile($file->getPathname(), $localPath);
  }
  $zip->addFromString('word/document.xml', $documentXml);
  $zip->close();

  $content = file_get_contents($tmpFile);
  @unlink($tmpFile);
  if($content === false){
    throw new RuntimeException('Nie udało się odczytać wygenerowanego pliku docx.');
  }
  return $content;
}
