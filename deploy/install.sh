#!/usr/bin/env bash
# deploy/install.sh — t-box 재구축 설치 (ARCHITECTURE 3 · 8 · 9 · 10장, PRD S-09-6 · S-09-10)
#
# 사용법:  sudo bash deploy/install.sh
#          그다음  sudo bash deploy/switch-site.sh v2   ← 80 포트에 어느 버전을 꽂을지 고른다
#
# 성질
#   · 멱등하다. 두 번 이상 실행해도 안전하다(있으면 건너뛴다).
#   · 패키지를 설치하지 않는다. 없으면 무엇이 없는지 알려 주고 멈춘다(apt 를 몰래 돌리지 않는다).
#   · 이미 있는 .env 의 DB 비밀번호를 바꾸지 않는다(돌아가던 앱을 끊지 않기 위해).
#   · **현재 도메인에 공개 중인 버전을 바꾸지 않는다.** 전환은 deploy/switch-site.sh 만 한다.
#     (공개 링크가 하나도 없을 때만 v1 실습 사이트를 기본으로 꽂는다.)
#   · TLS 를 만들지 않는다. HTTPS 는 강사 리버스 프록시가 종단하고 VM 은 80 만 받는다 (ARCH 3장 배포 메모).
set -euo pipefail

# ─────────────────────────────────────────────────────────────
# 0. 기본값
# ─────────────────────────────────────────────────────────────
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

APP_USER="tbox-php"                 # php-fpm 전용 시스템 계정 (PRD S-09-10)
OWNER_UNIX_USER="changkwon"         # 저장소·.env 소유자(개발자 계정)

DB_NAME="tbox_re"
DB_OWNER_ROLE="tbox_owner"          # 스키마 소유 (배포 시에만 사용)
DB_APP_ROLE="tbox_app"              # 앱 런타임 (최소 권한)
DB_HOST="127.0.0.1"
DB_PORT="5432"

STORAGE_DIR="/var/lib/tbox/storage"
SESSION_DIR="/var/lib/tbox/sessions"
LOG_DIR="/var/log/tbox"
CONF_DIR="/etc/tbox"
OWNER_PGPASS="${CONF_DIR}/owner.pgpass"

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

ENV_FILE="${REPO_DIR}/.env"
APP_URL="https://${CERT_CN}"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die()  { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash deploy/install.sh"

psql_su() { runuser -u postgres -- psql -v ON_ERROR_STOP=1 "$@"; }

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

# 저장 디렉터리 그룹은 www-data (ARCH 6.3, 2026-09-09 개정).
# 이유: 다운로드는 PHP 가 권한 확인을 끝낸 뒤 X-Accel-Redirect 로 nginx 에 넘긴다.
#       nginx(www-data)는 그 internal 경로(ARCH 8장 `/protected/`, 외부 URL 로는 접근 불가)에서만
#       파일을 읽으므로 그룹 읽기 권한이 필요하다. 쓰기는 소유자(tbox-php)만 — 0750 / 파일 0640.
install -d -o "$APP_USER" -g www-data    -m 0750 /var/lib/tbox
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

# 4-1. tbox_owner — 비밀번호는 설치 시에만 쓰므로 매 실행 새로 만들어 /etc/tbox/owner.pgpass 에 둔다.
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
info "설치용 비밀번호 저장: ${OWNER_PGPASS} (0600 root). .env 에는 넣지 않는다"

# 4-2. tbox_app — .env 가 이미 있으면 그 비밀번호를 그대로 쓴다(앱을 끊지 않는다).
APP_PASS=""
if [ -f "$ENV_FILE" ]; then
  APP_PASS="$(sed -n 's/^DB_PASS=//p' "$ENV_FILE" | head -n1)"
fi
if [ -n "$APP_PASS" ]; then
  info "기존 .env 의 DB_PASS 를 유지한다"
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
# 5. .env 생성 (없을 때만)  — PRD S-09-6
# ─────────────────────────────────────────────────────────────
step "5. .env"
if [ -f "$ENV_FILE" ]; then
  info ".env 있음 — 값을 바꾸지 않는다 (${ENV_FILE})"
else
  umask 077
  cat > "$ENV_FILE" <<ENVEOF
# 이 파일은 deploy/install.sh 가 생성했다. 저장소에 커밋하지 않는다 (.gitignore).
# 키 설명은 .env.example 참고.
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_APP_ROLE}
DB_PASS=${APP_PASS}
SESSION_IDLE_MIN=30
SESSION_MAX_HOURS=8
STORAGE_DIR=${STORAGE_DIR}
AUDIT_LOG_FILE=${LOG_DIR}/audit.log
FETCH_ALLOWED_HOSTS=
APP_URL=${APP_URL}
ENVEOF
  umask 022
  info ".env 생성"
fi
# 소유·권한은 매번 맞춘다 (php-fpm 계정이 그룹으로 읽는다. ARCH 3장 배포 메모)
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
# 7. 자체 확인 (PRD 13장 1단계 통과 기준)
# ─────────────────────────────────────────────────────────────
step "7. 자체 확인 (PRD 13장 1단계 통과 기준)"
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
printf '   GET  http://127.0.0.1:8081/T-Box_Re/src/Db.php  → %s  (v1 경유, 404 기대)\n' \
  "$(code http://127.0.0.1:8081/T-Box_Re/src/Db.php)"
printf '   GET  http://127.0.0.1:8081/T-Box_Re/.env        → %s  (v1 경유, 404 기대)\n' \
  "$(code http://127.0.0.1:8081/T-Box_Re/.env)"

printf '\n   보안 헤더 (%s/):\n' "$LOCAL_HTTP"
curl -sI --max-time 5 "${LOCAL_HTTP}/" | grep -iE 'content-security-policy|x-content-type|x-frame|referrer-policy|strict-transport' | sed 's/^/     /'
printf '   (HSTS 는 공개용 tbox-v2 에만 붙는다 — 로컬 8082 에는 없는 것이 정상)\n'

printf '\n   테이블 목록 (6개 기대):\n'
psql_su -d "$DB_NAME" -c "\dt" | sed 's/^/     /'

printf '\n   audit_logs / permissions 권한 (앱 계정에 UPDATE·DELETE 가 없어야 한다):\n'
psql_su -d "$DB_NAME" -c "\dp audit_logs" | sed 's/^/     /'
psql_su -d "$DB_NAME" -c "\dp permissions" | sed 's/^/     /'

printf '\n설치 완료.\n'
printf '  도메인 %s/ 은 지금 80 포트에 꽂힌 버전을 보여 준다 (현재: %s).\n' \
  "$APP_URL" "${CURRENT_PUBLIC:-tbox-v1}"
printf '  v2(이 저장소)를 공개하려면:  sudo bash deploy/switch-site.sh v2\n'
printf '  버전 상태 보기:              sudo bash deploy/switch-site.sh status\n'
printf '  공개와 무관하게 늘 열린 창구: v1 → http://127.0.0.1:8081/ , v2 → %s/\n' "$LOCAL_HTTP"
printf '    (원격에서는 ssh -L 8081:127.0.0.1:8081 -L 8082:127.0.0.1:8082 %s@VM)\n' "$OWNER_UNIX_USER"
