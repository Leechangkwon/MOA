#!/usr/bin/env bash
# Read-only browser verification with reserved review account. Only authentication is POSTed.
set -euo pipefail
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$TASK_ROOT/tests/lib/secrets.sh"
TASK_JAR="$(mktemp)"; trap 'rm -f "$TASK_JAR"' EXIT
TASK_USER=claude_admin01
TASK_PASSWORD=$(sudo -n awk -v u="$TASK_USER" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
TASK_SECRET=$(sudo -n awk -v u="$TASK_USER" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
TASK_BASE=http://127.0.0.1:8082/tbox
for attempt in 1 2 3; do
  TASK_RAW=$(totp_now TASK_SECRET); TASK_CODE=${TASK_RAW%%$'\n'*}
  printf -v TASK_BODY '{"username":"%s","password":"%s","otp":"%s"}' "$TASK_USER" "$TASK_PASSWORD" "$TASK_CODE"
  TASK_REPLY=$(curl_json_secret TASK_BODY -s -c "$TASK_JAR" -A 'tbox-review/2026-10-01' -H 'Content-Type: application/json' "$TASK_BASE/api/login")
  case "$TASK_REPLY" in *'"ok":true'*) break;; esac
  TASK_LEFT=$(printf '%s' "$TASK_RAW" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$TASK_LEFT" ] || TASK_LEFT=31
  echo "다음 인증 코드 창에서 재시도 (${TASK_LEFT}초)"; sleep $((TASK_LEFT+1))
done
case "$TASK_REPLY" in *'"ok":true'*) ;; *) echo '검토 계정 로그인 실패'; exit 1;; esac
TASK_COOKIES=$(awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$TASK_JAR")
py_secret TASK_COOKIES -- python3 "$TASK_ROOT/tests/design_live_browser.py"
