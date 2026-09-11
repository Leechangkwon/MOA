#!/usr/bin/env bash
# deploy/install.sh — t-box 재구축 설치 (ARCHITECTURE 3 · 8 · 9 · 10장, PRD S-09-6 · S-09-10)
#
# 사용법:  sudo bash deploy/install.sh
#          그다음  sudo bash deploy/switch-site.sh v2   ← 80 포트에 어느 버전을 꽂을지 고른다
#
# 성질
#   · 멱등하다. 두 번 이상 실행해도 안전하다(있으면 건너뛴다).
#   · 패키지를 설치하지 않는다. 없으면 무엇이 없는지 알려 주고 멈춘다(apt 를 몰래 돌리지 않는다).
#   · 이미 있는 저장소 루트 .env 의 DB 비밀번호를 바꾸지 않는다(돌아가던 앱을 끊지 않기 위해).
#   · **현재 도메인에 공개 중인 버전을 바꾸지 않는다.** 전환은 deploy/switch-site.sh 만 한다.
#     (공개 링크가 하나도 없을 때만 v1 실습 사이트를 기본으로 꽂는다.)
#   · TLS 를 만들지 않는다. HTTPS 는 강사 리버스 프록시가 종단하고 VM 은 80 만 받는다 (ARCH 3장 배포 메모).
set -euo pipefail

# ─────────────────────────────────────────────────────────────
# 0. 기본값
# ─────────────────────────────────────────────────────────────
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

APP_USER="tbox-php"                 # php-fpm 전용 시스템 계정 (PRD S-09-10)
# 저장소 소유자(개발자 계정). 설정 파일 .env 의 소유자다 (S-09-6) —
# .env 는 changkwon:tbox-php 0640: 개발자는 읽고 고치고, 앱(tbox-php)은 그룹으로 읽기만 한다.
OWNER_UNIX_USER="changkwon"

DB_NAME="tbox_re"
DB_OWNER_ROLE="tbox_owner"          # 스키마 소유 (배포 시에만 사용)
DB_APP_ROLE="tbox_app"              # 앱 런타임 (최소 권한)
DB_HOST="127.0.0.1"
DB_PORT="5432"

STORAGE_DIR="/var/lib/tbox-v2/storage"
TMP_DIR="/var/lib/tbox-v2/tmp"        # F-14 가져오기 임시파일 (7단계, PRD S-05 ⑧)
SESSION_DIR="/var/lib/tbox-v2/sessions"
LOG_DIR="/var/log/tbox-v2"
CONF_DIR="/etc/tbox-v2"
OWNER_PGPASS="${CONF_DIR}/owner.pgpass"
SEED_FILE="${CONF_DIR}/seed-accounts.txt"   # 시드·검토 계정 비밀번호 (0600 root, ARCH 4.0)
LOGROTATE_CONF="/etc/logrotate.d/tbox-v2"   # 감사 로그 회전 (ARCH 7.1, PRD S-07)

# ── 9단계 운영 (백업·경보·정합성) — PRD S-08 · S-11, ARCH 11.1 ──
DB_BACKUP_ROLE="tbox_backup"                  # 읽기 전용 백업 계정 (ARCH 10장)
BACKUP_PGPASS="${CONF_DIR}/backup.pgpass"     # 위 계정 비밀번호 (0600 root)
BACKUP_KEY="${CONF_DIR}/backup.key"           # gpg 대칭키 (0600 root) — 분실 = 백업 전체 무용 (R-08)
BACKUP_ROOT="/var/backups/tbox-v2"            # 산출물 (0700 root, 웹 루트·업로드 밖)
CRON_FILE="/etc/cron.d/tbox-v2"               # 백업·정리·검증·경보 일정 (ARCH 11.1)
ALERT_STATE="/var/lib/tbox-v2/alerts-state.json"   # 경보 중복 억제 상태 (0600 root)
AUDIT_CHAIN="/var/lib/tbox-v2/audit-chain.txt"     # 감사 로그 해시 체인 (0600 root, R-11)

# CERT_* 는 자체 서명 인증서를 쓰던 때의 값이다. 이제 VM 은 TLS 를 하지 않으므로
# 인증서를 만들지 않는다(3단계 삭제). 두 변수는 그대로 남긴다 —
#   CERT_CN  : APP_URL(브라우저가 보는 주소)과 자체 확인 메시지에 쓰인다
#   CERT_DIR : 예전에 만든 인증서가 남아 있으면 "더 이상 쓰지 않는다" 고 알려 주는 데 쓰인다
#   CERT_IP  : 운영 전환(VM 이 직접 443 을 받는 ARCH 8장 원문) 때 SAN 에 다시 필요하다
CERT_DIR="/etc/ssl/tbox"
CERT_CN="changkwon.mso.so"
CERT_IP="192.168.105.44"

# nginx 사이트 설치·전환은 deploy/switch-site.sh 한 곳에서만 한다 (ARCH 3장 배포 메모).
SWITCH_SH="${REPO_DIR}/deploy/switch-site.sh"
NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"
PUBLIC_SITES=(tbox-v1 tbox-v2 tbox-v3)
LOCAL_HTTP="http://127.0.0.1:8082"          # v2 로컬 전용 포트 (tbox-v2-local)

FPM_POOL="/etc/php/8.1/fpm/pool.d/tbox.conf"
FPM_SERVICE="php8.1-fpm"

