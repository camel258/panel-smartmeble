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

$u = auth_user();
if(!$u) json_err('unauthorized', 401);
$role = (int)($u['role_id'] ?? ($u['role'] ?? 0));
$pdoForRole = db();
$isExternalTechnologist = sm_is_external_technologist($pdoForRole,$u);
$isWorkerRole = sm_is_worker_role($pdoForRole,$u);
$default = function(string $module) use ($role, $isExternalTechnologist, $isWorkerRole){
  if($role===999) return 1;
  if($role===1) return !in_array($module,['orders_deleted','permissions'],true);
  if($role===3) return in_array($module,['news','orders_current','schedule','dashboard','gps_monitoring'],true) ? 1 : 0;
  if($isExternalTechnologist) return in_array($module,['news','dashboard','orders_current','schedule','gps_monitoring'],true) ? 1 : 0;
  if($isWorkerRole) return in_array($module,['news','dashboard','orders_current','schedule','gps_monitoring'],true) ? 1 : 0;
  return in_array($module,['news','dashboard','orders_current','schedule','gps_monitoring'],true) ? 1 : 0;
};
$modules = ['news','dashboard','orders_current','orders_finished','complaints','orders_deleted','payments','import_orders','schedule','users','statuses','settings','gps_monitoring','permissions','www_quotes','frontypax','zbudujmebel'];
$moduleCaps = [];
try{
  $pdo = db();
  $st = $pdo->prepare("SELECT module_code,allow_access FROM role_module_permissions WHERE role_id=?");
  $st->execute([$role]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach($rows as $r) $map[$r['module_code']] = (int)$r['allow_access'];
  foreach($modules as $m) $moduleCaps[$m] = (int)($map[$m] ?? $default($m));
}catch(Throwable $e){ foreach($modules as $m) $moduleCaps[$m] = (int)$default($m); }
json_ok(['ok'=>true,'user'=>$u,'caps'=>[
  'can_view_finance'=> ($moduleCaps['payments'] && !$isExternalTechnologist && !$isWorkerRole) ? 1 : 0,
  'is_superadmin'=> $role===999 ? 1 : 0,
  'is_admin'=> in_array($role,[1,999],true) ? 1 : 0,
  'modules'=>$moduleCaps
]]);
