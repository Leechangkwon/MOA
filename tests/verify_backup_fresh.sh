#!/usr/bin/env bash
# tests/verify_backup_fresh.sh — 백업 검증의 ⓪ 새로움 확인 (2026-09-26 — backup_db.sh 가 나흘 실패했는데 검증은 「통과」)
#
# 묻는 것: scripts/verify_backup.sh 가 가장 최근 산출물이 24.5시간을 넘었으면 문제로 세고, 두 번 연속이면 AL-8 을 남기는가.
#   A 새 산출물(지금)          → 통과(rc 0) · 「⓪ 새로움」 두 줄
#   B 둘 다 30시간 전          → 실패(rc 1) · 「새로 만들어지지 않았다」 두 줄 · 연속 실패 1
#   C 다시 B                   → 연속 실패 2 · alerts.log 에 AL-8(source verify_backup, origin system) · 감사 alert.raised AL-8
#   D DB 만 30시간 전          → 실패 · 그 줄 하나(DB)
#   E 24시간 전(경계 안)       → 통과 · E2 25시간 전(하루 결손 — 파일 03:30→다음날 04:30)이면 실패
#   G `.env` 없는 사본          → 멈춤(3) · 운영 경보 로그로 넘어가지 않음(2026-09-26 06:54 사고)
#   F 운영 불변                → 운영 실패 횟수 파일 · 운영 감사 최대 id · 운영 백업 폴더 목록이 그대로
#
# ★ 운영 DB·운영 로그·운영 백업 폴더에 쓰지 않는다:
#   · 임시 DB `tbox_vbfresh_test`(schema.sql + grants 의 DATABASE 줄만 임시 이름으로) — audit_cli.php 가 여기에 쓴다
#   · 임시 인스턴스 폴더(저장소 사본 + 임시 .env — DB 이름·감사 로그 경로만 바꿈, 값은 출력하지 않음)
#   · 산출물은 root 소유 0700 임시 폴더(--backup-root) — 운영 키(/etc/tbox-v2/backup.key)로 **작은 가짜 산출물**을 만든다
#     (DB 는 임시 DB 의 스키마 덤프, 파일은 글자 하나짜리 tar) — 운영 데이터는 산출물에 들어가지 않는다
# 준비물: sudo -n(root — verify_backup 은 root 로만 돈다 · postgres createdb/dropdb) · gpg · pg_dump · php
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB="tbox_vbfresh_test"
PASS=0; FAIL=0; SKIP=0
expect() { if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1)); else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi; }
sub() { printf '\n── %s\n' "$*"; }

sudo -n true 2>/dev/null || { echo "sudo -n 이 안 된다 — 이 시험은 root 가 필요하다"; echo "RESULT pass=0 fail=0 skip=1"; exit 3; }
[ -f "$ROOT/.env" ] || [ -n "${QF2_ENV_SRC:-}" ] || { echo "저장소 .env(또는 QF2_ENV_SRC)가 없다 — 임시 .env 를 만들 수 없다"; exit 3; }

WORK="$(mktemp -d)"; INST="$(mktemp -d)"; BK="$(sudo -n mktemp -d)"
cleanup() {
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  sudo -n rm -rf "$BK" "$INST"
  rm -rf "$WORK"
}
trap cleanup EXIT
sudo -n chmod 700 "$BK"; sudo -n mkdir -p "$BK/db" "$BK/files"

# 운영 불변 대조의 앞 값
PROD_FAIL_BEFORE="$(sudo -n cat /var/backups/tbox-v2/.fail_count 2>/dev/null || echo none)"
PROD_LIST_BEFORE="$(sudo -n ls -l --time-style=+%s /var/backups/tbox-v2/db /var/backups/tbox-v2/files 2>/dev/null | sha256sum | cut -c1-16)"
PROD_MAXID_BEFORE="$(sudo -n -u postgres psql -X -At -d tbox_re -c 'SELECT max(id) FROM audit_logs' 2>/dev/null)"
PROD_AL8_BEFORE="$(sudo -n grep -c '"rule":"AL-8"' /var/log/tbox-v2/alerts.log 2>/dev/null || echo 0)"

