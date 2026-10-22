<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/contract_signers.php';
header('Content-Type: application/json; charset=utf-8');

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$roleId = (int)($me['role_id'] ?? ($me['role'] ?? 0));

try{
  cs_ensure($pdo);

  if($_SERVER['REQUEST_METHOD']==='GET'){
    $rows = $pdo->query("SELECT id, full_name, is_active FROM contract_signers WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r){ $r['id']=(int)$r['id']; $r['initials']=cs_initials((string)$r['full_name']); }
    unset($r);
    json_ok(['ok'=>true,'items'=>$rows]);
  }

  if(!in_array($roleId,[1,999],true)) json_err('forbidden',403);
  $raw = file_get_contents('php://input'); $body = json_decode($raw,true); if(!is_array($body)) $body = $_POST;
  $action = trim((string)($body['action'] ?? ''));

  if($action === 'add'){
    $name = trim(preg_replace('/\s+/u',' ',(string)($body['full_name'] ?? '')));
    if($name === '' || mb_strlen($name) > 190) json_err('bad_request',422,['details'=>'Podaj imię i nazwisko.']);
    $st = $pdo->prepare("SELECT id FROM contract_signers WHERE full_name=? LIMIT 1");
    $st->execute([$name]);
    $existing = (int)($st->fetchColumn() ?: 0);
    if($existing){
      $pdo->prepare("UPDATE contract_signers SET is_active=1 WHERE id=?")->execute([$existing]);
      json_ok(['ok'=>true,'id'=>$existing]);
    }
    $ord = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM contract_signers")->fetchColumn();
    $pdo->prepare("INSERT INTO contract_signers(full_name, sort_order) VALUES(?,?)")->execute([$name, $ord]);
    json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  json_err('unsupported_action',400);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
