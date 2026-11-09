#!/usr/bin/env python3
"""SmartBook end-to-end API test suite (runs against php -S)."""
import json
import subprocess
import sys
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:8080/api"
PASS, FAIL = [], []


def call(method, path, body=None, token=None, csrf=None, expect=None, label=""):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    if csrf:
        req.add_header("X-CSRF-Token", csrf)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            code = r.status
            payload = json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        code = e.code
        raw = e.read().decode()
        try:
            payload = json.loads(raw or "{}")
        except json.JSONDecodeError:
            payload = {"raw": raw[:300]}

    ok = (code == expect) if expect is not None else True
    if code == 201 and isinstance(payload, dict) and payload.get("ok"):
        ok = True
    (PASS if ok else FAIL).append(f"{label or path} -> HTTP {code} (expected {expect})")
    if not ok:
        print("   detail:", json.dumps(payload)[:400])
    return code, payload


def sql(query):
    return subprocess.run(["sudo", "mariadb", "-N", "smartbook", "-e", query],
                          capture_output=True, text=True).stdout.strip()


print("== 1. health ==")
c, h = call("GET", "/health", expect=200, label="health")
print("   app:", h.get("data", {}).get("app"), "| db:", h.get("data", {}).get("database"))

print("== 2. register a new shop (tenant isolation subject) ==")
uniq = subprocess.run(["bash", "-c", "date +%s%N"], capture_output=True, text=True).stdout.strip()[-7:]
c, reg = call("POST", "/auth/register", {
    "owner_name": "Test Owner", "name": f"Test Shop {uniq}", "phone": "0772000111",
    "email": f"t{uniq}@example.ug", "location": "Mile 4, Mbarara",
    "business_category": "Retail shop", "language": "en",
    "username": f"test{uniq}", "password": "Secret123", "plan": "basic"
}, expect=201, label="register")
tokA, csrfA = reg["data"]["token"], reg["data"]["csrf"]
shopA = reg["data"]["shop"]["id"]
uidA = reg["data"]["user"]["id"]
print("   shop id", shopA, "shop_uid", reg["data"]["shop"]["shop_uid"], "| trial:", reg["data"]["shop"]["subscription"]["status"])

print("== 3. login returns a fresh token ==")
c, log = call("POST", "/auth/login", {"username": f"test{uniq}", "password": "Secret123"}, expect=200, label="login")
tokA, csrfA = log["data"]["token"], log["data"]["csrf"]

print("== 4. bad password rejected ==")
call("POST", "/auth/login", {"username": f"test{uniq}", "password": "wrong"}, expect=401, label="login-bad-password")

print("== 5. CSRF missing on a write is refused ==")
call("POST", "/products", {"name": "No CSRF"}, token=tokA, expect=419, label="csrf-missing")

