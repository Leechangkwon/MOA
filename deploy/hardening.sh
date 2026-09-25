#!/usr/bin/env bash
# deploy/hardening.sh — 서버 보안 기준선 **적용** (기본은 출력만, 9단계)
#   PRD 7.10 S-10-A (방화벽·SSH·계정) · S-10-B (자동 패치)
#   ARCHITECTURE 11.3 (Hardening 표와 그림) · SECURITY 8.4-12 (드롭인 순서 결함) · R-22 · R-37
#
# 사용법 — 순서대로 (출력 → 협의 → 적용)
#   1) 출력만 (기본, 아무것도 바꾸지 않는다)
#        sudo bash deploy/hardening.sh
#   2) 계정 소유자(강사)와 협의  ← eric 등 내 계정이 아닌 것을 건드리기 전에 반드시
#   3) 적용 (콘솔 또는 **다른 SSH 창을 열어 둔 채**)
#        sudo bash deploy/hardening.sh --apply --i-have-a-second-session \
#             [--admin-net 10.0.4.0/24] [--allow-users "changkwon@10.0.4.0/24"] \
#             [--sudo-password changkwon] [--lock-user eric]
#
# | 옵션 | 뜻 | 기본 |
# |---|---|---|
# | `--apply`                   | 실제로 적용한다. 아래 플래그가 함께 없으면 거부 | 출력만 |
# | `--i-have-a-second-session` | "다른 SSH 창이나 콘솔을 열어 두었다" 확인. `--apply` 필수 짝 | — |
# | `--admin-net <CIDR>`        | 22 번을 허용할 관리망 대역 | 10.0.4.0/24 |
# | `--allow-users "<spec>,…"`  | sshd `AllowUsers` (예: `changkwon@10.0.4.0/24`) | 넣지 않음 |
# | `--sudo-password <계정>`    | `/etc/sudoers.d/99-<계정>-nopasswd` 의 NOPASSWD 를 없앤다 (쉼표로 여러 개) | 현황만 출력 |
# | `--lock-user <계정>`        | `passwd -l <계정>` — **계정 소유자 협의 후에만** (쉼표로 여러 개) | 하지 않음 |
# | `--from-ip <IP>`            | 현재 세션 출처 IP 를 직접 지정(자동 탐지가 실패할 때만) | 자동 탐지 |
#
# ★ 왜 기본이 dry-run 인가 — 이 스크립트는 **원격 세션을 끊을 수 있다.**
#   · `ufw --force enable` 은 기본 정책이 deny 인 상태에서 켜지므로, 22 번 허용 규칙이
#     먼저 들어가지 않으면 그 순간 SSH 가 끊기고 다시 못 들어온다.
#   · `PasswordAuthentication no` 는 공개키를 아직 등록하지 않은 계정을 잠근다.
#   그래서 적용은 **콘솔(또는 키 로그인이 이미 되는 세션)에서 사람이** 하고, 자동화·AI 는
#   출력까지만 한다. 현재 상태 점검은 읽기 전용 scripts/check_baseline.sh 가 한다.
#
# ★ 설정은 파일이 아니라 **실효값**으로 검증한다 (SECURITY 8.4-12)
#   `/etc/ssh/sshd_config` 12행의 `Include /etc/ssh/sshd_config.d/*.conf` 는 **글꼴 순서**로 읽고,
#   sshd 는 **같은 키워드의 첫 값이 이긴다.** 그래서 드롭인 이름은 `00-tbox.conf`(cloud-init 의
#   `50-…` 보다 앞) 이고, 적용 뒤에는 `sshd -T` 실효값을 문자열로 대조한다. 다르면 실패(종료 2).
#
# ★ 적용 전 반드시 확인할 것 (--apply 를 주기 전에)
#   1) `ssh-copy-id` 등으로 **공개키 로그인이 이미 되는지** 확인했다
#   2) VM 콘솔(가상 콘솔/IPMI)로 들어갈 방법이 있다
#   3) 관리망 대역(`--admin-net`)과 프록시 IP(PROXY_IP)가 이 환경에 맞다
#   4) **다른 SSH 창**을 하나 더 열어 두었다 (`--i-have-a-second-session`)
#
# 종료 코드: 0 정상 / 1 사용법·권한 오류 / 2 적용 거부(자기 잠금 위험) 또는 실효값 검증 실패
set -euo pipefail

