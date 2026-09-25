#!/usr/bin/env bash
# scripts/verify_backup.sh — 백업 산출물 검증 (9단계)
#   PRD 7.8 S-08 "무결성: 백업마다 SHA-256 체크섬 기록, **매일 검증**" / "2회 연속 실패 시 경보"
#   PRD 7.11 AL-8, ARCHITECTURE 11.1 표 04:30 행
#
# 사용법:  sudo bash scripts/verify_backup.sh [--backup-root <디렉터리>]
#   --backup-root  /var/backups/tbox-v2 대신 **그 디렉터리**를 본다 (F-69 T5 시험용 — 빈 임시 폴더로
#                  실패 경로를 만든다). 연속 실패 횟수 파일도 그 안에 둔다 — 시험이 운영의 횟수를 밀지 않게.
#                  운영자가 셸에서 주는 절대 경로이며, 실제로 있는 디렉터리여야 한다.
#
# 무엇을 보나 — "파일이 있다" 는 검증이 아니다. 네 가지를 본다.
#   ⓪ 새로움   가장 최근 산출물이 24.5시간 안에 만들어졌나 → 백업 자체가 멈춘 것을 잡는다.
#              (2026-09-26 발견: backup_db.sh 가 09-23 부터 매일 실패했는데 이 검증은 남아 있던 09-22 파일만 보고
#               나흘 동안 「통과」 — AL-8 이 한 번도 안 울렸다. 백업이 03:00(DB)·03:30(파일), 검증이 04:30 이라 평소 1~1.5시간,
#               하루치가 빠지면 가장 최근 것이 25~25.5시간 전이 된다 → 24.5시간을 넘으면 문제로 센다. 26시간으로 두면
#               하루 결손을 놓친다(독립 검토). 검증을 손으로 백업 시각 직전에 돌려도 24시간 안팎이라 헛실패가 없다)
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
#
# 경보 한 줄의 모양 (F-69 A-3) — PHP 쪽 cli_raise_alert() 와 같은 키에 origin·title 을 더한다.
#   {"raised_at","rule":"AL-8","evidence":{rule_text,source,…},"dispatch","origin":"system","title":<rule_text>}
#   AL-8 은 행위자가 없는 배치 판정이라 origin 은 늘 `system` 이고, 그래서 title 은 rule_text 그대로다
#   (`[Claude] ` 앞머리는 claude-* 에만 — src/Origin.php 의 title()). 이 파일은 PHP 를 부르지 않고 같은 값을 적는다.
#   경보 로그 경로는 PHP 와 **같은 규칙**: 저장소 `.env` 의 AUDIT_LOG_FILE 디렉터리 + `alerts.log`
#   (없거나 못 읽으면 운영 기본 /var/log/tbox-v2/alerts.log). 임시 인스턴스 복사본은 그 임시 로그 폴더에 쓴다.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="/etc/tbox-v2"
BACKUP_ROOT="/var/backups/tbox-v2"