# 설정 파일 — 저장소 루트 .env, public/ 밖 (PRD S-09-6. ARCH 3장). .gitignore 로 Git 제외.
ENV_FILE="${REPO_DIR}/.env"
# 운영 전환 검토용으로 옮겼던 위치. 남아 있으면 5단계가 값을 보존하며 mv 로 되돌린다.
OLD_ENV_FILE="${CONF_DIR}/tbox.env"
APP_URL="https://${CERT_CN}"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die()  { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash deploy/install.sh"

psql_su() { runuser -u postgres -- psql -v ON_ERROR_STOP=1 "$@"; }

# 기존 설정 값 하나를 읽는다. 저장소 루트 .env 가 있으면 그것만 본다.
# 없고 /etc/tbox-v2/tbox.env 만 있으면 그 값을 본다 — 이 실행의 5단계가 곧 되돌려 옮길 파일이다.
# (4단계가 DB 비밀번호를 새로 만들어 버리면, 5단계가 이전한 옛 값과 어긋나 앱이 끊긴다.)
env_value() {
  local key="$1" f
  for f in "$ENV_FILE" "$OLD_ENV_FILE"; do
    if [ -f "$f" ]; then
      sed -n "s/^${key}=//p" "$f" | head -n1
      return 0
    fi
  done
}

# ─────────────────────────────────────────────────────────────
# 1. 패키지 확인만 (설치하지 않는다)  — PRD S-10-C
# ─────────────────────────────────────────────────────────────
step "1. 필요한 패키지 확인"
MISSING=()
# php8.1-curl 은 F-14(URL 로 파일 가져오기)의 외부 요청에 필요하다 — 7단계부터 필수다
# (ARCH 9장 "필요 확장", deploy/DEPENDENCIES.md 3장). 없으면 다른 패키지와 같은 규칙으로 멈춘다.
for pkg in php8.1-fpm php8.1-pgsql php8.1-curl nginx postgresql; do
  if dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q "ok installed"; then
    info "설치됨: ${pkg}"
  else
    MISSING+=("$pkg")
  fi
done
if [ "${#MISSING[@]}" -gt 0 ]; then
  printf '\n다음 패키지가 없다:\n' >&2
  for p in "${MISSING[@]}"; do printf '  - %s\n' "$p" >&2; done
  printf '\n설치 후 다시 실행한다:\n  sudo apt-get install %s\n' "${MISSING[*]}" >&2
  die "패키지 누락 — 이 스크립트는 apt 를 실행하지 않는다"
fi
for bin in openssl nginx php-fpm8.1 psql runuser; do
  command -v "$bin" >/dev/null 2>&1 || die "실행 파일을 찾을 수 없다: ${bin}"
done
info "PHP 확장 pgsql: $(php -m | grep -cx pgsql) (1 이어야 함)"
info "PHP 확장 curl:  $(php -m | grep -cx curl) (1 이어야 함 — F-14, PRD S-05)"

# ─────────────────────────────────────────────────────────────
# 2. 전용 계정과 디렉터리  — PRD S-09-10, ARCH 3장
# ─────────────────────────────────────────────────────────────
step "2. 시스템 계정과 디렉터리"
if id -u "$APP_USER" >/dev/null 2>&1; then
  info "계정 있음: ${APP_USER}"
else
  useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$APP_USER"
  info "계정 생성: ${APP_USER} (nologin, 홈 없음)"
fi

# 2-0. v1(실습 앱)과 완전히 분리 — 저장소 밖 디렉터리를 tbox → tbox-v2 로 옮긴다.
#      옛 경로가 있고 새 경로가 아직 없을 때만 통째로 mv 한다(멱등). 새 경로가 이미 있으면
#      건너뛰므로 두 번째 실행부터는 아무 일도 하지 않는다. mv 는 소유·권한을 보존한다.
#      /var/lib/tbox 를 옮기면 그 안의 storage·sessions 도 함께 따라온다.
for _pair in "/var/lib/tbox|/var/lib/tbox-v2" "/var/log/tbox|/var/log/tbox-v2" "/etc/tbox|/etc/tbox-v2"; do
  _old="${_pair%%|*}"; _new="${_pair##*|}"
  if [ -e "$_old" ] && [ ! -e "$_new" ]; then
    mv "$_old" "$_new"
    info "이동: ${_old} → ${_new} (v1 과 분리)"
  fi
done

# 저장 디렉터리 그룹은 tbox-php — **6단계 결정(2026-09-11, ARCH 6.3 · SECURITY R-34)**.
# 이유: 다운로드는 X-Accel-Redirect 가 아니라 PHP readfile() 로 확정했다. 그러면 nginx(www-data)가
#       저장소를 읽을 일이 아예 없다. 같은 VM 에서 옛 취약판 v1 이 www-data 로 돌기 때문에
#       "그룹 www-data 읽기" 는 곧 v1 웹셸의 읽기 권한이었다 → 그룹을 tbox-php 로 회수한다.
#       nginx 의 `/protected/` `internal` 블록은 404 보장용으로만 남긴다 (T-10).
#       쓰기는 소유자(tbox-php)만 — 디렉터리 0750 / 파일 0640.
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 /var/lib/tbox-v2
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$STORAGE_DIR"
# install -d 는 이미 있는 디렉터리의 소유·권한도 맞춰 주지만 **하위 폴더·파일까지는 손대지 않는다**.
# 3단계까지 www-data 그룹으로 만들어진 것이 남아 있을 수 있으므로 한 번 훑어 회수한다(멱등).
chgrp -R "$APP_USER" "$STORAGE_DIR"
# 세션 디렉터리는 nginx 가 읽을 일이 없다 — 0700 tbox-php 그대로 (R-10)
install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$SESSION_DIR"
# 가져오기(F-14) 임시 디렉터리 — 7단계. 저장소의 **형제** 디렉터리라 같은 파일시스템이고
# (Storage::putLocal 의 rename 이 디스크를 넘지 않는다) php-fpm 의 open_basedir 안이다.
# 앱만 쓰므로 0700 tbox-php. 여기 남는 파일은 실패한 가져오기의 흔적이다(정상 상태는 0건).
install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$TMP_DIR"
install -d -o "$APP_USER" -g adm         -m 0750 "$LOG_DIR"
install -d -o root       -g "$APP_USER"  -m 0750 "$CONF_DIR"
# 감사 로그 파일 사본 — Audit.php 가 직접 append 한다 (ARCH 7.1)
if [ ! -e "${LOG_DIR}/audit.log" ]; then
  install -o "$APP_USER" -g adm -m 0640 /dev/null "${LOG_DIR}/audit.log"
fi
if [ ! -e "${LOG_DIR}/app.log" ]; then
  install -o "$APP_USER" -g adm -m 0640 /dev/null "${LOG_DIR}/app.log"
fi

# 2-1. 감사 로그 파일을 "추가만 가능" 하게 잠근다 — chattr +a (ARCH 7.1, PRD S-07 "파일 사본 보호").
#      +a 가 걸리면 소유자(tbox-php)여도 덧붙이기만 되고, 지우거나 고치거나 이름을 바꿀 수 없다.
#      해제는 root 만 할 수 있고, 회전은 logrotate 가 prerotate/postrotate 에서 처리한다(6절).
#      파일시스템이 지원하지 않을 수 있으므로(overlayfs·tmpfs 등) 실패해도 설치를 멈추지 않는다 —
#      대신 경고를 남긴다. "걸린 줄 알았는데 안 걸려 있었다" 가 가장 나쁜 상태이므로 결과를 출력한다.
if command -v chattr >/dev/null 2>&1; then
  if chattr +a "${LOG_DIR}/audit.log" 2>/dev/null; then
    info "감사 로그를 추가 전용으로 잠갔다: chattr +a ${LOG_DIR}/audit.log"
  else
    info "경고: chattr +a 를 걸지 못했다 (파일시스템 미지원?) — audit.log 는 지워질 수 있다 (R-11)"
  fi
  info "$(lsattr "${LOG_DIR}/audit.log" 2>/dev/null || echo "lsattr 실행 불가: ${LOG_DIR}/audit.log")"
else
  info "경고: chattr 이 없다 (e2fsprogs) — 감사 로그 추가 전용 보호를 걸지 못했다 (R-11)"
fi
# 2-2. 운영 배치용 자리 — 백업 산출물·경보 로그·상태 파일 (9단계, PRD S-08 · S-11, ARCH 11.1)
#      백업 디렉터리는 0700 root: "백업 디렉터리 0700, 전용 계정만. 웹 루트·업로드 디렉터리 안에
#      두지 않는다" (PRD S-08 백업 보안). 앱 계정(tbox-php)은 여기를 읽지도 못한다 —
#      앱이 뚫려도 전체 데이터 사본에는 닿지 않는다.
install -d -o root -g root -m 0700 "$BACKUP_ROOT" \
        "${BACKUP_ROOT}/db" "${BACKUP_ROOT}/files" "${BACKUP_ROOT}/weekly" \
        "${BACKUP_ROOT}/monthly" "${BACKUP_ROOT}/files-mirror"
info "백업 디렉터리: ${BACKUP_ROOT} 0700 root (db·files·weekly·monthly·files-mirror)"

# 경보 로그 — 감사 로그와 같은 디렉터리·같은 소유. alerts.php 가 한 줄 JSON 으로 덧붙인다.
# audit.log 와 달리 chattr +a 를 걸지 않는다: 경보는 증거가 아니라 알림 사본이고,
# 원본 근거는 audit_logs 의 alert.raised 행이다 (ARCH 11.4).
if [ ! -e "${LOG_DIR}/alerts.log" ]; then
  install -o "$APP_USER" -g adm -m 0640 /dev/null "${LOG_DIR}/alerts.log"
fi

# 상태 파일 두 개는 root 만 읽고 쓴다 — 경보 근거·해시 체인은 침입자에게 보여 줄 값이 아니다.
[ -e "$ALERT_STATE" ] || install -o root -g root -m 0600 /dev/null "$ALERT_STATE"
[ -e "$AUDIT_CHAIN" ] || install -o root -g root -m 0600 /dev/null "$AUDIT_CHAIN"

# 백업 암호화 키 — **이미 있으면 절대 바꾸지 않는다.** 키가 바뀌면 그 전 백업을 전부 못 연다.
if [ -s "$BACKUP_KEY" ]; then
  info "백업 키 있음: ${BACKUP_KEY} (그대로 둔다 — 바꾸면 이전 백업을 못 연다)"
else
  umask 077
  openssl rand -hex 32 > "$BACKUP_KEY"
  umask 022
  chown root:root "$BACKUP_KEY"
  chmod 0600 "$BACKUP_KEY"
  info "백업 키 생성: ${BACKUP_KEY} (0600 root)"
  info "  ★ 이 키의 사본을 **서버 밖**에 2인 보관할 것 — 잃으면 모든 백업이 무용지물이다 (R-08, PRD S-10-E)"
fi

info "디렉터리: ${STORAGE_DIR} 0750 ${APP_USER}:${APP_USER} / ${TMP_DIR} 0700 ${APP_USER} / ${SESSION_DIR} 0700 ${APP_USER} / ${LOG_DIR} 0750 / ${CONF_DIR} 0750"

# ─────────────────────────────────────────────────────────────
# 3. TLS — 이 VM 에서는 만들지 않는다 (ARCH 3장 배포 메모, PRD S-09-1)
# ─────────────────────────────────────────────────────────────
step "3. TLS"
info "이 VM 은 TLS 를 하지 않는다 — 강사 리버스 프록시가 https 를 종단하고 80 으로 전달한다."
info "따라서 자체 서명 인증서를 만들지 않는다 (예전 3단계 삭제)."
if [ -e "${CERT_DIR}/fullchain.pem" ] || [ -e "${CERT_DIR}/privkey.pem" ]; then
  info "예전 인증서가 ${CERT_DIR} 에 남아 있다 — 더 이상 쓰지 않는다(지우지도 않는다)."
fi

# ─────────────────────────────────────────────────────────────
# 4. PostgreSQL 역할 · DB · 스키마  — ARCH 10장
# ─────────────────────────────────────────────────────────────
step "4. PostgreSQL"
systemctl is-active --quiet postgresql || die "postgresql 이 실행 중이 아니다 (systemctl start postgresql)"

role_exists() { [ "$(psql_su -Atc "SELECT 1 FROM pg_roles WHERE rolname='$1'")" = "1" ]; }
db_exists()   { [ "$(psql_su -Atc "SELECT 1 FROM pg_database WHERE datname='$1'")" = "1" ]; }

# 4-1. tbox_owner — 비밀번호는 설치 시에만 쓰므로 매 실행 새로 만들어 /etc/tbox-v2/owner.pgpass 에 둔다.
OWNER_PASS="$(openssl rand -hex 16)"
if role_exists "$DB_OWNER_ROLE"; then
  psql_su -qc "ALTER ROLE ${DB_OWNER_ROLE} WITH LOGIN PASSWORD '${OWNER_PASS}'"
  info "역할 있음: ${DB_OWNER_ROLE} (설치용 비밀번호 재발급)"
else
  psql_su -qc "CREATE ROLE ${DB_OWNER_ROLE} WITH LOGIN PASSWORD '${OWNER_PASS}'"
  info "역할 생성: ${DB_OWNER_ROLE}"
fi
umask 077
printf '%s:%s:%s:%s:%s\n' "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_OWNER_ROLE" "$OWNER_PASS" > "$OWNER_PGPASS"
chown root:root "$OWNER_PGPASS"
chmod 0600 "$OWNER_PGPASS"
umask 022
info "설치용 비밀번호 저장: ${OWNER_PGPASS} (0600 root). 앱 설정 파일에는 넣지 않는다"

# 4-2. tbox_app — 설정 파일이 이미 있으면 그 비밀번호를 그대로 쓴다(앱을 끊지 않는다).
APP_PASS="$(env_value DB_PASS)"
if [ -n "$APP_PASS" ]; then
  info "기존 설정의 DB_PASS 를 유지한다"
else
  APP_PASS="$(openssl rand -hex 16)"
  info "앱 계정 비밀번호를 새로 만든다"
fi
if role_exists "$DB_APP_ROLE"; then
  psql_su -qc "ALTER ROLE ${DB_APP_ROLE} WITH LOGIN PASSWORD '${APP_PASS}'"
  info "역할 있음: ${DB_APP_ROLE}"
else
  psql_su -qc "CREATE ROLE ${DB_APP_ROLE} WITH LOGIN PASSWORD '${APP_PASS}'"
  info "역할 생성: ${DB_APP_ROLE}"
fi
# 앱 계정에는 DB 생성·역할 생성 권한이 없어야 한다
psql_su -qc "ALTER ROLE ${DB_APP_ROLE} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS"
psql_su -qc "ALTER ROLE ${DB_OWNER_ROLE} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS"

# 4-2-1. tbox_backup — 읽기 전용 백업 계정 (9단계, ARCH 10장 · 11.1, PRD S-08).
#        비밀번호는 백업 스크립트만 쓰므로 매 실행 새로 만들어 /etc/tbox-v2/backup.pgpass 에 둔다.
#        명령줄·환경변수에 넣지 않는다 — `ps` 로 다른 계정에게 보이면 안 된다.
BACKUP_PASS="$(openssl rand -hex 16)"
if role_exists "$DB_BACKUP_ROLE"; then
  psql_su -qc "ALTER ROLE ${DB_BACKUP_ROLE} WITH LOGIN PASSWORD '${BACKUP_PASS}'"
  info "역할 있음: ${DB_BACKUP_ROLE} (백업용 비밀번호 재발급)"
else
  psql_su -qc "CREATE ROLE ${DB_BACKUP_ROLE} WITH LOGIN PASSWORD '${BACKUP_PASS}'"
  info "역할 생성: ${DB_BACKUP_ROLE} (읽기 전용)"
fi
psql_su -qc "ALTER ROLE ${DB_BACKUP_ROLE} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS"
umask 077
printf '%s:%s:%s:%s:%s\n' "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_BACKUP_ROLE" "$BACKUP_PASS" > "$BACKUP_PGPASS"
chown root:root "$BACKUP_PGPASS"
chmod 0600 "$BACKUP_PGPASS"
umask 022
info "백업 계정 비밀번호 저장: ${BACKUP_PGPASS} (0600 root)"

# 4-3. DB
if db_exists "$DB_NAME"; then
  info "DB 있음: ${DB_NAME}"
else
  psql_su -qc "CREATE DATABASE ${DB_NAME} OWNER ${DB_OWNER_ROLE} ENCODING 'UTF8'"
  info "DB 생성: ${DB_NAME} (OWNER ${DB_OWNER_ROLE})"
fi
# CONNECT 권한은 db/grants.sql 한 곳에서만 다룬다(PUBLIC 회수 + 필요한 역할에만 GRANT).
# 여기서 중복으로 GRANT 하지 않는다 — 권한이 두 곳에 흩어지면 회수 누락이 생긴다.

# 4-4. 스키마 — 표가 아직 없을 때만 적용한다(멱등)
HAS_TABLES="$(psql_su -d "$DB_NAME" -Atc "SELECT to_regclass('public.audit_logs') IS NOT NULL")"
if [ "$HAS_TABLES" = "t" ]; then
  info "스키마 있음 — schema.sql / seed.sql 을 건너뛴다"
else
  info "스키마 적용: db/schema.sql, db/seed.sql (${DB_OWNER_ROLE} 로 실행)"
  PGPASSWORD="$OWNER_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_OWNER_ROLE" -d "$DB_NAME" \
    -v ON_ERROR_STOP=1 -q -f "${REPO_DIR}/db/schema.sql"
  PGPASSWORD="$OWNER_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_OWNER_ROLE" -d "$DB_NAME" \
    -v ON_ERROR_STOP=1 -q -f "${REPO_DIR}/db/seed.sql"
fi

# 4-4-1. 마이그레이션 확인 — 표가 이미 있으면 schema.sql 을 건너뛰므로(4-4), 나중에 더해진
#        컬럼은 db/migrations/*.sql 로만 들어온다. 여기서는 **적용하지 않고 알려만 준다** —
#        운영 DB 에 DDL 을 자동으로 거는 스크립트는 만들지 않는다(ARCH 7.4 "기존 DB 반영").
for _mig in "summary|audit_logs|2026-09-10_audit_summary.sql" "purged_at|files|2026-09-11_purged_at.sql"; do
  _col="${_mig%%|*}"; _rest="${_mig#*|}"; _tbl="${_rest%%|*}"; _file="${_rest##*|}"
  _has="$(psql_su -d "$DB_NAME" -Atc "SELECT 1 FROM information_schema.columns WHERE table_name='${_tbl}' AND column_name='${_col}'")"
  if [ "$_has" = "1" ]; then
    info "마이그레이션 적용됨: ${_tbl}.${_col}"
  else
    info "★ 마이그레이션 필요: ${_tbl}.${_col} — sudo -u postgres psql -d ${DB_NAME} -v ON_ERROR_STOP=1 -f db/migrations/${_file}"
  fi
done

# 4-5. 권한 — 항상 적용한다(파일 자체가 REVOKE 후 GRANT 이므로 멱등)
info "권한 적용: db/grants.sql (postgres 로 실행)"
psql_su -d "$DB_NAME" -q -f "${REPO_DIR}/db/grants.sql"

# ─────────────────────────────────────────────────────────────
# 5. 앱 설정 파일  저장소 루트 .env  — PRD S-09-6, ARCH 3장
#    public/ 밖, .gitignore 로 Git 제외. 소유 changkwon:tbox-php 0640 —
#    앱(tbox-php)은 그룹으로 읽기만 하고, 스스로 자기 설정을 고쳐 쓸 수 없다.
#    (운영 전환 시 /etc/tbox-v2/tbox.env 로 이전 검토 — 심층 방어. PRD S-09-6)
# ─────────────────────────────────────────────────────────────
step "5. 앱 설정 파일 (${ENV_FILE})"

# 5-0. /etc/tbox-v2/tbox.env 로 옮겨 두었던 파일이 있으면 값을 보존하며 저장소 루트로 되돌린다.
#      복사가 아니라 mv 다 — 설정 파일은 한 곳(저장소 루트 .env)에만 있게 한다.
if [ ! -f "$ENV_FILE" ] && [ -f "$OLD_ENV_FILE" ]; then
  mv "$OLD_ENV_FILE" "$ENV_FILE"
  info "되돌림: ${OLD_ENV_FILE} → ${ENV_FILE} (값은 그대로. PRD S-09-6)"
  info "값을 보려면: sudo cat ${ENV_FILE}"
elif [ -f "$ENV_FILE" ] && [ -f "$OLD_ENV_FILE" ]; then
  # 둘 다 있으면 저장소 루트가 정답이다. 옛 파일은 지우지 않고 알려만 준다(사람이 확인 후 지운다).
  info "경고: /etc/tbox-v2 위치에 ${OLD_ENV_FILE} 이 남아 있다 — 앱은 ${ENV_FILE} 만 읽는다."
  info "      내용을 확인한 뒤 직접 지운다:  sudo rm ${OLD_ENV_FILE}"
fi

# CSRF 토큰 HMAC 키 (PRD S-09-3, ARCH 4.0). 기존 값은 절대 바꾸지 않는다 —
# 바꾸면 살아 있는 모든 세션의 CSRF 토큰이 한꺼번에 무효가 된다.
APP_SECRET="$(env_value APP_SECRET)"
if [ -z "$APP_SECRET" ]; then
  APP_SECRET="$(openssl rand -hex 32)"
fi

if [ -f "$ENV_FILE" ]; then
  info "설정 파일 있음 — 값을 바꾸지 않는다 (${ENV_FILE})"
  # 1단계에 만든 설정에는 APP_SECRET 줄이 없다. 없을 때만 덧붙인다(다른 값은 손대지 않는다).
  if grep -q '^APP_SECRET=' "$ENV_FILE"; then
    info "APP_SECRET 있음 — 유지한다"
  else
    # 마지막 줄에 개행이 없으면 먼저 넣는다 (기존 마지막 값에 붙어 버리는 것 방지)
    if [ -n "$(tail -c1 "$ENV_FILE")" ]; then printf '\n' >> "$ENV_FILE"; fi
    printf 'APP_SECRET=%s\n' "$APP_SECRET" >> "$ENV_FILE"
    info "APP_SECRET 이 없어 새로 만들어 덧붙였다 (CSRF 토큰 키, PRD S-09-3)"
  fi
else
  umask 077
  cat > "$ENV_FILE" <<ENVEOF
# 이 파일은 deploy/install.sh 가 생성했다. 저장소 루트 .env — PRD S-09-6.
# public/ 밖, .gitignore 로 Git 제외. 소유 changkwon:tbox-php 0640.
# 앱은 그룹으로 읽기만 한다. 고칠 때는 소유자·sudo, 그다음 php-fpm reload.
# 키 설명은 저장소의 .env.example 참고.
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_APP_ROLE}
DB_PASS=${APP_PASS}
APP_SECRET=${APP_SECRET}
SESSION_IDLE_MIN=30
SESSION_MAX_HOURS=8
STORAGE_DIR=${STORAGE_DIR}
AUDIT_LOG_FILE=${LOG_DIR}/audit.log
FETCH_ALLOWED_HOSTS=
ALERT_COMMAND=
APP_URL=${APP_URL}
ENVEOF
  umask 022
  info "설정 파일 생성: ${ENV_FILE}"
