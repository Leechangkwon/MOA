#!/usr/bin/env bash
# scripts/check_baseline.sh — 서버 보안 기준선 **점검** (읽기 전용, 9단계)
#   PRD 7.10 S-10-A (기준선) · 12.3 T-17 · T-18 · T-19
#   ARCHITECTURE 11.3 (Hardening 표) · 9장 (PHP 설정)
#
# 사용법:  sudo bash scripts/check_baseline.sh        (sudo 없이도 돌지만 일부 항목이 "확인 불가")
#
# ★ 이 스크립트는 **아무것도 바꾸지 않는다.** 읽고, PRD 기준과 견주어, 표시만 한다.
#   적용은 deploy/hardening.sh 가 하며 그쪽도 기본은 출력만이다(`--apply` 를 사람이 직접 준다).
#   "점검" 과 "적용" 을 한 스크립트에 두지 않는 이유: 방화벽·sshd 변경은 원격 세션을 끊을 수 있어
#   확인하러 들어간 사람이 잠기는 사고가 실제로 잦다.
#
# 표시
#   OK    기준을 만족한다
#   주의  기준과 다르거나, 이 환경에서는 받아들였지만 운영 전환 전에 고쳐야 한다
#   미적용 아직 하지 않았다 (적용 방법을 함께 적는다)
#   확인불가 권한·도구가 없어 읽지 못했다 (안전하다는 뜻이 아니다)
set -uo pipefail       # -e 를 쓰지 않는다: 한 항목이 실패해도 나머지를 계속 점검해야 한다

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FPM_POOL="/etc/php/8.1/fpm/pool.d/tbox.conf"
LOCAL_HTTP="http://127.0.0.1:8082"
AUDIT_LOG="/var/log/tbox-v2/audit.log"
PROXY_IP="192.168.104.100"

OK=0; WARN=0; TODO=0; UNKNOWN=0

hdr()  { printf '\n\033[1m── %s\033[0m\n' "$*"; }
ok()   { printf '  [OK]       %s\n' "$*"; OK=$((OK+1)); }
warn() { printf '  [주의]     %s\n' "$*"; WARN=$((WARN+1)); }
todo() { printf '  [미적용]   %s\n' "$*"; TODO=$((TODO+1)); }
unk()  { printf '  [확인불가] %s\n' "$*"; UNKNOWN=$((UNKNOWN+1)); }
raw()  { sed 's/^/      | /'; }

printf '\033[1m== t-box 서버 기준선 점검 (읽기 전용) %s ==\033[0m\n' "$(date -Is)"
printf '   호스트: %s · 실행 계정: %s\n' "$(hostname)" "$(id -un)"

# ─────────────────────────────────────────────────────────────
hdr "T-17-1  SSH (PRD S-10-A: 키 인증만 · root 로그인 금지 · MaxAuthTries)"
if command -v sshd >/dev/null 2>&1 || [ -x /usr/sbin/sshd ]; then
  SSHD_OUT="$(/usr/sbin/sshd -T 2>/dev/null | grep -iE '^(passwordauthentication|permitrootlogin|maxauthtries|pubkeyauthentication|kbdinteractiveauthentication)')"
  if [ -z "$SSHD_OUT" ]; then
    unk "sshd -T 를 읽지 못했다 (root 권한 필요)"
  else
    printf '%s\n' "$SSHD_OUT" | raw
    grep -qi '^passwordauthentication no'  <<<"$SSHD_OUT" && ok "PasswordAuthentication no" \
      || warn "PasswordAuthentication 이 no 가 아니다 — 비밀번호로 SSH 가 뚫릴 수 있다 (deploy/hardening.sh)"
    grep -qiE '^permitrootlogin (no|prohibit-password)' <<<"$SSHD_OUT" && ok "PermitRootLogin 제한됨" \
      || warn "PermitRootLogin 이 열려 있다"
    MAXTRY="$(grep -i '^maxauthtries' <<<"$SSHD_OUT" | awk '{print $2}')"
    if [ -n "${MAXTRY:-}" ] && [ "$MAXTRY" -le 3 ] 2>/dev/null; then ok "MaxAuthTries ${MAXTRY}"
    else warn "MaxAuthTries ${MAXTRY:-?} (기준 3 이하)"; fi
  fi
else
  unk "sshd 가 없다"
fi
if command -v fail2ban-client >/dev/null 2>&1; then
  ok "fail2ban 설치됨"
