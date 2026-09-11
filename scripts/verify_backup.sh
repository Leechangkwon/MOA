#!/usr/bin/env bash
# scripts/verify_backup.sh — 백업 산출물 검증 (9단계)
#   PRD 7.8 S-08 "무결성: 백업마다 SHA-256 체크섬 기록, **매일 검증**" / "2회 연속 실패 시 경보"
#   PRD 7.11 AL-8, ARCHITECTURE 11.1 표 04:30 행
#
# 사용법:  sudo bash scripts/verify_backup.sh
#
# 무엇을 보나 — "파일이 있다" 는 검증이 아니다. 세 가지를 본다.
#   ① 체크섬   sha256sum -c   → 저장된 뒤 바이트가 바뀌지 않았나 (디스크 손상·변조)
#   ② 복호화   gpg -d          → 키로 실제로 열리나 (키가 바뀌었거나 산출물이 깨졌으면 여기서 걸린다)
#   ③ 내용     pg_dump 는 `pg_restore -l` 로 **목록만**, 파일은 `tar -tz` 로 목록만
#              → 열린 내용이 정말 덤프·아카이브인가. 복원은 하지 않는다(운영 DB 를 건드리지 않는다).
#   평문은 전부 파이프로만 흐르고 디스크에 쓰지 않는다.
#
# 2회 연속 실패 판정
#   /var/backups/tbox-v2/.fail_count 에 연속 실패 횟수를 적는다. 성공하면 0 으로 지운다.
#   2 이상이 되면 경보 AL-8 을 남긴다 — "복구 능력 상실" 은 사고가 나기 전에 알아야 하는 일이다.
#
# 감사 기록:  audit.integrity allow | audit.integrity error mismatch  (+ 2회 연속이면 alert.raised AL-8)
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="/etc/tbox-v2"
BACKUP_ROOT="/var/backups/tbox-v2"
DB_DIR="${BACKUP_ROOT}/db"
FILES_DIR="${BACKUP_ROOT}/files"
PASS_FILE="${CONF_DIR}/backup.key"
FAIL_FILE="${BACKUP_ROOT}/.fail_count"