fi
# 5-2. 저장소 밖 경로가 tbox → tbox-v2 로 바뀌었으므로 .env 의 두 경로 키만 갱신한다.
#       이 두 줄(STORAGE_DIR·AUDIT_LOG_FILE)만 치환하고 다른 값·권한은 손대지 않는다(멱등).
#       audit.log 는 매 요청 쓰이므로 경로가 어긋나면 500 이 난다 — 파일 값과 실제 경로를 맞춘다.
if [ -f "$ENV_FILE" ]; then
  sed -i \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=${STORAGE_DIR}#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=${LOG_DIR}/audit.log#" \
    "$ENV_FILE"
  info ".env 경로 키 갱신: STORAGE_DIR=${STORAGE_DIR} / AUDIT_LOG_FILE=${LOG_DIR}/audit.log"
fi

# 소유·권한은 매번 맞춘다 (PRD S-09-6).
#   changkwon 소유 → 개발자는 읽고 고친다. 앱 계정(tbox-php)은 소유자가 아니다.
#   tbox-php  그룹 → php-fpm 이 그룹으로 읽는다 (ARCH 3장 배포 메모)
#   0640           → 그룹은 읽기 전용(앱은 자기 설정을 고쳐 쓸 수 없다), 그 외 계정은 읽지도 못한다
chown "${OWNER_UNIX_USER}:${APP_USER}" "$ENV_FILE"
chmod 0640 "$ENV_FILE"
info "$(ls -l "$ENV_FILE")"

