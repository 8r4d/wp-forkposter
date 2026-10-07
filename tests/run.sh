#!/usr/bin/env bash
# Runs the tests against a throwaway WordPress site in WordPress Playground.
#
#   tests/run.sh            integration tests (PHP, no browser)
#   tests/run.sh --editor   also the block editor browser test (needs Playwright)
#
# Needs Node.js 20+. Set PORT to use a port other than 9480.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-9480}"
URL="http://127.0.0.1:$PORT"
LOG="$(mktemp)"
JAR="$(mktemp)"

cleanup() {
	pkill -f -- "port=$PORT" 2>/dev/null || true
	rm -f "$LOG" "$JAR"
}
trap cleanup EXIT

echo "Starting WordPress Playground on $URL ..."
npx -y @wp-playground/cli@latest server --port="$PORT" --login \
	--mount="$ROOT:/wordpress/wp-content/plugins/forkposter" \
	--mount="$ROOT/tests/integration.php:/wordpress/forkposter-tests.php" \
	--mount="$ROOT/tests/editor-fixture.php:/wordpress/forkposter-editor-fixture.php" \
	>"$LOG" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 150); do
	grep -q "Ready" "$LOG" && break
	if ! kill -0 "$SERVER_PID" 2>/dev/null; then
		cat "$LOG"
		exit 1
	fi
	sleep 2
done
grep -q "Ready" "$LOG" || { echo "Playground didn't start:"; cat "$LOG"; exit 1; }

fetch() { curl -sS -L -c "$JAR" -b "$JAR" "$URL$1"; }

fetch /forkposter-tests.php >/dev/null # First request logs in and activates the plugin.
OUTPUT="$(fetch /forkposter-tests.php)"
echo "$OUTPUT"
STATUS=0
echo "$OUTPUT" | tail -1 | grep -q "^ALL PASSED" || STATUS=1

if [[ "${1:-}" == "--editor" ]]; then
	# The integration tests end by uninstalling; the fixture re-creates what it needs.
	echo
	echo "## Block editor (browser)"
	BASE_URL="$URL" node "$ROOT/tests/editor.mjs" || STATUS=1
fi

exit "$STATUS"
