#!/usr/bin/env bash
# 화면 시각 점검 — 검토 계정(qa_admin01)으로 로그인해 화면을 찍고 DOM 결함을 센다.
#   scripts/ui_snapshot.sh <출력 폴더> [폭(기본 1440)]
# 요구: python3 playwright(사용자 로컬 설치, 런타임 의존성 아님 — deploy/DEPENDENCIES.md 「검토 도구」). CLAUDE.md 3장: qa_ 계정 · UA tbox-review/<날짜>.
set -u
OUT="${1:?출력 폴더}"; WIDTH="${2:-1440}"
BASE="http://127.0.0.1:8082/tbox"; UA="tbox-review/$(date +%F)"
HERE="$(cd "$(dirname "$0")" && pwd)"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
who=qa_admin01
pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt)
sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt)
for try in 1 2 3; do
  raw=$(php "$HERE/totp_code.php" "$sec"); code=${raw%%$'\n'*}
  out=$(curl -s -c "$JAR" -A "$UA" -H 'Content-Type: application/json' \
        -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
  case "$out" in *'"ok":true'*) break;; esac
  left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
  echo "  · 코드 재사용 거부 — ${left}초 대기" >&2; sleep $((left+1))
done
case "$out" in *'"ok":true'*) ;; *) echo "로그인 실패: $out" >&2; exit 1;; esac
# 재인증(재인증 관문이 걸린 관리 화면이 홈으로 튀지 않게)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -A "$UA" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $(curl -s -b "$JAR" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')" \
  -d "{\"password\":\"$pw\"}" "$BASE/api/me/reauth"
mkdir -p "$OUT"
# 쿠키 jar → name=value 목록
COOKIES=$(awk '(!/^#/ || /^#HttpOnly_/) && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR")  # curl 은 HttpOnly 쿠키를 #HttpOnly_ 접두로 쓴다
export PATH="$HOME/.local/bin:$PATH"
python3 "$HERE/ui_snapshot.py" "$BASE" "$UA" "$COOKIES" "$OUT" "$WIDTH"