# ─────────────────────────────────────────────────────────────
# 6. php-fpm 풀 · nginx 설정
# ─────────────────────────────────────────────────────────────
step "6. php-fpm 풀과 nginx 사이트"
install -o root -g root -m 0644 "${REPO_DIR}/deploy/php-fpm-pool.conf" "$FPM_POOL"
info "복사: ${FPM_POOL}"

# 6-1. 로그 회전 — 감사 로그는 일 단위·365일·압축, 회전 전후로 chattr 을 풀고 다시 건다.
#      (ARCH 7.1 표, PRD S-07 "파일 사본 보호", SECURITY R-11)
#      logrotate 는 소유자·권한이 느슨한 설정 파일을 무시하므로 root:root 0644 로 넣는다.
install -o root -g root -m 0644 "${REPO_DIR}/deploy/logrotate.d/tbox-v2" "$LOGROTATE_CONF"
info "복사: ${LOGROTATE_CONF}"
# 문법 검사 — -d 는 실제로 회전하지 않고 설정만 읽는다. 오류가 있으면 여기서 멈춘다.
if ! logrotate -d "$LOGROTATE_CONF" >/dev/null 2>"${LOGROTATE_CONF}.check"; then
  cat "${LOGROTATE_CONF}.check" >&2
  rm -f "${LOGROTATE_CONF}.check"
  die "logrotate 설정 문법 오류: ${LOGROTATE_CONF}"