# ── 인자 ─────────────────────────────────────────────────────
APPLY=0
SECOND_SESSION=0
ALLOW_USERS=""
SUDO_PASSWORD_USERS=""
LOCK_USERS=""
FROM_IP=""
PROXY_IP="192.168.104.100"      # 80 을 보내는 쪽 (이 IP 에서만 80 을 받는다)
# ADMIN_NET 기본값: 사용자의 실제 SSH 출처(10.0.4.173)가 있는 대역.
# 2026-09-11 실측 — 이전 기본값 192.168.0.0/16 은 관리자를 잠그고(출처가 대역 밖),
# 침입 출처 192.168.200.50 은 오히려 허용하는 값이었다 (SECURITY R-22·R-37).
ADMIN_NET="10.0.4.0/24"
SSHD_DROPIN="/etc/ssh/sshd_config.d/00-tbox.conf"   # 00 = cloud-init 의 50- 보다 먼저 읽힌다
SSHD_DROPIN_OLD="/etc/ssh/sshd_config.d/tbox.conf"  # 예전 이름(순서에서 지는 이름) — 우리 것이면 제거
MARKER="deploy/hardening.sh"                        # 우리가 만든 파일임을 알리는 표식
DISABLED_SUFFIX=".disabled-by-tbox-$(date +%Y%m%d)"

usage() { sed -n '2,40p' "$0"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --apply)                    APPLY=1 ;;
    --i-have-a-second-session)  SECOND_SESSION=1 ;;
    --admin-net)                ADMIN_NET="${2:?--admin-net 에 대역이 필요하다}"; shift ;;
    --allow-users)              ALLOW_USERS="${2:?--allow-users 에 값이 필요하다}"; shift ;;
    --sudo-password)            SUDO_PASSWORD_USERS="${2:?--sudo-password 에 계정이 필요하다}"; shift ;;
    --lock-user)                LOCK_USERS="${2:?--lock-user 에 계정이 필요하다}"; shift ;;
    --from-ip)                  FROM_IP="${2:?--from-ip 에 IP 가 필요하다}"; shift ;;
    -h|--help)                  usage; exit 0 ;;
    *) printf '[중단] 모르는 인자: %s\n' "$1" >&2; usage >&2; exit 1 ;;
  esac
  shift
done

note()  { printf '   %s\n' "$*"; }
head2() { printf '\n\033[1m── %s\033[0m\n' "$*"; }
ok()    { printf '   \033[32m✔\033[0m %s\n' "$*"; }
warn()  { printf '   \033[33m⚠\033[0m %s\n' "$*"; }
bad()   { printf '   \033[1;31m✖ %s\033[0m\n' "$*"; }
refuse() { printf '\n\033[1;31m[거부] %s\033[0m\n' "$*" >&2; }

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