else
  todo "fail2ban 없음 — 새 패키지를 설치하지 않는 결정(PRD S-10-C)에 따라 미적용. 대신 sshd MaxAuthTries + 앱 잠금(S-09-4)"
fi

# ─────────────────────────────────────────────────────────────
hdr "T-17-2  방화벽 (PRD S-10-A: 인바운드·아웃바운드 기본 거부)"
if command -v ufw >/dev/null 2>&1; then
  UFW_OUT="$(ufw status verbose 2>/dev/null)"
  if [ -z "$UFW_OUT" ]; then
    unk "ufw status 를 읽지 못했다 (root 권한 필요)"
  else
    printf '%s\n' "$UFW_OUT" | raw
    if grep -q 'Status: active' <<<"$UFW_OUT"; then
      grep -q 'deny (incoming)'  <<<"$UFW_OUT" && ok "인바운드 기본 거부"  || warn "인바운드 기본이 거부가 아니다"
      grep -q 'deny (outgoing)'  <<<"$UFW_OUT" && ok "아웃바운드 기본 거부" \
        || warn "아웃바운드 기본이 거부가 아니다 — SSRF·데이터 유출 통로가 열려 있다 (PRD S-10-D)"
    else
      todo "ufw 가 inactive — 규칙이 하나도 적용되지 않았다. 적용은 사람이 직접: sudo bash deploy/hardening.sh --apply"
      todo "  (원격 SSH 로 작업 중이면 22 번 허용 규칙을 먼저 넣어야 한다. hardening.sh 가 그 순서로 출력한다)"
    fi
  fi
else
  unk "ufw 가 설치돼 있지 않다"
fi

# ─────────────────────────────────────────────────────────────
hdr "T-18  리스닝 포트 (PRD S-10-A: 443·22·PostgreSQL(127.0.0.1) 외 없음)"
if command -v ss >/dev/null 2>&1; then
  SS_OUT="$(ss -tlnp 2>/dev/null)"
  printf '%s\n' "$SS_OUT" | raw
  PG_PUB="$(awk 'NR>1 && $4 ~ /:5432$/ && $4 !~ /^(127\.0\.0\.1|\[::1\])/ {print $4}' <<<"$SS_OUT")"
  if [ -z "$PG_PUB" ]; then ok "PostgreSQL 은 127.0.0.1 에서만 듣는다"
  else warn "PostgreSQL 이 외부 주소에서 듣는다: ${PG_PUB}"; fi
  # 0.0.0.0/:: 로 열린 포트 목록 — 이 VM 은 프록시 뒤라 80 만 열려 있어야 한다 (ARCH 3장 배포 메모)
  PUBLIC_PORTS="$(awk 'NR>1 {split($4,a,":"); p=a[length(a)]; if ($4 ~ /^(0\.0\.0\.0|\[::\]|\*)/) print p}' <<<"$SS_OUT" | sort -un | tr '\n' ' ')"
  printf '      외부에 열린 포트: %s\n' "${PUBLIC_PORTS:-없음}"
  for p in $PUBLIC_PORTS; do
    case "$p" in
      22|80) ok "포트 ${p} (프록시 구조상 허용 — 443 종단은 강사 리버스 프록시, ARCH 3장)" ;;
      *)     warn "포트 ${p} 가 외부에 열려 있다 — 기준선 밖" ;;
    esac
  done
else
  unk "ss 가 없다"
fi

# ─────────────────────────────────────────────────────────────
hdr "T-19  버전 (PRD S-10-B: 교육 단계는 참가자 VM 과 동일 · 운영 전환 시 EOL 2년 이상)"
printf '      OS      : %s\n' "$(lsb_release -ds 2>/dev/null || cat /etc/os-release 2>/dev/null | sed -n 's/^PRETTY_NAME=//p' | tr -d '"')"
printf '      PHP     : %s\n' "$(php -v 2>/dev/null | head -1)"
printf '      psql    : %s\n' "$(psql -V 2>/dev/null)"
printf '      Postgres: %s\n' "$(runuser -u postgres -- psql -Atc 'SHOW server_version' 2>/dev/null || echo '확인 불가')"
printf '      nginx   : %s\n' "$(nginx -v 2>&1)"
printf '      gpg     : %s\n' "$(gpg --version 2>/dev/null | head -1)"
printf '      rsync   : %s\n' "$(rsync --version 2>/dev/null | head -1)"
ok "버전을 읽었다 — deploy/DEPENDENCIES.md 의 표와 대조할 것 (T-23)"
case "$(php -v 2>/dev/null | head -1)" in
  *"PHP 8.1"*) warn "PHP 8.1 은 2025-12-31 로 보안 지원이 끝난다 (Q-11 결정으로 교육 기간만 수용, SECURITY R-21)" ;;
