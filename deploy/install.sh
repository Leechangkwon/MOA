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
SESSION_DIR="/var/lib/tbox-v2/sessions"
LOG_DIR="/var/log/tbox-v2"
CONF_DIR="/etc/tbox-v2"
OWNER_PGPASS="${CONF_DIR}/owner.pgpass"
SEED_FILE="${CONF_DIR}/seed-accounts.txt"   # 시드 계정 비밀번호 (0600 root, ARCH 4.0)

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
for pkg in php8.1-fpm php8.1-pgsql nginx postgresql; do
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

# 저장 디렉터리 그룹은 www-data (ARCH 6.3, 2026-09-09 개정).
# 이유: 다운로드는 PHP 가 권한 확인을 끝낸 뒤 X-Accel-Redirect 로 nginx 에 넘긴다.
#       nginx(www-data)는 그 internal 경로(ARCH 8장 `/protected/`, 외부 URL 로는 접근 불가)에서만
#       파일을 읽으므로 그룹 읽기 권한이 필요하다. 쓰기는 소유자(tbox-php)만 — 0750 / 파일 0640.
install -d -o "$APP_USER" -g www-data    -m 0750 /var/lib/tbox-v2
install -d -o "$APP_USER" -g www-data    -m 0750 "$STORAGE_DIR"
# 세션 디렉터리는 nginx 가 읽을 일이 없다 — 0700 tbox-php 그대로 (R-10)
install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$SESSION_DIR"
install -d -o "$APP_USER" -g adm         -m 0750 "$LOG_DIR"
install -d -o root       -g "$APP_USER"  -m 0750 "$CONF_DIR"
# 감사 로그 파일 사본 — Audit.php 가 직접 append 한다 (ARCH 7.1)
if [ ! -e "${LOG_DIR}/audit.log" ]; then
  install -o "$APP_USER" -g adm -m 0640 /dev/null "${LOG_DIR}/audit.log"
fi
if [ ! -e "${LOG_DIR}/app.log" ]; then
  install -o "$APP_USER" -g adm -m 0640 /dev/null "${LOG_DIR}/app.log"
fi
info "디렉터리: ${STORAGE_DIR} 0750 ${APP_USER}:www-data / ${SESSION_DIR} 0700 ${APP_USER} / ${LOG_DIR} 0750 / ${CONF_DIR} 0750"

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
# 7. 시드 계정 (ARCH 4.0, PRD S-09-4 · S-09-6)
#    users 가 비어 있을 때만 5계정을 만든다. 비밀번호는 스크립트가 무작위로 만들고
#    평문은 /etc/tbox-v2/seed-accounts.txt (0600 root) 에만 남는다 — 저장소·README 에는 없다.
# ─────────────────────────────────────────────────────────────
step "7. 시드 계정"
set +e
SEED_OUT="$(php "${REPO_DIR}/scripts/seed_users.php" 2>&1)"
SEED_RC=$?
set -e
if [ "$SEED_RC" -ne 0 ]; then
  printf '%s\n' "$SEED_OUT" >&2
  die "시드 계정 생성 실패 (scripts/seed_users.php)"
fi
# 계정 줄(`username role password`)이 실제로 나왔을 때만 파일을 쓴다.
# '건너뜀' 만 나온 재실행에서 기존 파일을 덮어써 비밀번호를 잃지 않기 위함이다.
if printf '%s\n' "$SEED_OUT" | grep -qE '^[a-z0-9_.-]+ (Admin|Manager|Employee|Guest) [0-9a-f]{16}$'; then
  umask 077
  printf '%s\n' "$SEED_OUT" > "$SEED_FILE"
  umask 022
  chown root:root "$SEED_FILE"
  chmod 0600 "$SEED_FILE"
  info "시드 계정 5개 생성 — 비밀번호는 ${SEED_FILE} (0600 root) 에만 있다"
  info "$(ls -l "$SEED_FILE")"
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

printf '\n   테이블 목록 (6개 기대):\n'
psql_su -d "$DB_NAME" -c "\dt" | sed 's/^/     /'

printf '\n   audit_logs / permissions 권한 (앱 계정에 UPDATE·DELETE 가 없어야 한다):\n'
psql_su -d "$DB_NAME" -c "\dp audit_logs" | sed 's/^/     /'
psql_su -d "$DB_NAME" -c "\dp permissions" | sed 's/^/     /'

printf '\n설치 완료.\n'
printf '  도메인 %s/ 은 지금 80 포트에 꽂힌 버전을 보여 준다 (현재: %s).\n' \
  "$APP_URL" "${CURRENT_PUBLIC:-tbox-v1}"
printf '  시드 계정 비밀번호 보기:      sudo cat %s\n' "$SEED_FILE"
printf '  앱 설정 보기 / 고치기:        sudo cat %s  /  sudo nano %s (그다음 sudo systemctl reload %s)\n' \
  "$ENV_FILE" "$ENV_FILE" "$FPM_SERVICE"
printf '  v2(이 저장소)를 공개하려면:  sudo bash deploy/switch-site.sh v2\n'
printf '  버전 상태 보기:              sudo bash deploy/switch-site.sh status\n'
printf '  공개와 무관하게 늘 열린 창구: v1 → http://127.0.0.1:8081/ , v2 → %s/\n' "$LOCAL_HTTP"
printf '    (원격에서는 ssh -L 8081:127.0.0.1:8081 -L 8082:127.0.0.1:8082 %s@VM)\n' "$OWNER_UNIX_USER"