fi
if grep -q '^error:' "${LOGROTATE_CONF}.check"; then
  cat "${LOGROTATE_CONF}.check" >&2
  rm -f "${LOGROTATE_CONF}.check"
  die "logrotate 설정 문법 오류: ${LOGROTATE_CONF}"
fi
rm -f "${LOGROTATE_CONF}.check"
info "logrotate 문법 확인 통과 (logrotate -d ${LOGROTATE_CONF})"

# 6-2. cron — 백업·정리·검증·정합성·체인·경보 일정 (9단계, ARCH 11.1 표).
#      /etc/cron.d 형식이라 **실행 계정 칸(root)** 이 있다. 백업 키·pgpass 를 읽어야 하므로 root 다.
#      MAILTO="" 로 두어 메일을 보내지 않는다 — 이 VM 에는 MTA 가 없고, 결과는 감사 로그에 남는다.
#      cron.d 파일은 root 소유 0644 가 아니면 cron 이 **조용히 무시한다**.
install -o root -g root -m 0644 "${REPO_DIR}/deploy/cron.d/tbox-v2" "$CRON_FILE"
info "복사: ${CRON_FILE}"
info "$(head -c 0 /dev/null; grep -c '^[^#]' "$CRON_FILE") 개 작업 등록 (cat ${CRON_FILE} 로 확인)"

