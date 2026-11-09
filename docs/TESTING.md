# TESTING PROCEDURES

Every claim in `README.md` maps to a test below. The automated suite is
`tests/api_test.py`; run it with the server up:

```bash
php -S 127.0.0.1:8080 router.php &
python3 tests/api_test.py
```

---

## Automated suite - 21 groups

| # | Test | Expected | What it proves |
|---|---|---|---|
| 1 | `GET /api/health` | 200, `database: connected` | Server + DB wiring |
| 2 | `POST /auth/register` | 201, trial shop created | Multi-tenant onboarding |
| 3 | `POST /auth/login` | 200 + fresh token | Credential handling |
| 4 | Wrong password | **401** | No user enumeration, hashing works |
| 5 | Write without CSRF header | **419** | CSRF enforcement |
| 6 | Create product with opening stock | 200, `stock_qty = 20` | Product + opening movement |
| 7 | Replay same product UUID | 200, **same id**, 1 row | Idempotent upsert |
| 8 | Record a sale | 201, stock 20 → 17 | Auto stock deduction |
| 9 | **Replay the same sale UUID** | `duplicate: true`, stock **still 17**, 1 sale row | **No double-charging on offline retry** |
| 10 | Sell 9999 units | **409 INSUFFICIENT_STOCK** | Overselling blocked |
| 11 | Credit sale | customer balance + ledger row | Credit ledger integrity |
| 12 | Sync push (duplicate sale + new expense) | summary `duplicate:1, applied:1`, stock unchanged | Mixed-batch replay safety |
| 13 | Sync push stock delta `+10` | stock = ledger sum | **Delta merge, not overwrite** |
| 14 | `GET /sync/pull` | cursor + products + sales | Cursor-based pull |
| 15 | **Shop B reads/sells Shop A's product** | **404 / 422 / 404** | **Tenant isolation** |
| 16 | Cashier adds product / lists employees | **403 / 403**, sale **201** | RBAC matrix |
| 17 | Expired subscription: sale / reads | **402** / **200, 200, 200** | Read-only after expiry, data intact |
| 18 | Submit payment → admin verify → retry sale | 201 → 200 → sale **201** | Renewal loop end to end |
| 19 | Dashboard, analytics, backup, audit | 200 | Reporting surface |
| 20 | Restore a backup from another shop | **403 TENANT_MISMATCH** | Restore cannot cross tenants |
| 21 | `POST /sync/repair` | conflicts_remaining = 0 | Ledger/cache reconciliation |

### Sample run output

```
PASSED 24   FAILED 0
```

---

## Manual test scripts

### A. Offline sale and synchronisation (the most important test)

1. Sign in on an Android phone as `grace / Demo@2024`; open **Sell**.
2. Note the current stock of "Sugar Kabuye 1kg" (Products screen).
3. Turn on **airplane mode** (or DevTools → Network → Offline on desktop).
4. The header chip changes to **"Offline - saving on phone"**.
5. Tap Sugar twice → **Complete sale**. A receipt appears.
   *Pass: the sale is recorded, the stock tile decrements locally.*
6. Open **More → Sync status**: 1 item is listed as `pending`.
7. Turn the network back on.
   *Pass: within ~20s the chip shows "All synced" and the pending list is empty.*
8. Refresh the Products screen: stock has decreased by exactly 2, not 4.
9. Repeat steps 3–7 twice with the **same** queued sale (throttle the network to force a timeout
   during upload). *Pass: the server still holds exactly one sale — the double-post scenario.*

### B. Two devices, one shop

1. Sign in as `grace` on phone A and on phone B.
2. Both go offline. Sell 1 sugar on A, sell 2 sugar on B.
3. Reconnect A, then reconnect B.
   *Pass: stock falls by exactly 3. Neither device overwrote the other, because each pushed a
   `−1` / `−2` movement rather than an absolute total.*

### C. Expiry without data loss

1. As administrator, or directly in SQL, set a shop's `subscription_expires_at` to 10 days ago.
2. Sign in to that shop.
   *Pass: dashboard, products, sales and reports all still open; the banner explains the situation;
   "Complete sale" returns HTTP 402 with a plain message.*
3. Verify in SQL that `SELECT COUNT(*) FROM sales WHERE shop_id = …` is unchanged.
4. Submit a payment, verify it as administrator.
   *Pass: selling works again immediately; the old records were never touched.*

### D. Role permissions

| Action | Owner | Manager | Cashier | Storekeeper | Accountant |
|---|---|---|---|---|---|
| Record a sale | ✓ | ✓ | ✓ | ✗ | ✗ |
| Add/edit product | ✓ | ✓ | ✗ | ✓ | ✗ |
| Adjust stock | ✓ | ✓ | ✗ | ✓ | ✗ |
| View reports | ✓ | ✓ | ✗ | ✗ | ✓ |
| Record expense | ✓ | ✓ | ✗ | ✗ | ✓ |
| Manage employees | ✓ | ✓ | ✗ | ✗ | ✗ |
| Backup/restore | ✓ | ✗ | ✗ | ✗ | ✗ |

Sign in as `grace_cashier / Cashier@2024` and confirm the "Add product" button is absent and that
`POST /api/products` returns 403 even if called directly.

### E. Security checks

```bash
# SQL injection attempt - must be treated as a literal string
curl -s "http://127.0.0.1:8080/api/products?search='%20OR%201=1--" -H "Authorization: Bearer $TOKEN"
# XSS attempt - must come back escaped in the UI, never executed
curl -s -X POST http://127.0.0.1:8080/api/products \
  -H "Authorization: Bearer $TOKEN" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"<img src=x onerror=alert(1)>","selling_price":100}'
# Cross-tenant probe - must be 404
curl -s http://127.0.0.1:8080/api/products/get?id=1 -H "Authorization: Bearer $SHOP_B_TOKEN"
```

### F. PWA installation

1. Serve over HTTPS (or localhost).
2. Chrome → menu → **Add to Home screen**.
3. Launch from the home screen with airplane mode on.
   *Pass: shell, products and POS all load from cache; selling works.*

### G. Usability targets from the project document

| Target | How to measure | Result expected |
|---|---|---|
| Sale in ≤ 3 taps and < 10s | Stopwatch, 5 attempts per shopkeeper | search → tap product → Complete = 3 taps |
| ≥ 95% stock accuracy | Weekly physical count vs `products.stock_qty` | ledger design keeps this exact |
| ≥ 99% sync success within 60s | Count `sync_logs` results after reconnect | retries + idempotency |
| SUS ≥ 70 | Standard 10-question questionnaire after week 4 | to be measured in the pilot |
| ≥ 3/5 willing to pay UGX 10–20k | Exit interview | to be measured in the pilot |

---

## What is NOT covered by automated tests

Be honest about this when you present the work:

- **Barcode scanning** needs a physical camera; it was verified only for graceful degradation.
- **PWA install and Service Worker caching** require HTTPS and a real browser; verified manually.
- **MTN / Airtel Money** APIs are not called, because no operator credentials exist.
- **Load testing** (hundreds of shops) has not been run; the schema indexes `shop_id` on every
  tenant table, which is the right preparation, but it is not a substitute for a load test.
