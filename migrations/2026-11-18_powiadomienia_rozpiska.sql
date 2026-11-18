-- Powiadomienia mailowe dla osoby rozpisującej (backend/cli/powiadomienia_rozpiska.php)
CREATE TABLE IF NOT EXISTS powiadomienia_rozpiska_stan (
  klucz VARCHAR(50) NOT NULL PRIMARY KEY,
  wartosc BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS powiadomienia_rozpiska_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  temat VARCHAR(255) NOT NULL,
  zdarzen INT NOT NULL DEFAULT 1,
  wyslano TINYINT(1) NOT NULL DEFAULT 0,
  blad VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id), KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