# ── 현재 세션의 출처 IP 찾기 ──────────────────────────────────
# sudo 는 SSH_CONNECTION 을 지우므로, 없으면 부모 프로세스들의 environ 에서 찾는다.
detect_src_ip() {
  [ -n "$FROM_IP" ] && { printf '%s' "$FROM_IP"; return 0; }
  if [ -n "${SSH_CONNECTION:-}" ]; then printf '%s' "${SSH_CONNECTION%% *}"; return 0; fi
  local p=$$ i v
  for i in $(seq 1 40); do
    [ "$p" -le 1 ] && break
    v=$(tr '\0' '\n' < "/proc/$p/environ" 2>/dev/null | grep -m1 '^SSH_CONNECTION=' || true)
    if [ -n "$v" ]; then v=${v#SSH_CONNECTION=}; printf '%s' "${v%% *}"; return 0; fi
    p=$(awk '{print $4}' "/proc/$p/stat" 2>/dev/null || echo 1)
    [ -z "$p" ] && break
  done
  return 0   # 못 찾으면 빈 문자열
}

# ip_in_net <IP> <패턴>  — 0 포함 / 1 미포함 / 2 판정 불가. 패턴: CIDR · 단일 IP · '*' · 접두 문자열
ip_in_net() {
  local ip="$1" pat="$2"
  [ -z "$pat" ] && return 0
  [ "$pat" = "*" ] && return 0
  [ -z "$ip" ] && return 2
  python3 - "$ip" "$pat" <<'PYEOF'
import ipaddress, sys
ip, pat = sys.argv[1], sys.argv[2]
try:
    addr = ipaddress.ip_address(ip)
except ValueError:
    sys.exit(2)
if '/' in pat:
    try:
        sys.exit(0 if addr in ipaddress.ip_network(pat, strict=False) else 1)
    except ValueError:
        sys.exit(2)
try:
    sys.exit(0 if addr == ipaddress.ip_address(pat) else 1)
except ValueError:
    pass
# 접두 문자열(예: 10.0.4.* / 10.0.4.)
pref = pat.rstrip('*')
sys.exit(0 if ip.startswith(pref) else 1)
PYEOF
}

SRC_IP="$(detect_src_ip)"
ADMIN_USER="${SUDO_USER:-$(id -un)}"

# ─────────────────────────────────────────────────────────────
if [ "$APPLY" -eq 1 ]; then
  printf '\033[1m== 기준선 적용 (--apply) ==\033[0m\n'
else
  printf '\033[1m== 기준선 적용 계획 (dry-run — 아무것도 바꾸지 않는다) ==\033[0m\n'
  printf '   실제로 적용하려면: sudo bash deploy/hardening.sh --apply --i-have-a-second-session\n'
fi

# ─────────────────────────────────────────────────────────────
head2 "0. 사전 안전 점검 (자기 잠금 방지 — 이 절을 통과해야 아래가 돈다)"

if [ -n "$SRC_IP" ]; then
  note "현재 세션 출처: ${SRC_IP} (관리 계정 ${ADMIN_USER})"
else
  warn "현재 세션 출처 IP 를 찾지 못했다 (콘솔 로그인이거나 환경이 다름). --from-ip <IP> 로 알려 줄 수 있다."
fi

# (1) --apply 안전장치
if [ "$APPLY" -eq 1 ] && [ "$SECOND_SESSION" -ne 1 ]; then
  refuse "--apply 는 --i-have-a-second-session 과 함께여야 한다."
  printf '       다른 SSH 창이나 콘솔을 하나 더 열어 둔 채 실행하세요.\n' >&2
  printf '       그 창이 살아 있으면, 이 설정이 잘못돼도 되돌릴 수 있습니다.\n' >&2
  printf '       예: sudo bash deploy/hardening.sh --apply --i-have-a-second-session --admin-net %s\n' "$ADMIN_NET" >&2
  exit 2
fi

# (2) ADMIN_NET 이 현재 출처를 포함하는가 (ufw 22 번 규칙 — 지시 8·추가)
set +e; ip_in_net "$SRC_IP" "$ADMIN_NET"; ADMIN_NET_RC=$?; set -e
case "$ADMIN_NET_RC" in
  0) ok "현재 출처 ${SRC_IP} 가 ADMIN_NET ${ADMIN_NET} 에 **포함됨** — ufw 22 번 규칙으로 잠기지 않는다" ;;
  1) bad "현재 출처 ${SRC_IP} 가 ADMIN_NET ${ADMIN_NET} 에 **포함되지 않음**"
     refuse "이대로 ufw 를 켜면 관리자(당신)가 22 번에서 잠긴다."
     printf '       고치는 법: --admin-net %s.0/24  (또는 더 넓게 --admin-net 10.0.0.0/8)\n' "${SRC_IP%.*}" >&2
     exit 2 ;;
  *) if [ "$APPLY" -eq 1 ]; then
       refuse "현재 출처 IP 를 판정하지 못해 ufw 적용을 거부한다 (--from-ip <IP> 로 지정)."
       exit 2
     else
       warn "현재 출처를 판정하지 못해 ADMIN_NET ${ADMIN_NET} 포함 여부를 확인하지 못했다 (출력 모드라 계속)"
     fi ;;
esac

