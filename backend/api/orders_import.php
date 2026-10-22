<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user();
if (!$me) json_err('unauthorized',401);
$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if (!in_array($role,[1,999],true)) json_err('forbidden',403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('method_not_allowed',405);
if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) json_err('no_file',400);

$pdo = db();

function parse_amount_pl($v){
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return number_format((float)$v, 2, '.', '');
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(["\xc2\xa0", ' '], '', $v);
    // if both separators appear, treat dot as thousands and comma as decimal
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '.', $v);
    }
    if (!is_numeric($v)) return null;
    return number_format((float)$v, 2, '.', '');
}

function excel_serial_to_date($serial){
    $serial = (float)$serial;
    if ($serial <= 0) return null;
    $unix = (int)round(($serial - 25569) * 86400);
    if ($unix <= 0) return null;
    return gmdate('Y-m-d', $unix);
}

function parse_date_any($v){
    if ($v === null) return null;
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    if (is_int($v) || is_float($v)) return excel_serial_to_date($v);
    $v = trim((string)$v);
    if ($v === '') return null;
    // Excel serial stored as string in custom xlsx reader
    if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
        $d = excel_serial_to_date($v);
        if ($d) return $d;
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/',$v,$m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v)) return $v;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function col_letters_to_num($letters){
    $letters = preg_replace('/[^A-Z]/','',$letters);
    $num = 0;
    for($i=0;$i<strlen($letters);$i++) $num = $num*26 + (ord($letters[$i]) - 64);
    return $num;
}

function read_xlsx_rows($path){
    $zip = new ZipArchive();
    if($zip->open($path)!==true) throw new Exception('xlsx_open_failed');
    $shared=[];
    $ss=$zip->getFromName('xl/sharedStrings.xml');
    if($ss!==false){
        $xml=simplexml_load_string($ss);
        if($xml){
            foreach($xml->si as $si){
                if(isset($si->t)) $shared[]=(string)$si->t;
                else {
                    $txt=''; foreach($si->r as $r){ $txt.=(string)$r->t; }
                    $shared[]=$txt;
                }
            }
        }
    }
    $sheetXml=$zip->getFromName('xl/worksheets/sheet1.xml');
    if($sheetXml===false) throw new Exception('sheet1_missing');
    $sheet=simplexml_load_string($sheetXml);
    $rows=[];
    foreach($sheet->sheetData->row as $row){
        $vals=[];
        foreach($row->c as $c){
            $ref=(string)$c['r'];
            preg_match('/([A-Z]+)\d+/', $ref, $m);
            $col=col_letters_to_num($m[1]??'A');
            $type=(string)$c['t'];
            $v='';
            if($type==='s') $v=$shared[(int)$c->v]??'';
            elseif($type==='inlineStr') $v=(string)$c->is->t;
            else $v=(string)$c->v;
            $vals[$col]=$v;
        }
        if($vals) $rows[]=$vals;
    }
    $zip->close();
    return $rows;
}

function getv($row,$map,$keys){
    foreach((array)$keys as $key){
        $idx = $map[$key] ?? null;
        if($idx && array_key_exists($idx,$row)){
            $val = $row[$idx];
            if ($val !== null && trim((string)$val) !== '') return $val;
        }
    }
    return '';
}

