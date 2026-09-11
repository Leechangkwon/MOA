#!/usr/bin/env bash
# scripts/restore.sh — 백업에서 복구 (9단계)
#   PRD 7.8 S-08 "복구 절차 (Admin용 체크리스트)" 1~8단계
#   ARCHITECTURE 11.2 "복구 순서" 의 명령을 그대로 담은 것
#
# 사용법
#   sudo bash scripts/restore.sh --files <files-YYYYMMDD.tar.gz.gpg> [--target-dir DIR]
#   sudo bash scripts/restore.sh --db    <db-YYYYMMDD-HHMM.dump.gpg> --dbname tbox_re_restore
#   sudo bash scripts/restore.sh --db ... --dbname tbox_re --yes-production   ← 운영 DB 를 덮을 때만
#   공통 옵션: --stop-service (복구 중 php-fpm 정지) · --skip-check (정합성 검사 생략)
#
# 안전 장치
#   · 운영 DB(tbox_re)에 `pg_restore --clean` 을 거는 것은 **되돌릴 수 없다**. 그래서
#     `--yes-production` 없이는 거부한다. 리허설은 별도 DB(`--dbname tbox_re_restore`)로 한다
#     (PRD S-08 "복구 훈련: 별도 서버에서", ARCH 11.2 주석).
#   · 산출물은 손대기 전에 **체크섬부터** 확인한다 (ARCH 11.2 3단계). 깨진 백업으로 덮는 것이
#     아무것도 안 하는 것보다 나쁘다.
#   · 평문 덤프·평문 tar 를 디스크에 남기지 않는다 — gpg 출력은 파이프로만 흐른다.
#   · 인자로 받은 산출물 이름은 **백업 디렉터리 안의 파일**이어야 한다(경로 탈출 방지).
#
# 감사 기록:  backup.restore allow
#   배치에는 로그인 사용자가 없으므로 actor_* 는 NULL 이다. 실행한 사람은 target_name 에
#   `$SUDO_USER`(sudo 를 건 계정)로 남긴다 — "누가 복구했나" 를 로그만으로 알 수 있게.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="/etc/tbox-v2"
BACKUP_ROOT="/var/backups/tbox-v2"
DB_DIR="${BACKUP_ROOT}/db"
FILES_DIR="${BACKUP_ROOT}/files"
PASS_FILE="${CONF_DIR}/backup.key"
OWNER_PGPASS="${CONF_DIR}/owner.pgpass"

APP_USER="tbox-php"
DEFAULT_TARGET="/var/lib/tbox-v2/storage"
DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_OWNER_ROLE="tbox_owner"
PROD_DB="tbox_re"
FPM_SERVICE="php8.1-fpm"

DB_ARTIFACT=""
FILES_ARTIFACT=""
TARGET_DIR="$DEFAULT_TARGET"
DB_NAME="$PROD_DB"
STOP_SERVICE=0
SKIP_CHECK=0
YES_PROD=0

say() { printf '   %s\n' "$*"; }
die() { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

usage() {
  cat >&2 <<'USAGE'
사용법:
  sudo bash scripts/restore.sh --files <files-YYYYMMDD.tar.gz.gpg> [--target-dir DIR]
  sudo bash scripts/restore.sh --db <db-YYYYMMDD-HHMM.dump.gpg> --dbname <DB>
옵션:
  --dbname NAME        복구 대상 DB (기본 tbox_re). 리허설은 tbox_re_restore 를 쓴다
  --yes-production     운영 DB(tbox_re)에 --clean 복구를 실제로 건다 (되돌릴 수 없다)
  --target-dir DIR     파일 복구 목적지 (기본 /var/lib/tbox-v2/storage)
  --stop-service       복구 중 php-fpm 을 멈추고 끝나면 다시 켠다 (ARCH 11.2 1·8단계)
  --skip-check         복구 뒤 정합성 검사를 생략한다 (권하지 않는다)
USAGE
  exit 2
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --db)          DB_ARTIFACT="${2:-}";    shift 2 ;;
    --files)       FILES_ARTIFACT="${2:-}"; shift 2 ;;
    --target-dir)  TARGET_DIR="${2:-}";     shift 2 ;;
    --dbname)      DB_NAME="${2:-}";        shift 2 ;;
    --stop-service) STOP_SERVICE=1;         shift ;;
    --skip-check)   SKIP_CHECK=1;           shift ;;
    --yes-production) YES_PROD=1;           shift ;;
    *) printf '[중단] 모르는 인자: %s\n' "$1" >&2; usage ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash scripts/restore.sh …"
