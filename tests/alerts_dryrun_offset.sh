#!/usr/bin/env bash
# tests/alerts_dryrun_offset.sh — 경보 판정 `--dry-run` 은 auth.log 읽기 위치(오프셋·inode)를 저장하지 않는다
#                                (개발 대기열 2c · 점검 봇 발견 2026-09-24 · ARCH 11.4 · SECURITY R-73)
#
# 결함: scripts/alerts.php 가 운영 auth.log 를 읽으면 al_read_auth_log 가 advance=true 를 돌려주고, 그 뒤
#       읽기 위치를 바꾸고 상태 파일에 저장했다 — dry-run 확인 없이. 그래서 root 로 `--dry-run` 을 한 번 치면
#       auth_log_offset 이 앞으로 가서 그 사이 줄을 다음 cron 이 건너뛰었다(AL-3·AL-9 누락).
#       ssh_seen 은 이미 !$dryRun 으로 막고 있었는데 오프셋만 빠져 있었다.
#
# 이 시험: 오프셋 0 인 **임시** 상태 파일을 주고(`--state`), `--auth-log` 없이(= 진짜 auth.log, advance 갈래)
#          root 로 `--rule AL-9 --dry-run` 을 돌린 뒤 상태 파일이 **한 바이트도 안 바뀌었는지** 본다.
#          고치기 전 코드로 돌리면 실패한다(오프셋이 auth.log 끝으로 가서 파일이 다시 쓰인다).
#
# ★ 운영에 쓰지 않는다: 상태 파일은 mktemp 안이고 dry-run 이라 경보(alerts.log·감사)도 남지 않는다.
#   운영에 닿는 것은 읽기 둘뿐 — /var/log/auth.log(root) · 부트스트랩의 DB 연결.
#   이 시험이 치는 `sudo php … alerts.php`·`sudo sha256sum` 은 경보 규칙에 걸리지 않는다(AL-9 는 삭제·통째 읽기 명령만 —
#   `sudo wc -l /var/log/auth.log` 도 읽기 개수만이다). 정리는 일반 rm 이 먼저다.
# 준비물: sudo -n(root 로 php) · php.  나가는 값: 0 통과 · 1 실패 · 3 준비물 없음
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
W="$(mktemp -d)"; chmod 700 "$W"
# dry-run 은 root 로 파일을 만들지 않으니 일반 rm 으로 충분하다 — sudo rm 은 그래도 안 지워질 때만(AL-9 가 절대 경로 rm 을
# 잡게 되는 날 이 시험이 진짜 경보를 내지 않게 — 독립 검토 2c Low)
trap 'rm -rf "$W" 2>/dev/null || sudo -n rm -rf "$W"' EXIT
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

sudo -n true 2>/dev/null || { echo "sudo -n 을 쓸 수 없다 — root 로 auth.log 를 읽어야 성립하는 시험"; exit 3; }
sudo -n test -r /var/log/auth.log || { echo "/var/log/auth.log 가 없다"; exit 3; }
LINES=$(sudo -n wc -l < /var/log/auth.log | tr -d ' ')
[ "${LINES:-0}" -gt 0 ] || { echo "auth.log 가 비어 있다 — 오프셋이 움직일 재료가 없어 이 시험이 성립하지 않는다"; exit 3; }

printf '── dry-run 은 읽기 위치를 저장하지 않는다 (auth.log %s줄)\n' "$LINES"
printf '{\n    "auth_log_inode": 0,\n    "auth_log_offset": 0\n}\n' > "$W/state.json"
BEFORE=$(sha256sum "$W/state.json" | cut -d' ' -f1)
sudo -n php "$ROOT/scripts/alerts.php" --rule AL-9 --dry-run --state "$W/state.json" > "$W/out.txt" 2>&1
RC=$?
AFTER=$(sudo -n sha256sum "$W/state.json" | cut -d' ' -f1)
sed 's/^/    | /' "$W/out.txt" | tail -6
expect "종료 코드 0" "0" "$RC"
expect "잡히지 않은 예외 0(부트스트랩이 실패하면 종료 코드가 0 이어도 판정이 안 돈다)" "0" "$(grep -ci 'uncaught' "$W/out.txt")"
READ_N=$(sed -n 's/.*AL-3·AL-9 .* 새 줄 \([0-9]*\)개.*/\1/p' "$W/out.txt" | tail -1)
expect "진짜 auth.log 를 읽었다(새 줄 1개 이상 — advance 갈래를 탔다)" "yes" "$([ "${READ_N:-0}" -gt 0 ] && echo yes || echo "no(${READ_N:-없음})")"
expect "상태 파일이 한 바이트도 안 바뀌었다(오프셋·inode 저장 0)" "$BEFORE" "$AFTER"
expect "출력이 dry-run 이라 오프셋을 저장하지 않는다고 적는다" "1" "$(grep -c '오프셋을 저장하지 않는다' "$W/out.txt")"

printf '── 정적 — 오프셋 갱신이 dry-run 을 본다\n'
expect "authPos 갱신 조건에 !\$dryRun" "1" "$(grep -c 'if (!\$dryRun && \$read\[.advance.\]' "$ROOT/scripts/alerts.php")"
expect "php -l scripts/alerts.php" "0" "$(php -l "$ROOT/scripts/alerts.php" > /dev/null 2>&1; echo $?)"
expect "bash -n 이 시험" "0" "$(bash -n "$0"; echo $?)"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
