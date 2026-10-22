<?php
// Osoby podpisujące umowy: słownik + kolumna orders.contract_signer_id.
// Tabela i kolumna tworzą się automatycznie przy pierwszym użyciu.

function cs_ensure(PDO $pdo): void {
  static $done = false;
  if($done) return;
  $done = true;
  $isNew = false;
  try{
    $exists = $pdo->query("SHOW TABLES LIKE 'contract_signers'")->fetchColumn();
    if(!$exists){
      $pdo->exec("CREATE TABLE contract_signers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(190) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $isNew = true;
    }
  }catch(Throwable $e){}
  if($isNew){
    try{
      $ins = $pdo->prepare("INSERT INTO contract_signers(full_name, sort_order) VALUES(?,?)");
      foreach(['Kamil Wiśniewski','Mateusz Nizielski','Adam Gołuński'] as $i=>$n){ $ins->execute([$n, $i+1]); }
    }catch(Throwable $e){}
  }
  try{
    $has = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'contract_signer_id'")->fetchColumn();
    if(!$has) $pdo->exec("ALTER TABLE orders ADD COLUMN contract_signer_id INT UNSIGNED NULL");
  }catch(Throwable $e){}
}

function cs_initials(string $name): string {
  $parts = preg_split('/\s+/u', trim($name)) ?: [];
  $out = '';
  foreach($parts as $p){ if($p !== '') $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
  return $out;
}
