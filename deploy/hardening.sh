#!/usr/bin/env bash
# deploy/hardening.sh — 서버 보안 기준선 **적용** (기본은 출력만, 9단계)
#   PRD 7.10 S-10-A (방화벽·SSH·계정) · S-10-B (자동 패치)
#   ARCHITECTURE 11.3 (Hardening 표와 그림)
#
# 사용법
#   sudo bash deploy/hardening.sh            ← 기본: **무엇을 할지 출력만** 한다 (dry-run)
#   sudo bash deploy/hardening.sh --apply    ← 실제로 적용한다 (사람이 직접, 콘솔에서)
#
# ★ 왜 기본이 dry-run 인가 — 이 스크립트는 **원격 세션을 끊을 수 있다.**
#   · `ufw --force enable` 은 기본 정책이 deny 인 상태에서 켜지므로, 22 번 허용 규칙이
#     먼저 들어가지 않으면 그 순간 SSH 가 끊기고 다시 못 들어온다.
#   · `PasswordAuthentication no` 는 공개키를 아직 등록하지 않은 계정을 잠근다.
#   그래서 적용은 **콘솔(또는 키 로그인이 이미 되는 세션)에서 사람이** 하고, 자동화·AI 는
#   출력까지만 한다. 현재 상태 점검은 읽기 전용 scripts/check_baseline.sh 가 한다.
#
# ★ 적용 전 반드시 확인할 것 (--apply 를 주기 전에)
#   1) `ssh-copy-id` 등으로 **공개키 로그인이 이미 되는지** 확인했다
#   2) VM 콘솔(가상 콘솔/IPMI)로 들어갈 방법이 있다
#   3) 관리망 대역(ADMIN_NET)과 프록시 IP(PROXY_IP)가 이 환경에 맞다
set -euo pipefail

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

# ── 이 환경의 값 (ARCH 3장 배포 메모 · 11.3) ──────────────────
# 강사 리버스 프록시가 TLS 를 종단하고 VM 의 80 으로만 보낸다. VM 은 443 을 받지 않는다.
PROXY_IP="192.168.104.100"      # 80 을 보내는 쪽 (이 IP 에서만 80 을 받는다)
ADMIN_NET="192.168.0.0/16"      # 22 를 허용할 관리망 대역 — 환경에 맞게 고친다
SSHD_DROPIN="/etc/ssh/sshd_config.d/tbox.conf"

note() { printf '   %s\n' "$*"; }
head2() { printf '\n\033[1m── %s\033[0m\n' "$*"; }

# 적용 모드에서만 실제로 돌리고, 기본은 명령을 보여 주기만 한다.
run() {
  if [ "$APPLY" -eq 1 ]; then
    printf '   $ %s\n' "$*"
    "$@"
  else
    printf '   (출력만) %s\n' "$*"
  fi
}

[ "$(id -u)" -eq 0 ] || { printf '[중단] root 로 실행한다: sudo bash deploy/hardening.sh\n' >&2; exit 1; }

if [ "$APPLY" -eq 1 ]; then
  printf '\033[1m== 기준선 적용 (--apply) ==\033[0m\n'
  printf '\033[1m★ 이 실행은 방화벽·SSH 설정을 실제로 바꾼다. 원격 세션이 끊길 수 있다.\033[0m\n'
  printf '   중단하려면 지금 Ctrl-C. 10초 뒤 시작한다.\n'
  sleep 10
else
  printf '\033[1m== 기준선 적용 계획 (dry-run — 아무것도 바꾸지 않는다) ==\033[0m\n'
  printf '   실제로 적용하려면 콘솔에서: sudo bash deploy/hardening.sh --apply\n'
fi

# ─────────────────────────────────────────────────────────────
head2 "1. 방화벽 ufw (PRD S-10-A: 인바운드·아웃바운드 기본 거부)"
note "현재 상태: $(ufw status 2>/dev/null | head -1 || echo '확인 불가')"
note "순서가 중요하다 — 허용 규칙을 **먼저** 넣고 맨 마지막에 enable 한다."
run ufw --force reset
run ufw default deny incoming
run ufw default deny outgoing
# 인바운드: 22(관리망만), 80(프록시만). 443 은 VM 이 받지 않는다(프록시가 종단).
run ufw allow from "$ADMIN_NET" to any port 22 proto tcp comment 'SSH 관리망만'
run ufw allow from "$PROXY_IP"  to any port 80 proto tcp comment '강사 리버스 프록시만'
# 아웃바운드: 이름 풀이·시각 동기화·패키지 미러만. 그 밖은 거부 = 데이터 유출·C2 통로 차단.
run ufw allow out 53 comment 'DNS'
run ufw allow out 123 comment 'NTP'
run ufw allow out 80 proto tcp comment 'apt 미러'
run ufw allow out 443 proto tcp comment 'apt 미러·F-14 허용 도메인'
run ufw --force enable
note "확인: ufw status verbose  /  scripts/check_baseline.sh"
note "주의: 아웃바운드 443 을 통째로 여는 것은 임시다. F-14 허용 도메인이 정해지면(Q-10)"
note "      그 IP 대역으로 좁힌다 — 지금은 허용 목록이 비어 있어(전부 거부) 앱이 밖으로 나가지 않는다."