sub "준비 — 임시 DB · 임시 인스턴스"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" || { echo "임시 DB 를 만들 수 없다"; exit 3; }
{ echo 'SET ROLE tbox_owner;'; cat "$ROOT/db/schema.sql"; } | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=1 -f - >/dev/null 2>"$WORK/schema.err" \
  || { echo "schema.sql 실패: $(grep -v 'could not change' "$WORK/schema.err" | head -2)"; exit 3; }
sed "s/DATABASE tbox_re/DATABASE $DB/g" "$ROOT/db/grants.sql" | sudo -n -u postgres psql -X -q -d "$DB" -f - >/dev/null 2>&1
mkdir -p "$INST/app" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=./.env scripts src db VERSION 2>/dev/null | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 .env 사본(0600 · trap 정리) · 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "${QF2_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 없다 — 중단(운영 DB 에 쓰지 않게)"; exit 4; }
grep -q "^AUDIT_LOG_FILE=$INST/log/audit.log\$" "$INST/app/.env" || { echo "임시 .env 의 감사 로그가 임시가 아니다 — 중단"; exit 4; }
touch "$INST/log/audit.log" "$INST/log/alerts.log"
VB="$INST/app/scripts/verify_backup.sh"
expect "검증 스크립트에 ⓪ 새로움 함수" "1" "$(grep -c '^fresh_check()' "$VB")"

sub "가짜 산출물 — 운영 키로 암호화(임시 DB 스키마 덤프 · 글자 하나 tar)"
STAMP="$(date +%Y%m%d-%H%M)"
DBF="db-$STAMP.dump.gpg"; FF="files-$STAMP.tar.gz.gpg"
sudo -n bash -c "set -o pipefail; cd / && runuser -u postgres -- pg_dump -Fc -s -d '$DB' | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-file /etc/tbox-v2/backup.key --output '$BK/db/$DBF' && cd '$BK/db' && sha256sum '$DBF' > '$DBF.sha256'" \
  || { echo "가짜 DB 산출물을 만들지 못했다"; exit 3; }
mkdir -p "$WORK/f"; echo x > "$WORK/f/a.txt"
tar -C "$WORK/f" -czf - a.txt | sudo -n bash -c "gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-file /etc/tbox-v2/backup.key --output '$BK/files/$FF' && cd '$BK/files' && sha256sum '$FF' > '$FF.sha256'" \
  || { echo "가짜 파일 산출물을 만들지 못했다"; exit 3; }
setage() { sudo -n touch -d "$1" "$BK/db/$DBF" "$BK/files/$FF"; }   # 체크섬은 내용만 보므로 시각을 바꿔도 그대로
run() { sudo -n bash "$VB" --backup-root "$BK" > "$WORK/out.txt" 2>&1; echo $?; }
n_of() { ( grep -c -- "$1" "$WORK/out.txt" || true ); }

sub "A 새 산출물(지금)"
setage "now"
expect "A 종료 코드" "0" "$(run)"
expect "A ⓪ 새로움 두 줄(DB·파일)" "2" "$(n_of '⓪ 새로움')"
expect "A 「새로 만들어지지 않았다」 0" "0" "$(n_of '새로 만들어지지 않았다')"

sub "B 둘 다 30시간 전"
setage "-30 hours"
expect "B 종료 코드" "1" "$(run)"
expect "B 「새로 만들어지지 않았다」 두 줄" "2" "$(n_of '새로 만들어지지 않았다')"
expect "B 연속 실패 1" "1" "$(sudo -n cat "$BK/.fail_count" 2>/dev/null)"
AL8_BEFORE="$( ( grep -c '"rule":"AL-8"' "$INST/log/alerts.log" || true ) )"

sub "C 다시 B — 두 번 연속이면 AL-8"
expect "C 종료 코드" "1" "$(run)"
expect "C 연속 실패 2" "2" "$(sudo -n cat "$BK/.fail_count" 2>/dev/null)"
expect "C alerts.log 에 AL-8 한 줄 더" "$((AL8_BEFORE + 1))" "$( ( grep -c '"rule":"AL-8"' "$INST/log/alerts.log" || true ) )"
expect "C AL-8 줄 source=verify_backup · origin=system" "verify_backup|system" \
  "$(grep '"rule":"AL-8"' "$INST/log/alerts.log" | tail -1 | python3 -c 'import json,sys; d=json.loads(sys.stdin.read()); print(d["evidence"]["source"]+"|"+d["origin"])' 2>/dev/null)"
