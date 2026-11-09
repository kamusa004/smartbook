# DEPLOYMENT AND OPERATIONS

## 1. Pilot deployment plan (Mile 4, Mbarara)

### Week 0 - preparation
1. Buy hosting with PHP 8.1 + MySQL, and a domain. Enable AutoSSL.
2. Import `schema.sql` and `seed.sql`; create the least-privilege DB user.
3. Upload the code (layout in `INSTALL.md` §4), set `debug => false`, `force_https => true`.
4. Change the super administrator password.
5. Create one real shop account per pilot shop (**do not** leave `is_sample = 1` on a live shop).

### Week 1 - onboarding five shops
For each shop: register the shop, set the correct plan, add 20–40 of their fastest-moving products
with real prices and current quantities, then train for ~15 minutes:

1. Tap a product → tap **Complete sale** (that is the whole lesson).
2. Show the **Sell** screen working in airplane mode.
3. Show **Products → Low stock** and explain the restock list.
4. Show **Customers** and record one credit sale so they see the balance appear.

Give each owner the printed one-page card: *tap, confirm, done.*

### Weeks 2–5 - four-week pilot
- Weekly physical stock count vs the app's number (target ≥ 95% agreement).
- Record stock-outs and compare against the baseline sheet.
- Note the time to record one sale (target < 10s).
- Collect the SUS questionnaire at the end of week 4.

### Week 6 - evaluation
Compare against the SMART targets in the project document, update the financial assumptions, and
make a documented go / modify / delay decision.

---

## 2. Backups

A subscription lapse must never cost a shop its records, so backups are non-negotiable.

### Nightly database dump

```bash
# /usr/local/bin/smartbook-backup.sh
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date +%Y%m%d-%H%M)
DEST=/var/backups/smartbook
mkdir -p "$DEST"

mysqldump --single-transaction --quick --routines \
  -u smartbook -p"$SMARTBOOK_DB_PASS" smartbook \
  | gzip > "$DEST/smartbook-$STAMP.sql.gz"

# keep 30 days
find "$DEST" -name 'smartbook-*.sql.gz' -mtime +30 -delete
```

```bash
chmod +x /usr/local/bin/smartbook-backup.sh
# crontab -e
0 2 * * * SMARTBOOK_DB_PASS='...' /usr/local/bin/smartbook-backup.sh
```

### Per-shop backup
Every shop owner can download their own JSON backup from **More → Backup & restore**. This matters
for the Data Protection and Privacy Act: a shop leaving the platform can take its records with it.

### Restore drill (do this before the pilot, not during it)

```bash
gunzip -c /var/backups/smartbook/smartbook-20261030-0200.sql.gz | mysql -u root -p smartbook
```

Time it. If it takes longer than you expect, that is the number to quote to the shop owners.

---

## 3. Monitoring

| What | How | Alert threshold |
|---|---|---|
| App alive | `curl -fsS https://your.domain/api/health` every 5 min | 2 consecutive failures |
| DB reachable | same endpoint's `database` field | `!= connected` |
| Failed syncs | `SELECT COUNT(*) FROM sync_logs WHERE result IN ('rejected','error') AND created_at > NOW() - INTERVAL 1 HOUR` | > 10 |
| Pending payments | administrator dashboard badge | > 5 for 48h |
| Disk | `df -h` | > 80% |
| Expiring subscriptions | `SELECT * FROM shops WHERE subscription_expires_at < NOW() + INTERVAL 5 DAY` | informs reminder calls |

A simple uptime check:

```bash
*/5 * * * * curl -fsS https://your.domain/api/health > /dev/null || \
  echo "SmartBook health check FAILED at $(date)" | mail -s "SmartBook DOWN" you@example.com
```

---

## 4. Subscription operations runbook

### A shop says it paid but is still blocked
1. Administrator → **Verify payments**. If the payment is there, check the reference against the
   merchant statement and approve.
2. If it is not there, the shop never submitted it: ask them to open **Subscription → Submit
   payment** with the MoMo transaction ID.

### A shop's subscription expired
Do **not** delete anything. The shop in `expired` state can still read and export every record. On
verification the subscription extends from the old end date (not from today), so no paid days are
lost.

### A shop stops paying permanently
Set status to `deactivated`. All their tokens are revoked, the shop can no longer sign in, and the
records stay in the database — recoverable if they return, and available to hand over if they ask.

### Refund / good-will extension
```sql
UPDATE shops
   SET subscription_expires_at = DATE_ADD(subscription_expires_at, INTERVAL 7 DAY),
       subscription_status = 'active'
 WHERE id = ?;
INSERT INTO subscriptions (shop_id, plan_id, period_months, amount_ugx, starts_at, ends_at, status)
VALUES (?, NULL, 0, 0, NOW(), (SELECT subscription_expires_at FROM shops WHERE id = ?), 'active');
```
Always insert the `subscriptions` row too, so the history stays truthful.

---

## 5. Scaling path

The schema is already multi-tenant by `shop_id` with an index on every tenant table, which carries
you comfortably to a few thousand shops on one server. When you outgrow that:

1. **Read replicas** for reports and analytics (`analytics.base_url` already points at a separate
   service, so the Python worker can read a replica).
2. **Move `inventory_movements` and `sales` to monthly partitioning** once they pass ~10M rows;
   the queries filter on `sold_at`/`created_at`, so partition pruning works cleanly.
3. **Per-tenant database sharding** is the last step, and the code is already shaped for it: every
   query takes `shop_id` first, so a shard router only has to map `shop_id → database`.

---

## 6. Post-deployment checklist

- [ ] `debug => false`
- [ ] `force_https => true` and HTTP redirects to HTTPS
- [ ] Super administrator password changed from `Admin@2026`
- [ ] Least-privilege DB user in use (no DROP/GRANT)
- [ ] Nightly backup running, and one **restore** rehearsed
- [ ] `config/`, `backend/`, `database/` are outside the web root
- [ ] `.sql`, `.md`, `.log` files blocked by the web server
- [ ] Health check monitored externally
- [ ] Five pilot shops created as **real** tenants (`is_sample = 0`)
- [ ] Analytics service running, or `analytics.enabled => false` accepted
- [ ] Support contact visible to shop owners (Support form + phone number)
- [ ] One-page training card printed for each shop
