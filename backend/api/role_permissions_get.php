<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function current_role_id_local(PDO $pdo, array $me): int {
  $rid = (int)($me['role_id'] ?? ($me['role'] ?? 0));
  if($rid>0) return $rid;
  $uid = (int)($me['id'] ?? ($me['uid'] ?? 0));
  if($uid>0){
    try{
      $st=$pdo->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1');
      $st->execute([$uid]);
      return (int)$st->fetchColumn();
    }catch(Throwable $e){}
  }
  return 0;
}
function role_name_local(PDO $pdo, int $rid): string {
  if($rid<=0) return '';
  try{
    $cols = array_column($pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach(['name','title','label'] as $c){
      if(in_array($c,$cols,true)){
        $st=$pdo->prepare("SELECT `$c` FROM roles WHERE id=? LIMIT 1");
        $st->execute([$rid]);
        return strtolower(trim((string)$st->fetchColumn()));
      }
    }
  }catch(Throwable $e){}
  return '';
}
function is_permissions_admin_local(PDO $pdo, array $me): bool {
  $rid = current_role_id_local($pdo,$me);
  if(in_array($rid,[1,999],true)) return true;
  $rname = role_name_local($pdo,$rid);
  if($rname!=='' && (strpos($rname,'super')!==false || strpos($rname,'admin')!==false)) return true;
  try{
    $exists = $pdo->query("SHOW TABLES LIKE 'role_module_permissions'")->fetchColumn();
    if($exists){
      $st=$pdo->prepare("SELECT allow_access FROM role_module_permissions WHERE role_id=? AND module_code='permissions' LIMIT 1");
      $st->execute([$rid]);
      if((int)$st->fetchColumn()===1) return true;
    }
  }catch(Throwable $e){}
  return false;
}
if(!is_permissions_admin_local($pdo,$me)) json_err('forbidden',403,['details'=>'permissions_admin_required']);

$modules = [
  'news'=>'Aktualności',
  'dashboard'=>'Dashboard','orders_current'=>'Zamówienia aktualne','orders_finished'=>'Zamówienia zakończone','complaints'=>'Reklamacje','orders_deleted'=>'Zamówienia usunięte','payments'=>'Rozliczenia','import_orders'=>'Import zamówień','schedule'=>'Grafik','users'=>'Użytkownicy','statuses'=>'Statusy','settings'=>'Ustawienia','gps_monitoring'=>'Monitoring GPS','permissions'=>'Uprawnienia grup','frontypax'=>'FRONTYPAX – zamówienia sklepu','zbudujmebel'=>'ZBUDUJMEBEL – zamówienia sklepu'
];
$widgets = [
  'orders_month'=>'Liczba zamówień w tym miesiącu','orders_week'=>'Liczba zamówień w tym tygodniu','sales_month'=>'Sprzedaż w tym miesiącu','avg_order_value'=>'Średnia wartość zamówienia','payments_month'=>'Wpłaty w tym miesiącu','payments_week'=>'Wpłaty w tym tygodniu','installs_week'=>'Montaże w tym tygodniu','sales_6m'=>'Sprzedaż i nowe zamówienia z ostatnich 6 miesięcy','statuses_current'=>'Statusy aktywnych zamówień','boss_tasks'=>'Zadania od szefa'
];
$fields = [
  'client_first_name'=>'Imię klienta','client_last_name'=>'Nazwisko klienta','item_description'=>'Przedmiot zamówienia','client_phone'=>'Telefon','client_email'=>'E-mail','install_address'=>'Adres montażu','contract_date'=>'Data umowy','install_date'=>'Data montażu','install_date_new'=>'Nowa data montażu','measure_due_date'=>'Data pomiaru do','status_id'=>'Status','scheduler_id'=>'Osoba rozpisująca','mounters'=>'Monterzy','finance_summary'=>'Podsumowanie finansowe','finance_components'=>'Składniki wartości','finance_payments'=>'Wpłaty','notes'=>'Uwagi','files'=>'Pliki','files_public'=>'Pliki ogólne','files_admin'=>'Pliki administracyjne','corrections'=>'Elementy do poprawy'
];

function table_exists_local(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?
  ");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function default_module_access_local(int $rid, string $code): int {
  if($rid===999) return 1; if($rid===1) return !in_array($code,['orders_deleted','permissions'],true);
  if($rid===3) return in_array($code,['news','orders_current','schedule','dashboard','gps_monitoring'],true)?1:0;
  return in_array($code,['news','dashboard','orders_current','schedule','gps_monitoring'],true)?1:0;
}
function default_dashboard_widget_access_local(int $rid, string $code): int {
  if(in_array($rid,[1,999],true)) return 1;
  return in_array($code,['orders_month','orders_week','installs_week'],true)?1:0;
}
function default_field_permissions_local(int $rid, string $code): array {
  if(in_array($rid,[1,999],true)) return ['view'=>1,'edit'=>1];
  if(in_array($code,['finance_components','finance_payments'],true)) return ['view'=>0,'edit'=>1];
  if(in_array($code,['finance_summary'],true)) return ['view'=>0,'edit'=>0];
  return ['view'=>1,'edit'=>1];
}
try{
  $rolesCols = array_column($pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_ASSOC), 'Field');
  $roleNameCol = in_array('name',$rolesCols,true) ? 'name' : (in_array('title',$rolesCols,true) ? 'title' : (in_array('label',$rolesCols,true) ? 'label' : 'id'));
  $roles = $pdo->query("SELECT id, `$roleNameCol` AS name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  $mraw = table_exists_local($pdo,'role_module_permissions') ? $pdo->query('SELECT role_id,module_code,allow_access FROM role_module_permissions')->fetchAll(PDO::FETCH_ASSOC) : [];
  $draw = table_exists_local($pdo,'role_dashboard_permissions') ? $pdo->query('SELECT role_id,widget_code,allow_view FROM role_dashboard_permissions')->fetchAll(PDO::FETCH_ASSOC) : [];
  $fraw = table_exists_local($pdo,'role_field_permissions') ? $pdo->query('SELECT role_id,field_code,allow_view,allow_edit FROM role_field_permissions')->fetchAll(PDO::FETCH_ASSOC) : [];
  $mods=[]; foreach($mraw as $r){ $mods[(int)$r['role_id']][(string)$r['module_code']] = (int)$r['allow_access']; }
  $dw=[]; foreach($draw as $r){ $dw[(int)$r['role_id']][(string)$r['widget_code']] = (int)$r['allow_view']; }
  $fw=[]; foreach($fraw as $r){ $fw[(int)$r['role_id']][(string)$r['field_code']] = ['view'=>(int)$r['allow_view'],'edit'=>(int)$r['allow_edit']]; }
  $items=[];
  foreach($roles as $role){
    $rid=(int)$role['id'];
    $it=['role_id'=>$rid,'role_name'=>$role['name'],'modules'=>[],'dashboard_widgets'=>[],'order_fields'=>[]];
    foreach($modules as $code=>$label){ $it['modules'][]=['code'=>$code,'label'=>$label,'allow_access'=>(int)($mods[$rid][$code] ?? default_module_access_local($rid,$code))]; }
    foreach($widgets as $code=>$label){ $it['dashboard_widgets'][]=['code'=>$code,'label'=>$label,'allow_view'=>(int)($dw[$rid][$code] ?? default_dashboard_widget_access_local($rid,$code))]; }
    foreach($fields as $code=>$label){ $perm=$fw[$rid][$code] ?? default_field_permissions_local($rid,$code); $it['order_fields'][]=['code'=>$code,'label'=>$label,'allow_view'=>(int)$perm['view'],'allow_edit'=>(int)$perm['edit']]; }
    $items[]=$it;
  }
  json_ok(['items'=>$items]);
}catch(Throwable $e){ json_err('server_error',500,['details'=>$e->getMessage()]); }