[ -n "$DB_ARTIFACT" ] || [ -n "$FILES_ARTIFACT" ] || usage
[ -r "$PASS_FILE" ] || die "백업 키가 없다: ${PASS_FILE}"

# 인자는 **이름만** 받는다. 경로가 섞이면 백업 디렉터리 밖을 가리킬 수 있다.
for v in "$DB_ARTIFACT" "$FILES_ARTIFACT"; do
  case "$v" in
    */*|..*) die "산출물은 경로가 아니라 파일 이름으로 지정한다: ${v}" ;;
  esac
done
case "$DB_NAME" in
  [a-z_][a-z0-9_]*) : ;;
  *) die "DB 이름 형태가 아니다: ${DB_NAME}" ;;
esac

RESTORED=""
OPERATOR="${SUDO_USER:-root}"
START_TS="$(date +%s)"

printf '\n== 복구 시작 %s (실행 %s) ==\n' "$(date -Is)" "$OPERATOR"

# ── ARCH 11.2 [1] 서비스 중단 ──────────────────────────────────
if [ "$STOP_SERVICE" -eq 1 ]; then
  systemctl stop "$FPM_SERVICE"
  say "[1] ${FPM_SERVICE} 정지 (복구 중 새 데이터가 섞이지 않게)"
else
  say "[1] 서비스는 그대로 둔다 (--stop-service 를 주면 php-fpm 을 멈춘다)"
fi

restore_finish_service() {
  if [ "$STOP_SERVICE" -eq 1 ]; then
    systemctl start "$FPM_SERVICE"
    say "[8] ${FPM_SERVICE} 재시작"
  fi
}
trap restore_finish_service EXIT

# ── ARCH 11.2 [3] 무결성 확인 ──────────────────────────────────
verify_checksum() {  # verify_checksum <디렉터리> <파일명>
  local dir="$1" name="$2"
  [ -r "${dir}/${name}" ] || die "산출물이 없다: ${dir}/${name}"
  [ -r "${dir}/${name}.sha256" ] || die "체크섬 파일이 없다: ${dir}/${name}.sha256"
  ( cd "$dir" && sha256sum -c --status "${name}.sha256" ) \
    || die "체크섬 불일치 — 이 백업으로 복구하지 않는다: ${name}"
  say "[3] 체크섬 확인 OK: ${name}"
}

# ── ARCH 11.2 [4] DB 복구 ──────────────────────────────────────
if [ -n "$DB_ARTIFACT" ]; then
  verify_checksum "$DB_DIR" "$DB_ARTIFACT"

  if [ "$DB_NAME" = "$PROD_DB" ] && [ "$YES_PROD" -ne 1 ]; then
    die "운영 DB(${PROD_DB})에 --clean 복구를 걸려면 --yes-production 이 필요하다.
      리허설은 별도 DB 로 한다:
        sudo -u postgres createdb -O ${DB_OWNER_ROLE} tbox_re_restore
        sudo bash scripts/restore.sh --db ${DB_ARTIFACT} --dbname tbox_re_restore
        sudo -u postgres dropdb tbox_re_restore"
  fi

  [ -r "$OWNER_PGPASS" ] || die "설치용 비밀번호 파일이 없다: ${OWNER_PGPASS} (deploy/install.sh 가 만든다)"
  # owner.pgpass 에는 운영 DB(tbox_re) 줄만 있다. 리허설 DB 로 붙으려면 같은 비밀번호로
  # **그 DB 이름의 줄**이 필요하다 → 0600 root 임시 파일을 만들어 쓰고 바로 지운다.
  # 비밀번호를 명령줄·환경변수(PGPASSWORD)에 싣지 않기 위해서다 (`ps` 노출 방지).
  OWNER_PASS="$(awk -F: -v h="$DB_HOST" '$1==h {print $5; exit}' "$OWNER_PGPASS")"
  [ -n "$OWNER_PASS" ] || die "owner.pgpass 에서 비밀번호를 읽지 못했다"
  TMP_PGPASS="$(mktemp "${CONF_DIR}/.restore-pgpass.XXXXXX")"
  chmod 0600 "$TMP_PGPASS"
  printf '%s:%s:%s:%s:%s\n' "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_OWNER_ROLE" "$OWNER_PASS" > "$TMP_PGPASS"
  unset OWNER_PASS

  say "[4] DB 복구: ${DB_ARTIFACT} → ${DB_NAME} (역할 ${DB_OWNER_ROLE}, --clean --if-exists)"
  RC_FILE="$(mktemp "${BACKUP_ROOT}/.restore-rc.XXXXXX")"
  set +e
  { gpg --batch --quiet --decrypt --passphrase-file "$PASS_FILE" "${DB_DIR}/${DB_ARTIFACT}" 2>/dev/null; \
    printf '%s' "$?" > "$RC_FILE"; } \
    | PGPASSFILE="$TMP_PGPASS" pg_restore --clean --if-exists --no-owner --no-privileges \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_OWNER_ROLE" -d "$DB_NAME"
  PGR_RC=$?
  set -e
  GPG_RC="$(cat "$RC_FILE")"; rm -f "$RC_FILE"
  rm -f "$TMP_PGPASS"

  [ "${GPG_RC:-1}" -eq 0 ] || die "복호화 실패 (gpg 종료 코드 ${GPG_RC}) — 키가 맞지 않거나 파일이 깨졌다"
  if [ "$PGR_RC" -ne 0 ]; then
    # pg_restore 는 "없는 것을 DROP" 같은 경고에도 0 이 아닌 값을 낼 수 있다(빈 DB 에 --clean).
    # 그래서 멈추지 않고 알리기만 한다 — 실제 성공 여부는 아래 표·행 수로 확인한다.
    say "    경고: pg_restore 종료 코드 ${PGR_RC} (빈 DB 에 --clean 을 걸면 흔히 난다. 아래 표 수를 확인할 것)"
  fi
  say "    표 수: $(runuser -u postgres -- psql -Atd "$DB_NAME" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")"
  RESTORED="${RESTORED}${RESTORED:+,}db"
fi

# ── ARCH 11.2 [5] 파일 복구 ────────────────────────────────────
if [ -n "$FILES_ARTIFACT" ]; then
  verify_checksum "$FILES_DIR" "$FILES_ARTIFACT"
  [ -d "$TARGET_DIR" ] || die "복구 목적지가 없다: ${TARGET_DIR}"

  say "[5] 파일 복구: ${FILES_ARTIFACT} → ${TARGET_DIR}"
  TARGET_BASE="$(basename "$TARGET_DIR")"
  TARGET_PARENT="$(dirname "$TARGET_DIR")"

  RC_FILE="$(mktemp "${BACKUP_ROOT}/.restore-rc.XXXXXX")"
  set +e
  if [ "$TARGET_BASE" = "storage" ]; then
    # 아카이브 안의 최상위 이름이 storage 라 그대로 펼치면 된다 (ARCH 11.2 5단계 명령 그대로).
    { gpg --batch --quiet --decrypt --passphrase-file "$PASS_FILE" "${FILES_DIR}/${FILES_ARTIFACT}" 2>/dev/null; \
      printf '%s' "$?" > "$RC_FILE"; } | tar -C "$TARGET_PARENT" -xzf -
    TAR_RC=$?
  else
    # 목적지 이름이 다르면 같은 파일시스템의 임시 자리에 펼친 뒤 옮긴다.
    STAGE="$(mktemp -d "${TARGET_PARENT}/.restore-stage.XXXXXX")"
    { gpg --batch --quiet --decrypt --passphrase-file "$PASS_FILE" "${FILES_DIR}/${FILES_ARTIFACT}" 2>/dev/null; \
      printf '%s' "$?" > "$RC_FILE"; } | tar -C "$STAGE" -xzf -
    TAR_RC=$?
    if [ "$TAR_RC" -eq 0 ]; then
      rsync -a "${STAGE}/storage/" "${TARGET_DIR}/"
      TAR_RC=$?
    fi
    rm -rf "$STAGE"
  fi
  set -e
  GPG_RC="$(cat "$RC_FILE")"; rm -f "$RC_FILE"

  [ "${GPG_RC:-1}" -eq 0 ] || die "복호화 실패 (gpg 종료 코드 ${GPG_RC})"
  [ "$TAR_RC" -eq 0 ] || die "압축 해제 실패 (tar 종료 코드 ${TAR_RC})"

  # 소유·권한을 다시 맞춘다 (ARCH 6.3: 디렉터리 0750 / 파일 0640, 소유·그룹 tbox-php).
  # tar 가 아카이브의 소유를 복원하지만, 다른 서버에서 만든 산출물을 풀 때를 대비해 한 번 더 건다.
  if id -u "$APP_USER" >/dev/null 2>&1; then
    chown -R "${APP_USER}:${APP_USER}" "$TARGET_DIR"
  fi
  find "$TARGET_DIR" -type d -exec chmod 0750 {} +
  find "$TARGET_DIR" -type f -exec chmod 0640 {} +
  say "    복구된 파일: $(find "$TARGET_DIR" -type f | wc -l)개, $(du -sh "$TARGET_DIR" | awk '{print $1}')"
  RESTORED="${RESTORED}${RESTORED:+,}files"
fi

# ── ARCH 11.2 [6] 정합성 검사 ──────────────────────────────────
CHECK_RESULT="skipped"
if [ "$SKIP_CHECK" -eq 0 ]; then
  say "[6] 정합성 검사 (php scripts/consistency_check.php --strict)"
  set +e
  php "${REPO_DIR}/scripts/consistency_check.php" --strict
  CHECK_RC=$?
  set -e
  if [ "$CHECK_RC" -eq 0 ]; then
    CHECK_RESULT="pass"
  else
    CHECK_RESULT="fail"
    say "    ★ 정합성 검사 실패 (종료 코드 ${CHECK_RC}) — 복구가 끝난 것이 아니다"
  fi
else
  say "[6] 정합성 검사 생략 (--skip-check)"
fi

ELAPSED=$(( $(date +%s) - START_TS ))

# ── ARCH 11.2 [8] 기록 ─────────────────────────────────────────
php "${REPO_DIR}/scripts/audit_cli.php" backup.restore allow \
  --target "$OPERATOR" --target-type user \
  --detail "$(printf '{"scope":"%s","artifact":"%s","dbname":"%s","target_dir":"%s","consistency":"%s","elapsed_sec":%s}' \
    "$RESTORED" "${DB_ARTIFACT}${DB_ARTIFACT:+${FILES_ARTIFACT:+ }}${FILES_ARTIFACT}" "$DB_NAME" "$TARGET_DIR" "$CHECK_RESULT" "$ELAPSED")" \
  || printf '[경고] 감사 기록 실패\n' >&2

printf '\n복구 끝 — 범위 %s / 정합성 %s / %s초\n' "$RESTORED" "$CHECK_RESULT" "$ELAPSED"
printf '다음: [7] 스모크 테스트(로그인 → 목록 → 업로드 → 다운로드 → 권한 매트릭스) — PRD 7.8\n'
[ "$CHECK_RESULT" != "fail" ]
