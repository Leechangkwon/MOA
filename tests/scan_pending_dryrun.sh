#!/usr/bin/env bash
# tests/scan_pending_dryrun.sh — 악성코드 검사 배치 `--dry-run` 은 DB·감사에 쓰지 않는다 (개발 대기열 2e · 점검 봇 발견 2026-09-24)
#
# 결함: scripts/scan_pending.php 의 ① 복구 갈래(저장소에 바이트가 없고 격리 폴더에 같은 이름이 있으면 「기록만 마무리」)와
#       ② 한 건 실패 기록 scan_fail() 이 `--dry-run` 이어도 `UPDATE files SET scan_status='infected'`·Audit::deny/error 를 썼다.
#       dry-run 확인은 스캐너 결과를 반영하는 마지막 갈래에만 있었다(대기열 2c alerts.php 와 같은 부류).
#
# 이 시험: 운영 DB 를 **읽어** 만든 임시 DB 와 임시 저장소·격리 폴더로 두 갈래를 일부러 만든 뒤
#   A. `--dry-run` → files(id·scan_status 전체 해시)·audit_logs(최대 id·행 수)·감사 로그 파일이 **그대로**, 출력에 「[예정」
#   B. 대조군: 같은 준비에서 `--dry-run` 없이 → 복구 행이 infected 가 되고 감사가 늘어난다(갈래에 실제로 닿았다는 증거)
# 스캐너는 띄우지 않는다(대상이 전부 복구·분실 갈래라 clamscan 을 부르기 전에 끝난다).
#
# ★ 운영에 쓰지 않는다 — 임시 DB `tbox_scandry_test`(pg_dump | psql 파이프) · 임시 폴더(mktemp) · 임시 .env(DB 이름·저장소·감사 로그만 바꿈).
#   배치는 **일반 사용자로** 임시 사본을 돌린다(잠금 파일은 저장소 형제 경로 — 임시 폴더 안).
# 준비물: sudo -n(postgres psql·pg_dump·createdb·dropdb) · php.  나가는 값: 0 통과 · 1 실패 · 3 준비물 없음
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC_DB="tbox_re"            # 운영 — **읽기만**
DB="tbox_scandry_test"      # 임시 — 여기에만 쓴다
INST="$(mktemp -d)"; chmod 700 "$INST"
PASS=0; FAIL=0
cleanup() { sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1; rm -rf "$INST"; }
trap cleanup EXIT
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 || { echo "psql(postgres) 을 쓸 수 없다"; exit 3; }
[ -f "$ROOT/.env" ] || { echo "$ROOT/.env 가 없다 — 임시 .env 를 만들 원본이 없다"; exit 3; }

echo "── 준비: 임시 DB · 임시 저장소·격리 폴더 · 임시 .env"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$INST/restore.err" \
  || { set +o pipefail; echo "임시 DB 로 옮기지 못했다"; exit 3; }
set +o pipefail
mkdir -p "$INST/app" "$INST/data/storage" "$INST/data/quarantine" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env scripts src | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/data/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" -e '/^SCAN_ENABLED=/d' "$ROOT/.env" > "$INST/app/.env"
echo "SCAN_ENABLED=1" >> "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 없다 — 중단"; exit 3; }

# 두 갈래를 만든다(임시 DB 에서만): 살아 있는 파일 두 행을 pending 으로
IDS=$(psqlq "SELECT id FROM files WHERE deleted_at IS NULL AND purged_at IS NULL AND stored_name ~ '^[0-9a-f-]{36}' ORDER BY id LIMIT 2" | tr '\n' ' ')
set -- $IDS; A="${1:-}"; B="${2:-}"
[ -n "$A" ] && [ -n "$B" ] || { echo "시험에 쓸 파일 행이 두 개 없다"; exit 3; }
psqlq "UPDATE files SET scan_status = 'pending' WHERE id IN ($A, $B)" >/dev/null
A_NAME=$(psqlq "SELECT stored_name FROM files WHERE id = $A")
printf 'quarantined-earlier\n' > "$INST/data/quarantine/$A_NAME"      # A: 격리 폴더에만 있다 → 복구 갈래
# B: 어디에도 없다 → 분실 갈래(scan_fail file_missing)
N_PENDING=$(psqlq "SELECT count(*) FROM files WHERE scan_status = 'pending' AND purged_at IS NULL")
echo "  · 복구 갈래 id=$A · 분실 갈래 id=$B · 대기 행 전체 $N_PENDING"

snap() { psqlq "SELECT md5(string_agg(id || ':' || scan_status, ',' ORDER BY id)) FROM files"; }
amax() { psqlq "SELECT coalesce(max(id),0) || '/' || count(*) FROM audit_logs"; }

echo "── A. --dry-run 은 아무것도 쓰지 않는다"
F0=$(snap); A0=$(amax)
php "$INST/app/scripts/scan_pending.php" --dry-run > "$INST/dry.out" 2>&1; RC=$?
sed 's/^/    | /' "$INST/dry.out" | tail -8
expect "종료 코드 0 또는 1(분실은 실패로 센다)" "yes" "$([ "$RC" = 0 ] || [ "$RC" = 1 ] && echo yes || echo "no($RC)")"
expect "CLI 실패 줄 0(예외 핸들러는 「[실패] cli:…」를 찍는다)" "0" "$(grep -c '\[실패\]' "$INST/dry.out")"
expect "files 의 id·scan_status 전체가 그대로" "$F0" "$(snap)"
expect "audit_logs 최대 id·행 수가 그대로" "$A0" "$(amax)"
expect "감사 로그 파일에 쓴 줄 0" "0" "$( [ -f "$INST/log/audit.log" ] && wc -l < "$INST/log/audit.log" | tr -d ' ' || echo 0)"
expect "복구 갈래를 「예정」으로만 알린다" "1" "$(grep -c "\[예정·dry-run\] id=$A " "$INST/dry.out")"
expect "분실 갈래를 「예정」으로만 알린다" "1" "$(grep -c "\[예정·dry-run\] id=$B .*file_missing" "$INST/dry.out")"
expect "격리 파일은 그대로 있다" "yes" "$([ -f "$INST/data/quarantine/$A_NAME" ] && echo yes || echo no)"

echo "── B. 대조군 — --dry-run 없이는 복구 갈래가 기록을 마무리한다(갈래에 닿았다는 증거)"
php "$INST/app/scripts/scan_pending.php" > "$INST/real.out" 2>&1
expect "복구 행 A 가 infected" "infected" "$(psqlq "SELECT scan_status FROM files WHERE id = $A")"
expect "분실 행 B 는 pending 그대로" "pending" "$(psqlq "SELECT scan_status FROM files WHERE id = $B")"
expect "감사가 늘었다(file.scan deny infected + error scan_failed)" "yes" "$([ "$(amax)" != "$A0" ] && echo yes || echo no)"
expect "감사에 A 의 infected(quarantined_earlier)" "1" "$(psqlq "SELECT count(*) FROM audit_logs WHERE action='file.scan' AND result='deny' AND reason='infected' AND target_id=$A AND detail->>'signature'='quarantined_earlier'")"

echo "── 정적"
expect "php -l scripts/scan_pending.php" "0" "$(php -l "$ROOT/scripts/scan_pending.php" > /dev/null 2>&1; echo $?)"
expect "bash -n 이 시험" "0" "$(bash -n "$0"; echo $?)"

printf '\n통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