expect "C AL-8 증거에 「새로 만들어지지 않았다」" "yes" \
  "$(grep '"rule":"AL-8"' "$INST/log/alerts.log" | tail -1 | grep -q '새로 만들어지지 않았다' && echo yes || echo no)"
expect "C 임시 DB 감사 alert.raised AL-8" "1" \
  "$(sudo -n -u postgres psql -X -At -d "$DB" -c "SELECT count(*) FROM audit_logs WHERE action='alert.raised' AND target_name='AL-8'" 2>/dev/null)"

sub "D DB 만 30시간 전"
sudo -n touch -d "now" "$BK/files/$FF"; sudo -n touch -d "-30 hours" "$BK/db/$DBF"
expect "D 종료 코드" "1" "$(run)"
expect "D 문제 줄은 DB 하나" "1|0" "$(n_of 'DB 백업이 새로 만들어지지 않았다')|$(n_of '파일 백업이 새로 만들어지지 않았다')"

sub "E 24시간 전(경계 안) — 성공하면 연속 실패도 지운다"
setage "-24 hours"
expect "E 종료 코드" "0" "$(run)"
expect "E 연속 실패 파일 지워짐" "none" "$(sudo -n cat "$BK/.fail_count" 2>/dev/null || echo none)"

sub "E2 25시간 전 — 하루 결손(파일 03:30 → 다음날 04:30 검증)을 바로 잡는다"
setage "-25 hours"
expect "E2 종료 코드" "1" "$(run)"
expect "E2 「새로 만들어지지 않았다」 두 줄" "2" "$(n_of '새로 만들어지지 않았다')"
sudo -n rm -f "$BK/.fail_count"

sub "G .env 없는 사본 — 운영 경보 로그로 넘어가지 않고 멈춘다"
PROD_ALERTS_N0="$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo none)"
NOENV="$(mktemp -d)"; mkdir -p "$NOENV/scripts"; cp "$VB" "$NOENV/scripts/verify_backup.sh"
setage "-30 hours"
sudo -n bash "$NOENV/scripts/verify_backup.sh" --backup-root "$BK" > "$WORK/out.txt" 2>&1; G_RC=$?
expect "G 종료 코드(멈춤)" "3" "$G_RC"
expect "G 멈춘 까닭 문구" "1" "$(n_of '경보 로그 자리를 정할 수 없어 멈춘다')"
expect "G 운영 경보 로그 줄 수 그대로" "$PROD_ALERTS_N0" "$(sudo -n wc -l < /var/log/tbox-v2/alerts.log 2>/dev/null || echo none)"
expect "G 연속 실패 파일 안 만듦" "none" "$(sudo -n cat "$BK/.fail_count" 2>/dev/null || echo none)"
rm -rf "$NOENV"

sub "F 운영 불변"
expect "F 운영 경보 로그의 AL-8 줄 수(이 시험이 늘리지 않음 — 06:54 사고 대조)" "$PROD_AL8_BEFORE" "$(sudo -n grep -c '"rule":"AL-8"' /var/log/tbox-v2/alerts.log 2>/dev/null || echo 0)"
expect "F 운영 실패 횟수 파일" "$PROD_FAIL_BEFORE" "$(sudo -n cat /var/backups/tbox-v2/.fail_count 2>/dev/null || echo none)"
expect "F 운영 백업 폴더 목록" "$PROD_LIST_BEFORE" "$(sudo -n ls -l --time-style=+%s /var/backups/tbox-v2/db /var/backups/tbox-v2/files 2>/dev/null | sha256sum | cut -c1-16)"
expect "F 운영 감사에 이 시험의 검증·경보 행 0" "0" \
  "$(sudo -n -u postgres psql -X -At -d tbox_re -c "SELECT count(*) FROM audit_logs WHERE id > ${PROD_MAXID_BEFORE:-0} AND action IN ('audit.integrity','alert.raised') AND target_name IN ('backup_verify','AL-8')" 2>/dev/null)"

printf '\n통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
printf 'RESULT pass=%d fail=%d skip=%d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ]