# nginx 사이트 파일 설치·전환은 deploy/switch-site.sh 한 곳에서만 한다.
#   · 이 스크립트는 **공개 중인 버전을 바꾸지 않는다** — 도메인에 무엇이 보이는지는 운영 결정이다.
#     설치 때문에 실습 사이트가 갑자기 내려가거나, 반대로 v2 가 내려가는 일이 없어야 한다.
#   · switch-site.sh status 가 사이트 파일 복사(sites-available, conf.d)와
#     로컬용(tbox-v1-local·tbox-v2-local) enable 까지 해 준다. 공개 링크만 손대지 않는다.
#   · 공개 링크가 하나도 없을 때만 v1(실습 사이트)을 기본으로 꽂는다.
[ -f "$SWITCH_SH" ] || die "deploy/switch-site.sh 가 없다 — nginx 사이트를 설치할 수 없다"

CURRENT_PUBLIC=""
for site in "${PUBLIC_SITES[@]}"; do
  if [ -L "${NGINX_ENABLED_DIR}/${site}" ]; then
    CURRENT_PUBLIC="${CURRENT_PUBLIC}${CURRENT_PUBLIC:+ }${site}"
  fi
done

if [ -n "$CURRENT_PUBLIC" ]; then
  info "공개 중인 버전: ${CURRENT_PUBLIC} — 바꾸지 않는다 (전환은 switch-site.sh vN)"
  bash "$SWITCH_SH" status
