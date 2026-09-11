#!/usr/bin/env bash
# scripts/backup_db.sh — PostgreSQL 전체 백업 (9단계)
#   PRD 7.8 S-08 (매일 03:00, 암호화, 체크섬, 보관 일 7·주 4·월 6)
#   ARCHITECTURE 11.1 표 03:00 행
#
# 사용법:  sudo bash scripts/backup_db.sh [--keep-daily 7]
#
# 성질
#   · **평문 덤프를 디스크에 남기지 않는다.** pg_dump 의 출력을 파이프로 바로 gpg 에 넣는다.
#     중간 파일을 만들면 그 순간 "암호화되지 않은 전체 데이터" 가 디스크에 존재한다 (PRD S-08 백업 보안).
#   · 읽기 전용 역할 `tbox_backup` 으로만 붙는다 (ARCH 10장). 비밀번호는 /etc/tbox-v2/backup.pgpass
#     (0600 root) 에만 있고 명령줄·환경변수에 넣지 않는다 — `ps` 로 보이지 않게.
#   · 암호화는 gpg 대칭키(AES256). `age` 는 이 VM 에 없고 새 패키지를 설치하지 않는다
#     (PRD S-10-C, ARCH 11.1 도구 결정 2026-09-11).
#   · 키 /etc/tbox-v2/backup.key 를 잃으면 **모든 백업이 무용지물**이다. 사본을 서버 밖에
#     2인 보관한다 (PRD S-10-E "백업본 보호", SECURITY R-08). README "백업·복구" 참고.
#   · 산출물은 /var/backups/tbox-v2 (0700 root) — 웹 루트·업로드 디렉터리 안이 아니다.
#
# 감사 기록:  backup.run allow | backup.run error backup_failed  (scripts/audit_cli.php)
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="/etc/tbox-v2"
BACKUP_ROOT="/var/backups/tbox-v2"
DB_DIR="${BACKUP_ROOT}/db"
WEEKLY_DIR="${BACKUP_ROOT}/weekly"
MONTHLY_DIR="${BACKUP_ROOT}/monthly"
PASS_FILE="${CONF_DIR}/backup.key"
PGPASS_FILE="${CONF_DIR}/backup.pgpass"

DB_NAME="tbox_re"
DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_ROLE="tbox_backup"

KEEP_DAILY=7      # PRD S-08 보관 정책: 일 7
KEEP_WEEKLY=4     #                      주 4
KEEP_MONTHLY=6    #                      월 6

if [ "${1:-}" = "--keep-daily" ] && [ -n "${2:-}" ]; then
  case "$2" in
    ''|*[!0-9]*) printf '[중단] --keep-daily 는 정수다\n' >&2; exit 2 ;;
    *) KEEP_DAILY="$2" ;;
  esac
fi