try {
    $rows = read_xlsx_rows($_FILES['file']['tmp_name']);
    if (count($rows) < 2) json_err('empty_file',400);

    $headerRow = $rows[0];
    $headers = [];
    foreach($headerRow as $idx=>$val){
        $key = trim((string)$val);
        if ($key !== '') $headers[$key] = $idx;
    }

    $statusMap = [];
    foreach($pdo->query("SELECT id,name FROM order_statuses")->fetchAll(PDO::FETCH_ASSOC) as $r){
        $statusMap[mb_strtolower(trim((string)$r['name']))] = (int)$r['id'];
    }

    $created = 0;
    $errors = [];

    for($i=1;$i<count($rows);$i++){
        $row = $rows[$i];

        $first = trim((string)getv($row,$headers,['client_first_name']));
        $last  = trim((string)getv($row,$headers,['client_last_name']));
        $item  = trim((string)getv($row,$headers,['item_description']));
        if ($first === '' && $last === '' && $item === '') continue;

        try {
            $pdo->beginTransaction();

            $statusName = mb_strtolower(trim((string)getv($row,$headers,['status_name'])));
            $statusId = $statusMap[$statusName] ?? null;

            $state = mb_strtolower(trim((string)getv($row,$headers,['order_state(aktualne|zakonczone|usuniete)','order_state'])));
            $fy = (int)getv($row,$headers,['finished_year']);
            $fm = (int)getv($row,$headers,['finished_month(1-12)','finished_month']);
            $mainAmount = parse_amount_pl(getv($row,$headers,['main_amount_pln'])) ?? '0.00';

            $isFinished = ($state==='zakonczone' || (int)getv($row,$headers,['is_finished'])===1) ? 1 : 0;
            $isDeleted  = ($state==='usuniete' || (int)getv($row,$headers,['is_deleted'])===1) ? 1 : 0;

            if($isFinished && (!$fy || !$fm)){
                $dt = parse_date_any(getv($row,$headers,['finished_at','install_date_new_dd.mm.yyyy','install_date_dd.mm.yyyy'])) ?: date('Y-m-d');
                $fy = (int)date('Y', strtotime($dt));
                $fm = (int)date('n', strtotime($dt));
            }

            $finishedAt = $isFinished ? ((parse_date_any(getv($row,$headers,['finished_at'])) ?: date('Y-m-d')) . ' 00:00:00') : null;
            $deletedAt = $isDeleted ? date('Y-m-d H:i:s') : null;

            $street = trim((string)getv($row,$headers,['install_street']));
            $no = trim((string)getv($row,$headers,['install_no','install_number']));
            $postcode = trim((string)getv($row,$headers,['install_postcode','install_zip']));
            $city = trim((string)getv($row,$headers,['install_city']));

            // Build addons list from componentX_* columns.
            $addons = [];
            $hasMainComponent = false;
            for ($n=1; $n<=20; $n++) {
                $desc = trim((string)getv($row,$headers,["component{$n}_description"]));
                $amt  = parse_amount_pl(getv($row,$headers,["component{$n}_amount_pln"]));
                if ($desc === '' && $amt === null) continue;
                if ($desc === '') $desc = 'Składnik ' . $n;
                $amt = $amt ?? '0.00';
                if (mb_strtolower($desc) === 'zamówienie główne' || mb_strtolower($desc) === 'zamowienie glowne') {
                    $hasMainComponent = true;
                }
                $addons[] = ['description'=>$desc, 'amount'=>$amt];
            }
            if (!$hasMainComponent && (float)$mainAmount > 0) {
                array_unshift($addons, ['description'=>'Zamówienie główne', 'amount'=>$mainAmount]);
            }

            $totalAmount = 0.0;
            if (!empty($addons)) {
                foreach ($addons as $a) $totalAmount += (float)$a['amount'];
            } else {
                $totalAmount = (float)$mainAmount;
            }

            $st = $pdo->prepare("INSERT INTO orders (client_first_name,client_last_name,item_description,client_phone,client_email,install_street,install_no,install_postcode,install_zip,install_city,contract_date,install_date,install_date_new,measure_due_date,status_id,total_amount,is_finished,is_deleted,finished_bucket_month,finished_bucket_year,finished_at,deleted_at,deleted_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $first,
                $last,
                $item,
                trim((string)getv($row,$headers,['client_phone'])),
                trim((string)getv($row,$headers,['client_email'])),
                $street,
                $no,
                $postcode,
                $postcode,
                $city,
                parse_date_any(getv($row,$headers,['contract_date_dd.mm.yyyy','contract_date'])),
                parse_date_any(getv($row,$headers,['install_date_dd.mm.yyyy','install_date'])),
                parse_date_any(getv($row,$headers,['install_date_new_dd.mm.yyyy','install_date_new'])),
                parse_date_any(getv($row,$headers,['measure_due_date_dd.mm.yyyy','measure_due_date'])),
                $statusId,
                number_format($totalAmount,2,'.',''),
                $isFinished,
                $isDeleted,
                ($fm?:null),
                ($fy?:null),
                $finishedAt,
                $deletedAt,
                ($isDeleted ? 'Import' : null)
            ]);

            $orderId = (int)$pdo->lastInsertId();

            // Save addons
            if ($orderId > 0 && !empty($addons)) {
                $insAddon = $pdo->prepare("INSERT INTO order_addons (order_id, description, amount) VALUES (?,?,?)");
                foreach ($addons as $a) {
                    $insAddon->execute([$orderId, $a['description'], $a['amount']]);
                }
            }

            // Save payments
            if ($orderId > 0) {
                $insPayment = $pdo->prepare("INSERT INTO order_payments (order_id, pay_date, amount, description) VALUES (?,?,?,?)");
                for ($n=1; $n<=20; $n++) {
                    $payDate = parse_date_any(getv($row,$headers,["payment{$n}_date_dd.mm.yyyy", "payment{$n}_date"]));
                    $payAmount = parse_amount_pl(getv($row,$headers,["payment{$n}_amount_pln", "payment{$n}_amount"]));
                    $payDesc = trim((string)getv($row,$headers,["payment{$n}_description"]));
                    if (!$payDate && $payAmount === null && $payDesc === '') continue;
                    if ($payAmount === null) $payAmount = '0.00';
                    $insPayment->execute([$orderId, $payDate, $payAmount, $payDesc]);
                }
            }

            $pdo->commit();
            $created++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Wiersz '.($i+1).': '.$e->getMessage();
        }
    }

    json_ok(['ok'=>true,'created'=>$created,'errors'=>$errors]);
} catch (Throwable $e) {
    json_err('import_failed',500,['details'=>$e->getMessage()]);
}
