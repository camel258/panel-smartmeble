<?php
require_once __DIR__.'/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$u = auth_user(); if(!$u) json_err('unauthorized',401);
$pdo=db(); $q=trim($_GET['q']??'');
if($q!==''){
  $st=$pdo->prepare("SELECT u.id,u.full_name,u.email,u.role_id,u.is_active,r.name AS role_name
                     FROM users u LEFT JOIN roles r ON r.id=u.role_id
                     WHERE u.full_name LIKE ? OR u.email LIKE ?
                     ORDER BY u.full_name");
  $st->execute(["%$q%","%$q%"]);
}else{
  $st=$pdo->query("SELECT u.id,u.full_name,u.email,u.role_id,u.is_active,r.name AS role_name
                   FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY u.full_name");
}
json_ok(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