# (3) --allow-users 가 현재 세션을 포함하는가 (sshd AllowUsers — 지시 4)
if [ -n "$ALLOW_USERS" ]; then
  spec_ok=0
  IFS=', ' read -r -a _specs <<< "$ALLOW_USERS"
  for spec in "${_specs[@]}"; do
    [ -z "$spec" ] && continue
    if [[ "$spec" == *@* ]]; then u="${spec%%@*}"; h="${spec#*@}"; else u="$spec"; h=""; fi
    set +e; ip_in_net "$SRC_IP" "$h"; hrc=$?; set -e
    if { [ "$u" = "$ADMIN_USER" ] || [ "$u" = "*" ]; } && [ "$hrc" -eq 0 ]; then spec_ok=1; fi
  done
  if [ "$spec_ok" -eq 1 ]; then
    ok "AllowUsers \"${ALLOW_USERS}\" 는 현재 세션(${ADMIN_USER}@${SRC_IP})을 포함한다 — 적용해도 잠기지 않는다"
  else
    bad "AllowUsers \"${ALLOW_USERS}\" 에 현재 세션(${ADMIN_USER}@${SRC_IP:-출처불명})이 들어 있지 않다"
    refuse "자기 잠금 위험 — AllowUsers 를 이대로 넣으면 지금 이 계정·출처로 다시 들어올 수 없다."
    printf '       예: --allow-users "%s@%s.0/24"\n' "$ADMIN_USER" "${SRC_IP%.*}" >&2
    exit 2
  fi
else
  note "AllowUsers 는 넣지 않는다 (옵션). 현재 접속 출처 ${SRC_IP:-?} 기준 예: --allow-users \"${ADMIN_USER}@${SRC_IP%.*}.0/24\""
fi

# ─────────────────────────────────────────────────────────────
head2 "1. 방화벽 ufw (PRD S-10-A: 인바운드·아웃바운드 기본 거부)"
note "현재 상태: $(ufw status 2>/dev/null | head -1 || echo '확인 불가')"
note "22 번 허용 대역(ADMIN_NET): ${ADMIN_NET}   80 번 허용(PROXY_IP): ${PROXY_IP}"
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
run ufw allow out 67/udp comment 'DHCP 갱신'   # 서버 주소가 DHCP(netplan dhcp4) — 나가는 기본 거부면 갱신(T1) 요청이 막힌다(2026-09-26 d53 규칙안에서 더함)
run ufw allow out 80/tcp comment 'apt 미러'   # 「allow out 80 proto tcp」 는 ufw 가 거부한다(Wrong number of arguments) — --apply 였다면 set -e 로 방화벽이 꺼진 채 멈췄을 것(5d 독립 검토 · 실제로 돌린 적은 없음)
run ufw allow out 443/tcp comment 'apt 미러·F-14 허용 도메인'
run ufw --force enable
note "확인: ufw status verbose  /  scripts/check_baseline.sh"
note "주의: 아웃바운드 443 을 통째로 여는 것은 임시다. F-14 허용 도메인이 정해지면(Q-10)"
note "      그 IP 대역으로 좁힌다 — 지금은 허용 목록이 비어 있어(전부 거부) 앱이 밖으로 나가지 않는다."

# ─────────────────────────────────────────────────────────────
head2 "2. SSH (PRD S-10-A: 키 인증만 · root 로그인 금지 · MaxAuthTries 3)"
note "드롭인 파일: ${SSHD_DROPIN}"
note "이름이 00- 인 이유: Include 는 글꼴 순서로 읽고 sshd 는 **첫 값이 이긴다**. 50-cloud-init.conf 보다 앞서야 한다 (SECURITY 8.4-12)."

