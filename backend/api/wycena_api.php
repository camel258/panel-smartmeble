<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/wycena_guard.php';
require_once __DIR__ . '/../lib/wycena_data.php';
header('Content-Type: application/json; charset=utf-8');

$me = wycena_check_access();
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);

try{
  wq_ensure_schema($pdo);

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $action = trim((string)($_GET['action'] ?? 'list'));

    if($action === 'list'){
      $rows = $pdo->query("SELECT q.id, q.quote_date, q.client_first_name, q.client_last_name, q.source, q.total_value, q.created_by_name, q.linked_order_id,
          (SELECT GROUP_CONCAT(f.name ORDER BY f.sort_order, f.id SEPARATOR ', ') FROM wycena_furniture f WHERE f.quote_id=q.id) AS furniture_names
        FROM wycena_quotes q
        ORDER BY q.quote_date DESC, q.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
      json_ok(['ok'=>true,'items'=>$rows]);
    }

    if($action === 'get'){
      $id = (int)($_GET['id'] ?? 0);
      if($id<=0) json_err('bad_request',422);
      $q = wq_quote_full($pdo, $id);
      if(!$q) json_err('not_found',404);
      json_ok(['ok'=>true,'quote'=>$q]);
    }

    if($action === 'find_by_order'){
      $orderId = (int)($_GET['order_id'] ?? 0);
      if($orderId<=0) json_err('bad_request',422);
      $st = $pdo->prepare("SELECT id FROM wycena_quotes WHERE linked_order_id=? LIMIT 1");
      $st->execute([$orderId]);
      json_ok(['ok'=>true,'quote_id'=>(int)($st->fetchColumn() ?: 0)]);
    }

    json_err('unsupported_action',400);
  }

  $body = wq_req();
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'create'){
    $name = wq_user_name($me, $uid);
    $pdo->prepare("INSERT INTO wycena_quotes(quote_date, created_by_name, created_by_id) VALUES(CURDATE(),?,?)")->execute([$name, $uid]);
    json_ok(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'save_client'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $pdo->prepare("UPDATE wycena_quotes SET quote_date=?, client_first_name=?, client_last_name=?, client_phone=?, client_email=?, install_street=?, install_no=?, install_postcode=?, install_city=?, source=? WHERE id=?")
      ->execute([
        $body['quote_date'] ?: null,
        trim((string)($body['client_first_name'] ?? '')),
        trim((string)($body['client_last_name'] ?? '')),
        trim((string)($body['client_phone'] ?? '')),
        trim((string)($body['client_email'] ?? '')),
        trim((string)($body['install_street'] ?? '')),
        trim((string)($body['install_no'] ?? '')),
        trim((string)($body['install_postcode'] ?? '')),
        trim((string)($body['install_city'] ?? '')),
        trim((string)($body['source'] ?? '')),
        $id
      ]);
    json_ok(['ok'=>true]);
  }

  if($action === 'delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $fst = $pdo->prepare("SELECT id FROM wycena_furniture WHERE quote_id=?");
    $fst->execute([$id]);
    $fids = array_map(function($r){ return (int)$r['id']; }, $fst->fetchAll(PDO::FETCH_ASSOC));
    if($fids){
      $in = implode(',', array_fill(0,count($fids),'?'));
      $pdo->prepare("DELETE FROM wycena_furniture_materials WHERE furniture_id IN ($in)")->execute($fids);
      $pdo->prepare("DELETE FROM wycena_furniture_specs WHERE furniture_id IN ($in)")->execute($fids);
    }
    $pdo->prepare("DELETE FROM wycena_furniture WHERE quote_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM wycena_quotes WHERE id=?")->execute([$id]);
    json_ok(['ok'=>true]);
  }

  if($action === 'furniture_add'){
    $quoteId = (int)($body['quote_id'] ?? 0);
    $name = trim((string)($body['name'] ?? 'Nowy mebel'));
    if($quoteId<=0) json_err('bad_request',422);
    $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM wycena_furniture WHERE quote_id=?");
    $ord->execute([$quoteId]);
    $sort = (int)$ord->fetchColumn();
    $pdo->prepare("INSERT INTO wycena_furniture(quote_id,name,sort_order) VALUES(?,?,?)")->execute([$quoteId, $name ?: 'Nowy mebel', $sort]);
    json_ok(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'furniture_update'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $name = trim((string)($body['name'] ?? ''));
    $multiplier = (float)($body['multiplier'] ?? 2.3);
    $discount = (float)($body['discount_amount'] ?? 0);
    $mode = trim((string)($body['pricing_mode'] ?? 'materials'));
    if(!in_array($mode, ['materials','fixed'], true)) $mode = 'materials';
    $fixedPrice = (float)($body['fixed_price'] ?? 0);
    $pdo->prepare("UPDATE wycena_furniture SET name=?, multiplier=?, discount_amount=?, pricing_mode=?, fixed_price=? WHERE id=?")->execute([$name, $multiplier, $discount, $mode, $fixedPrice, $id]);
    wq_recalc_furniture($pdo, $id);
    $quoteId = wq_furniture_quote_id($pdo, $id);
    if($quoteId) wq_recalc_quote($pdo, $quoteId);
    json_ok(['ok'=>true]);
  }

  if($action === 'furniture_delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $quoteId = wq_furniture_quote_id($pdo, $id);
    $pdo->prepare("DELETE FROM wycena_furniture_materials WHERE furniture_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM wycena_furniture_specs WHERE furniture_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM wycena_furniture WHERE id=?")->execute([$id]);
    if($quoteId) wq_recalc_quote($pdo, $quoteId);
    json_ok(['ok'=>true]);
  }

  if($action === 'material_add'){
    $furnitureId = (int)($body['furniture_id'] ?? 0);
    if($furnitureId<=0) json_err('bad_request',422);
    $materialId = !empty($body['material_id']) ? (int)$body['material_id'] : null;
    $name = trim((string)($body['material_name'] ?? ''));
    $unit = trim((string)($body['unit'] ?? 'm2')) ?: 'm2';
    $unitPrice = (float)($body['unit_price'] ?? 0);
    $qty = (float)($body['quantity'] ?? 0);
    if($materialId){
      $mst = $pdo->prepare("SELECT name, unit, unit_price FROM wycena_materials WHERE id=?");
      $mst->execute([$materialId]);
      $mrow = $mst->fetch(PDO::FETCH_ASSOC);
      if($mrow){
        if($name==='') $name = $mrow['name'];
        if(empty($body['unit'])) $unit = $mrow['unit'];
        if(!isset($body['unit_price'])) $unitPrice = (float)$mrow['unit_price'];
      }
    }
    if($name==='') $name = 'Nowy materiał';
    $lineTotal = round($unitPrice * $qty, 2);
    $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM wycena_furniture_materials WHERE furniture_id=?");
    $ord->execute([$furnitureId]);
    $sort = (int)$ord->fetchColumn();
    $pdo->prepare("INSERT INTO wycena_furniture_materials(furniture_id,material_id,material_name,unit,unit_price,quantity,line_total,sort_order) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([$furnitureId, $materialId, $name, $unit, $unitPrice, $qty, $lineTotal, $sort]);
    wq_recalc_furniture($pdo, $furnitureId);
    $quoteId = wq_furniture_quote_id($pdo, $furnitureId);
    if($quoteId) wq_recalc_quote($pdo, $quoteId);
    json_ok(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'material_update'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT furniture_id FROM wycena_furniture_materials WHERE id=?");
    $st->execute([$id]);
    $furnitureId = (int)$st->fetchColumn();
    if(!$furnitureId) json_err('not_found',404);
    $unitPrice = (float)($body['unit_price'] ?? 0);
    $qty = (float)($body['quantity'] ?? 0);
    $lineTotal = round($unitPrice * $qty, 2);
    if(array_key_exists('material_name', $body) || array_key_exists('material_id', $body)){
      $materialId = !empty($body['material_id']) ? (int)$body['material_id'] : null;
      $name = trim((string)($body['material_name'] ?? ''));
      $unit = null;
      if($materialId){
        $mst = $pdo->prepare("SELECT name, unit FROM wycena_materials WHERE id=?");
        $mst->execute([$materialId]);
        $mrow = $mst->fetch(PDO::FETCH_ASSOC);
        if($mrow){ if($name==='') $name = $mrow['name']; $unit = $mrow['unit']; }
      }
      if($unit !== null){
        $pdo->prepare("UPDATE wycena_furniture_materials SET material_id=?, material_name=?, unit=?, unit_price=?, quantity=?, line_total=? WHERE id=?")->execute([$materialId, $name, $unit, $unitPrice, $qty, $lineTotal, $id]);
      } else {
        $pdo->prepare("UPDATE wycena_furniture_materials SET material_id=?, material_name=?, unit_price=?, quantity=?, line_total=? WHERE id=?")->execute([$materialId, $name, $unitPrice, $qty, $lineTotal, $id]);
      }
    } else {
      $pdo->prepare("UPDATE wycena_furniture_materials SET unit_price=?, quantity=?, line_total=? WHERE id=?")->execute([$unitPrice, $qty, $lineTotal, $id]);
    }
    wq_recalc_furniture($pdo, $furnitureId);
    $quoteId = wq_furniture_quote_id($pdo, $furnitureId);
    if($quoteId) wq_recalc_quote($pdo, $quoteId);
    json_ok(['ok'=>true]);
  }

  if($action === 'material_delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $st = $pdo->prepare("SELECT furniture_id FROM wycena_furniture_materials WHERE id=?");
    $st->execute([$id]);
    $furnitureId = (int)$st->fetchColumn();
    $pdo->prepare("DELETE FROM wycena_furniture_materials WHERE id=?")->execute([$id]);
    if($furnitureId){
      wq_recalc_furniture($pdo, $furnitureId);
      $quoteId = wq_furniture_quote_id($pdo, $furnitureId);
      if($quoteId) wq_recalc_quote($pdo, $quoteId);
    }
    json_ok(['ok'=>true]);
  }

  if($action === 'spec_add'){
    $furnitureId = (int)($body['furniture_id'] ?? 0);
    $text = trim((string)($body['text'] ?? ''));
    if($furnitureId<=0 || $text==='') json_err('bad_request',422);
    $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM wycena_furniture_specs WHERE furniture_id=?");
    $ord->execute([$furnitureId]);
    $sort = (int)$ord->fetchColumn();
    $pdo->prepare("INSERT INTO wycena_furniture_specs(furniture_id,text,sort_order) VALUES(?,?,?)")->execute([$furnitureId, $text, $sort]);
    json_ok(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
  }

  if($action === 'spec_update'){
    $id = (int)($body['id'] ?? 0);
    $text = trim((string)($body['text'] ?? ''));
    if($id<=0 || $text==='') json_err('bad_request',422);
    $pdo->prepare("UPDATE wycena_furniture_specs SET text=? WHERE id=?")->execute([$text, $id]);
    json_ok(['ok'=>true]);
  }

  if($action === 'spec_delete'){
    $id = (int)($body['id'] ?? 0);
    if($id<=0) json_err('bad_request',422);
    $pdo->prepare("DELETE FROM wycena_furniture_specs WHERE id=?")->execute([$id]);
    json_ok(['ok'=>true]);
  }

  if($action === 'link_order'){
    $quoteId = (int)($body['quote_id'] ?? 0);
    $orderId = (int)($body['order_id'] ?? 0);
    if($quoteId<=0 || $orderId<=0) json_err('bad_request',422);
    $pdo->prepare("UPDATE wycena_quotes SET linked_order_id=? WHERE id=?")->execute([$orderId, $quoteId]);
    $pdo->prepare("UPDATE orders SET linked_quote_id=? WHERE id=?")->execute([$quoteId, $orderId]);
    json_ok(['ok'=>true]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
