<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$body = api_request_data();
if(!api_is_permissions_admin($pdo,$me)) json_err('forbidden',403,['details'=>'permissions_admin_required']);


try{
  $pdo->beginTransaction();
  if (!api_table_exists($pdo, 'role_module_permissions')) $pdo->exec('CREATE TABLE role_module_permissions (role_id INT UNSIGNED NOT NULL, module_code VARCHAR(64) NOT NULL, allow_access TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(role_id,module_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if (!api_table_exists($pdo, 'role_dashboard_permissions')) $pdo->exec('CREATE TABLE role_dashboard_permissions (role_id INT UNSIGNED NOT NULL, widget_code VARCHAR(64) NOT NULL, allow_view TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(role_id,widget_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if (!api_table_exists($pdo, 'role_field_permissions')) $pdo->exec('CREATE TABLE role_field_permissions (role_id INT UNSIGNED NOT NULL, field_code VARCHAR(64) NOT NULL, allow_view TINYINT(1) NOT NULL DEFAULT 1, allow_edit TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(role_id,field_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

  if(!empty($body['modules']) && is_array($body['modules'])){
    $st = $pdo->prepare('INSERT INTO role_module_permissions(role_id,module_code,allow_access) VALUES(?,?,?) ON DUPLICATE KEY UPDATE allow_access=VALUES(allow_access)');
    foreach($body['modules'] as $row){ $rid=(int)($row['role_id']??0); $code=trim((string)($row['module_code']??'')); if($rid<=0||$code==='') continue; $allow=!empty($row['allow_access'])?1:0; if($code==='permissions' && role_name_local($pdo,$rid) && strpos(role_name_local($pdo,$rid),'super')!==false) $allow=1; $st->execute([$rid,$code,$allow]); }
  }
  if(!empty($body['dashboard_widgets']) && is_array($body['dashboard_widgets'])){
    $st = $pdo->prepare('INSERT INTO role_dashboard_permissions(role_id,widget_code,allow_view) VALUES(?,?,?) ON DUPLICATE KEY UPDATE allow_view=VALUES(allow_view)');
    foreach($body['dashboard_widgets'] as $row){ $rid=(int)($row['role_id']??0); $code=trim((string)($row['widget_code']??'')); if($rid<=0||$code==='') continue; $st->execute([$rid,$code,!empty($row['allow_view'])?1:0]); }
  }
  if(!empty($body['order_fields']) && is_array($body['order_fields'])){
    $st = $pdo->prepare('INSERT INTO role_field_permissions(role_id,field_code,allow_view,allow_edit) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE allow_view=VALUES(allow_view), allow_edit=VALUES(allow_edit)');
    foreach($body['order_fields'] as $row){
      $rid=(int)($row['role_id']??0); $code=trim((string)($row['field_code']??'')); if($rid<=0||$code==='') continue; $view=!empty($row['allow_view'])?1:0; $edit=!empty($row['allow_edit'])?1:0;
      if(!$view && !in_array($code,['finance_components','finance_payments'],true)) $edit=0;
      $st->execute([$rid,$code,$view,$edit]);
    }
  }
  $pdo->commit(); json_ok(['ok'=>true]);
}catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); json_err('save_failed',500,['details'=>$e->getMessage()]); }