# 2-1. 충돌 드롭인 탐지 ── 우리 파일이 아닌데 반대 값을 선언한 파일
CONFLICT_RE='^[[:space:]]*(PasswordAuthentication[[:space:]]+yes|PermitRootLogin[[:space:]]+(yes|without-password|prohibit-password)|KbdInteractiveAuthentication[[:space:]]+yes)'
conflicts=()
shopt -s nullglob
for f in /etc/ssh/sshd_config.d/*.conf; do
  [ "$f" = "$SSHD_DROPIN" ] && continue
  grep -q "$MARKER" "$f" 2>/dev/null && continue          # 우리가 만든 파일
  if grep -qiE "$CONFLICT_RE" "$f" 2>/dev/null; then conflicts+=("$f"); fi
done
shopt -u nullglob

if [ "${#conflicts[@]}" -eq 0 ]; then
  ok "충돌 드롭인 없음 (sshd_config.d 안에 반대 값을 선언한 남의 파일이 없다)"
else
  for f in "${conflicts[@]}"; do
    bad "충돌 드롭인: $f — 아래 줄이 우리 설정을 덮을 수 있다"
    grep -inE "$CONFLICT_RE" "$f" | sed 's/^/        | /'
    if [[ "$(basename "$f")" < "$(basename "$SSHD_DROPIN")" ]]; then
      bad "  이 파일이 ${SSHD_DROPIN##*/} 보다 **먼저** 읽힌다 → 이 파일이 이긴다"
    else
      note "  ${SSHD_DROPIN##*/} 가 먼저 읽히므로 우리 값이 이긴다 (그래도 혼선을 줄이려 비활성화한다)"
    fi
    if [ "$APPLY" -eq 1 ]; then
      printf '   $ mv %s %s\n' "$f" "${f}${DISABLED_SUFFIX}"
      mv "$f" "${f}${DISABLED_SUFFIX}"     # 삭제하지 않는다 — 되돌리기용. .conf 로 끝나지 않아 Include 에서 빠진다
    else
      printf '   (출력만) mv %s %s   ← 삭제하지 않고 이름만 바꿔 Include 에서 뺀다\n' "$f" "${f}${DISABLED_SUFFIX}"
    fi
  done
fi

# 2-2. 예전 이름(tbox.conf) 정리 — 우리가 만든 것만
if [ -f "$SSHD_DROPIN_OLD" ]; then
  if grep -q "$MARKER" "$SSHD_DROPIN_OLD" 2>/dev/null; then
    warn "예전 드롭인 ${SSHD_DROPIN_OLD} (순서에서 지는 이름) — 우리가 만든 것이라 제거한다"
    run rm -f "$SSHD_DROPIN_OLD"
  else
    warn "${SSHD_DROPIN_OLD} 가 있으나 우리 표식이 없다 — 건드리지 않는다 (남의 파일)"
  fi
fi

# 2-3. 드롭인 내용
dropin_body() {
  cat <<'SSHEOF'
# /etc/ssh/sshd_config.d/00-tbox.conf — deploy/hardening.sh 가 만든다 (PRD S-10-A, ARCH 11.3)
# 이름의 00 은 Include 순서용 — sshd 는 같은 키워드의 첫 값이 이긴다 (SECURITY 8.4-12)
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
MaxAuthTries 3
PubkeyAuthentication yes
X11Forwarding no
SSHEOF
  [ -n "$ALLOW_USERS" ] && printf 'AllowUsers %s\n' "${ALLOW_USERS//,/ }"
  return 0
}

if [ "$APPLY" -eq 1 ]; then
  install -d -o root -g root -m 0755 /etc/ssh/sshd_config.d
  dropin_body > "$SSHD_DROPIN"
  chmod 0644 "$SSHD_DROPIN"
  printf '   $ sshd -t (문법 검사)\n'
  /usr/sbin/sshd -t
  printf '   $ systemctl reload ssh\n'
  systemctl reload ssh
else
  printf '   (출력만) %s 에 아래 내용을 쓰고 sshd -t 로 검사한 뒤 systemctl reload ssh\n' "$SSHD_DROPIN"
  dropin_body | sed 's/^/      | /'
  printf '   ★ 적용 전에 `ssh-copy-id` 로 공개키 로그인이 되는지 먼저 확인할 것 — 아니면 잠긴다.\n'
fi

# 2-4. 실효값 검증 — 파일이 아니라 sshd -T 로 (SECURITY 8.4-12)
head2 "2-1. SSH 실효값 검증 (sshd -T — 파일이 아니라 실제로 먹은 값)"
want_keys=(passwordauthentication kbdinteractiveauthentication permitrootlogin maxauthtries)
want_vals=(no                     no                            no               3)
if [ -n "$ALLOW_USERS" ]; then
  want_keys+=(allowusers); want_vals+=("${ALLOW_USERS//,/ }")
