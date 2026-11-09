#!/usr/bin/env python3
"""
SmartBook Solutions - analytics microservice.

PHP remains the system backend; this service only produces the smart
recommendations (forecasting, restock quantities, slow movers, profitability).

It reads the same MySQL database (read-only queries) and exposes:

    GET  /health
    POST /analyze   {"shop_id": 1, "days": 30}

Run:  python3 app.py            (defaults to 127.0.0.1:8000)

If Flask is unavailable the module still works through the CLI:
    python3 app.py --cli --shop 1
"""
from __future__ import annotations

import json
import os
import sys
from datetime import datetime, timedelta

DB = {
    "host": os.getenv("SMARTBOOK_DB_HOST", "127.0.0.1"),
    "user": os.getenv("SMARTBOOK_DB_USER", "root"),
    "password": os.getenv("SMARTBOOK_DB_PASS", ""),
    "database": os.getenv("SMARTBOOK_DB_NAME", "smartbook"),
    "port": int(os.getenv("SMARTBOOK_DB_PORT", "3306")),
}


def connect():
    import pymysql  # imported lazily so --help works without it
    return pymysql.connect(cursorclass=pymysql.cursors.DictCursor, **DB)


# --------------------------------------------------------------------------
# Analysis
# --------------------------------------------------------------------------
def fetch_sales(shop_id: int, days: int):
    since = (datetime.now() - timedelta(days=days)).strftime("%Y-%m-%d 00:00:00")
    with connect() as cx, cx.cursor() as cur:
        cur.execute(
            """SELECT DATE(sold_at) AS day, SUM(total) AS revenue, COUNT(*) AS transactions
                 FROM sales
                WHERE shop_id=%s AND status<>'cancelled' AND sold_at>=%s
                GROUP BY DATE(sold_at) ORDER BY day""",
            (shop_id, since),
        )
        daily = cur.fetchall()
        cur.execute(
            """SELECT i.product_name AS name, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
                 FROM sale_items i JOIN sales s ON s.id=i.sale_id
                WHERE s.shop_id=%s AND s.status<>'cancelled' AND s.sold_at>=%s
                GROUP BY i.product_name ORDER BY qty DESC LIMIT 10""",
            (shop_id, since),
        )
        best = cur.fetchall()
        cur.execute(
            """SELECT p.id, p.name, p.stock_qty, p.min_stock, p.purchase_price, p.selling_price,
                      COALESCE(SUM(CASE WHEN s.sold_at>=%s THEN i.quantity END),0) AS sold_window
                 FROM products p
                 LEFT JOIN sale_items i ON i.product_id=p.id
                 LEFT JOIN sales s ON s.id=i.sale_id AND s.status<>'cancelled'
                WHERE p.shop_id=%s AND p.is_deleted=0 AND p.is_active=1
                GROUP BY p.id, p.name, p.stock_qty, p.min_stock, p.purchase_price, p.selling_price""",
            (since, shop_id),
        )
        products = cur.fetchall()
    return daily, best, products


def forecast(series, horizon=7):
    """Damped linear trend. Returns (daily_avg, slope, forecast_total)."""
    ys = [float(v or 0) for v in series][-14:]
    n = len(ys)
    if n < 3:
        avg = sum(ys) / n if n else 0.0
        return round(avg, 2), 0.0, round(avg * horizon, 2)

    xs = list(range(1, n + 1))
    sx, sy = sum(xs), sum(ys)
    sxy = sum(x * y for x, y in zip(xs, ys))
    sxx = sum(x * x for x in xs)
    denom = (n * sxx - sx * sx) or 1
    slope = (n * sxy - sx * sy) / denom
    intercept = (sy - slope * sx) / n
    total = sum(max(0.0, intercept + slope * (n + d)) for d in range(1, horizon + 1))
    return round(sy / n, 2), round(slope, 2), round(total, 2)