esac

# ─────────────────────────────────────────────────────────────
hdr "시간 동기화 (PRD S-10-A: 감사 로그 시각 정합성)"
if command -v timedatectl >/dev/null 2>&1; then
  timedatectl 2>/dev/null | raw
  if timedatectl 2>/dev/null | grep -q 'synchronized: yes'; then
    ok "시각 동기화됨"
  else
    warn "시각이 동기화되지 않았다 — 감사 로그의 시각을 믿을 수 없게 된다"
  fi
  if command -v chronyc >/dev/null 2>&1; then
    ok "chrony 설치됨"
  else
    warn "chrony 없음 — systemd-timesyncd 로 동기화 중이다. 문서(PRD S-10-A)는 chrony 를 요구하지만 새 패키지를 설치하지 않는다(S-10-C). 동기화 자체는 되고 있다"
  fi
else
  unk "timedatectl 이 없다"
fi

# ─────────────────────────────────────────────────────────────
hdr "PHP·php-fpm 설정 (ARCH 9장: disable_functions · open_basedir · display_errors)"
if [ -r "$FPM_POOL" ]; then
  grep -E 'disable_functions|open_basedir|display_errors|allow_url_fopen|allow_url_include|expose_php|user =|group =|listen' "$FPM_POOL" | raw
  grep -q 'disable_functions' "$FPM_POOL" && ok "풀 설정에 disable_functions 가 있다" || warn "풀 설정에 disable_functions 가 없다"
  grep -q 'open_basedir'      "$FPM_POOL" && ok "풀 설정에 open_basedir 가 있다"      || warn "풀 설정에 open_basedir 가 없다"
else
  unk "php-fpm 풀 설정을 읽지 못했다: ${FPM_POOL}"
fi
# 실효값은 설정 파일이 아니라 **도는 프로세스**가 답이다. 앱이 살아 있는지부터 본다.
HEALTH="$(curl -s --max-time 5 "${LOCAL_HTTP}/api/health" 2>/dev/null)"
if [ -n "$HEALTH" ]; then
  printf '      GET %s/api/health → %s\n' "$LOCAL_HTTP" "$HEALTH"
  ok "앱(php-fpm 풀 tbox)이 응답한다"
else
  warn "앱이 응답하지 않는다 (${LOCAL_HTTP}/api/health)"
fi
printf '      CLI php.ini 실효값(참고 — 웹 풀과 다르다):\n'
php -r 'printf("        display_errors=%s open_basedir=%s allow_url_fopen=%s\n",
   ini_get("display_errors")?:"0", ini_get("open_basedir")?:"(없음)", ini_get("allow_url_fopen")?:"0");' 2>/dev/null

# ─────────────────────────────────────────────────────────────
hdr "파일 권한 (PRD S-10-A: /var/www/tbox-v2 에 other-writable 0건)"
WORLD_W="$(find "$REPO_DIR" -path "${REPO_DIR}/.git" -prune -o -perm -o+w -print 2>/dev/null)"
if [ -z "$WORLD_W" ]; then ok "other 쓰기 권한 파일 0건"
else printf '%s\n' "$WORLD_W" | raw; warn "other 가 쓸 수 있는 파일이 있다 ($(printf '%s\n' "$WORLD_W" | wc -l)건)"; fi
printf '      .env : %s\n' "$(ls -l "${REPO_DIR}/.env" 2>/dev/null || echo '확인 불가')"

hdr "감사 로그 보호 (ARCH 7.1: chattr +a · logrotate)"
if command -v lsattr >/dev/null 2>&1; then
  LSA="$(lsattr "$AUDIT_LOG" 2>/dev/null)"
  if [ -z "$LSA" ]; then unk "lsattr 로 읽지 못했다: ${AUDIT_LOG}"
  else
    printf '      %s\n' "$LSA"
    case "$LSA" in *a*) ok "audit.log 가 추가 전용(a)이다" ;; *) warn "audit.log 에 추가 전용 속성이 없다 — 지워질 수 있다 (R-11)" ;; esac
  fi
else
  unk "lsattr 이 없다"
