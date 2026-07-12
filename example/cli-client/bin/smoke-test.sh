#!/usr/bin/env bash
#
# Manual smoke test for the CLI client (M4, tasks-m4.md T9).
#
# Starts the stub LLM and the M3 BEAR Swoole app in the background, runs
# the CLI client through the 3 scenarios from tasks-m4.md's DoD checklist
# (parallel tool call, interrupt, multi-turn history), and tears both
# servers down on exit. Not part of `composer tests` — this is a
# convenience wrapper around the manual steps in example/cli-client/README.md
# and example/bear/README.md, not an automated assertion (integration
# tests here deliberately don't start real HTTP servers, D22). Read the
# output yourself: weather_get/news_get should both appear for the
# parallel run, an [interrupt] line should appear for the reminder run,
# and no [error] line should appear anywhere.
#
# Usage: example/cli-client/bin/smoke-test.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
STUB_HOST=127.0.0.1
STUB_PORT=8081
AGUI_HOST=127.0.0.1
AGUI_PORT=8080
STUB_LOG="$(mktemp -t agui-smoke-stub.XXXXXX)"
AGUI_LOG="$(mktemp -t agui-smoke-bear.XXXXXX)"

STUB_PID=""
AGUI_PID=""

cleanup() {
    [ -n "$STUB_PID" ] && kill "$STUB_PID" 2>/dev/null || true
    [ -n "$AGUI_PID" ] && kill "$AGUI_PID" 2>/dev/null || true
}
trap cleanup EXIT

wait_for_http() {
    local url="$1"
    local label="$2"
    for _ in $(seq 1 30); do
        if curl -s -o /dev/null -m 1 "$url"; then
            return 0
        fi
        sleep 0.2
    done
    echo "error: $label did not become ready at $url" >&2
    return 1
}

echo "== starting stub LLM on $STUB_HOST:$STUB_PORT =="
php -S "$STUB_HOST:$STUB_PORT" -t "$ROOT_DIR/example/stub-llm/public" "$ROOT_DIR/example/stub-llm/public/index.php" \
    > "$STUB_LOG" 2>&1 &
STUB_PID=$!
wait_for_http "http://$STUB_HOST:$STUB_PORT/v1/chat/completions" "stub LLM" || {
    cat "$STUB_LOG" >&2
    exit 1
}

echo "== starting M3 bear app on $AGUI_HOST:$AGUI_PORT =="
AGUI_HOST="$AGUI_HOST" AGUI_PORT="$AGUI_PORT" OPENAI_BASE_URL="http://$STUB_HOST:$STUB_PORT/v1" \
    php "$ROOT_DIR/example/bear/public/server.php" > "$AGUI_LOG" 2>&1 &
AGUI_PID=$!
wait_for_http "http://$AGUI_HOST:$AGUI_PORT/ping" "M3 bear app" || {
    cat "$AGUI_LOG" >&2
    exit 1
}

export AGUI_BASE_URL="http://$AGUI_HOST:$AGUI_PORT"
CLI="php $ROOT_DIR/example/cli-client/bin/agui-chat.php"

echo
echo "== scenario 1/3: parallel tool call (weather_get + news_get) =="
$CLI "Weather in Tokyo and the news, please."

echo
echo "== scenario 2/3: interrupt (reminder_put, confirm required) =="
$CLI "Remind me to buy milk."

echo
echo "== scenario 3/3: multi-turn history (2 turns, one process) =="
printf 'What time is it?\nThanks, one more time please?\n' | $CLI

echo
echo "== done: check above for [error] lines (there should be none) =="