fi
sshd_t="$(/usr/sbin/sshd -T 2>/dev/null || true)"
if [ -z "$sshd_t" ]; then
  warn "sshd -T 를 읽지 못했다 (root 로 실행했는지 확인). 실효값 검증을 건너뛴다."
  [ "$APPLY" -eq 1 ] && { refuse "적용 모드에서는 실효값을 확인하지 못하면 실패로 본다."; exit 2; }
else
  printf '   %-32s %-12s %-12s %s\n' "키워드" "목표" "현재" "판정"
  verify_fail=0
  for i in "${!want_keys[@]}"; do
    k="${want_keys[$i]}"; wv="${want_vals[$i]}"
    cur="$(printf '%s\n' "$sshd_t" | awk -v k="$k" 'tolower($1)==k {$1=""; sub(/^ /,""); print; exit}')"
    [ -z "$cur" ] && cur="(없음)"
    if [ "$(printf '%s' "$cur" | tr 'A-Z' 'a-z')" = "$(printf '%s' "$wv" | tr 'A-Z' 'a-z')" ]; then
      printf '   %-32s %-12s %-12s \033[32m일치\033[0m\n' "$k" "$wv" "$cur"
    else
      printf '   %-32s %-12s %-12s \033[1;31m다름\033[0m\n' "$k" "$wv" "$cur"
      verify_fail=1
    fi
  done
  if [ "$verify_fail" -eq 1 ]; then
    if [ "$APPLY" -eq 1 ]; then
      bad "적용했는데 실효값이 목표와 다르다 — 다른 드롭인이나 sshd_config 본문이 이기고 있다."
      refuse "실효값 검증 실패. 위 '다름' 항목을 확인하라: sshd -T | grep -iE 'passwordauth|permitroot|maxauth|allowusers'"
      exit 2
    else
      warn "아직 적용 전이라 '다름' 이 정상이다 — 적용 뒤 이 표가 전부 '일치' 여야 한다."
    fi
  else
    ok "실효값이 목표와 모두 일치한다."
  fi
fi

