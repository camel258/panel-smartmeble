<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user(); if(!$me) json_err('unauthorized',401);
$role = (int)($me['role_id'] ?? ($me['role'] ?? 0));
if(!in_array($role,[1,999],true)) json_err('forbidden',403);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$title = trim((string)($raw['title'] ?? ''));
$content = trim((string)($raw['content'] ?? ''));
if($title==='' || $content==='') json_err('missing_fields',422);
function table_exists_news_add(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}
if(!table_exists_news_add($pdo,'manual_news')){
  $pdo->exec("CREATE TABLE manual_news (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    content TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_by_name VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_active_created (is_active, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
$uid = (int)($me['id'] ?? ($me['uid'] ?? 0));
$uname = trim((string)($me['full_name'] ?? $me['email'] ?? 'Administrator'));
$st = $pdo->prepare("INSERT INTO manual_news(title,content,created_by,created_by_name) VALUES(?,?,?,?)");
$st->execute([$title,$content,$uid>0?$uid:null,$uname!==''?$uname:null]);
json_ok(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
