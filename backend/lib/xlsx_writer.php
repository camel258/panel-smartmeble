<?php
// Minimalny zapis pliku .xlsx (jeden arkusz) bez zewnętrznych bibliotek – wymaga rozszerzenia zip.
// Komórka: string | int/float | null albo ['v'=>wartość, 't'=>'date'|'money'|'text'].
// Styl wiersza nagłówka: pierwszy wiersz; $opts: widths (szerokości kolumn), bold_last (pogrubiony ostatni wiersz – suma).

function xlsx_col(int $i): string {
  $s = '';
  for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
  return $s;
}

function xlsx_esc(string $s): string {
  $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
  return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_cell(string $ref, $cell, bool $bold): string {
  $t = is_array($cell) ? ($cell['t'] ?? 'text') : null;
  $v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
  if ($v === null || $v === '') return $bold ? '<c r="' . $ref . '" s="1"/>' : '';
  if ($t === 'date') {
    $ts = strtotime(substr((string)$v, 0, 10) . ' 00:00:00 UTC');
    if ($ts !== false) return '<c r="' . $ref . '" s="' . ($bold ? 1 : 2) . '"><v>' . ($ts / 86400 + 25569) . '</v></c>';
    $t = 'text';
  }
  if ($t === 'money' || ($t === null && (is_int($v) || is_float($v)))) {
    $s = $t === 'money' ? ($bold ? 4 : 3) : ($bold ? 1 : 0);
    return '<c r="' . $ref . '" s="' . $s . '"><v>' . (0 + $v) . '</v></c>';
  }
  return '<c r="' . $ref . '" t="inlineStr"' . ($bold ? ' s="1"' : '') . '><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
}

/** Zwraca zawartość pliku .xlsx jako string. */
function xlsx_build(string $sheetName, array $rows, array $opts = []): string {
  $sheetName = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', $sheetName), 0, 31) ?: 'Arkusz1';
  $nCols = 0; foreach ($rows as $r) $nCols = max($nCols, count($r));
  $nRows = count($rows);
  $last = $nRows - 1;
  $xml = '';
  foreach (array_values($rows) as $ri => $r) {
    $bold = $ri === 0 || (!empty($opts['bold_last']) && $ri === $last);
    $xml .= '<row r="' . ($ri + 1) . '">';
    foreach (array_values($r) as $ci => $cell) $xml .= xlsx_cell(xlsx_col($ci) . ($ri + 1), $cell, $bold);
    $xml .= '</row>';
  }
  $cols = '';
  foreach (($opts['widths'] ?? []) as $ci => $w) $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
  $lastRef = xlsx_col(max(0, $nCols - 1)) . max(1, $nRows - (!empty($opts['bold_last']) ? 1 : 0));
  $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . ($cols ? '<cols>' . $cols . '</cols>' : '')
    . '<sheetData>' . $xml . '</sheetData>'
    . ($nRows > 1 ? '<autoFilter ref="A1:' . $lastRef . '"/>' : '')
    . '</worksheet>';
  $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="#,##0.00\ &quot;zł&quot;"/></numFmts>'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="165" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
    . '</cellXfs></styleSheet>';
  $files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="' . xlsx_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
      . ($nRows > 1 ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . xlsx_esc(str_replace("'", "''", $sheetName)) . '\'!$A$1:$' . preg_replace('/(\d+)$/', '\$$1', $lastRef) . '</definedName></definedNames>' : '')
      . '</workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      . '</Relationships>',
    'xl/worksheets/sheet1.xml' => $sheet,
    'xl/styles.xml' => $styles,
  ];
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('zip_open');
  foreach ($files as $name => $content) $zip->addFromString($name, $content);
  $zip->close();
  $data = (string)file_get_contents($tmp);
  @unlink($tmp);
  return $data;
}