# ─────────────────────────────────────────────────────────────
head2 "3. sudo NOPASSWD (옵션 --sudo-password <계정>)"
shopt -s nullglob
for f in /etc/sudoers.d/*nopasswd*; do
  note "현황: $f → $(tr -s ' ' < "$f" | tr '\n' ' ')"
done
shopt -u nullglob
if [ -z "$SUDO_PASSWORD_USERS" ]; then
  note "지정한 계정이 없어 현황만 출력한다 (--sudo-password changkwon 처럼 계정을 준다)."
  note '★ AI 자동 검토 스크립트가 "sudo -n" 을 쓰므로, changkwon 의 NOPASSWD 를 없애면'
  note "  검토·점검 스크립트는 **사용자가 직접** 비밀번호를 넣어 실행해야 한다."
  note "★ eric 은 계정 소유자(강사) 협의 전엔 건드리지 않는다 — 옵션으로 지정할 때만 바뀐다 (R-37)."
else
  IFS=', ' read -r -a _sudo_users <<< "$SUDO_PASSWORD_USERS"
  for u in "${_sudo_users[@]}"; do
    [ -z "$u" ] && continue
    sf="/etc/sudoers.d/99-${u}-nopasswd"
    if [ ! -f "$sf" ]; then warn "$sf 없음 — 건너뛴다"; continue; fi
    note "${u}: $sf 를 '${u} ALL=(ALL:ALL) ALL' 로 바꾼다 (sudo 에 비밀번호를 요구하게)"
    if [ "$APPLY" -eq 1 ]; then
      bak="${sf}.bak-$(date +%Y%m%d%H%M%S)"
      cp -p "$sf" "$bak"
      printf '%s ALL=(ALL:ALL) ALL\n' "$u" > "$sf"
      chmod 0440 "$sf"
      if visudo -c -f "$sf" >/dev/null 2>&1; then
        ok "visudo -c 통과 ($sf, 원본은 $bak)"
      else
        bad "visudo -c 실패 — 원본으로 되돌린다"
        cp -p "$bak" "$sf"
        refuse "sudoers 문법 검사 실패. $sf 를 원복했다."
        exit 2
      fi
    else
      printf '   (출력만) cp -p %s %s.bak-<시각> ; echo "%s ALL=(ALL:ALL) ALL" > %s ; visudo -c -f %s\n' "$sf" "$sf" "$u" "$sf" "$sf"
    fi
  done
fi

# ─────────────────────────────────────────────────────────────
head2 "4. 계정 잠금 (옵션 --lock-user <계정>) — **협의 후에만**"
if [ -z "$LOCK_USERS" ]; then
  note "기본은 아무 계정도 잠그지 않는다."
  note "eric 처럼 내 계정이 아닌 것은 **계정 소유자(강사) 협의 후에만** --lock-user eric 로 잠근다 (R-37)."
else
  IFS=', ' read -r -a _lock_users <<< "$LOCK_USERS"
  for u in "${_lock_users[@]}"; do
    [ -z "$u" ] && continue
    warn "${u} 잠금 — 계정 소유자와 **협의했는지** 다시 확인하라 (협의 없이 잠그면 강사 업무가 막힌다)"
    run passwd -l "$u"
  done
fi

# ─────────────────────────────────────────────────────────────
head2 "5. 자동 보안 패치 (PRD S-10-B)"
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
head2 "6. 이 스크립트가 **하지 않는** 것 (근거와 함께)"
note "· fail2ban·chrony 설치 — 새 패키지 0 원칙 (PRD S-10-C). 대체: sshd MaxAuthTries, 앱 잠금(S-09-4), systemd-timesyncd."
note "· ClamAV — Q-3 재결정(2026-09-16)으로 설치돼 있다(F-54 배치 scripts/scan_pending.php · clamav-freshclam). 설치·점검은 이 스크립트 몫이 아니다(DEPENDENCIES 1장)."
note "· /var/lib/tbox-v2/storage 를 noexec,nosuid,nodev 별도 마운트로 — 파티션 재구성이 필요하다."
note "  (지금도 디렉터리에 실행 권한이 없고 PHP 핸들러가 붙지 않아 실행은 되지 않는다 — ARCH 6.3)"
note "· AIDE/debsums 무결성 감시(AL-9) — 미적용. 현재 대체 수단은 scripts/check_baseline.sh 수동 점검."
note "· 원격 로그 서버(rsyslog TLS) — 대상 서버 없음. 예시: deploy/rsyslog/tbox-v2.conf.example (R-11)"
note "· PostgreSQL WAL 아카이브 — 원격 저장소 미결(Q-4). 예시: deploy/postgresql/archive.conf.example"
note "· ModSecurity(OWASP CRS) — 선택 항목(PRD S-10-D)."
note "· 공개키 등록(ssh-copy-id) — 키는 사람이 만들고 옮긴다. 스크립트가 키를 넣지 않는다."

printf '\n'
if [ "$APPLY" -eq 1 ]; then
  printf '적용 끝. 바로 확인한다: sudo bash scripts/check_baseline.sh\n'
  printf '\033[1m★ 지금 새 창에서 SSH 접속을 확인한 뒤 이 창을 닫으세요.\033[0m\n'
  printf '   (새 창 접속이 안 되면 이 창에서 되돌린다: rm %s ; 필요하면 %s%s 를 원래 이름으로 ; systemctl reload ssh ; ufw disable)\n' \
         "$SSHD_DROPIN" "/etc/ssh/sshd_config.d/<이름>.conf" "$DISABLED_SUFFIX"
else
  printf 'dry-run 끝 — 아무것도 바꾸지 않았다.\n'
  printf '현재 상태 점검: sudo bash scripts/check_baseline.sh\n'
  printf '적용: sudo bash deploy/hardening.sh --apply --i-have-a-second-session [--allow-users "%s@%s.0/24"] [--sudo-password %s]\n' \
         "$ADMIN_USER" "${SRC_IP%.*}" "$ADMIN_USER"
fi
