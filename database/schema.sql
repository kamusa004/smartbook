-- =====================================================================
-- SMARTBOOK SOLUTIONS - Inventory & Sales Management System
-- MySQL / MariaDB schema  (multi-tenant, offline-first)
-- "Your Business, Under Control."
-- =====================================================================
-- TENANT RULE: every shop-owned table carries shop_id with an FK + index.
-- SYNC RULE:  every syncable table carries client_uuid with a UNIQUE key
--             (per shop) so that replayed offline records are idempotent.
-- STOCK RULE: stock is stored as signed movement deltas in
--             inventory_movements; products.stock_qty is only a cache that
--             is recalculated from those deltas.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- PLATFORM LEVEL (owned by the SmartBook platform, not by a shop)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS system_administrators;
CREATE TABLE system_administrators (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(120) NOT NULL,
  username        VARCHAR(60)  NOT NULL,
  email           VARCHAR(160) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sysadmin_username (username),
  UNIQUE KEY uq_sysadmin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS subscription_plans;
CREATE TABLE subscription_plans (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,          -- basic | standard | premium
  name         VARCHAR(80)  NOT NULL,
  price_ugx    INT UNSIGNED NOT NULL,          -- monthly price in UGX
  max_users    INT UNSIGNED NOT NULL DEFAULT 3,
  features     TEXT NULL,                      -- JSON array of feature keys
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shops;
CREATE TABLE shops (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_uid               CHAR(12)     NOT NULL,   -- public Shop ID, e.g. SB-4F7A21
  name                   VARCHAR(160) NOT NULL,
  owner_name             VARCHAR(160) NOT NULL,
  phone                  VARCHAR(30)  NOT NULL,
  email                  VARCHAR(160) NULL,
  location               VARCHAR(200) NOT NULL,
  business_category      VARCHAR(80)  NOT NULL DEFAULT 'Retail shop',
  preferred_language     VARCHAR(10)  NOT NULL DEFAULT 'en',
  status                 ENUM('pending','active','suspended','deactivated') NOT NULL DEFAULT 'pending',
  is_sample              TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = demonstration data, never real records
  plan_id                INT UNSIGNED NULL,
  subscription_status    ENUM('trial','active','grace','expired','suspended') NOT NULL DEFAULT 'trial',
  subscription_started_at DATETIME    NULL,
  subscription_expires_at DATETIME    NULL,
  grace_days             SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  offline_grace_days     SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_uid (shop_uid),
  KEY idx_shops_status (status),
  KEY idx_shops_plan (plan_id),
  CONSTRAINT fk_shops_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30)  NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  UNIQUE KEY uq_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code   VARCHAR(60) NOT NULL,
  name   VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_perm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS role_permissions;
CREATE TABLE role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SHOP USERS (tenant scoped)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS shop_users;
CREATE TABLE shop_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  full_name     VARCHAR(160) NOT NULL,
  username      VARCHAR(60)  NOT NULL,
  phone         VARCHAR(30)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shopuser (shop_id, username),
  KEY idx_shopusers_shop (shop_id),
  KEY idx_shopusers_role (role_id),
  CONSTRAINT fk_su_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_su_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SUBSCRIPTIONS / PAYMENTS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  plan_id       INT UNSIGNED NULL,
  amount_ugx    INT UNSIGNED NOT NULL,
  method        ENUM('mtn_momo','airtel_money','cash','bank','manual') NOT NULL DEFAULT 'manual',
  reference     VARCHAR(120) NULL,           -- mobile money / bank reference
  period_months TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status        ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by   INT UNSIGNED NULL,           -- system_administrators.id
  verified_at   DATETIME NULL,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  client_uuid   CHAR(36) NULL,
  KEY idx_pay_shop (shop_id),
  KEY idx_pay_status (status),
  UNIQUE KEY uq_pay_uuid (shop_id, client_uuid),
  CONSTRAINT fk_pay_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS announcements;
CREATE TABLE announcements (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(160) NOT NULL,
  body       TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ann_admin FOREIGN KEY (created_by) REFERENCES system_administrators(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS support_requests;
CREATE TABLE support_requests (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id    INT UNSIGNED NULL,
  subject    VARCHAR(160) NOT NULL,
  message    TEXT NOT NULL,
  status     ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
  response   TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_support_shop (shop_id),
  CONSTRAINT fk_support_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- INVENTORY
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS product_categories;
CREATE TABLE product_categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  client_uuid CHAR(36) NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cat_shop (shop_id),
  UNIQUE KEY uq_cat_uuid (shop_id, client_uuid),
  CONSTRAINT fk_cat_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS products;
CREATE TABLE products (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id           INT UNSIGNED NOT NULL,
  client_uuid       CHAR(36) NOT NULL,
  sku               VARCHAR(60)  NULL,
  name              VARCHAR(160) NOT NULL,
  category_id       INT UNSIGNED NULL,
  barcode           VARCHAR(80)  NULL,
  unit              VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  purchase_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_qty         DECIMAL(14,3) NOT NULL DEFAULT 0,   -- cache of SUM(inventory_movements.qty_change)
  min_stock         DECIMAL(14,3) NOT NULL DEFAULT 0,
  description       VARCHAR(400) NULL,
  image_path        VARCHAR(255) NULL,
  expiry_date       DATE NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_prod_shop (shop_id),
  KEY idx_prod_barcode (shop_id, barcode),
  KEY idx_prod_cat (category_id),
  KEY idx_prod_sync (shop_id, updated_at),
  UNIQUE KEY uq_prod_uuid (shop_id, client_uuid),
  CONSTRAINT fk_prod_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS suppliers;
CREATE TABLE suppliers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NOT NULL,
  client_uuid CHAR(36) NOT NULL,
  name        VARCHAR(160) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone       VARCHAR(30) NULL,
  email       VARCHAR(160) NULL,
  address     VARCHAR(200) NULL,
  balance     DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sup_shop (shop_id),
  UNIQUE KEY uq_sup_uuid (shop_id, client_uuid),
  CONSTRAINT fk_sup_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Signed stock deltas. THE source of truth for stock.
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  client_uuid   CHAR(36) NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  movement_type ENUM('purchase','sale','adjustment','return','loss','opening','transfer') NOT NULL,
  qty_change    DECIMAL(14,3) NOT NULL,          -- +in / -out
  unit_cost     DECIMAL(12,2) NOT NULL DEFAULT 0,
  reference     VARCHAR(60) NULL,               -- sale_no / PO no
  reason        VARCHAR(200) NULL,
  employee_id   INT UNSIGNED NULL,
  device_id     VARCHAR(64) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mov_shop (shop_id),
  KEY idx_mov_product (shop_id, product_id),
  KEY idx_mov_sync (shop_id, updated_at),
  UNIQUE KEY uq_mov_uuid (shop_id, client_uuid),
  CONSTRAINT fk_mov_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SALES
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS sales;
CREATE TABLE sales (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id        INT UNSIGNED NOT NULL,
  client_uuid    CHAR(36) NOT NULL,
  sale_no        VARCHAR(40) NOT NULL,
  customer_id    INT UNSIGNED NULL,
  employee_id    INT UNSIGNED NULL,
  subtotal       DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid    DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_method ENUM('cash','mtn_momo','airtel_money','credit') NOT NULL DEFAULT 'cash',
  status         ENUM('completed','credit','cancelled','returned') NOT NULL DEFAULT 'completed',
  note           VARCHAR(255) NULL,
  device_id      VARCHAR(64) NULL,
  sold_at        DATETIME NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sales_shop (shop_id),
  KEY idx_sales_date (shop_id, sold_at),
  KEY idx_sales_customer (customer_id),
  KEY idx_sales_device (shop_id, device_id),
  KEY idx_sales_sync (shop_id, updated_at),
  UNIQUE KEY uq_sale_uuid (shop_id, client_uuid),
  UNIQUE KEY uq_sale_no (shop_id, sale_no),
  CONSTRAINT fk_sales_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sale_items;
CREATE TABLE sale_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id        INT UNSIGNED NOT NULL,
  sale_id        INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  product_name   VARCHAR(160) NOT NULL,
  quantity       DECIMAL(14,3) NOT NULL,
  unit_price     DECIMAL(12,2) NOT NULL,
  unit_cost      DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total     DECIMAL(14,2) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_items_sale (sale_id),
  KEY idx_items_shop (shop_id),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CUSTOMERS & CREDIT
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  client_uuid   CHAR(36) NOT NULL,
  name          VARCHAR(160) NOT NULL,
  phone         VARCHAR(30) NULL,
  email         VARCHAR(160) NULL,
  address       VARCHAR(200) NULL,
  credit_limit  DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- outstanding debt
  notes         VARCHAR(255) NULL,
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cust_shop (shop_id),
  KEY idx_cust_sync (shop_id, updated_at),
  UNIQUE KEY uq_cust_uuid (shop_id, client_uuid),
  CONSTRAINT fk_cust_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS credit_payments;
CREATE TABLE credit_payments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NOT NULL,
  client_uuid CHAR(36) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  sale_id     INT UNSIGNED NULL,
  amount      DECIMAL(14,2) NOT NULL,
  method      ENUM('cash','mtn_momo','airtel_money','other') NOT NULL DEFAULT 'cash',
  paid_at     DATETIME NOT NULL,
  employee_id INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cp_shop (shop_id),
  KEY idx_cp_cust (customer_id),
  UNIQUE KEY uq_cp_uuid (shop_id, client_uuid),
  CONSTRAINT fk_cp_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CREDIT LEDGER + SUBSCRIPTION HISTORY
-- ---------------------------------------------------------------------
-- Immutable credit ledger: one 'charge' per credit sale, one 'payment' per
-- receipt. customers.balance is a cache of SUM(charge) - SUM(payment).
DROP TABLE IF EXISTS customer_credit;
CREATE TABLE customer_credit (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  client_uuid   CHAR(36) NULL,
  customer_id   INT UNSIGNED NOT NULL,
  sale_id       INT UNSIGNED NULL,
  entry_type    ENUM('charge','payment','adjustment') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL DEFAULT 0,
  note          VARCHAR(200) NULL,
  employee_id   INT UNSIGNED NULL,
  occurred_at   DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cc_shop (shop_id),
  KEY idx_cc_cust (customer_id, occurred_at),
  KEY idx_cc_sync (shop_id, updated_at),
  UNIQUE KEY uq_cc_uuid (shop_id, client_uuid),
  CONSTRAINT fk_cc_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per granted billing period.
DROP TABLE IF EXISTS subscriptions;
CREATE TABLE subscriptions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id       INT UNSIGNED NOT NULL,
  plan_id       INT UNSIGNED NULL,
  payment_id    INT UNSIGNED NULL,
  period_months TINYINT UNSIGNED NOT NULL DEFAULT 1,
  amount_ugx    INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at     DATETIME NOT NULL,
  ends_at       DATETIME NOT NULL,
  status        ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sub_shop (shop_id, ends_at),
  CONSTRAINT fk_sub_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PURCHASING
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS purchase_orders;
CREATE TABLE purchase_orders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NOT NULL,
  client_uuid CHAR(36) NOT NULL,
  po_no       VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NULL,
  status      ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'ordered',
  total       DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  ordered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  note        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_po_shop (shop_id),
  UNIQUE KEY uq_po_uuid (shop_id, client_uuid),
  CONSTRAINT fk_po_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS purchase_items;
CREATE TABLE purchase_items (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT UNSIGNED NOT NULL,
  po_id        INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  quantity     DECIMAL(14,3) NOT NULL,
  unit_cost    DECIMAL(12,2) NOT NULL,
  line_total   DECIMAL(14,2) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pi_po (po_id),
  CONSTRAINT fk_pi_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- EXPENSES
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS expenses;
CREATE TABLE expenses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NOT NULL,
  client_uuid CHAR(36) NOT NULL,
  category    ENUM('rent','transport','electricity','salaries','internet','water','licences','other') NOT NULL DEFAULT 'other',
  description VARCHAR(200) NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  spent_at    DATETIME NOT NULL,
  employee_id INT UNSIGNED NULL,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_exp_shop (shop_id),
  KEY idx_exp_date (shop_id, spent_at),
  UNIQUE KEY uq_exp_uuid (shop_id, client_uuid),
  CONSTRAINT fk_exp_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LOGS / NOTIFICATIONS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id    INT UNSIGNED NOT NULL,
  type       ENUM('low_stock','out_of_stock','subscription','credit','system','expiry') NOT NULL DEFAULT 'system',
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(400) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notif_shop (shop_id, is_read),
  CONSTRAINT fk_notif_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NULL,
  actor_type  ENUM('sysadmin','shop_user','system') NOT NULL DEFAULT 'shop_user',
  actor_id    INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  entity      VARCHAR(60) NULL,
  entity_id   VARCHAR(60) NULL,
  ip_address  VARCHAR(45) NULL,
  meta        TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_shop (shop_id, created_at),
  CONSTRAINT fk_audit_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sync_logs;
CREATE TABLE sync_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id      INT UNSIGNED NOT NULL,
  device_id    VARCHAR(64) NULL,
  direction    ENUM('push','pull') NOT NULL,
  entity       VARCHAR(40) NULL,
  record_uuid  CHAR(36) NULL,
  result       ENUM('applied','duplicate','rejected','error') NOT NULL,
  message      VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sync_shop (shop_id, created_at),
  CONSTRAINT fk_sync_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- API TOKENS (session alternative for PWA / offline clients)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS api_tokens;
CREATE TABLE api_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id     INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  sysadmin_id INT UNSIGNED NULL,
  token_hash  CHAR(64) NOT NULL,
  device_id   VARCHAR(64) NULL,
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_token_shop (shop_id),
  CONSTRAINT fk_token_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
