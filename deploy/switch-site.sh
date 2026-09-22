#!/usr/bin/env bash
# deploy/switch-site.sh — 80 포트에 꽂을 t-box 버전을 고른다 (ARCHITECTURE 3장 "실습 VM 배포 메모")
#
# 사용법:  sudo bash deploy/switch-site.sh {v1|v2|v3|status}
#
# 왜 필요한가
#   이 VM 의 외부 노출 구조는 VM 에서 바꿀 수 없다. 강사 리버스 프록시가 TLS 를 종단하고
#   http→https 301 을 한 뒤 VM 의 **80 포트 하나**로만 전달한다. 그래서 도메인
#   https://changkwon.mso.so/ 로 보이는 웹은 한 번에 한 버전뿐이다.
#   세 버전은 나란히 유지하고, 80 에 꽂을 버전만 이 스크립트로 고른다.
#
#   | 버전 | 디렉터리                     | 공개 사이트 | 항상 열린 로컬 포트 |
#   |------|------------------------------|-------------|---------------------|
#   | v1   | /var/www/html (실습·취약)    | tbox-v1     | 127.0.0.1:8081      |
#   | v2   | /var/www/tbox-v2 (이 저장소) | tbox-v2     | 127.0.0.1:8082      |
#   | v3   | /var/www/tbox-v3 (미착수)    | tbox-v3     | 127.0.0.1:8083      |
#
#   로컬 포트는 어느 버전이 공개 중이든 항상 열려 있다(127.0.0.1 만 listen).
#   보는 방법:  ssh -L 8082:127.0.0.1:8082 changkwon@VM  →  http://localhost:8082/
#
# 성질
#   · 멱등하다. 같은 인자로 여러 번 실행해도 결과가 같다.
#   · 모든 인자(status 포함)에서 저장소의 사이트 파일을 /etc 로 다시 복사하고
#     로컬용 사이트를 enable 한다 — 저장소가 설정의 유일한 원본이다.
#     `status` 는 그중 **공개 중인 버전만 바꾸지 않는다**(전환은 v1/v2/v3 인자로만).
#   · `nginx -t` 가 실패하면 바꾸기 전 링크 상태로 되돌리고 중단한다.
#   · 옛 사이트 파일(tbox, tbox-re, tbox.bak-1단계)은 지우지 않고
#     /etc/nginx/sites-available/legacy-1단계/ 로 옮겨 보존한다.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="${REPO_DIR}/deploy/sites"
AVAIL="/etc/nginx/sites-available"
ENABLED="/etc/nginx/sites-enabled"
CONFD="/etc/nginx/conf.d"
LEGACY_DIR="${AVAIL}/legacy-1단계"
COMMON_CONF="tbox-common.conf"

PUBLIC_SITES=(tbox-v1 tbox-v2 tbox-v3)
LOCAL_SITES=(tbox-v1-local tbox-v2-local tbox-v3-local)
LEGACY_NAMES=(tbox tbox-re "tbox.bak-1단계")

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die()  { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

usage() {
  cat >&2 <<USAGE
사용법: sudo bash deploy/switch-site.sh {v1|v2|v3|status}

  v1      실습(취약) 사이트를 80 포트에 꽂는다
  v2      재구축 사이트(이 저장소)를 80 포트에 꽂는다
  v3      새 T-BOX 를 80 포트에 꽂는다 (설정 파일이 아직 없으면 중단)
  status  현재 공개 중인 버전과 로컬 포트 상태만 출력한다
USAGE
  exit 2
}

ACTION="${1:-}"
case "$ACTION" in
  v1|v2|v3|status) ;;
  *) usage ;;
esac

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash deploy/switch-site.sh ${ACTION:-status}"
[ -d "$SRC_DIR" ] || die "사이트 설정 디렉터리가 없다: ${SRC_DIR}"
command -v nginx >/dev/null 2>&1 || die "nginx 를 찾을 수 없다"

# ─────────────────────────────────────────────────────────────
# 되돌리기 준비 — 바꾸기 전 sites-enabled 링크 상태를 기록해 둔다 (E5)
# ─────────────────────────────────────────────────────────────
SNAP_FILE="$(mktemp /tmp/tbox-switch-snapshot.XXXXXX)"
MOVED_PAIRS=()          # "옮긴 위치|원래 위치"
trap 'rm -f "$SNAP_FILE"' EXIT

