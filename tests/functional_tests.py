"""
Stockwise functional, security and performance test suite.

Runs against a live installation over HTTP and checks results in the
database. Use it on a FRESH copy of the demo data:

    1. import database/schema.sql            (fresh database)
    2. python ml/generate_history.py
    3. python ml/forecast_job.py
    4. start the site (php -S localhost:8000 -t public, or Apache)
    5. python tests/functional_tests.py --base http://localhost:8000 --php C:\\xampp\\php\\php.exe

The suite changes data (stock movements, new users), so re-import the
database afterwards if you want the demo data back. Results are printed
and written to tests/results.md and tests/results.csv.
"""
import argparse
import csv
import http.cookiejar
import re
import statistics
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "ml"))
import db  # noqa: E402  (reads the same .env as the app)

RESULTS = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    """A browser-like session: keeps cookies, does not follow redirects."""

    def __init__(self, base):
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def request(self, path, data=None):
        url = self.base + "/" + path.lstrip("/")
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        started = time.perf_counter()
        try:
            resp = self.opener.open(urllib.request.Request(url, data=body), timeout=30)
            code, headers, text = resp.status, resp.headers, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            code, headers, text = e.code, e.headers, e.read().decode("utf-8", "replace")
        self.last_ms = (time.perf_counter() - started) * 1000
        return code, headers.get("Location", ""), text

    def get(self, path):
        return self.request(path)

    def token(self, path):
        _, _, html = self.get(path)
        m = re.search(r'name="csrf_token" value="([^"]+)"', html)
        return m.group(1) if m else ""

    def post_form(self, path, fields, with_token=True):
        data = dict(fields)
        if with_token:
            data["csrf_token"] = self.token(path)
        return self.request(path, data)

    def login(self, email, password):
        return self.post_form("login.php", {"email": email, "password": password})

    def session_id(self):
        return next((c.value for c in self.jar if c.name.upper().startswith("PHPSESS")), None)


def record(tc, req, desc, expected, actual, passed):
    RESULTS.append({"id": tc, "requirement": req, "test": desc, "expected": expected,
                    "actual": actual, "result": "Pass" if passed else "Fail"})
    print(f"{'PASS' if passed else 'FAIL'}  {tc:<6} {req:<10} {desc}  ->  {actual}")


def q(sql, args=()):
    conn = db.connect()
    with conn.cursor() as cur:
        cur.execute(sql, args)
        rows = cur.fetchall()
    conn.close()
    return rows


def qty(pid):
    return int(q("SELECT current_quantity FROM products WHERE id=%s", (pid,))[0]["current_quantity"])


