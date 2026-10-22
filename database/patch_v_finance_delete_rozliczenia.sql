
-- Patch: Rozliczenia + usuwanie zamówień + wpłaty/domówienia
-- Uwaga: przy aktualizacji mogą pojawić się komunikaty o duplikatach kolumn/indeksów – można je zignorować.

-- 1) Kolumny adresu montażu (jeśli nie istnieją)
ALTER TABLE orders
  ADD COLUMN install_street VARCHAR(255) NULL,
  ADD COLUMN install_number VARCHAR(50) NULL,
  ADD COLUMN install_postal_code VARCHAR(20) NULL,
  ADD COLUMN install_city VARCHAR(120) NULL;

-- 2) Miękkie usuwanie zamówień
ALTER TABLE orders
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deleted_at DATETIME NULL,
  ADD COLUMN deleted_reason VARCHAR(500) NULL;

ALTER TABLE orders
  ADD INDEX idx_orders_deleted (is_deleted);

-- 3) Wpłaty
CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  paid_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_order (order_id),
  INDEX idx_payments_date (paid_date),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Domówienia
CREATE TABLE IF NOT EXISTS order_addons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_addons_order (order_id),
  CONSTRAINT fk_addons_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_addons_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Migracja: jeśli nie ma wpłat, a jest deposit_amount, dodaj wpłatę typu "Zaliczka" (legacy)
INSERT INTO order_payments (order_id, paid_date, amount, description, created_by)
SELECT o.id,
       COALESCE(o.contract_date, CURDATE()) AS paid_date,
       o.deposit_amount,
       'Zaliczka (import z pola Zaliczka)' AS description,
       o.scheduler_id
FROM orders o
LEFT JOIN (SELECT order_id, COUNT(*) c FROM order_payments GROUP BY order_id) p ON p.order_id=o.id
WHERE COALESCE(p.c,0)=0 AND o.deposit_amount IS NOT NULL AND o.deposit_amount>0;

-- 6) Rola Super Admin (999) – jeśli nie istnieje
INSERT INTO roles (id, name) VALUES (999, 'Super Admin')
ON DUPLICATE KEY UPDATE name=VALUES(name);