def analyse(shop_id: int, days: int = 30) -> dict:
    daily, best, products = fetch_sales(shop_id, days)
    revenue = [d["revenue"] for d in daily]
    daily_avg, slope, next7 = forecast(revenue)

    # Days of cover per product, from the observed selling rate.
    run_out, slow, dead = [], [], []
    for p in products:
        per_day = float(p["sold_window"] or 0) / days
        stock = float(p["stock_qty"] or 0)
        if per_day <= 0:
            if stock > 0:
                slow.append({"name": p["name"], "stock_qty": stock, "sold": 0})
                if stock > 0:
                    dead.append(p["name"])
            continue
        days_left = stock / per_day
        if days_left <= 7:
            suggested = max(1, int(round(per_day * 14 - stock)))
            run_out.append({
                "product_id": p["id"],
                "name": p["name"],
                "stock_qty": stock,
                "days_left": int(days_left),
                "sold_per_day": round(per_day, 2),
                "suggested_order": suggested,
            })

    run_out.sort(key=lambda r: r["days_left"])
    slow.sort(key=lambda r: r["stock_qty"], reverse=True)

    this_week = sum(revenue[-7:]) if len(revenue) >= 7 else sum(revenue)
    last_week = sum(revenue[-14:-7]) if len(revenue) >= 14 else 0
    pct = round((this_week - last_week) / last_week * 100, 1) if last_week else None

    recommendations = []
    for r in run_out[:5]:
        recommendations.append(
            f"Your {r['name']} stock may run out within {max(1, r['days_left'])} day(s). "
            f"Consider ordering {r['suggested_order']} more."
        )
    for s in slow[:3]:
        recommendations.append(
            f"{s['name']} has {int(s['stock_qty'])} units in stock but no sales in {days} days. "
            "Consider a promotion or a smaller reorder."
        )
    if not recommendations:
        recommendations.append(
            "Stock levels look healthy for the next two weeks based on recent sales."
        )

    margin = [
        {
            "name": p["name"],
            "margin": round(float(p["selling_price"]) - float(p["purchase_price"]), 2),
            "margin_pct": round(
                (float(p["selling_price"]) - float(p["purchase_price"]))
                / float(p["selling_price"]) * 100, 1
            ) if float(p["selling_price"]) else 0,
        }
        for p in products
    ]
    margin.sort(key=lambda m: m["margin"], reverse=True)

    return {
        "generated_at": datetime.now().isoformat(timespec="seconds"),
        "window_days": days,
        "best_sellers": [
            {"product_name": b["name"], "qty": float(b["qty"]), "revenue": float(b["revenue"])}
            for b in best
        ],
        "slow_movers": slow[:5],
        "run_out_soon": run_out[:10],
        "weekly_change": {
            "this_week": round(this_week, 2),
            "last_week": round(last_week, 2),
            "percent": pct,
        },
        "forecast_next_7d": {
            "daily_average": daily_avg,
            "slope": slope,
            "next_7d": next7,
        },
        "profitability": margin[:10],
        "recommendations": recommendations,
        "disclaimer": "These are recommendations based on past sales, not guaranteed outcomes.",
    }


# --------------------------------------------------------------------------
# Optional HTTP layer
# --------------------------------------------------------------------------
def build_app():
    try:
        from flask import Flask, jsonify, request
    except ImportError:
        return None

    app = Flask(__name__)

    @app.get("/health")
    def health():
        return jsonify({"ok": True, "service": "smartbook-analytics", "time": datetime.now().isoformat()})

    @app.post("/analyze")
    def analyze():
        payload = request.get_json(silent=True) or {}
        shop_id = int(payload.get("shop_id", 0))
        days = int(payload.get("days", 30))
        if not shop_id:
            return jsonify({"ok": False, "error": {"code": "VALIDATION", "message": "shop_id is required"}}), 422
        try:
            return jsonify({"ok": True, "data": analyse(shop_id, days)})
        except Exception as exc:  # noqa: BLE001 - reported to the caller
            return jsonify({"ok": False, "error": {"code": "ANALYTICS_ERROR", "message": str(exc)}}), 500

    return app


if __name__ == "__main__":
    if "--cli" in sys.argv:
        shop = int(sys.argv[sys.argv.index("--shop") + 1]) if "--shop" in sys.argv else 1
        print(json.dumps(analyse(shop), indent=2))
        raise SystemExit(0)

    app = build_app()
    if app is None:
        print("Flask is not installed (pip install flask pymysql). Use --cli --shop <id> instead.")
        raise SystemExit(1)
    app.run(host=os.getenv("SMARTBOOK_ANALYTICS_HOST", "127.0.0.1"),
            port=int(os.getenv("SMARTBOOK_ANALYTICS_PORT", "8000")))
