<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/api_helpers.php';
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$role=api_current_role_id($pdo,$me); if(!in_array($role,[1,999],true)) json_err('forbidden',403);
$body=api_request_data();
$id=(int)($body['id']??0); $y=(int)($body['bucket_year']??0); $m=(int)($body['bucket_month']??0); if($id<=0) json_err('bad_request',400);
try{
  if($y<=0 || $m<1 || $m>12){
    $q=$pdo->prepare("SELECT finished_bucket_year, finished_bucket_month FROM orders WHERE id=? LIMIT 1");
    $q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC) ?: [];
    $y=(int)($row['finished_bucket_year']??0);
    $m=(int)($row['finished_bucket_month']??0);
  }
  if($y<=0 || $m<1 || $m>12){ $y=(int)date('Y'); $m=(int)date('n'); }
  $st=$pdo->prepare("UPDATE orders SET is_finished=1, finished_at=NOW(), finished_bucket_year=?, finished_bucket_month=? WHERE id=?"); $st->execute([$y,$m,$id]); json_ok(['ok'=>true]);
}catch(Throwable $e){ json_err('save_failed',500,['details'=>$e->getMessage()]); }