say()  { printf '   %s\n' "$*"; }
die()  { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

# 감사 기록 — 실패해도 백업 결과를 덮어쓰지 않게 따로 다룬다.
audit() {  # audit <allow|error> [reason] <detail-json>
  local result="$1"; shift
  local reason=""
  if [ "$result" != "allow" ]; then reason="$1"; shift; fi
  local detail="$1"
  if [ -n "$reason" ]; then
    php "${REPO_DIR}/scripts/audit_cli.php" backup.run "$result" "$reason" \
      --target db --detail "$detail" || printf '[경고] 감사 기록 실패\n' >&2
  else
    php "${REPO_DIR}/scripts/audit_cli.php" backup.run "$result" \
      --target db --detail "$detail" || printf '[경고] 감사 기록 실패\n' >&2
  fi
}

fail() {  # fail <단계> <설명>
  local step="$1" msg="$2"
  printf '[실패] %s: %s\n' "$step" "$msg" >&2
  audit error backup_failed "$(printf '{"target":"db","step":"%s"}' "$step")"
  exit 1
}

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash scripts/backup_db.sh"
[ -r "$PASS_FILE" ]   || fail "key" "백업 키가 없다: ${PASS_FILE} (deploy/install.sh 가 만든다)"
[ -r "$PGPASS_FILE" ] || fail "pgpass" "백업 계정 비밀번호 파일이 없다: ${PGPASS_FILE}"
command -v gpg     >/dev/null 2>&1 || fail "gpg" "gpg 가 없다"
command -v pg_dump >/dev/null 2>&1 || fail "pg_dump" "pg_dump 가 없다"

install -d -o root -g root -m 0700 "$BACKUP_ROOT" "$DB_DIR" "$WEEKLY_DIR" "$MONTHLY_DIR"

STAMP="$(date +%Y%m%d-%H%M)"
NAME="db-${STAMP}.dump.gpg"
OUT="${DB_DIR}/${NAME}"
TMP="${OUT}.partial"

printf '\n== DB 백업 %s ==\n' "$STAMP"
say "대상: ${DB_NAME} (역할 ${DB_ROLE}, 읽기 전용)"

# ── 덤프 → 암호화 (한 파이프. 평문은 디스크에 닿지 않는다) ──
# 먼저 .partial 로 쓰고 성공했을 때만 최종 이름으로 바꾼다 — 반쯤 쓰인 파일이
# "최신 백업" 으로 보이는 상태를 만들지 않기 위해서다.
umask 077
set +e
PGPASSFILE="$PGPASS_FILE" pg_dump -Fc --no-owner --no-privileges \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_ROLE" -d "$DB_NAME" \
  | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
        --passphrase-file "$PASS_FILE" --output "$TMP"
RC=("${PIPESTATUS[@]}")
set -e
umask 022

if [ "${RC[0]}" -ne 0 ]; then
  rm -f "$TMP"
  fail "pg_dump" "종료 코드 ${RC[0]}"
fi
if [ "${RC[1]}" -ne 0 ]; then
  rm -f "$TMP"
  fail "gpg" "종료 코드 ${RC[1]}"
fi
[ -s "$TMP" ] || { rm -f "$TMP"; fail "output" "산출물이 비어 있다"; }

mv "$TMP" "$OUT"
chmod 0600 "$OUT"

# ── 체크섬 (PRD S-08 "백업마다 SHA-256 체크섬 기록, 매일 검증") ──
# sha256sum -c 가 그대로 먹도록 **파일 이름만** 적는다 (경로를 넣으면 다른 곳에서 검증할 때 깨진다).
( cd "$DB_DIR" && sha256sum "$NAME" > "${NAME}.sha256" )
chmod 0600 "${OUT}.sha256"

BYTES="$(stat -c %s "$OUT")"
SUM="$(awk '{print $1}' "${OUT}.sha256")"
say "산출물: ${OUT}"
say "크기:   ${BYTES} 바이트"
say "sha256: ${SUM}"

# ── 보관 정책 ──────────────────────────────────────────────────
# 일 7개 유지. 일요일이면 weekly/ 로, 매월 1일이면 monthly/ 로 사본을 남기고 각각 4·6개 유지.
# (restic 의 forget --keep-daily/weekly/monthly 를 셸로 흉내 낸 것이다 — restic 은 이 VM 에 없다.)
prune() {  # prune <디렉터리> <남길 개수> <glob>
  local dir="$1" keep="$2" pattern="$3" f n=0
  # 새 것부터 훑어 $keep 개를 넘으면 지운다. 이름에 YYYYMMDD-HHMM 이 들어가 사전순 = 시간순이다.
  while IFS= read -r f; do
    n=$((n + 1))
    if [ "$n" -gt "$keep" ]; then
      rm -f -- "$f" "${f}.sha256"
      say "보관 정책: 삭제 $(basename "$f")"
    fi
  done < <(find "$dir" -maxdepth 1 -type f -name "$pattern" -printf '%f\n' \
             | sort -r | sed "s#^#${dir}/#")
}

DOW="$(date +%u)"   # 1=월 … 7=일
DOM="$(date +%d)"

if [ "$DOW" = "7" ]; then
  cp -p "$OUT" "${WEEKLY_DIR}/${NAME}"
  ( cd "$WEEKLY_DIR" && sha256sum "$NAME" > "${NAME}.sha256" )
  say "주간 사본: ${WEEKLY_DIR}/${NAME}"
fi
if [ "$DOM" = "01" ]; then
  cp -p "$OUT" "${MONTHLY_DIR}/${NAME}"
  ( cd "$MONTHLY_DIR" && sha256sum "$NAME" > "${NAME}.sha256" )
  say "월간 사본: ${MONTHLY_DIR}/${NAME}"
fi

prune "$DB_DIR"      "$KEEP_DAILY"   'db-*.dump.gpg'
prune "$WEEKLY_DIR"  "$KEEP_WEEKLY"  'db-*.dump.gpg'
prune "$MONTHLY_DIR" "$KEEP_MONTHLY" 'db-*.dump.gpg'

# ── 감사 기록 (ARCH 7.5 artifact·checksum·bytes) ──
audit allow "$(printf '{"target":"db","artifact":"%s","checksum":"%s","bytes":%s,"tool":"pg_dump -Fc | gpg AES256"}' \
  "$NAME" "$SUM" "$BYTES")"

say "완료. 보관 중: $(find "$DB_DIR" -maxdepth 1 -name 'db-*.dump.gpg' | wc -l)개(일) / $(find "$WEEKLY_DIR" -maxdepth 1 -name 'db-*.dump.gpg' | wc -l)개(주) / $(find "$MONTHLY_DIR" -maxdepth 1 -name 'db-*.dump.gpg' | wc -l)개(월)"