fi
[ -r /etc/logrotate.d/tbox-v2 ] && ok "logrotate 설정 있음: /etc/logrotate.d/tbox-v2" || warn "logrotate 설정이 없다"

hdr "백업 (PRD S-08)"
if [ -d /var/backups/tbox-v2 ]; then
  ls -ld /var/backups/tbox-v2 2>/dev/null | raw
  PERM="$(stat -c %a /var/backups/tbox-v2 2>/dev/null)"
  [ "$PERM" = "700" ] && ok "백업 디렉터리 0700" || warn "백업 디렉터리 권한이 ${PERM} 이다 (기준 0700)"
  printf '      최신 DB 백업   : %s\n' "$(ls -t /var/backups/tbox-v2/db/*.dump.gpg 2>/dev/null | head -1 || echo '없음')"
  printf '      최신 파일 백업 : %s\n' "$(ls -t /var/backups/tbox-v2/files/*.tar.gz.gpg 2>/dev/null | head -1 || echo '없음')"
  [ -r /etc/tbox-v2/backup.key ] && ok "백업 키 있음 (사본을 서버 밖에 보관할 것 — R-08)" || warn "백업 키가 없다"
else
  unk "백업 디렉터리를 읽지 못했다 (root 권한 필요)"
fi
[ -r /etc/cron.d/tbox-v2 ] && ok "cron 일정 있음: /etc/cron.d/tbox-v2 ($(grep -c '^[^#A-Z]' /etc/cron.d/tbox-v2 2>/dev/null)줄)" \
  || warn "cron 일정이 없다 (deploy/install.sh 가 복사한다)"

# ─────────────────────────────────────────────────────────────
hdr "계정 (PRD S-10-A: 서비스 계정은 로그인 셸 없음 · 사람은 1인 1계정)"
printf '      로그인 셸이 있는 계정:\n'
getent passwd | grep -vE '(nologin|/bin/false|/bin/sync)$' | raw
SVC_BAD="$(getent passwd tbox-php postgres www-data 2>/dev/null | sort -u | grep -vE '(nologin|/bin/false)$')"
if [ -z "$SVC_BAD" ]; then ok "서비스 계정(tbox-php·postgres·www-data)에 로그인 셸이 없다"
else printf '%s\n' "$SVC_BAD" | raw; warn "서비스 계정에 로그인 셸이 있다: $(printf '%s\n' "$SVC_BAD" | cut -d: -f1 | tr '\n' ' ')(기준 nologin)"; fi
printf '      sudo 그룹: %s\n' "$(getent group sudo | cut -d: -f4)"

hdr "자동 보안 패치 (PRD S-10-B)"
if [ -r /etc/apt/apt.conf.d/20auto-upgrades ]; then
  cat /etc/apt/apt.conf.d/20auto-upgrades | raw
  grep -q 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades && ok "unattended-upgrades 켜짐" \
    || warn "unattended-upgrades 가 꺼져 있다"
else
  todo "unattended-upgrades 설정이 없다 (/etc/apt/apt.conf.d/20auto-upgrades)"
fi

hdr "원격 로그 전송 (PRD S-10-E · R-11, ARCH 11.4)"
todo "대상 로그 서버가 없어 미적용 — 예시 설정만 둔다: deploy/rsyslog/tbox-v2.conf.example"
todo "그동안의 대체 수단: scripts/audit_chain.php 의 일별 해시 체인 + chattr +a"

hdr "PostgreSQL WAL 아카이브 (PRD S-08 RPO 15분)"
WAL_MODE="$(runuser -u postgres -- psql -Atc 'SHOW archive_mode' 2>/dev/null || echo '확인 불가')"
printf '      archive_mode = %s\n' "$WAL_MODE"
if [ "$WAL_MODE" = "on" ]; then ok "WAL 아카이브 켜짐"
else todo "WAL 아카이브 미적용 — 실제 RPO 는 일 1회(24시간)다. 예시 설정: deploy/postgresql/archive.conf.example (원격 저장소 Q-4 미결)"; fi

# ─────────────────────────────────────────────────────────────
printf '\n\033[1m── 요약 ──\033[0m\n'
printf '  OK %d · 주의 %d · 미적용 %d · 확인불가 %d\n' "$OK" "$WARN" "$TODO" "$UNKNOWN"
printf '  이 스크립트는 아무것도 바꾸지 않았다. 적용은 sudo bash deploy/hardening.sh (기본 출력만, --apply 는 사람이 직접).\n'
exit 0
