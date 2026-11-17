#!/usr/bin/env bash
# php -l on every PHP file and the unit tests on each PHP version WHMCS 8.10 to 9.1 supports
# (7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 8.4) and on PHP 8.5, plus the editor's counter test with node.
#
#   bash tools/check.sh            all versions
#   bash tools/check.sh 83 72      only PHP 8.3 and 7.2
#
# Each version gets its own illuminate/database (WHMCS's Capsule is Laravel's), installed once into
# tests/.cache/vendor-phpXY by the composer:2 image: Laravel 7 on PHP 7.2 (as WHMCS 8 ships), 8 on
# 7.3/7.4, 10 on 8.1, 11 on 8.2, 12 on 8.3+. The HTTP tests run against the fake SMSTunnel API
# (tests/fake-api/server.mjs), started here on a free port and stopped at the end. Nothing goes to
# smstunnel.io. Docker: compose project smst-int-whmcs, taken down with -v at the end.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")"
fi
COMPOSE=(docker compose -p smst-int-whmcs -f "$ROOT/tests/docker/docker-compose.yml")
export MSYS_NO_PATHCONV=1

VERSIONS=("$@")
if [ ${#VERSIONS[@]} -eq 0 ]; then
  VERSIONS=(72 73 74 81 82 83 84 85)
fi

illuminate_for() {
  case "$1" in
    72) echo "^7.30" ;;
    73|74) echo "^8.83" ;;
    81) echo "^10.48" ;;
    82) echo "^11.0" ;;
    *) echo "^12.0" ;;
  esac
}
platform_for() {
  case "$1" in
    72) echo "7.2.34" ;; 73) echo "7.3.33" ;; 74) echo "7.4.33" ;; 81) echo "8.1.31" ;;
    82) echo "8.2.27" ;; 83) echo "8.3.15" ;; 84) echo "8.4.2" ;; 85) echo "8.5.0" ;;
  esac
}

PORT_FILE="$ROOT/tests/fake-api/check-port.txt"
rm -f "$PORT_FILE"
node "$ROOT/tests/fake-api/server.mjs" --port 0 --port-file "$PORT_FILE" > "$ROOT/tests/fake-api/check.log" 2>&1 &
FAKE_PID=$!
for _ in $(seq 1 50); do [ -s "$PORT_FILE" ] && break; sleep 0.1; done
PORT="$(cat "$PORT_FILE")"
cleanup() {
  curl -s -X POST "http://127.0.0.1:$PORT/__shutdown" >/dev/null 2>&1 || true
  kill "$FAKE_PID" >/dev/null 2>&1 || true
  rm -f "$PORT_FILE"
  "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT
echo "fake SMSTunnel API on 127.0.0.1:$PORT"

status=0
for v in "${VERSIONS[@]}"; do
  echo "== PHP ${v:0:1}.${v:1}"
  dir="tests/.cache/composer-php$v"
  if [ ! -f "$ROOT/tests/.cache/vendor-php$v/autoload.php" ]; then
    mkdir -p "$ROOT/$dir"
    cat > "$ROOT/$dir/composer.json" <<JSON
{
  "require": { "illuminate/database": "$(illuminate_for "$v")" },
  "config": { "platform": { "php": "$(platform_for "$v")" }, "vendor-dir": "../vendor-php$v", "allow-plugins": false }
}
JSON
    "${COMPOSE[@]}" run --rm -T composer "composer install --working-dir=$dir --no-interaction --no-progress --quiet --ignore-platform-req=ext-*" || { echo "composer failed for PHP $v"; status=1; continue; }
  fi
  "${COMPOSE[@]}" run --rm -T -e SMSTUNNEL_FAKE_URL="http://host.docker.internal:$PORT" "php$v" '
    bad=0
    for f in $(find modules tests/harness tests/unit -name "*.php"); do
      out=$(php -l "$f" 2>&1) || { echo "$out"; bad=1; }
    done
    [ $bad -eq 0 ] && echo "php -l: all files OK"
    php -d memory_limit=256M tests/unit/run.php || bad=1
    exit $bad' || status=1
done

echo "== node: the editor's counter"
node "$ROOT/tests/unit/counter.test.mjs" || status=1
exit $status