print("== 6. create product (with CSRF) ==")
c, prod = call("POST", "/products", {
    "client_uuid": "11111111-1111-4111-8111-111111111111",
    "name": "Sugar 1kg", "selling_price": 4500, "purchase_price": 3800,
    "opening_qty": 20, "min_stock": 5, "unit": "pcs", "barcode": "6001112223334"
}, token=tokA, csrf=csrfA, expect=200, label="product-create")
pidA = prod["data"]["id"]
print("   product", pidA, "stock", sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"))

print("== 7. replay the same product uuid = no duplicate ==")
c, prod2 = call("POST", "/products", {
    "client_uuid": "11111111-1111-4111-8111-111111111111", "name": "Sugar 1kg", "selling_price": 4500
}, token=tokA, csrf=csrfA, expect=200, label="product-idempotent")
print("   same id returned:", prod2["data"]["id"] == pidA, "| product rows:",
      sql(f"SELECT COUNT(*) FROM smartbook.products WHERE shop_id={shopA}"))

print("== 8. record a sale ==")
sale_uuid = "22222222-2222-4222-8222-222222222222"
sale_body = {
    "client_uuid": sale_uuid, "payment_method": "cash",
    "items": [{"product_id": pidA, "quantity": 3, "unit_price": 4500}]
}
c, sale = call("POST", "/sales", sale_body, token=tokA, csrf=csrfA, expect=201, label="sale-create")
print("   sale", sale["data"]["sale"]["sale_no"], "total", sale["data"]["sale"]["total"],
      "| stock now", sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"))

print("== 9. replay the sale (offline queue retry) = no double deduction ==")
c, sale2 = call("POST", "/sales", sale_body, token=tokA, csrf=csrfA, expect=201, label="sale-replay-duplicate")
print("   duplicate flag:", sale2["data"].get("duplicate"), "| stock still",
      sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"),
      "| sale rows", sql(f"SELECT COUNT(*) FROM smartbook.sales WHERE shop_id={shopA}"))

print("== 10. overselling is blocked ==")
call("POST", "/sales", {
    "client_uuid": "33333333-3333-4333-8333-333333333333", "payment_method": "cash",
    "items": [{"product_id": pidA, "quantity": 9999}]
}, token=tokA, csrf=csrfA, expect=409, label="sale-insufficient-stock")

print("== 11. credit sale builds a customer ledger ==")
c, cust = call("POST", "/customers", {"client_uuid": "44444444-4444-4444-8444-444444444444",
                                      "name": "John Mugisha", "phone": "0772445566", "credit_limit": 50000},
               token=tokA, csrf=csrfA, expect=200, label="customer-create")
custA = cust["data"]["id"]
call("POST", "/sales", {"client_uuid": "55555555-5555-4555-8555-555555555555", "payment_method": "credit",
                        "customer_id": custA, "amount_paid": 2000,
                        "items": [{"product_id": pidA, "quantity": 2}]},
     token=tokA, csrf=csrfA, expect=201, label="sale-credit")
print("   customer balance:", sql(f"SELECT balance FROM smartbook.customers WHERE id={custA}"),
      "| ledger entries:", sql(f"SELECT COUNT(*) FROM smartbook.customer_credit WHERE customer_id={custA}"))

print("== 12. offline sync push (batch, mixed duplicate + new) ==")
c, sync1 = call("POST", "/sync/push", {
    "device_id": "dev-test",
    "sales": [sale_body],  # duplicate on purpose
    "expenses": [{"client_uuid": "66666666-6666-4666-8666-666666666666",
                  "category": "transport", "description": "Boda fare", "amount": 3000,
                  "spent_at": "2026-10-29 10:00:00"}]
}, token=tokA, csrf=csrfA, expect=200, label="sync-push")
print("   summary:", json.dumps(sync1["data"]["summary"]))
print("   stock after replayed sync:", sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"))

print("== 13. offline stock movement delta merges instead of overwriting ==")
call("POST", "/sync/push", {"device_id": "dev-test", "stock_movements": [
    {"client_uuid": "77777777-7777-4777-8777-777777777777", "product_id": pidA,
     "movement_type": "purchase", "qty_change": 10, "unit_cost": 3800}
]}, token=tokA, csrf=csrfA, expect=200, label="sync-stock-delta")
print("   stock:", sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"),
      "| ledger sum:", sql(f"SELECT COALESCE(SUM(qty_change),0) FROM smartbook.inventory_movements WHERE product_id={pidA}"))

print("== 14. sync pull with cursor ==")
c, pull = call("GET", "/sync/pull", token=tokA, expect=200, label="sync-pull")
print("   cursor:", pull["data"]["cursor"], "| products:", len(pull["data"]["data"]["products"]),
      "| sales:", len(pull["data"]["data"]["sales"]))

print("== 15. CROSS-SHOP ACCESS: shop B cannot touch shop A's records ==")
c, regB = call("POST", "/auth/register", {
    "owner_name": "Other Owner", "name": f"Other Shop {uniq}", "phone": "0772000222",
    "location": "Kampala Road", "username": f"other{uniq}", "password": "Secret123", "plan": "basic"
}, expect=201, label="register-shop-B")
tokB, csrfB = regB["data"]["token"], regB["data"]["csrf"]
call("GET", f"/products/get?id={pidA}", token=tokB, expect=404, label="cross-shop-read-product-A")
call("POST", "/sales", {"client_uuid": "88888888-8888-4888-8888-888888888888",
                        "payment_method": "cash", "items": [{"product_id": pidA, "quantity": 1}]},
     token=tokB, csrf=csrfB, expect=422, label="cross-shop-sell-product-A")
call("GET", f"/customers/ledger?id={custA}", token=tokB, expect=404, label="cross-shop-read-customer-A")
print("   shop A stock untouched:", sql(f"SELECT stock_qty FROM smartbook.products WHERE id={pidA}"))

print("== 16. RBAC: cashier cannot manage products or employees ==")
call("POST", "/employees", {"full_name": "Cashier One", "username": f"cash{uniq}",
                            "password": "1234", "role": "cashier"},
     token=tokA, csrf=csrfA, expect=200, label="employee-create-cashier")
c, cl = call("POST", "/auth/login", {"username": f"cash{uniq}", "password": "1234"}, expect=200, label="cashier-login")
tokC, csrfC = cl["data"]["token"], cl["data"]["csrf"]
print("   cashier permissions:", cl["data"]["permissions"])
call("POST", "/products", {"name": "Should Fail", "selling_price": 1}, token=tokC, csrf=csrfC, expect=403, label="cashier-cannot-add-product")
call("GET", "/employees", token=tokC, expect=403, label="cashier-cannot-list-employees")
call("POST", "/sales", {"client_uuid": "99999999-9999-4999-8999-999999999999", "payment_method": "cash",
                        "items": [{"product_id": pidA, "quantity": 1}]},
     token=tokC, csrf=csrfC, expect=201, label="cashier-can-sell")

print("== 17. expired subscription: writes blocked, reads still work ==")
sql(f"UPDATE smartbook.shops SET subscription_status='expired', subscription_expires_at=DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id={shopA}")
call("POST", "/sales", {"client_uuid": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "payment_method": "cash",
                        "items": [{"product_id": pidA, "quantity": 1}]},
     token=tokA, csrf=csrfA, expect=402, label="expired-blocks-new-sale")
call("GET", "/dashboard", token=tokA, expect=200, label="expired-allows-read-dashboard")
call("GET", "/products", token=tokA, expect=200, label="expired-allows-read-products")
c, rep = call("GET", "/reports?period=month", token=tokA, expect=200, label="expired-allows-read-reports")
print("   records still visible: revenue", rep["data"]["totals"]["revenue"], "| sales rows",
      sql(f"SELECT COUNT(*) FROM smartbook.sales WHERE shop_id={shopA}"))

print("== 18. renewal: shop submits payment, admin verifies, writes resume ==")
c, plans = call("GET", "/meta/plans", expect=200, label="plans")
std = [p for p in plans["data"] if p["code"] == "standard"][0]
c, subpay = call("POST", "/subscription", {"plan_id": std["id"], "period_months": 1,
                                           "method": "mtn_momo", "reference": "MP2601TEST01",
                                           "client_uuid": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"},
                 token=tokA, csrf=csrfA, expect=201, label="subscription-submit")
pay_id = subpay["data"]["payment_id"]
print("   payment", pay_id, "status", subpay["data"]["status"], "amount", subpay["data"]["amount_ugx"], "| provider", subpay["data"]["provider"]["mode"])

c, adm = call("POST", "/auth/admin-login", {"username": "sysadmin", "password": "Admin@2026"}, expect=200, label="admin-login")
atok, acsrf = adm["data"]["token"], adm["data"]["csrf"]
call("GET", "/admin/overview", token=tokA, expect=401, label="shop-token-rejected-by-admin-api")
c, ov = call("GET", "/admin/overview", token=atok, expect=200, label="admin-overview")
print("   shops:", ov["data"]["shops"], "| pending payments:", ov["data"]["pending_payments"])
c, ver = call("POST", "/admin/verify-payment", {"payment_id": pay_id, "approve": True, "note": "MoMo confirmed"},
              token=atok, csrf=acsrf, expect=200, label="admin-verify-payment")
print("   new expiry:", ver["data"]["subscription_expires_at"])
call("POST", "/sales", {"client_uuid": "cccccccc-cccc-4ccc-8ccc-cccccccccccc", "payment_method": "cash",
                        "items": [{"product_id": pidA, "quantity": 1}]},
     token=tokA, csrf=csrfA, expect=201, label="renewed-can-sell-again")
print("   subscription history rows:", sql(f"SELECT COUNT(*) FROM smartbook.subscriptions WHERE shop_id={shopA}"))

print("== 19. reports + analytics + audit + backup ==")
c, d = call("GET", "/dashboard", token=tokA, expect=200, label="dashboard")
print("   today:", d["data"]["sales_today"], "| profit:", d["data"]["profit"])
call("GET", "/analytics", token=tokA, expect=200, label="analytics")
print("   analytics engine:", call("GET", "/analytics", token=tokA)[1]["data"]["engine"])
c, bk = call("GET", "/backup", token=tokA, expect=200, label="backup-export")
print("   backup sections:", list(bk["data"].keys()))
call("GET", "/audit", token=tokA, expect=200, label="audit-log")
print("   audit rows:", len(call("GET", "/audit", token=tokA)[1]["data"]))

print("== 20. restore refuses another shop's backup ==")
call("POST", "/backup", {"backup": {"meta": {"shop_id": shopB if 'shopB' in dir() else regB["data"]["shop"]["id"]}}},
     token=tokA, csrf=csrfA, expect=403, label="restore-tenant-mismatch")

print("== 21. stock integrity repair ==")
c, rp = call("POST", "/sync/repair", {}, token=tokA, csrf=csrfA, expect=200, label="sync-repair")
print("   ", json.dumps(rp["data"]))

print("\n================ RESULT ================")
print(f"PASSED {len(PASS)}   FAILED {len(FAIL)}")
for f in FAIL:
    print("FAIL:", f)
sys.exit(1 if FAIL else 0)
