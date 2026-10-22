-- Grafik testowy + Ekipy
-- NIE TRZEBA uruchamiać ręcznie: tabele zakładają się same przy pierwszym wejściu
-- w zakładkę "Ekipy" lub "Grafik testowy". Ten plik jest na wypadek, gdyby
-- użytkownik bazy nie miał prawa CREATE (wtedy wklej w phpMyAdmin -> SQL).

CREATE TABLE IF NOT EXISTS crews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  vehicle VARCHAR(120) NOT NULL DEFAULT '',
  color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
  extra_members VARCHAR(255) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crew_members (
  crew_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (crew_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- order_id = 0 oznacza WARSZTAT
CREATE TABLE IF NOT EXISTS crew_schedule (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_date DATE NOT NULL,
  crew_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  members_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_day_crew_order (work_date, crew_id, order_id),
  KEY idx_date (work_date),
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- zamówienia z innych miesięcy dodane ręcznie (przycisk "+") do grafiku danego miesiąca
CREATE TABLE IF NOT EXISTS crew_schedule_pins (
  ym CHAR(7) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ym, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