snapshot_enabled() {
  : > "$SNAP_FILE"
  local f name target
  shopt -s nullglob
  for f in "$ENABLED"/*; do
    name="$(basename "$f")"
    if [ -L "$f" ]; then
      target="$(readlink "$f")"
    else
      target="@REGULAR@"          # 심볼릭 링크가 아닌 것은 건드리지 않는다
    fi
    printf '%s\t%s\n' "$name" "$target" >> "$SNAP_FILE"
  done
  shopt -u nullglob
  return 0
}

rollback_enabled() {
  local f name target pair from to
  # 지금 있는 링크를 모두 걷어내고 (일반 파일은 남긴다)
  shopt -s nullglob
  for f in "$ENABLED"/*; do
    if [ -L "$f" ]; then rm -f "$f"; fi
  done
  shopt -u nullglob
  # 기록해 둔 링크를 그대로 되살린다
  while IFS=$'\t' read -r name target; do
    [ -n "$name" ] || continue
    [ "$target" = "@REGULAR@" ] && continue
    ln -sfn "$target" "${ENABLED}/${name}"
  done < "$SNAP_FILE"
  # legacy 로 옮긴 파일도 제자리로
  local i
  for pair in ${MOVED_PAIRS[@]+"${MOVED_PAIRS[@]}"}; do
    from="${pair%%|*}"; to="${pair##*|}"
    if [ -f "$from" ] && [ ! -e "$to" ]; then mv "$from" "$to"; fi
  done
  return 0
}

# ─────────────────────────────────────────────────────────────
# 현재 상태 조회 헬퍼
# ─────────────────────────────────────────────────────────────
current_public() {                # 공개(80)로 enable 된 사이트 이름들
  local n out=""
  for n in "${PUBLIC_SITES[@]}"; do
    if [ -L "${ENABLED}/${n}" ]; then out="${out}${out:+ }${n}"; fi
  done
  printf '%s' "$out"
  return 0
}

http_code() {                     # 실패해도 0 을 돌려준다(스크립트를 멈추지 않게)
  curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$1" 2>/dev/null || printf '실패'
  return 0
}

print_status() {
  local pub n port
  pub="$(current_public)"
  step "현재 상태"
  info "공개 (80 포트, 도메인 https://changkwon.mso.so/): ${pub:-없음 — switch-site.sh vN 으로 고른다}"
  info "로컬 포트 (127.0.0.1 만, SSH 포워딩으로 접속):"
  port=8080
  for n in "${LOCAL_SITES[@]}"; do
    port=$((port + 1))
    if [ -L "${ENABLED}/${n}" ]; then
      info "  ${n}  127.0.0.1:${port}  enable"
    elif [ -f "${AVAIL}/${n}" ]; then
      info "  ${n}  127.0.0.1:${port}  설정은 있으나 disable"
    else
      info "  ${n}  127.0.0.1:${port}  설정 파일 없음 (미착수)"
    fi
  done
  return 0
}

# ─────────────────────────────────────────────────────────────
# 1. 저장소의 사이트 파일을 /etc 로 복사
#    log_format·map 정의는 http 컨텍스트에 한 번만 있어야 하므로 conf.d 로 따로 간다.
# ─────────────────────────────────────────────────────────────
snapshot_enabled

step "1. 사이트 설정 파일 설치"
install -o root -g root -m 0644 "${SRC_DIR}/${COMMON_CONF}" "${CONFD}/${COMMON_CONF}"
info "복사: ${CONFD}/${COMMON_CONF}  (log_format tbox_proxy · map \$tbox_loggable)"

shopt -s nullglob
for src in "${SRC_DIR}"/*.conf; do
  name="$(basename "$src" .conf)"
  [ "$name" = "tbox-common" ] && continue
  install -o root -g root -m 0644 "$src" "${AVAIL}/${name}"
  info "복사: ${AVAIL}/${name}"
done
shopt -u nullglob

# ─────────────────────────────────────────────────────────────
# 2. 옛 이름(tbox, tbox-re) 정리 — 지우지 않고 보존한다
# ─────────────────────────────────────────────────────────────
step "2. 옛 사이트 이름 정리 (보존)"
install -d -o root -g root -m 0755 "$LEGACY_DIR"
for name in tbox tbox-re; do
  if [ -L "${ENABLED}/${name}" ]; then
    rm -f "${ENABLED}/${name}"
    info "링크 제거: ${ENABLED}/${name}  (새 이름은 tbox-v1 · tbox-v2)"
  fi
done
for name in "${LEGACY_NAMES[@]}"; do
  if [ -f "${AVAIL}/${name}" ] && [ ! -L "${AVAIL}/${name}" ]; then
    mv "${AVAIL}/${name}" "${LEGACY_DIR}/${name}"
    MOVED_PAIRS+=("${LEGACY_DIR}/${name}|${AVAIL}/${name}")
    info "보존 이동: ${AVAIL}/${name} → ${LEGACY_DIR}/${name}"
  fi
done
info "보존 디렉터리: ${LEGACY_DIR}  (nginx 는 sites-enabled 만 읽으므로 여기 파일은 로드되지 않는다)"

# ─────────────────────────────────────────────────────────────
# 3. 로컬용 사이트는 항상 enable
# ─────────────────────────────────────────────────────────────
step "3. 로컬 전용 사이트 enable"
for name in "${LOCAL_SITES[@]}"; do
  if [ -f "${AVAIL}/${name}" ]; then
    ln -sfn "${AVAIL}/${name}" "${ENABLED}/${name}"
    info "enable: ${name}"
  else
    if [ -L "${ENABLED}/${name}" ]; then rm -f "${ENABLED}/${name}"; fi
    info "건너뜀: ${name} — 설정 파일이 없다 (v3 미착수)"
  fi
done

# ─────────────────────────────────────────────────────────────
# 4. 공개(80 포트) 버전 선택
# ─────────────────────────────────────────────────────────────
if [ "$ACTION" = "status" ]; then
  step "4. 공개 버전 — status 이므로 바꾸지 않는다"
  info "현재 공개: $(current_public)"
else
  TARGET="tbox-${ACTION}"
  step "4. 공개 버전 전환 → ${TARGET}"
  if [ ! -f "${AVAIL}/${TARGET}" ]; then
    rollback_enabled
    die "${TARGET} 설정 파일이 없다 (${AVAIL}/${TARGET}). ${ACTION} 는 아직 만들지 않았다 — 링크 상태를 되돌렸다"
  fi
  for name in "${PUBLIC_SITES[@]}"; do
    if [ "$name" = "$TARGET" ]; then
      ln -sfn "${AVAIL}/${name}" "${ENABLED}/${name}"
      info "enable: ${name}  (80 포트 공개)"
    elif [ -L "${ENABLED}/${name}" ]; then
      rm -f "${ENABLED}/${name}"
      info "disable: ${name}"
    fi
  done
fi

# ─────────────────────────────────────────────────────────────
# 5. 문법 검사 — 실패하면 바꾸기 전 상태로 되돌린다
# ─────────────────────────────────────────────────────────────
step "5. nginx -t"
if nginx -t; then
  info "문법 검사 통과"
else
  printf '\n   문법 검사 실패 — 바꾸기 전 링크 상태로 되돌린다\n'
  rollback_enabled
  nginx -t || true
  die "nginx 설정 문법 오류. sites-enabled 를 원래대로 되돌렸다 (reload 하지 않았다)"
fi

# ─────────────────────────────────────────────────────────────
# 6. 적용과 확인
# ─────────────────────────────────────────────────────────────
step "6. nginx 재적용"
if systemctl is-active --quiet nginx; then
  systemctl reload nginx
  info "reload 완료 (nginx: $(systemctl is-active nginx))"
else
  systemctl start nginx
  info "start 완료 (nginx: $(systemctl is-active nginx))"
fi

print_status

step "접속 확인"
printf '   GET  http://127.0.0.1/                    → %s   (공개 80 포트)\n' "$(http_code http://127.0.0.1/)"
printf '   GET  http://127.0.0.1:8081/index.php      → %s   (v1 실습, 로컬)\n' "$(http_code http://127.0.0.1:8081/index.php)"
printf '   GET  http://127.0.0.1:8082/tbox/api/health → %s   (v2 재구축, 로컬)\n' "$(http_code http://127.0.0.1:8082/tbox/api/health)"
printf '   HSTS 헤더 (공개 80, v2 일 때만 보인다):\n'
curl -sI --max-time 5 http://127.0.0.1/ 2>/dev/null | grep -i strict | sed 's/^/     /' || printf '     (없음)\n'

printf '\n완료. 도메인 https://changkwon.mso.so/ 는 지금 "%s" 를 가리킨다.\n' "$(current_public)"
printf '로컬만 보기:  ssh -L 8081:127.0.0.1:8081 -L 8082:127.0.0.1:8082 changkwon@VM\n'