else
  info "공개 중인 버전이 없다 — v1(실습 사이트)을 기본으로 꽂는다"
  bash "$SWITCH_SH" v1
fi

step "설정 문법 검사"
php-fpm8.1 -t
nginx -t

step "서비스 재적용"
systemctl reload-or-restart "$FPM_SERVICE"
systemctl reload-or-restart nginx
info "php-fpm: $(systemctl is-active "$FPM_SERVICE") / nginx: $(systemctl is-active nginx)"
# ondemand 풀은 첫 요청 때 소켓이 만들어진다. 최대 5초 기다린다.
for _ in 1 2 3 4 5; do
  [ -S /run/php/php8.1-fpm-tbox.sock ] && break
  sleep 1
done
[ -S /run/php/php8.1-fpm-tbox.sock ] && info "소켓 준비: $(ls -l /run/php/php8.1-fpm-tbox.sock)" \
  || info "경고: /run/php/php8.1-fpm-tbox.sock 이 아직 없다"

# ─────────────────────────────────────────────────────────────
# 7. 시드 계정 · 검토 계정 (ARCH 4.0, PRD S-07 · S-09-4 · S-09-6)
#    seed_users.php 가 **계정별로** 없는 것만 만든다(멱등). 비밀번호는 무작위이고
#    평문은 /etc/tbox-v2/seed-accounts.txt (0600 root) 에만 남는다 — 저장소·README 에는 없다.
#    새로 만든 계정 줄은 기존 파일에 **덧붙인다**(>>). 기존 줄을 지우면 이미 쓰고 있는
#    계정의 비밀번호를 잃는다 — 되찾을 방법이 없다(해시만 DB 에 있다).
#    운영 배포에서 검토 계정을 빼려면: php scripts/seed_users.php --no-qa
# ─────────────────────────────────────────────────────────────
step "7. 시드 계정 · 검토 계정"
set +e
SEED_OUT="$(php "${REPO_DIR}/scripts/seed_users.php" 2>&1)"
SEED_RC=$?
set -e
if [ "$SEED_RC" -ne 0 ]; then
  printf '%s\n' "$SEED_OUT" >&2
  die "시드 계정 생성 실패 (scripts/seed_users.php)"
fi
# 계정 줄(`username role password`)만 골라 파일에 **덧붙인다**.
# 덮어쓰지 않는 이유: 이미 쓰고 있는 계정의 평문 비밀번호는 이 파일에만 있다(DB 에는 해시뿐).
# 한 줄도 안 나온 재실행에서는 파일을 건드리지 않는다.
SEED_NEW="$(printf '%s\n' "$SEED_OUT" | grep -E '^[a-z0-9_.-]+ (Admin|Manager|Employee|Guest) [0-9a-f]{16}$' || true)"
if [ -n "$SEED_NEW" ]; then
  if [ ! -e "$SEED_FILE" ]; then
    umask 077
    : > "$SEED_FILE"
    umask 022
  fi
  chown root:root "$SEED_FILE"
  chmod 0600 "$SEED_FILE"
  # 마지막 줄에 개행이 없으면 먼저 넣는다 (기존 줄에 붙어 버리는 것 방지)
  if [ -s "$SEED_FILE" ] && [ -n "$(tail -c1 "$SEED_FILE")" ]; then printf '\n' >> "$SEED_FILE"; fi
  printf '%s\n' "$SEED_NEW" >> "$SEED_FILE"
  info "새 계정 $(printf '%s\n' "$SEED_NEW" | wc -l)개를 ${SEED_FILE} (0600 root) 에 덧붙였다 — 기존 줄은 그대로다"
  info "$(ls -l "$SEED_FILE")"
  info "만든 계정: $(printf '%s\n' "$SEED_NEW" | awk '{printf "%s ", $1}')"
else
  printf '   %s\n' "$SEED_OUT"
  if [ -f "$SEED_FILE" ]; then
    info "${SEED_FILE} 은 그대로 둔다"
  fi
fi

# ─────────────────────────────────────────────────────────────
# 8. 자체 확인 (PRD 13장 1단계 통과 기준)
# ─────────────────────────────────────────────────────────────
step "8. 자체 확인 (PRD 13장 1 · 2단계 통과 기준)"
# 확인은 **v2 로컬 포트(8082)** 로 한다. 공개 80 포트에는 v1 이 꽂혀 있을 수도 있으므로
# "v2 앱이 제대로 섰는지" 를 보려면 공개 버전과 무관한 창구를 써야 한다.
# https://127.0.0.1 은 더 이상 없다 — VM 은 TLS 를 하지 않는다 (ARCH 3장 배포 메모).
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$1"; }

printf '   GET  %s/api/health        → %s  %s\n' \
  "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/api/health")" "$(curl -s --max-time 5 "${LOCAL_HTTP}/api/health")"
printf '   GET  %s/                  → %s\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/")"
printf '   GET  %s/.env              → %s  (404 기대)\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/.env")"
printf '   GET  %s/src/Db.php        → %s  (404 기대)\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/src/Db.php")"
printf '   GET  %s/db/schema.sql     → %s  (404 기대)\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/db/schema.sql")"
printf '   GET  %s/index.php         → %s\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/index.php")"
printf '   GET  %s/protected/x       → %s  (404 기대)\n' "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/protected/x")"
printf '   GET  %s/nope              → %s  %s  (404 JSON 기대)\n' \
  "$LOCAL_HTTP" "$(code "${LOCAL_HTTP}/nope")" "$(curl -s --max-time 5 "${LOCAL_HTTP}/nope")"