say() { printf '   %s\n' "$*"; }
die() { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash scripts/verify_backup.sh"
[ -r "$PASS_FILE" ]  || die "백업 키가 없다: ${PASS_FILE}"

PROBLEMS=()
CHECKED=0

note_problem() { PROBLEMS+=("$1"); printf '   ✖ %s\n' "$1" >&2; }

# 디렉터리에서 가장 최근 산출물 하나 (이름에 날짜가 들어가 사전순 = 시간순)
latest() {  # latest <디렉터리> <glob>
  find "$1" -maxdepth 1 -type f -name "$2" -printf '%f\n' 2>/dev/null | sort -r | head -n1
}

printf '\n== 백업 검증 %s ==\n' "$(date +%Y-%m-%d\ %H:%M)"

# ─── DB 산출물 ──────────────────────────────────────────────────
DB_NAME_LATEST="$(latest "$DB_DIR" 'db-*.dump.gpg')"
if [ -z "$DB_NAME_LATEST" ]; then
  note_problem "DB 백업 산출물이 하나도 없다 (${DB_DIR})"
else
  CHECKED=$((CHECKED + 1))
  say "DB 산출물: ${DB_NAME_LATEST} ($(stat -c %s "${DB_DIR}/${DB_NAME_LATEST}") 바이트)"

  # ① 체크섬 — 체크섬 파일 안에는 이름만 있으므로 그 디렉터리에서 돌린다
  if [ ! -r "${DB_DIR}/${DB_NAME_LATEST}.sha256" ]; then
    note_problem "DB 체크섬 파일이 없다: ${DB_NAME_LATEST}.sha256"
  elif ( cd "$DB_DIR" && sha256sum -c --status "${DB_NAME_LATEST}.sha256" ); then
    say "① sha256sum -c : OK"
  else
    note_problem "DB 체크섬 불일치: ${DB_NAME_LATEST}"
  fi

  # ②③ 복호화 + 목록 — 평문은 파이프로만 흐른다. pg_restore -l 은 **읽기만** 한다.
  #
  # gpg 의 종료 코드는 파이프 **중간**에 있어 `$(...)` 안에서는 PIPESTATUS 로 꺼낼 수 없다
  # (명령 치환은 서브셸이라 바깥 PIPESTATUS 에 남지 않는다 — 2026-09-11 실측으로 확인).
  # 그래서 gpg 의 상태만 0700 디렉터리의 임시 파일에 적어 꺼낸다. **평문은 여기 오지 않는다.**
  RC_FILE="$(mktemp "${BACKUP_ROOT}/.verify-rc.XXXXXX")"
  set +e
  DB_TOC="$( { gpg --batch --quiet --decrypt --passphrase-file "$PASS_FILE" "${DB_DIR}/${DB_NAME_LATEST}" 2>/dev/null; \
               printf '%s' "$?" > "$RC_FILE"; } | pg_restore -l 2>/dev/null | grep -c '^[0-9]' )"
  TOC_RC=$?
  set -e
  GPG_RC="$(cat "$RC_FILE")"; rm -f "$RC_FILE"
  #
  # 판정 순서가 중요하다. `pg_restore -l` 은 사용자 정의 형식 덤프의 **목차만** 읽고 바로 끝낸다.
  # 그러면 아직 쓰고 있던 gpg 가 EPIPE 를 받아 종료 코드 2 로 끝난다 — 복호화가 실패한 것이
  # 아니라 **읽는 쪽이 먼저 만족한 것**이다(2026-09-11 실측). 그래서 "목록이 나왔나" 를 먼저 보고,
  # 목록이 비었을 때만 gpg 의 종료 코드를 근거로 삼는다.
  if [ "$TOC_RC" -eq 0 ] && [ "${DB_TOC:-0}" -ge 1 ]; then
    say "②③ gpg -d | pg_restore -l : OK (목록 ${DB_TOC}개 항목$([ "${GPG_RC:-0}" = "2" ] && printf ', gpg 는 목차만 읽고 끊겨 rc=2 — 정상'))"
  elif [ "${GPG_RC:-1}" -ne 0 ]; then
    note_problem "DB 복호화 실패 (gpg 종료 코드 ${GPG_RC}) — 키가 맞지 않거나 파일이 깨졌다"
  else
    note_problem "DB 덤프 목록을 읽을 수 없다 (pg_restore -l)"
  fi
fi

# ─── 파일 산출물 ────────────────────────────────────────────────
F_NAME_LATEST="$(latest "$FILES_DIR" 'files-*.tar.gz.gpg')"
if [ -z "$F_NAME_LATEST" ]; then
  note_problem "파일 백업 산출물이 하나도 없다 (${FILES_DIR})"
else
  CHECKED=$((CHECKED + 1))
  say "파일 산출물: ${F_NAME_LATEST} ($(stat -c %s "${FILES_DIR}/${F_NAME_LATEST}") 바이트)"

  if [ ! -r "${FILES_DIR}/${F_NAME_LATEST}.sha256" ]; then
    note_problem "파일 체크섬 파일이 없다: ${F_NAME_LATEST}.sha256"
  elif ( cd "$FILES_DIR" && sha256sum -c --status "${F_NAME_LATEST}.sha256" ); then
    say "① sha256sum -c : OK"
  else
    note_problem "파일 체크섬 불일치: ${F_NAME_LATEST}"
  fi

  RC_FILE="$(mktemp "${BACKUP_ROOT}/.verify-rc.XXXXXX")"
  set +e
  F_ENTRIES="$( { gpg --batch --quiet --decrypt --passphrase-file "$PASS_FILE" "${FILES_DIR}/${F_NAME_LATEST}" 2>/dev/null; \
                  printf '%s' "$?" > "$RC_FILE"; } | tar -tzf - 2>/dev/null | wc -l )"
  TAR_RC=$?
  set -e
  GPG_RC="$(cat "$RC_FILE")"; rm -f "$RC_FILE"
  # tar -tz 는 스트림을 끝까지 읽으므로 보통 gpg 도 0 으로 끝난다. 그래도 판정 순서는 위와 같게 둔다.
  if [ "$TAR_RC" -eq 0 ] && [ "${F_ENTRIES:-0}" -ge 1 ]; then
    say "②③ gpg -d | tar -tz : OK (항목 ${F_ENTRIES}개)"
  elif [ "${GPG_RC:-1}" -ne 0 ]; then
    note_problem "파일 복호화 실패 (gpg 종료 코드 ${GPG_RC})"
  else
    note_problem "tar 목록을 읽을 수 없다"
  fi
fi

# ─── 결과 · 연속 실패 판정 ──────────────────────────────────────
PREV_FAILS=0
if [ -r "$FAIL_FILE" ]; then
  PREV_FAILS="$(tr -dc '0-9' < "$FAIL_FILE")"
  PREV_FAILS="${PREV_FAILS:-0}"
fi

if [ "${#PROBLEMS[@]}" -eq 0 ]; then
  rm -f "$FAIL_FILE"
  php "${REPO_DIR}/scripts/audit_cli.php" audit.integrity allow --target backup_verify \
    --detail "$(printf '{"checked":%s,"mismatches":0,"artifact":"%s"}' "$CHECKED" "${DB_NAME_LATEST:-none}")" \
    || printf '[경고] 감사 기록 실패\n' >&2
  say "결과: 통과 (검증 ${CHECKED}건, 문제 0건)"
  exit 0
fi

FAILS=$((PREV_FAILS + 1))
umask 077
printf '%s\n' "$FAILS" > "$FAIL_FILE"
umask 022
chmod 0600 "$FAIL_FILE"

# 문제 목록을 JSON 배열로 (따옴표·역슬래시를 이스케이프한다)
LIST=""
for p in "${PROBLEMS[@]}"; do
  esc="${p//\\/\\\\}"; esc="${esc//\"/\\\"}"
  LIST="${LIST}${LIST:+,}\"${esc}\""
done

php "${REPO_DIR}/scripts/audit_cli.php" audit.integrity error mismatch --target backup_verify \
  --detail "$(printf '{"checked":%s,"mismatches":[%s],"consecutive_failures":%s}' "$CHECKED" "$LIST" "$FAILS")" \
  || printf '[경고] 감사 기록 실패\n' >&2

printf '   결과: 실패 %s건 (연속 %s회)\n' "${#PROBLEMS[@]}" "$FAILS" >&2

if [ "$FAILS" -ge 2 ]; then
  # 경보는 두 곳에 남는다 (ARCH 11.4): alerts.log 한 줄 JSON + audit_logs 의 alert.raised 행.
  # PHP 쪽 cli_raise_alert() 와 같은 모양으로 적는다 — 수집기가 한 가지 형식만 읽으면 되도록.
  printf '{"raised_at":"%s","rule":"AL-8","evidence":{"source":"verify_backup","consecutive_failures":%s,"problems":[%s]},"dispatch":"log_only"}\n' \
    "$(date -Is)" "$FAILS" "$LIST" >> /var/log/tbox-v2/alerts.log || printf '[경고] alerts.log 기록 실패\n' >&2
  php "${REPO_DIR}/scripts/audit_cli.php" alert.raised allow --target AL-8 \
    --detail "$(printf '{"rule":"AL-8","evidence":{"source":"verify_backup","consecutive_failures":%s,"problems":[%s]}}' "$FAILS" "$LIST")" \
    || printf '[경고] 감사 기록 실패\n' >&2
  printf '   ★ 경보 AL-8: 백업 검증이 %s회 연속 실패했다 — 복구 능력 상실 (PRD 7.11)\n' "$FAILS" >&2
fi

exit 1
