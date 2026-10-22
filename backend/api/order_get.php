<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');

function sm_uid(array $me): int {
  foreach(['id','uid','user_id'] as $k){ $v=(int)($me[$k]??0); if($v>0) return $v; }
  return 0;
}
function sm_role_name(PDO $pdo, array $me): string {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  if($rid<=0) return '';
  try{
    $st=$pdo->prepare("SELECT name FROM roles WHERE id=? LIMIT 1");
    $st->execute([$rid]);
    return mb_strtolower(trim((string)$st->fetchColumn()), 'UTF-8');
  }catch(Throwable $e){ return ''; }
}
function sm_is_external_technologist(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return (strpos($n,'technolog')!==false && (strpos($n,'zewn')!==false || strpos($n,'zewnętr')!==false || strpos($n,'zewnetrz')!==false));
}
function sm_is_worker_role(PDO $pdo, array $me): bool {
  $n = sm_role_name($pdo,$me);
  return strpos($n,'pracownik')!==false;
}
function sm_is_admin_role(array $me): bool {
  $rid=(int)($me['role_id'] ?? ($me['role'] ?? 0));
  return in_array($rid,[1,999],true);
}

$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $id = (int)($_GET['id'] ?? 0); if($id<=0) json_err('bad_request',400);
$uid = (int)($me['id'] ?? $me['uid'] ?? 0);
$isExternalTechnologist=sm_is_external_technologist($pdo,$me); $isWorkerRole=sm_is_worker_role($pdo,$me);
$view = function(string $code) use ($uid, $isExternalTechnologist, $isWorkerRole){
  if(($isExternalTechnologist || $isWorkerRole) && in_array($code,['finance_summary','finance_components','finance_payments','finance_discount_edit'],true)) return false;
  if($isExternalTechnologist && $code==='files_admin') return false;
  if($uid<=0) return true;
  try { return can_view_field($uid, $code); } catch (Throwable $e) { return true; }
};
try{
  $st = $pdo->prepare("SELECT o.*, s.name AS status_name, u.full_name AS scheduler_name FROM orders o LEFT JOIN order_statuses s ON s.id=o.status_id LEFT JOIN users u ON u.id=o.scheduler_id WHERE o.id=? LIMIT 1");
  $st->execute([$id]); $o = $st->fetch(PDO::FETCH_ASSOC); if(!$o) json_err('not_found',404);
  if($isExternalTechnologist && (int)($o['scheduler_id'] ?? 0) !== $uid) json_err('forbidden',403);
  $mounters=[]; $mounterIds=[];
  if($view('mounters')){
    $m = $pdo->prepare("SELECT a.user_id, us.full_name FROM order_assignees a JOIN users us ON us.id=a.user_id WHERE a.order_id=? ORDER BY us.full_name");
    $m->execute([$id]); $rows = $m->fetchAll(PDO::FETCH_ASSOC);
    $mounterIds = array_map(fn($r)=>(int)$r['user_id'], $rows);
    $mounters = array_map(fn($r)=>(string)$r['full_name'], $rows);
  }
  if(empty($o['install_postcode']) && !empty($o['install_zip'])) $o['install_postcode'] = $o['install_zip'];
  $map = [
    'client_first_name'=>['client_first_name'], 'client_last_name'=>['client_last_name'], 'item_description'=>['item_description'],
    'client_phone'=>['client_phone'], 'client_email'=>['client_email'], 'install_address'=>['client_address','install_street','install_no','install_postcode','install_zip','install_city'],
    'contract_date'=>['contract_date'], 'install_date'=>['install_date'], 'install_date_new'=>['install_date_new'], 'measure_due_date'=>['measure_due_date'],
    'status_id'=>['status_id','status_name'], 'scheduler_id'=>['scheduler_id','scheduler_name']
  ];
  foreach($map as $code=>$cols){ if(!$view($code)){ foreach($cols as $c) $o[$c]=null; } }
  if(!$view('mounters')){ $mounterIds=[]; $mounters=[]; }
  if(!$view('finance_summary')){ $o['total_amount']=null; $o['deposit_amount']=null; }
  $o['mounter_ids']=$mounterIds; $o['mounters']=$mounters;
  $edit = function(string $code) use ($uid){
    if($uid<=0) return true;
    try { return can_edit_field($uid, $code); } catch (Throwable $e) { return true; }
  };
  $fieldCaps = [
    'finance_summary'       => ['view'=>$view('finance_summary')?1:0,'edit'=>$edit('finance_summary')?1:0],
    'finance_components'    => ['view'=>$view('finance_components')?1:0,'edit'=>$edit('finance_components')?1:0],
    'finance_payments'      => ['view'=>$view('finance_payments')?1:0,'edit'=>$edit('finance_payments')?1:0],
    'finance_discount_edit' => ['view'=>$view('finance_discount_edit')?1:0,'edit'=>$edit('finance_discount_edit')?1:0],
    'notes'                 => ['view'=>$view('notes')?1:0,'edit'=>$edit('notes')?1:0],
    'files'                 => ['view'=>$view('files')?1:0,'edit'=>$edit('files')?1:0],
    'corrections'           => ['view'=>$view('corrections')?1:0,'edit'=>$edit('corrections')?1:0],
  ];
  json_ok(['item'=>$o,'field_caps'=>$fieldCaps]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
