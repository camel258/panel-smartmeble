
-- SmartMeble patch v1.8 (adres, wpłaty, domówienia, kosz, role finanse, usunięte)
-- 1) Rozszerzenie adresu montażu (zostawiamy client_address dla kompatybilności)
ALTER TABLE orders
  ADD COLUMN install_street VARCHAR(180) NULL,
  ADD COLUMN install_no VARCHAR(40) NULL,
  ADD COLUMN install_postcode VARCHAR(20) NULL,
  ADD COLUMN install_city VARCHAR(120) NULL;

-- 2) Zakończone/usunięte
ALTER TABLE orders
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deleted_at DATETIME NULL,
  ADD COLUMN deleted_reason VARCHAR(500) NULL,
  ADD COLUMN deleted_by INT UNSIGNED NULL;

ALTER TABLE orders
  ADD INDEX idx_orders_deleted (is_deleted),
  ADD INDEX idx_orders_finished (is_finished);

-- 3) Finanse: wpłaty i domówienia
CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  paid_at DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  label VARCHAR(120) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_addons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Role: superadmin i finanse (bezpiecznie - dodajemy kolumny jeśli nie ma)
ALTER TABLE roles
  ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN can_view_finance TINYINT(1) NOT NULL DEFAULT 0;

-- upewnij się, że istnieje rola Super Admin
INSERT IGNORE INTO roles(id,name,is_superadmin,can_view_finance) VALUES (999,'Super Admin',1,1);

-- jeśli masz role o nazwie Administrator - nadaj dostęp do finansów
UPDATE roles SET can_view_finance=1 WHERE name IN ('Administrator','Admin');

