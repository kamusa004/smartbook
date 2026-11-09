#!/usr/bin/env bash
# SmartBook - start services and run the automated API suite.
# Avoids pkill patterns that match this script's own command line.
set -uo pipefail
cd "$(dirname "$0")"

echo "=== 0. services ==="
(sudo service mariadb start >/dev/null 2>&1) || true
for i in $(seq 1 20); do
  if mysqladmin ping --silent 2>/dev/null || sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 1
done
echo "mariadb: $(sudo mariadb -N -e 'SELECT VERSION()' 2>/dev/null || echo DOWN)"

# free the port without a self-matching pkill
if command -v fuser >/dev/null 2>&1; then fuser -k 8080/tcp >/dev/null 2>&1 || true; fi
sleep 1

echo "=== 1. lint every PHP file ==="
BAD=0
while IFS= read -r f; do
  OUT=$(php -l "$f" 2>&1)
  case "$OUT" in *"No syntax errors"*) ;; *) echo "LINT FAIL: $f"; echo "$OUT"; BAD=1;; esac
done < <(find . -name '*.php' -not -path './tests/*')
echo "php files linted: $(find . -name '*.php' | wc -l), failures: $BAD"

echo "=== 2. database ==="
sudo mariadb -N -e "SELECT CONCAT('tables=', COUNT(*)) FROM information_schema.tables WHERE table_schema='smartbook'"
sudo mariadb -N smartbook -e "SELECT CONCAT('shops=', COUNT(*), ' sample=', SUM(is_sample)) FROM shops"
sudo mariadb -N smartbook -e "SELECT CONCAT('sales=', COUNT(*)) FROM sales"
sudo mariadb -N smartbook -e "SELECT CONCAT('movements=', COUNT(*)) FROM inventory_movements"

echo "=== 3. start php server ==="
nohup php -S 127.0.0.1:8080 router.php > /tmp/php.log 2>&1 &
SRV=$!
sleep 3
echo "pid $SRV"
echo "health: $(curl -s --max-time 10 http://127.0.0.1:8080/api/health)"
for u in / /sw.js /manifest.json /admin.html /assets/css/app.css /assets/js/app.js /assets/vendor/bootstrap.min.css; do
  printf "  %-30s %s\n" "$u" "$(curl -s -o /dev/null --max-time 10 -w '%{http_code}' http://127.0.0.1:8080$u)"
done

echo "=== 4. automated API suite ==="
python3 tests/api_test.py
RC=$?

echo "=== 5. server error log (tail) ==="
grep -i -E "error|warning|fatal" /tmp/php.log | grep -v "Accepted\|Closing" | tail -12 || echo "  (no PHP errors logged)"

kill $SRV 2>/dev/null || true
echo "=== exit code: $RC ==="
exit $RC
