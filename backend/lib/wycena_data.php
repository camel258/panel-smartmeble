<?php
// Wspólna warstwa danych dla modułu "Wycena" - używana przez wycena_api.php
// oraz przez generator specyfikacji (wycena_specyfikacja.php).

function wq_req(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:$_POST; }

function wq_user_name(array $me, int $uid): string {
  $full = trim((string)($me['full_name'] ?? ''));
  if($full !== '') return $full;
  foreach(['name','login','username','email'] as $k){ $v = trim((string)($me[$k] ?? '')); if($v!=='') return $v; }
  return 'Użytkownik #'.$uid;
}

function wq_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS wycena_quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_date DATE NULL,
    client_first_name VARCHAR(120) NOT NULL DEFAULT '',
    client_last_name VARCHAR(120) NOT NULL DEFAULT '',
    client_phone VARCHAR(60) NOT NULL DEFAULT '',
    client_email VARCHAR(190) NOT NULL DEFAULT '',
    install_street VARCHAR(190) NOT NULL DEFAULT '',
    install_no VARCHAR(30) NOT NULL DEFAULT '',
    install_postcode VARCHAR(20) NOT NULL DEFAULT '',
    install_city VARCHAR(120) NOT NULL DEFAULT '',
    source VARCHAR(150) NOT NULL DEFAULT '',
    total_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_by_name VARCHAR(190) NOT NULL DEFAULT '',
    created_by_id INT UNSIGNED NULL,
    linked_order_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS wycena_furniture (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    pricing_mode VARCHAR(20) NOT NULL DEFAULT 'materials',
    multiplier DECIMAL(6,2) NOT NULL DEFAULT 2.30,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    fixed_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    materials_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    final_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_quote (quote_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Migracja dla instalacji sprzed wprowadzenia trybu wyceny "stała cena".
  try{ $pdo->exec("ALTER TABLE wycena_furniture ADD COLUMN pricing_mode VARCHAR(20) NOT NULL DEFAULT 'materials'"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE wycena_furniture ADD COLUMN fixed_price DECIMAL(10,2) NOT NULL DEFAULT 0"); }catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS wycena_furniture_materials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    furniture_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NULL,
    material_name VARCHAR(255) NOT NULL DEFAULT '',
    unit VARCHAR(20) NOT NULL DEFAULT 'm2',
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_furniture (furniture_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS wycena_furniture_specs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    furniture_id BIGINT UNSIGNED NOT NULL,
    text VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_furniture (furniture_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN linked_quote_id BIGINT UNSIGNED NULL"); }catch(Throwable $e){}
}

function wq_recalc_furniture(PDO $pdo, int $furnitureId): void {
  $f = $pdo->prepare("SELECT multiplier, discount_amount, pricing_mode, fixed_price FROM wycena_furniture WHERE id=?");
  $f->execute([$furnitureId]);
  $row = $f->fetch(PDO::FETCH_ASSOC);
  if(!$row) return;

  if((string)($row['pricing_mode'] ?? 'materials') === 'fixed'){
    $final = max(0.0, round((float)$row['fixed_price'], 2));
    $pdo->prepare("UPDATE wycena_furniture SET materials_total=0, final_price=? WHERE id=?")->execute([$final, $furnitureId]);
    return;
  }

  $st = $pdo->prepare("SELECT COALESCE(SUM(line_total),0) FROM wycena_furniture_materials WHERE furniture_id=?");
  $st->execute([$furnitureId]);
  $materialsTotal = (float)$st->fetchColumn();
  $final = max(0.0, round($materialsTotal * (float)$row['multiplier'] - (float)$row['discount_amount'], 2));
  $pdo->prepare("UPDATE wycena_furniture SET materials_total=?, final_price=? WHERE id=?")->execute([round($materialsTotal,2), $final, $furnitureId]);
}

function wq_recalc_quote(PDO $pdo, int $quoteId): void {
  $st = $pdo->prepare("SELECT COALESCE(SUM(final_price),0) FROM wycena_furniture WHERE quote_id=?");
  $st->execute([$quoteId]);
  $total = round((float)$st->fetchColumn(), 2);
  $pdo->prepare("UPDATE wycena_quotes SET total_value=? WHERE id=?")->execute([$total, $quoteId]);
}

function wq_furniture_quote_id(PDO $pdo, int $furnitureId): int {
  $st = $pdo->prepare("SELECT quote_id FROM wycena_furniture WHERE id=?");
  $st->execute([$furnitureId]);
  return (int)$st->fetchColumn();
}

function wq_quote_full(PDO $pdo, int $quoteId): ?array {
  $st = $pdo->prepare("SELECT * FROM wycena_quotes WHERE id=? LIMIT 1");
  $st->execute([$quoteId]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if(!$q) return null;

  $fst = $pdo->prepare("SELECT * FROM wycena_furniture WHERE quote_id=? ORDER BY sort_order ASC, id ASC");
  $fst->execute([$quoteId]);
  $furniture = $fst->fetchAll(PDO::FETCH_ASSOC);
  $furnitureIds = array_map(function($r){ return (int)$r['id']; }, $furniture);

  $materialsByFurniture = [];
  $specsByFurniture = [];
  if($furnitureIds){
    $in = implode(',', array_fill(0,count($furnitureIds),'?'));
    $mst = $pdo->prepare("SELECT * FROM wycena_furniture_materials WHERE furniture_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $mst->execute($furnitureIds);
    foreach($mst->fetchAll(PDO::FETCH_ASSOC) as $m){ $materialsByFurniture[(int)$m['furniture_id']][] = $m; }
    $sst = $pdo->prepare("SELECT * FROM wycena_furniture_specs WHERE furniture_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $sst->execute($furnitureIds);
    foreach($sst->fetchAll(PDO::FETCH_ASSOC) as $s){ $specsByFurniture[(int)$s['furniture_id']][] = $s; }
  }
  foreach($furniture as &$f){
    $fid = (int)$f['id'];
    $f['materials'] = $materialsByFurniture[$fid] ?? [];
    $f['specs'] = $specsByFurniture[$fid] ?? [];
  }
  unset($f);
  $q['furniture'] = $furniture;
  return $q;
}