printf '   POST %s/api/health        → %s  %s  (405 기대)\n' "$LOCAL_HTTP" \
  "$(curl -s -X POST -o /dev/null -w '%{http_code}' --max-time 5 "${LOCAL_HTTP}/api/health")" \
  "$(curl -s -X POST --max-time 5 "${LOCAL_HTTP}/api/health")"
printf '   GET  http://127.0.0.1:8081/index.php            → %s  (v1 실습 사이트 생존, 200 기대)\n' \
  "$(code http://127.0.0.1:8081/index.php)"

printf '\n   보안 헤더 (%s/):\n' "$LOCAL_HTTP"
curl -sI --max-time 5 "${LOCAL_HTTP}/" | grep -iE 'content-security-policy|x-content-type|x-frame|referrer-policy|strict-transport' | sed 's/^/     /'
printf '   (HSTS 는 공개용 tbox-v2 에만 붙는다 — 로컬 8082 에는 없는 것이 정상)\n'

printf '\n   앱 설정 파일 (%s:%s 0640, 저장소 루트 .env. PRD S-09-6):\n' "$OWNER_UNIX_USER" "$APP_USER"
printf '     %s\n' "$(ls -l "$ENV_FILE")"
if [ -e "$OLD_ENV_FILE" ]; then
  printf '     경고: /etc/tbox-v2 위치가 아직 있다 → %s\n' "$(ls -l "$OLD_ENV_FILE")"
else
  printf '     /etc/tbox-v2/tbox.env: 없음 (정상)\n'
fi

printf '\n   가져오기 임시 디렉터리 (F-14, 남은 파일은 0 이어야 한다):\n'
printf '     %s\n' "$(ls -ld "$TMP_DIR")"
printf '     남은 임시파일: %s 개\n' "$(ls -A "$TMP_DIR" | wc -l)"

printf '\n   감사 로그 파일 사본 보호 (a = 추가 전용. ARCH 7.1, PRD S-07):\n'
printf '     %s\n' "$(lsattr "${LOG_DIR}/audit.log" 2>/dev/null || echo "lsattr 실행 불가")"
printf '     회전 설정: %s\n' "$(ls -l "$LOGROTATE_CONF" 2>/dev/null || echo '없음')"

printf '\n   테이블 목록 (6개 기대):\n'
psql_su -d "$DB_NAME" -c "\dt" | sed 's/^/     /'

printf '\n   audit_logs / permissions 권한 (앱 계정에 UPDATE·DELETE 가 없어야 한다):\n'
psql_su -d "$DB_NAME" -c "\dp audit_logs" | sed 's/^/     /'
psql_su -d "$DB_NAME" -c "\dp permissions" | sed 's/^/     /'

printf '\n   백업 계정 %s 은 읽기 전용이어야 한다 (ARCH 10장, PRD S-08):\n' "$DB_BACKUP_ROLE"
psql_su -d "$DB_NAME" -Atc "SELECT relname, array_to_string(array_agg(privilege_type ORDER BY privilege_type), ',')
     FROM information_schema.table_privileges tp
     JOIN pg_class c ON c.relname = tp.table_name
    WHERE grantee = '${DB_BACKUP_ROLE}' AND table_schema = 'public'
    GROUP BY relname ORDER BY relname" | sed 's/^/     /'

printf '\n   운영 배치 자리 (9단계, PRD S-08 · S-11):\n'
printf '     %s\n' "$(ls -ld "$BACKUP_ROOT")"
printf '     %s\n' "$(ls -l "$BACKUP_KEY" 2>/dev/null || echo '백업 키 없음')"
printf '     %s\n' "$(ls -l "$BACKUP_PGPASS" 2>/dev/null || echo 'backup.pgpass 없음')"
printf '     %s\n' "$(ls -l "$CRON_FILE" 2>/dev/null || echo 'cron 파일 없음')"
printf '     %s\n' "$(ls -l "$ALERT_STATE" 2>/dev/null || echo 'alerts-state.json 없음')"
printf '     %s\n' "$(ls -l "$AUDIT_CHAIN" 2>/dev/null || echo 'audit-chain.txt 없음')"
printf '     백업 산출물 사용량: %s\n' "$(du -sh "$BACKUP_ROOT" 2>/dev/null | awk '{print $1}')"
printf '     디스크: %s\n' "$(df -h / | awk 'NR==2{print $3" / "$2" ("$5" 사용)"}')"

printf '\n설치 완료.\n'
printf '  도메인 %s/ 은 지금 80 포트에 꽂힌 버전을 보여 준다 (현재: %s).\n' \
  "$APP_URL" "${CURRENT_PUBLIC:-tbox-v1}"
printf '  시드 계정 비밀번호 보기:      sudo cat %s\n' "$SEED_FILE"
printf '  앱 설정 보기 / 고치기:        sudo cat %s  /  sudo nano %s (그다음 sudo systemctl reload %s)\n' \
  "$ENV_FILE" "$ENV_FILE" "$FPM_SERVICE"
printf '  백업을 지금 한 번 돌려 보려면: sudo bash scripts/backup_db.sh && sudo bash scripts/backup_files.sh\n'
printf '  ★ 백업 키 %s 의 사본을 서버 밖에 보관할 것 — 잃으면 백업을 못 연다 (R-08)\n' "$BACKUP_KEY"
printf '  v2(이 저장소)를 공개하려면:  sudo bash deploy/switch-site.sh v2\n'
printf '  버전 상태 보기:              sudo bash deploy/switch-site.sh status\n'
printf '  공개와 무관하게 늘 열린 창구: v1 → http://127.0.0.1:8081/ , v2 → %s/\n' "$LOCAL_HTTP"
printf '    (원격에서는 ssh -L 8081:127.0.0.1:8081 -L 8082:127.0.0.1:8082 %s@VM)\n' "$OWNER_UNIX_USER"
