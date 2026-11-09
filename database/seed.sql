-- SmartBook Solutions - reference + demonstration data
-- Run AFTER schema.sql.  Every demo shop is flagged is_sample = 1 so it is
-- always distinguishable from a real business record in the UI.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- roles
INSERT INTO roles (code, name, description) VALUES
  ('owner',       'Shop Owner',  'Full control over the shop'),
  ('manager',     'Manager',     'Inventory, sales, employees and reports'),
  ('cashier',     'Cashier',     'Records sales and issues receipts'),
  ('storekeeper', 'Storekeeper', 'Products, stock entries and adjustments'),
  ('accountant',  'Accountant',  'Financial reports, expenses and credit records');

-- ---------------------------------------------------------- permissions
INSERT INTO permissions (code, name) VALUES
  ('dashboard.view','View dashboard'), ('products.view','View products'),
  ('products.manage','Manage products'), ('stock.adjust','Adjust stock'),
  ('sales.create','Record sales'), ('sales.view','View sales'),
  ('sales.cancel','Cancel a sale'), ('customers.view','View customers'),
  ('customers.manage','Manage customers'), ('suppliers.view','View suppliers'),
  ('suppliers.manage','Manage suppliers'), ('expenses.view','View expenses'),
  ('expenses.manage','Record expenses'), ('reports.view','View reports'),
  ('analytics.view','View analytics'), ('employees.manage','Manage employees'),
  ('settings.manage','Shop settings'), ('audit.view','View activity log'),
  ('subscription.manage','Manage subscription'), ('sync.use','Offline sync');

-- owner gets everything except nothing; other roles per the brief
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'owner';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
  ('dashboard.view','products.view','products.manage','stock.adjust','sales.create','sales.view',
   'sales.cancel','customers.view','customers.manage','suppliers.view','suppliers.manage',
   'expenses.view','expenses.manage','reports.view','analytics.view','employees.manage',
   'audit.view','sync.use')
WHERE r.code = 'manager';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
  ('dashboard.view','products.view','sales.create','sales.view','customers.view','sync.use')
WHERE r.code = 'cashier';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
  ('dashboard.view','products.view','products.manage','stock.adjust','suppliers.view','suppliers.manage',
   'analytics.view','sync.use')
WHERE r.code = 'storekeeper';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN
  ('dashboard.view','products.view','sales.view','customers.view','customers.manage','expenses.view',
   'expenses.manage','reports.view','analytics.view','subscription.manage','sync.use')
WHERE r.code = 'accountant';

-- --------------------------------------------------------------- plans
-- Prices exactly as proposed in the project document.
INSERT INTO subscription_plans (code, name, price_ugx, max_users, features) VALUES
  ('basic','Basic',10000,2,'["inventory","sales_pos","receipts","reports_basic","offline_mode"]'),
  ('standard','Standard',15000,5,'["inventory","sales_pos","receipts","reports_full","offline_mode","credit_ledger","suppliers","expenses"]'),
  ('premium','Premium',20000,15,'["inventory","sales_pos","receipts","reports_full","offline_mode","credit_ledger","suppliers","expenses","analytics","multi_device","barcode","sms_reminders"]');

-- --------------------------------------------------------- super admin
-- Password: Admin@2026  (bcrypt cost 11 - CHANGE IT after first login)
INSERT INTO system_administrators (full_name, username, email, password_hash) VALUES
  ('SmartBook Platform Owner','sysadmin','admin@smartbook.ug',
   '$2y$11$jrgD7irP/rpabuIKawdK.eqh2L6SQ7fj3yimOvfRiuH7IQ1GaSrGS');