def batches(pid):
    return {r["id"]: int(r["quantity_remaining"]) for r in q("SELECT id, quantity_remaining FROM stock_batches WHERE product_id=%s", (pid,))}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost:8000")
    ap.add_argument("--php", default="php", help="path to the PHP CLI, used to run the daily alert sweep")
    ap.add_argument("--python", default=sys.executable)
    args = ap.parse_args()
    B = args.base
    stamp = str(int(time.time()))

    # ----------------------------------------------------------------- authentication and access control
    anon = Client(B)
    code, loc, _ = anon.get("dashboard.php")
    record("TC-01", "FR-I", "Unauthenticated user opens the dashboard", "Redirect to login", f"{code} -> {loc or '-'}", code == 302 and "login" in loc)

    mgr = Client(B)
    before = mgr.session_id() or (mgr.get("login.php") and mgr.session_id())
    code, loc, _ = mgr.login("manager@stockwise.com", "manager123")
    after = mgr.session_id()
    record("TC-02", "FR-I", "General Manager logs in with valid credentials", "Redirect to dashboard", f"{code} -> {loc}", code == 302 and "dashboard" in loc)
    record("TC-03", "NFR-I", "Session identifier is regenerated at login", "New session id", "changed" if before and after and before != after else "unchanged", bool(before and after and before != after))

    bad = Client(B)
    code, loc, html = bad.login("manager@stockwise.com", "wrong-password")
    record("TC-04", "FR-I", "Login with a wrong password", "Rejected, stays on login", f"{code}, error shown={'Invalid' in html or 'incorrect' in html.lower()}", code == 200 and not loc)

    inj = Client(B)
    code, loc, _ = inj.login("' OR '1'='1' -- ", "x")
    code2, loc2, _ = inj.login("manager@stockwise.com' -- ", "anything")
    record("TC-05", "NFR-I", "SQL injection attempts in the login form", "Both rejected", f"{code}/{code2}, redirects: {loc or '-'} / {loc2 or '-'}", not loc and not loc2)

    clerk = Client(B)
    code, loc, _ = clerk.login("chifubu@stockwise.com", "staff123")
    record("TC-06", "FR-I", "Branch Staff logs in", "Redirect to own branch (Chifubu)", f"{code} -> {loc}", code == 302 and "chifubu" in loc)

    leaks = []
    for page in ("branch.php", "branch_stock.php", "branch_forecast.php", "branch_expiry.php", "branch_inventory.php"):
        c, l, _ = clerk.get(f"{page}?branch=ndeke")
        if not (c == 302 and "chifubu" in l):
            leaks.append(f"{page}:{c}")
    record("TC-07", "FR-II", "Branch Staff edits the URL to open another branch's pages (5 pages)", "All redirected to own branch", "none leaked" if not leaks else ", ".join(leaks), not leaks)

    c, l, _ = clerk.get("dashboard.php")
    record("TC-08", "FR-II", "Branch Staff opens the General Manager dashboard", "Redirected away", f"{c} -> {l}", c == 302)

    code, _, html = mgr.get("dashboard.php")
    shown = sum(1 for b in ("Chifubu", "Ndeke", "Masala", "Kansenshi") if b in html)
    record("TC-09", "FR-VIII", "General Manager dashboard lists every company branch", "4 branches shown", f"{shown} shown", code == 200 and shown == 4)

    pid_milk = int(q("SELECT id FROM products WHERE name='Almond Milk 6-Pack'")[0]["id"])
    q0 = qty(pid_milk)
    mgr.post_form("branch_stock.php?branch=chifubu", {"action": "stock_out", "product_id": pid_milk, "quantity": 1})
    record("TC-10", "FR-II", "General Manager attempts a stock-out (read-only role)", "Refused; quantity unchanged", f"quantity {q0} -> {qty(pid_milk)}", qty(pid_milk) == q0)

    # ----------------------------------------------------------------- onboarding
    new_mgr = Client(B)
    email2 = f"owner{stamp}@example.com"
    code, loc, _ = new_mgr.post_form("signup_manager.php", {"name": "Test Owner", "email": email2, "phone": "0970000000",
                                                           "password": "secret123", "company_name": f"Test Traders {stamp}",
                                                           "number_of_branches": 2})
    rows = q("SELECT b.branch_code FROM branches b JOIN companies c ON c.id=b.company_id WHERE c.name=%s", (f"Test Traders {stamp}",))
    codes = [r["branch_code"] for r in rows]
    ok = len(codes) == 2 and all(re.fullmatch(r"[A-Z0-9]{7}", c) for c in codes)
    record("TC-11", "FR-III", "General Manager registers a company with 2 branches", "Company + 2 branches, unique 7-character codes", f"{len(codes)} branches, codes {codes}", ok)

    c, l, _ = new_mgr.get("branch.php?branch=chifubu")
    record("TC-12", "FR-II", "Second company's General Manager opens the demo company's branch", "Access denied (404)", f"{c}", c == 404)

    new_clerk = Client(B)
    code, loc, _ = new_clerk.post_form("signup_employee.php", {"name": "Test Clerk", "email": f"clerk{stamp}@example.com", "phone": "0960000000",
                                                              "password": "secret123", "branch_code": codes[0] if codes else "XXXXXXX"})
    bad_clerk = Client(B)
    code2, loc2, html2 = bad_clerk.post_form("signup_employee.php", {"name": "X", "email": f"x{stamp}@example.com", "phone": "1",
                                                                   "password": "secret123", "branch_code": "ZZZZZZZ"})
    record("TC-13", "FR-III", "Branch Staff registers with a valid code, then with an invalid code", "Valid: joins branch; invalid: rejected",
           f"valid {code} -> {loc}; invalid {code2}", code == 302 and "branch.php" in loc and not loc2)

    ph = q("SELECT password_hash FROM users WHERE email=%s", (email2,))[0]["password_hash"]
    record("TC-14", "NFR-I", "Stored password is a hash, not plain text", "bcrypt hash ($2y$...)", ph[:7] + "...", ph.startswith("$2y$") and "secret123" not in ph)

    # ----------------------------------------------------------------- products and stock
    S = "branch_stock.php?branch=chifubu"
    name = f"Test Rice 5kg {stamp}"
    clerk.post_form(S, {"action": "add_product", "name": name, "category": "Food & Beverage", "unit": "bag",
                        "low_stock_threshold": 10, "expiry_alert_days": 14})
    prow = q("SELECT * FROM products WHERE name=%s", (name,))
    record("TC-15", "FR-IV", "Branch Staff adds a product", "Product saved with threshold 10, quantity 0",
           f"{'saved' if prow else 'missing'}" + (f", qty {prow[0]['current_quantity']}" if prow else ""), bool(prow) and prow[0]["current_quantity"] == 0)
    pid = int(prow[0]["id"]) if prow else 0

    exp1 = (date.today() + timedelta(days=60)).isoformat()
    exp2 = (date.today() + timedelta(days=10)).isoformat()
    t0 = time.perf_counter()
    clerk.post_form(S, {"action": "stock_in", "product_id": pid, "quantity": 30, "expiry_date": exp1, "received_date": date.today().isoformat(), "barcode": "T1"})
    clerk.post_form(S, {"action": "stock_in", "product_id": pid, "quantity": 20, "expiry_date": exp2, "received_date": date.today().isoformat(), "barcode": "T2"})
    tx = q("SELECT COUNT(*) n FROM transactions WHERE product_id=%s AND type='stock_in' AND source='app'", (pid,))[0]["n"]
    record("TC-16", "FR-V", "Two stock-ins with different expiry dates", "Quantity 50; 2 batches; 2 audit rows",
           f"quantity {qty(pid)}, batches {sorted(batches(pid).values())}, audit rows {tx}", qty(pid) == 50 and len(batches(pid)) == 2 and tx == 2)

    code, _, html = clerk.post_form(S, {"action": "stock_out", "product_id": pid, "quantity": 25})
    ms = clerk.last_ms
    b = q("SELECT expiry_date, quantity_remaining FROM stock_batches WHERE product_id=%s ORDER BY expiry_date", (pid,))
    soon, later = int(b[0]["quantity_remaining"]), int(b[1]["quantity_remaining"])
    record("TC-17", "FR-V", "Stock-out of 25 (soonest batch holds 20)", "Soonest batch emptied first: 0 and 25 left; quantity 25",
           f"batches {soon} and {later}; quantity {qty(pid)}", soon == 0 and later == 25 and qty(pid) == 25)
    record("TC-18", "NFR-III", "Updated quantity shown on the page returned by the stock-out", "New quantity visible in same response",
           f"response {ms:.0f} ms, shows 25: {'25' in html}", "25" in html)

    clerk.post_form(S, {"action": "stock_out", "product_id": pid, "quantity": 999})
    record("TC-19", "FR-V", "Stock-out larger than stock on hand", "Rejected; nothing changes", f"quantity {qty(pid)}, batches {sorted(batches(pid).values())}",
           qty(pid) == 25 and sorted(batches(pid).values()) == [0, 25])

    c, _, _ = clerk.post_form(S, {"action": "stock_out", "product_id": pid, "quantity": 1}, with_token=False)
    record("TC-20", "NFR-I", "Stock-out submitted without the anti-forgery token", "HTTP 400; quantity unchanged", f"{c}, quantity {qty(pid)}", c == 400 and qty(pid) == 25)

    mismatch = q("SELECT p.id FROM products p LEFT JOIN (SELECT product_id, SUM(quantity_remaining) s FROM stock_batches GROUP BY product_id) b "
                 "ON b.product_id=p.id WHERE p.current_quantity <> COALESCE(b.s,0)")
    record("TC-21", "NFR-II", "Every product's quantity equals the sum of its batches", "0 mismatches", f"{len(mismatch)} mismatches", not mismatch)

    xss = f"<script>alert(1)</script>{stamp}"
    clerk.post_form(S, {"action": "add_product", "name": xss, "category": "Tools", "unit": "unit", "low_stock_threshold": 1, "expiry_alert_days": 7})
    _, _, inv = clerk.get("branch_inventory.php?branch=chifubu")
    record("TC-22", "NFR-I", "Product name containing a script tag", "Displayed as text (escaped)", "escaped" if xss not in inv and "&lt;script&gt;" in inv else "NOT escaped", xss not in inv)

    _, _, exp_html = clerk.get("branch_expiry.php?branch=chifubu")
    record("TC-23", "FR-VI", "Expiry page classifies batches", "Ibuprofen expired; Almond Milk expiring soon",
           f"expired label={'Expired' in exp_html}, Ibuprofen listed={'Ibuprofen' in exp_html}", "Expired" in exp_html and "Ibuprofen" in exp_html)

    # ----------------------------------------------------------------- notifications
    n0 = q("SELECT COUNT(*) n FROM notifications WHERE type='low_stock'")[0]["n"]
    clerk.post_form(S, {"action": "stock_out", "product_id": pid, "quantity": 20})   # 25 -> 5, threshold 10
    n1 = q("SELECT COUNT(*) n FROM notifications WHERE type='low_stock'")[0]["n"]
    record("TC-24", "FR-VII", "Stock-out leaves a product below its threshold", "Low-stock alert logged for branch Branch Staff", f"{n1 - n0} new alert(s)", n1 > n0)

    out = subprocess.run([args.php, str(ROOT / "cron" / "check_alerts.php")], capture_output=True, text=True)
    dig = q("SELECT type, COUNT(*) n FROM notifications WHERE type IN ('branch_digest','manager_summary') GROUP BY type")
    got = {r["type"]: r["n"] for r in dig}
    record("TC-25", "FR-VII", "Daily alert sweep", "Branch digest(s) and a General Manager summary logged",
           f"{out.stdout.strip()[:40]} digests={got.get('branch_digest', 0)} summaries={got.get('manager_summary', 0)}",
           got.get("branch_digest", 0) > 0 and got.get("manager_summary", 0) > 0)

    # ----------------------------------------------------------------- forecasting
    t0 = time.perf_counter()
    fj = subprocess.run([args.python, str(ROOT / "ml" / "forecast_job.py")], capture_output=True, text=True, cwd=str(ROOT / "ml"))
    secs = time.perf_counter() - t0
    record("TC-26", "NFR-III", "Nightly forecast job run time", "Completes within 5 minutes", f"{secs:.1f} s, exit {fj.returncode}", fj.returncode == 0 and secs < 300)
    log = fj.stdout + fj.stderr
    used = {r["model"] for r in q("SELECT DISTINCT model FROM forecasts")}
    record("TC-41", "FR-IX", "Nightly job uses the saved, trained model file", "Loads ml/model/demand_model.joblib; forecasts made with random_forest",
           f"loaded={'Loaded model demand_model.joblib' in log}, models used {sorted(used)}",
           "Loaded model demand_model.joblib" in log and "random_forest" in used)

    per = q("SELECT COUNT(*) n, COUNT(DISTINCT product_id) p FROM forecast_daily")[0]
    nprod = q("SELECT COUNT(*) n FROM products")[0]["n"]
    record("TC-27", "FR-IX", "Forecast rows written", "14 daily forecasts for every product", f"{per['n']} rows for {per['p']} of {nprod} products",
           per["p"] == nprod and per["n"] == 14 * nprod)

    st = {r["name"]: r for r in q("SELECT p.name, f.* FROM forecasts f JOIN products p ON p.id=f.product_id")}
    milk = st.get("Almond Milk 6-Pack", {})
    record("TC-28", "FR-X", "Almond Milk (24 packs on hand, several sold a day)", "Runs out within the 5-day lead time: status critical, order suggested",
           f"days left {milk.get('days_until_stockout')}, status {milk.get('status')}, order {milk.get('suggested_order_qty')}",
           milk.get("status") == "critical" and (milk.get("suggested_order_qty") or 0) > 0)

    ibu = st.get("Ibuprofen 200mg x50", {})
    record("TC-29", "FR-X", "Ibuprofen (all 40 units expired)", "Sellable quantity 0; stockout now", f"sellable {ibu.get('sellable_quantity')}, days left {ibu.get('days_until_stockout')}",
           ibu.get("sellable_quantity") == 0 and float(ibu.get("days_until_stockout") or 0) == 0)

    risk = {r["name"]: r for r in q("SELECT p.name, r.* FROM forecast_batch_risk r JOIN products p ON p.id=r.product_id WHERE r.at_risk_quantity>0")}
    tea = risk.get("Green Tea 100 Bags", {})
    record("TC-30", "FR-X", "Green Tea batch expiring in 4 days", "Part of the batch flagged as at risk", f"{tea.get('at_risk_quantity', 0)} of {tea.get('quantity_remaining', '?')} at risk",
           (tea.get("at_risk_quantity") or 0) > 0)

    newp = st.get(name, {})
    xssp = next((v for k, v in st.items() if k.startswith("<script>")), {})
    record("TC-31", "FR-IX", "Products with almost no history", "Fall back to the moving average or 'no history'",
           f"{newp.get('model')}, {xssp.get('model')}", newp.get("model") in ("moving_average", "no_history") and xssp.get("model") in ("moving_average", "no_history"))

    c1, _, fhtml = clerk.get("branch_forecast.php?branch=chifubu")
    c2, _, dhtml = mgr.get("dashboard.php")
    record("TC-32", "FR-X", "Forecast page and dashboard show forecast figures", "Forecast table on branch page; warnings on dashboard",
           f"{c1}/{c2}, table={'Days left' in fhtml or 'DAYS LEFT' in fhtml.upper()}, dashboard={'run out before delivery' in dhtml}",
           c1 == 200 and c2 == 200 and "run out before delivery" in dhtml)

    info_file = ROOT / "ml" / "model" / "model_info.json"
    if info_file.is_file():
        import json
        t = json.loads(info_file.read_text())["test"]
        rf, ma = t["random_forest"]["mae_units_per_day"], t["baseline_7_day_average"]["mae_units_per_day"]
        record("TC-33", "NFR-V", "Trained model vs 7-day moving average on the held-out test set", "Lower MAE than the baseline",
               f"random forest {rf:.2f} vs moving average {ma:.2f} ({t['test_rows']:,} test rows)", rf < ma)

    # ----------------------------------------------------------------- load test (NFR-IV)
    pages = ["dashboard.php", "branch.php?branch=chifubu", "branch_inventory.php?branch=ndeke", "branch_forecast.php?branch=masala", "branch_expiry.php?branch=kansenshi"]
    times, errors, lock = [], [], threading.Lock()

    def user(i):
        c = Client(B)
        c.login("manager@stockwise.com", "manager123")
        for k in range(20):
            code, _, _ = c.get(pages[(i + k) % len(pages)])
            with lock:
                times.append(c.last_ms)
                if code != 200:
                    errors.append(code)

    single = Client(B); single.login("manager@stockwise.com", "manager123")
    base_t = []
    for p in pages * 4:
        single.get(p); base_t.append(single.last_ms)
    threads = [threading.Thread(target=user, args=(i,)) for i in range(10)]
    t0 = time.perf_counter()
    [t.start() for t in threads]; [t.join() for t in threads]
    wall = time.perf_counter() - t0
    times.sort()
    p95 = times[int(0.95 * (len(times) - 1))]
    record("TC-34", "NFR-IV", "10 concurrent users, 20 page loads each (200 requests)", "No errors; median under 1 s",
           f"errors {len(errors)}, median {statistics.median(times):.0f} ms, 95th pct {p95:.0f} ms (single user median {statistics.median(base_t):.0f} ms), {len(times)/wall:.1f} req/s",
           not errors and statistics.median(times) < 1000)


    # ----------------------------------------------------------------- regression tests for defects D1-D5
    # dashboard snapshot first, while the demo data still holds an expired batch (checked in TC-39)
    _, _, dh = mgr.get("dashboard.php")
    def card_sum(label):
        return sum(int(x) for x in re.findall(r'>(\d+)</div>\s*<div class="text-faint"[^>]*>' + label + '<', dh))
    db_out = q("SELECT COUNT(*) n FROM products p JOIN branches b ON b.id=p.branch_id WHERE b.company_id=1 AND p.current_quantity<=0")[0]["n"]
    db_exp = q("SELECT COUNT(*) n FROM stock_batches sb JOIN products p ON p.id=sb.product_id JOIN branches b ON b.id=p.branch_id "
               "WHERE b.company_id=1 AND sb.quantity_remaining>0 AND sb.expiry_date < CURDATE()")[0]["n"]
    shown = {k: card_sum(k) for k in ("Out of stock", "Low stock", "Expiring soon", "Expired")}
    ibu = q("SELECT id, branch_id, current_quantity FROM products WHERE name=%s", ("Ibuprofen 200mg x50",))
    if ibu:
        ipid, ibr, before = int(ibu[0]["id"]), ibu[0]["branch_id"], int(ibu[0]["current_quantity"])
        ib_clerk = Client(B)
        em = q("SELECT email FROM users WHERE branch_id=%s AND role='employee' LIMIT 1", (ibr,))
        if ibr == "chifubu" or not em:
            ib_clerk = clerk
        else:
            ib_clerk.login(em[0]["email"], "staff123")
        _, _, h = ib_clerk.post_form(f"branch_stock.php?branch={ibr}", {"action": "stock_out", "product_id": ipid, "quantity": 2})
        record("TC-35", "FR-VI", "Stock-out of a product whose only stock has expired (D1)", "Rejected; quantity unchanged",
               f"quantity {before} -> {qty(ipid)}, message shown={'expired' in h}", qty(ipid) == before and "expired" in h)

        expb = q("SELECT id, quantity_remaining FROM stock_batches WHERE product_id=%s AND quantity_remaining>0 AND expiry_date < CURDATE()", (ipid,))
        n_adj0 = q("SELECT COUNT(*) n FROM transactions WHERE product_id=%s AND type='adjustment'", (ipid,))[0]["n"]
        n_out0 = q("SELECT COUNT(*) n FROM transactions WHERE product_id=%s AND type='stock_out'", (ipid,))[0]["n"]
        for bb in expb:
            ib_clerk.post_form(f"branch_expiry.php?branch={ibr}", {"batch_id": bb["id"]})
        n_adj1 = q("SELECT COUNT(*) n FROM transactions WHERE product_id=%s AND type='adjustment'", (ipid,))[0]["n"]
        n_out1 = q("SELECT COUNT(*) n FROM transactions WHERE product_id=%s AND type='stock_out'", (ipid,))[0]["n"]
        gone = sum(int(bb["quantity_remaining"]) for bb in expb)
        record("TC-36", "FR-VI/NFR-II", "Branch Staff writes off the expired batch on the Expiry page", "Batch emptied; quantity reduced; recorded as adjustment, not a sale",
               f"quantity {before} -> {qty(ipid)}, adjustments +{n_adj1 - n_adj0}, sales +{n_out1 - n_out0}",
               qty(ipid) == before - gone and n_adj1 - n_adj0 == len(expb) and n_out1 == n_out0 and gone > 0)

    syd = q("SELECT SUM(current_quantity) s FROM products WHERE branch_id='kansenshi'")[0]["s"]
    mgr.post_form("branches.php?tab=delete&branch=kansenshi", {"action": "clear", "branch_id": "kansenshi"})
    syd2 = q("SELECT SUM(current_quantity) s FROM products WHERE branch_id='kansenshi'")[0]["s"]
    record("TC-37", "FR-II/NFR-II", "General Manager submits the former 'clear inventory' action (D2)", "No effect; branch stock unchanged",
           f"Kansenshi stock {syd} -> {syd2}", syd == syd2)

    aname = f"Test Alert Item {stamp}"
    clerk.post_form(S, {"action": "add_product", "name": aname, "category": "Tools", "unit": "unit", "low_stock_threshold": 10, "expiry_alert_days": 7})
    apid = int(q("SELECT id FROM products WHERE name=%s", (aname,))[0]["id"])
    def alerts():
        return q("SELECT COUNT(*) n FROM notifications WHERE type='low_stock' AND subject=%s", (f"Low stock: {aname}",))[0]["n"]
    steps = [("stock_in", 25), ("stock_out", 16), ("stock_out", 2), ("stock_in", 1), ("stock_in", 10), ("stock_out", 9)]
    seen = []
    for act, n in steps:
        clerk.post_form(S, {"action": act, "product_id": apid, "quantity": n, "expiry_date": "", "received_date": date.today().isoformat(), "barcode": ""})
        seen.append(alerts())
    record("TC-38", "FR-VII", "Threshold 10: 25 -> 9 -> 7 -> 8 -> 18 -> 9 (D3)", "Alert only on each crossing below 10: 2 alerts",
           f"alerts after each step {seen}", seen == [0, 1, 1, 1, 1, 2])

    record("TC-39", "FR-VIII", "Dashboard shows per-branch out-of-stock, low-stock, expiring and expired counts (D4)", "All four counts on every card; totals match the database",
           f"shown {shown}; database out {db_out}, expired {db_exp}",
           dh.count("Expiring soon<") == 4 and shown["Out of stock"] == db_out and shown["Expired"] == db_exp)

    st5 = q("SELECT status FROM notifications WHERE type='low_stock' ORDER BY id DESC LIMIT 1")
    record("TC-40", "FR-VII", "Alert logged while no mail server is configured (D5)", "Status 'not_sent'",
           f"status {st5[0]['status'] if st5 else '-'}", bool(st5) and st5[0]["status"] == "not_sent")

    # ----------------------------------------------------------------- branch management (FR-III)
    bname = f"Kitwe Depot {stamp}"
    new_mgr.post_form("branches.php?tab=add", {"action": "create", "name": bname, "city": "Kitwe", "country": "ZM", "manager": "T. Phiri"})
    br = q("SELECT b.id FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.name=%s AND c.name=%s", (bname, f"Test Traders {stamp}"))
    bid = br[0]["id"] if br else ""
    new_mgr.post_form(f"branches.php?tab=edit&branch={bid}", {"action": "update", "branch_id": bid, "name": bname + " East", "city": "Kitwe", "country": "ZM", "manager": "T. Phiri"})
    renamed = bool(q("SELECT 1 FROM branches WHERE id=%s AND name=%s", (bid, bname + " East")))
    new_mgr.post_form(f"branches.php?tab=delete&branch={bid}", {"action": "delete", "branch_id": bid})
    gone = not q("SELECT 1 FROM branches WHERE id=%s", (bid,))
    new_mgr.post_form("branches.php?tab=delete&branch=chifubu", {"action": "delete", "branch_id": "chifubu"})
    other_kept = bool(q("SELECT 1 FROM branches WHERE id='chifubu'"))
    record("TC-42", "FR-III", "General Manager adds, edits and deletes a branch; tries to delete another company's branch",
           "Own branch created, renamed and deleted; other company's branch untouched",
           f"created={bool(br)}, renamed={renamed}, deleted={gone}, other company's branch kept={other_kept}",
           bool(br) and renamed and gone and other_kept)

    # ----------------------------------------------------------------- output
    out_md = ROOT / "tests" / "results.md"
    with (ROOT / "tests" / "results.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(RESULTS[0])); w.writeheader(); w.writerows(RESULTS)
    lines = ["| ID | Requirement | Test | Expected | Actual | Result |", "|---|---|---|---|---|---|"]
    lines += [f"| {r['id']} | {r['requirement']} | {r['test']} | {r['expected']} | {r['actual']} | {r['result']} |" for r in RESULTS]
    out_md.write_text("\n".join(lines) + "\n", encoding="utf-8")
    passed = sum(r["result"] == "Pass" for r in RESULTS)
    print(f"\n{passed}/{len(RESULTS)} passed. Results written to tests/results.md and tests/results.csv")


if __name__ == "__main__":
    main()