# ─────────────────────────────────────────────────────────────
head2 "2. SSH (PRD S-10-A: 키 인증만 · root 로그인 금지 · MaxAuthTries 3)"
note "현재: $(/usr/sbin/sshd -T 2>/dev/null | grep -iE '^(passwordauthentication|permitrootlogin|maxauthtries)' | tr '\n' ' ' || echo '확인 불가')"
note "드롭인 파일을 쓴다 (${SSHD_DROPIN}) — /etc/ssh/sshd_config 원본을 고치지 않아 되돌리기 쉽다."
if [ "$APPLY" -eq 1 ]; then
  install -d -o root -g root -m 0755 /etc/ssh/sshd_config.d
  cat > "$SSHD_DROPIN" <<'SSHEOF'
# /etc/ssh/sshd_config.d/tbox.conf — deploy/hardening.sh 가 만든다 (PRD S-10-A, ARCH 11.3)
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
MaxAuthTries 3
PubkeyAuthentication yes
X11Forwarding no
SSHEOF
  chmod 0644 "$SSHD_DROPIN"
  printf '   $ sshd -t (문법 검사)\n'
  /usr/sbin/sshd -t
  printf '   $ systemctl reload ssh\n'
  systemctl reload ssh
else
  printf '   (출력만) %s 에 아래 내용을 쓰고 sshd -t 로 검사한 뒤 systemctl reload ssh\n' "$SSHD_DROPIN"
  cat <<'SSHEOF' | sed 's/^/      | /'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
MaxAuthTries 3
PubkeyAuthentication yes
X11Forwarding no
SSHEOF
  printf '   ★ 적용 전에 `ssh-copy-id` 로 공개키 로그인이 되는지 먼저 확인할 것 — 아니면 잠긴다.\n'
fi

# ─────────────────────────────────────────────────────────────
head2 "3. 자동 보안 패치 (PRD S-10-B)"
if [ -r /etc/apt/apt.conf.d/20auto-upgrades ] \
   && grep -q 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades; then
  note "이미 켜져 있다 — 바꾸지 않는다."
  sed 's/^/      | /' /etc/apt/apt.conf.d/20auto-upgrades
else
  note "꺼져 있다. 아래를 /etc/apt/apt.conf.d/20auto-upgrades 에 넣는다:"
  printf '      | APT::Periodic::Update-Package-Lists "1";\n      | APT::Periodic::Unattended-Upgrade "1";\n'
  note "이 스크립트는 패키지를 설치하지 않는다 (PRD S-10-C) — unattended-upgrades 가 없으면 사람이 판단한다."
fi

# ─────────────────────────────────────────────────────────────
head2 "4. 이 스크립트가 **하지 않는** 것 (근거와 함께)"
note "· fail2ban·chrony·ClamAV 설치 — 새 패키지 0 원칙 (PRD S-10-C). 대체: sshd MaxAuthTries,"
note "  앱 잠금(S-09-4), systemd-timesyncd, Q-3 결정(ClamAV 미포함)."
note "· /var/lib/tbox-v2/storage 를 noexec,nosuid,nodev 별도 마운트로 — 파티션 재구성이 필요하다."
note "  (지금도 디렉터리에 실행 권한이 없고 PHP 핸들러가 붙지 않아 실행은 되지 않는다 — ARCH 6.3)"
note "· AIDE/debsums 무결성 감시(AL-9) — 미적용. 현재 대체 수단은 scripts/check_baseline.sh 수동 점검."
note "· 원격 로그 서버(rsyslog TLS) — 대상 서버 없음. 예시: deploy/rsyslog/tbox-v2.conf.example (R-11)"
note "· PostgreSQL WAL 아카이브 — 원격 저장소 미결(Q-4). 예시: deploy/postgresql/archive.conf.example"
note "· ModSecurity(OWASP CRS) — 선택 항목(PRD S-10-D)."

printf '\n'
if [ "$APPLY" -eq 1 ]; then
  printf '적용 끝. 바로 확인한다: sudo bash scripts/check_baseline.sh\n'
  printf '★ 지금 열린 SSH 세션을 닫기 전에, **다른 창에서 새로 로그인되는지** 확인할 것.\n'
else
  printf 'dry-run 끝 — 아무것도 바꾸지 않았다.\n'
  printf '현재 상태 점검: sudo bash scripts/check_baseline.sh\n'
fi