# F-69 T5 — 시험용 인자 하나. 다른 인자는 받지 않는다(모르는 인자는 사용법 오류).
while [ "$#" -gt 0 ]; do
  case "$1" in
    --backup-root)
      [ "$#" -ge 2 ] || { printf '[사용법 오류] --backup-root 에 디렉터리가 없다\n' >&2; exit 3; }
      case "$2" in
        /*) ;;
        *) printf '[사용법 오류] --backup-root 는 절대 경로여야 한다\n' >&2; exit 3 ;;
      esac
      [ -d "$2" ] || { printf '[사용법 오류] --backup-root 디렉터리가 없다: %s\n' "$2" >&2; exit 3; }
      BACKUP_ROOT="$2"; shift 2 ;;
    *) printf '[사용법 오류] 모르는 인자: %s\n' "$1" >&2; exit 3 ;;
  esac
done

# 경보 로그 — PHP(cli_raise_alert · admin_alerts.php)와 같은 규칙으로 만든다(`.env` 의 AUDIT_LOG_FILE 디렉터리).
# `.env` 에서 **그 한 줄만** 읽는다(다른 값·비밀은 읽지 않는다). 따옴표는 Env.php 처럼 한 겹 벗긴다.
# ★ `.env` 를 못 읽거나 그 줄이 없으면 **멈춘다** — 예전에는 운영 기본값(/var/log/tbox-v2/alerts.log)으로 넘어가서,
#   `.env` 없는 복제본에서 --backup-root 임시 폴더로 돌린 검토가 운영 경보 로그에 가짜 AL-8 을 남겼고 휴대폰 알림 배치가
#   그것을 사용자에게 보냈다(2026-09-26 06:54 — SECURITY R-104). 운영 `.env` 에는 이 줄이 있다(cron 04:30 은 그대로 돈다).
ALERTS_LOG=""
AUDIT_LOG_LINE="$(grep -m1 '^AUDIT_LOG_FILE=' "${REPO_DIR}/.env" 2>/dev/null || true)"
if [ -n "$AUDIT_LOG_LINE" ]; then
  AUDIT_LOG_VALUE="${AUDIT_LOG_LINE#AUDIT_LOG_FILE=}"
  AUDIT_LOG_VALUE="${AUDIT_LOG_VALUE%\"}"; AUDIT_LOG_VALUE="${AUDIT_LOG_VALUE#\"}"
  AUDIT_LOG_VALUE="${AUDIT_LOG_VALUE%\'}"; AUDIT_LOG_VALUE="${AUDIT_LOG_VALUE#\'}"
  case "$AUDIT_LOG_VALUE" in
    /*) ALERTS_LOG="$(dirname "$AUDIT_LOG_VALUE")/alerts.log" ;;
  esac
fi
[ -n "$ALERTS_LOG" ] || { printf '[중단] %s/.env 의 AUDIT_LOG_FILE(절대 경로)을 읽지 못했다 — 경보 로그 자리를 정할 수 없어 멈춘다(운영 로그로 넘어가지 않는다)\n' "$REPO_DIR" >&2; exit 3; }

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

# ⓪ 새로움 — 가장 최근 산출물의 수정 시각(백업 스크립트가 mv 로 최종 이름을 준 시각)이 24.5시간 안인가
MAX_AGE_MIN=$((24 * 60 + 30))   # 24.5시간 — 하루 빠지면 DB 25.5시간·파일 25시간이라 다음 검증에서 바로 잡힌다(26시간이면 하루 결손을 놓친다 — 독립 검토)
fresh_check() {  # fresh_check <라벨> <경로>
  local mtime now age
  mtime="$(stat -c %Y "$2" 2>/dev/null || echo '')"
  now="$(date +%s)"
  if [ -z "$mtime" ]; then
    note_problem "$1 백업의 만든 시각을 읽을 수 없다: $(basename "$2")"
    return 0
  fi
  age=$(( (now - mtime) / 60 ))
  if [ "$age" -gt "$MAX_AGE_MIN" ]; then
    note_problem "$1 백업이 새로 만들어지지 않았다 — 가장 최근 $(basename "$2") 이 $((age / 60))시간 $((age % 60))분 전(24.5시간 넘음 · 백업 스크립트 실패 여부를 본다)"
  else
    say "⓪ 새로움: $((age / 60))시간 $((age % 60))분 전 (24.5시간 안)"
  fi
}

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
  fresh_check "DB" "${DB_DIR}/${DB_NAME_LATEST}"

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
  fresh_check "파일" "${FILES_DIR}/${F_NAME_LATEST}"

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
  # F-69 A-3 — origin 은 늘 system(행위자 없는 배치), title = rule_text (claude-* 가 아니므로 앞머리 없음).
  AL8_TEXT='백업 검증 2회 연속 실패 — 복구 능력 상실'
  printf '{"raised_at":"%s","rule":"AL-8","evidence":{"rule_text":"%s","source":"verify_backup","consecutive_failures":%s,"problems":[%s]},"dispatch":"log_only","origin":"system","title":"%s"}\n' \
    "$(date -Is)" "$AL8_TEXT" "$FAILS" "$LIST" "$AL8_TEXT" >> "$ALERTS_LOG" || printf '[경고] alerts.log 기록 실패\n' >&2
  php "${REPO_DIR}/scripts/audit_cli.php" alert.raised allow --target AL-8 \
    --detail "$(printf '{"rule":"AL-8","evidence":{"rule_text":"%s","source":"verify_backup","consecutive_failures":%s,"problems":[%s]},"origin":"system"}' "$AL8_TEXT" "$FAILS" "$LIST")" \
    || printf '[경고] 감사 기록 실패\n' >&2
  printf '   ★ 경보 AL-8: 백업 검증이 %s회 연속 실패했다 — 복구 능력 상실 (PRD 7.11)\n' "$FAILS" >&2
fi

exit 1
