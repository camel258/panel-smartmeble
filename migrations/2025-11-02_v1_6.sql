ALTER TABLE orders
  ADD COLUMN real_month TINYINT NULL AFTER install_date_new,
  ADD COLUMN real_year SMALLINT NULL AFTER real_month;
UPDATE orders
SET real_month = IFNULL(real_month, MONTH(COALESCE(install_date_new, install_date))),
    real_year   = IFNULL(real_year,  YEAR(COALESCE(install_date_new, install_date)));
ALTER TABLE orders ADD INDEX idx_orders_real_yr_m (real_year, real_month);
