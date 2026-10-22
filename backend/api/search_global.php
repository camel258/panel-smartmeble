<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/access.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = trim((string)($_GET['q'] ?? ''));
if($q==='') json_ok(['ok'=>true,'items'=>[]]);
$needle = '%' . $q . '%';
// zewnętrzny technolog – tylko jego zamówienia
$scopeParams = [];
$scope = '';
if(acc_is_external_technologist($pdo, $me)){ $scope = 'AND o.scheduler_id=?'; $scopeParams[] = acc_uid($me); }
try{
  $sql = "SELECT o.id,
    CONCAT(TRIM(COALESCE(o.client_first_name,'')),' ',TRIM(COALESCE(o.client_last_name,''))) AS client_name,
    COALESCE(o.client_phone,'') AS client_phone,
    COALESCE(o.client_email,'') AS client_email,
    TRIM(CONCAT(
      COALESCE(NULLIF(o.client_address,''), ''),
      CASE WHEN COALESCE(o.client_address,'')<>'' AND (COALESCE(o.install_street,'')<>'' OR COALESCE(o.install_no,'')<>'' OR COALESCE(o.install_postcode,'')<>'' OR COALESCE(o.install_zip,'')<>'' OR COALESCE(o.install_city,'')<>'') THEN ' / ' ELSE '' END,
      COALESCE(o.install_street,''),
      CASE WHEN COALESCE(o.install_no,'')<>'' THEN CONCAT(' ', o.install_no) ELSE '' END,
      CASE WHEN COALESCE(o.install_postcode,'')<>'' THEN CONCAT(', ', o.install_postcode) ELSE '' END,
      CASE WHEN COALESCE(o.install_zip,'')<>'' AND COALESCE(o.install_zip,'')<>COALESCE(o.install_postcode,'') THEN CONCAT(', ', o.install_zip) ELSE '' END,
      CASE WHEN COALESCE(o.install_city,'')<>'' THEN CONCAT(' ', o.install_city) ELSE '' END
    )) AS install_address,
    COALESCE(o.item_description,'') AS item_description,
    DATE_FORMAT(o.contract_date,'%Y-%m-%d') AS contract_date,
    DATE_FORMAT(COALESCE(o.install_date_new,o.install_date),'%Y-%m-%d') AS install_date,
    COALESCE(s.name,'—') AS status_name
    FROM orders o
    LEFT JOIN order_statuses s ON s.id=o.status_id
    WHERE COALESCE(o.is_deleted,0)=0 {$scope} AND (
      CAST(o.id AS CHAR) LIKE ?
      OR CONCAT(TRIM(COALESCE(o.client_first_name,'')),' ',TRIM(COALESCE(o.client_last_name,''))) LIKE ?
      OR COALESCE(o.client_phone,'') LIKE ?
      OR COALESCE(o.client_email,'') LIKE ?
      OR COALESCE(o.client_address,'') LIKE ?
      OR COALESCE(o.install_street,'') LIKE ?
      OR COALESCE(o.install_no,'') LIKE ?
      OR COALESCE(o.install_postcode,'') LIKE ?
      OR COALESCE(o.install_zip,'') LIKE ?
      OR COALESCE(o.install_city,'') LIKE ?
      OR COALESCE(o.item_description,'') LIKE ?
    )
    ORDER BY o.id DESC
    LIMIT 30";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($scopeParams, [$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle,$needle]));
  json_ok(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}catch(Throwable $e){
  json_err('search_error',500,['details'=>$e->getMessage()]);
}
