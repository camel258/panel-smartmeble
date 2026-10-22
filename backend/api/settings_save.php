<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u=auth_user(); if(!$u) json_err('unauthorized',401);

function _role_id($u){
  if(is_array($u)){
    if(isset($u['role'])) return (int)$u['role'];
    if(isset($u['role_id'])) return (int)$u['role_id'];
    if(isset($u['caps_role'])) return (int)$u['caps_role'];
  }
  return 0;
}
function _is_admin($u){ $r=_role_id($u); return in_array($r,[1,999],true); }
function _is_super($u){ return _role_id($u)===999; }
function _can_view_finance($u){ return _is_admin($u); }

if(!_is_admin($u)) json_err('forbidden',403);

$data=json_decode(file_get_contents('php://input'),true);
if(!is_array($data)) json_err('invalid_json',400);
$pdo=db();
$pdo->beginTransaction();
try {
  $st=$pdo->prepare("REPLACE INTO settings(`key`,`value`) VALUES(?,?)");
  foreach($data as $k=>$v){
    $st->execute([(string)$k,(string)$v]);
  }
  $pdo->commit();
  json_ok(['ok'=>true]);
} catch(Throwable $e) {
  $pdo->rollBack();
  json_err('db_error',500,['details'=>$e->getMessage()]);
}
