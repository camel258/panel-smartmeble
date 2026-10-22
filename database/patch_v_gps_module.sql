CREATE TABLE gps_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  traccar_device_id BIGINT UNSIGNED NOT NULL,
  imei VARCHAR(32) DEFAULT NULL,
  vehicle_name VARCHAR(255) NOT NULL,
  plate_number VARCHAR(64) DEFAULT NULL,
  driver_name VARCHAR(255) DEFAULT NULL,
  last_position_id BIGINT UNSIGNED DEFAULT NULL,
  last_fix_time DATETIME DEFAULT NULL,
  last_server_time DATETIME DEFAULT NULL,
  last_device_time DATETIME DEFAULT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  speed_kmh DECIMAL(10,2) DEFAULT NULL,
  ignition TINYINT(1) NOT NULL DEFAULT 0,
  motion TINYINT(1) NOT NULL DEFAULT 0,
  address TEXT DEFAULT NULL,
  online TINYINT(1) NOT NULL DEFAULT 0,
  last_raw_json LONGTEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_traccar_device_id (traccar_device_id),
  KEY idx_vehicle_name (vehicle_name),
  KEY idx_online (online)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gps_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  traccar_event_id BIGINT UNSIGNED DEFAULT NULL,
  traccar_device_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_time DATETIME NOT NULL,
  server_time DATETIME DEFAULT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  duration_minutes INT DEFAULT NULL,
  extra_json LONGTEXT DEFAULT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'sync',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_traccar_event_id (traccar_event_id),
  KEY idx_device_time (traccar_device_id, event_time),
  KEY idx_event_type (event_type),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gps_event_state (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  traccar_device_id BIGINT UNSIGNED NOT NULL,
  stop_started_at DATETIME DEFAULT NULL,
  stop_latitude DECIMAL(10,7) DEFAULT NULL,
  stop_longitude DECIMAL(10,7) DEFAULT NULL,
  stop_address TEXT DEFAULT NULL,
  long_stop_notified_at DATETIME DEFAULT NULL,
  last_ignition_state TINYINT(1) NOT NULL DEFAULT 0,
  last_motion_state TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_state_device (traccar_device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_module_permissions (role_id, module_code, allow_access)
SELECT r.id, 'gps', CASE WHEN r.id IN (1, 999, 3) THEN 1 ELSE 0 END
FROM roles r
ON DUPLICATE KEY UPDATE allow_access = VALUES(allow_access);
