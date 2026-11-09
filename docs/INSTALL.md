# INSTALLATION AND CONFIGURATION GUIDE

SmartBook Solutions - Inventory and Sales Management System
Mbarara University of Science and Technology, Bachelor of Information Technology

---

## 1. Requirements

### Minimum (local development)
| Component | Version | Notes |
|---|---|---|
| PHP | 8.1 or newer | Needs `pdo_mysql`, `mbstring`, `json` (all standard) |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.4+ | `utf8mb4` required |
| Web server | Apache 2.4, Nginx, or PHP built-in server | `mod_rewrite` on Apache |
| Browser | Chrome 90+, Edge, Samsung Internet | Service Workers + IndexedDB required |
| Python | 3.8+ **optional** | Only for the analytics service |

### Recommended for the Mile 4 pilot
- Shared hosting with cPanel (PHP 8.1, MySQL 5.7) - around UGX 150,000/year
- HTTPS enabled (Let's Encrypt is free) - **the PWA will not install without HTTPS**
- A domain, or the hosting provider's subdomain for the pilot phase

---

## 2. Database setup

```bash
# Create the database with the correct collation
mysql -u root -p -e "
  CREATE DATABASE smartbook
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;"

# Structure: 27 tables
mysql -u root -p smartbook < database/schema.sql

# Reference data: roles, permissions, the three packages, super administrator
mysql -u root -p smartbook < database/seed.sql
```

Verify:

```sql
USE smartbook;
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'smartbook';  -- 27
SELECT code, name, price_ugx, max_users FROM subscription_plans;
+----------+----------+-----------+-----------+
| basic    | Basic    |     10000 |         2 |
| standard | Standard |     15000 |         5 |
| premium  | Premium  |     20000 |        15 |
```

### Create a dedicated database user (do not use root)

```sql
CREATE USER 'smartbook'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON smartbook.* TO 'smartbook'@'localhost';
-- No DROP, no GRANT: the application never needs them.
FLUSH PRIVILEGES;
```

---

## 3. Application configuration

Everything lives in `config/config.php`. No secrets are hard-coded anywhere else.

```php
'app' => [
    'env'      => 'production',       // local | production
    'base_url' => 'https://smartbook.yourdomain.ug',
    'debug'    => false,              // MUST be false in production
    'timezone' => 'Africa/Kampala',
],
'db' => [
    'host' => 'localhost',
    'name' => 'smartbook',
    'user' => 'smartbook',
    'pass' => 'a-long-random-password',
],
'security' => [
    'force_https'     => true,        // true once you have a certificate
    'bcrypt_cost'     => 11,
    'token_ttl_hours' => 72,
],
'subscription' => [
    'trial_days'         => 14,
    'default_grace_days' => 5,
    'offline_grace_days' => 3,
],
```

### File permissions

```bash
chmod -R 755 storage/
chmod 640 config/config.php
# The web server user must be able to write uploads and session files only.
```

---

## 4. Deploying

### Option A - Apache shared hosting (what the pilot will use)

1. Upload the project so the document root contains:
   ```
   public_html/
   ├── .htaccess
   ├── api/         <- contents of public/api
   ├── index.html
   ├── admin.html
   ├── sw.js
   ├── manifest.json
   └── assets/
   ```
2. Keep `backend/`, `config/`, `database/`, `python/` **outside** `public_html`
   (one level up), and adjust the `require` paths in `bootstrap.php` accordingly.
3. Create `public_html/.htaccess`:

```apache
RewriteEngine On
RewriteBase /

# Route /api/* into the PHP front controller
RewriteRule ^api/(.*)$ api/index.php [QSA,L]

# Send everything else to the PWA shell
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# Never cache the service worker
<Files "sw.js">
    Header set Cache-Control "no-cache, must-revalidate"
</Files>

# Basic hardening
Options -Indexes
<FilesMatch "\.(sql|md|log)$">
    Require all denied
</FilesMatch>
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

4. Enable HTTPS from cPanel (AutoSSL / Let's Encrypt), then set `force_https => true`.

### Option B - Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name smartbook.yourdomain.ug;
    root /var/www/smartbook/public_html;
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/smartbook/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/smartbook/privkey.pem;

    location /api/ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/smartbook/public_html/api/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location = /sw.js { add_header Cache-Control "no-cache, must-revalidate"; }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(sql|md|log)$ { deny all; }
}
```

### Option C - Local demonstration (used during testing)

```bash
php -S 127.0.0.1:8080 router.php
# open http://127.0.0.1:8080/
```

---

## 5. Python analytics service

The core system works without it (PHP falls back to equivalent SQL). Enable it for the full
forecasting engine:

```bash
pip install flask pymysql

export SMARTBOOK_DB_HOST=127.0.0.1
export SMARTBOOK_DB_USER=smartbook
export SMARTBOOK_DB_PASS='a-long-random-password'
export SMARTBOOK_DB_NAME=smartbook

python3 python/app.py            # http://127.0.0.1:8000
```

Check it:

```bash
curl -s http://127.0.0.1:8000/health
curl -s -X POST http://127.0.0.1:8000/analyze \
     -H 'Content-Type: application/json' -d '{"shop_id":1,"days":30}'
```

Command-line mode (no Flask needed):

```bash
python3 python/app.py --cli --shop 1
```

On cPanel shared hosting, run it as a **Setup Python App** with a WSGI entry, or simply leave
`analytics.enabled => false` and use the SQL fallback.

---

## 6. Payment integration

### Default: manual verification (works today, no credentials)

1. The shop opens **Subscription → Submit payment**, chooses MTN/Airtel/bank/cash, and enters the
   transaction reference.
2. The record lands in `payments` with `status = 'pending'`.
3. The Super Administrator opens **Verify payments**, checks the reference against the merchant
   statement, and clicks **Approve & extend**.
4. The subscription extends from its current end date (or from today if it has lapsed), a row is
   written to `subscriptions`, and the shop gets a notification.

This is fully functional and is what the pilot should use.

### MTN Mobile Money / Airtel Money

The adapters in `backend/src/Subscription.php` are **deliberately inert**. They activate only when
you supply real operator credentials:

```php
'payments' => [
    'provider' => 'mtn_momo',            // or airtel_money
    'mtn' => [
        'enabled'         => true,
        'base_url'        => 'https://...',   // from your MTN developer account
        'subscription_key'=> '...',
        'api_user'        => '...',
        'api_key'         => '...',
        'target_env'      => 'sandbox',
    ],
],
```

**No API keys, endpoints or prices have been invented in this codebase.** Until an operator issues
you credentials, the system silently falls back to manual verification and tells the user so. A
payment is never marked verified automatically: the operator callback (or the administrator) must
confirm it.

---

## 7. First-run checklist

- [ ] `GET /api/health` returns `"database":"connected"`
- [ ] Sign in as `sysadmin` / `Admin@2026`, then **change the password**
- [ ] Sign in as a shop owner and confirm the dashboard loads
- [ ] Add one product, record one sale, open the receipt
- [ ] Load the app on an Android phone, confirm the offline indicator changes when you enable
      airplane mode, then record a sale offline and watch it sync when you reconnect
- [ ] Confirm `https://` so "Add to Home screen" installs the PWA
- [ ] Set `debug => false`, `force_https => true`
- [ ] Schedule the nightly backup (see `docs/DEPLOY.md`)

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `DB_CONNECTION` on every request | Wrong credentials, or MySQL not running | Check `config/config.php`, `systemctl status mysql` |
| Login works but every write returns 419 | CSRF header missing | The shipped frontend sends it; a custom client must send `X-CSRF-Token` |
| App works online only, never caches | Service Worker not registered | The site must be HTTPS (or `localhost`); check DevTools → Application → Service Workers |
| Sales stay "pending" forever | Sync endpoint unauthorized | Token expired after 72h; sign in again |
| "Not enough stock" on a valid sale | Stock cache out of step with the ledger | Run **Sync status → Re-check stock integrity** |
| Subscription shows expired but the shop paid | Payment still `pending` | Administrator must verify it |
| Analytics says `sql-fallback` | Python service unreachable | Start `python/app.py`, or accept the SQL fallback |
