#!/usr/bin/env bash
# scripts/backup_files.sh — 업로드된 실제 파일 백업 (9단계)
#   PRD 7.8 S-08 (매일 03:30, 증분 + 주 1회 전체), ARCHITECTURE 11.1 표 03:30 행
#
# 사용법:  sudo bash scripts/backup_files.sh [--keep 7] [--no-mirror]
#
# 두 가지를 **둘 다** 만든다
#   1) 암호화 전체 사본  tar -cz | gpg  →  files/files-YYYYMMDD.tar.gz.gpg + .sha256
#      · 서버 밖으로 옮길 수 있고(원격 저장소 Q-4), 암호화돼 있어 복사본이 새도 열리지 않는다.
#   2) 로컬 미러        rsync -a --delete  →  files-mirror/
#      · 파일 하나를 실수로 지웠을 때 **몇 초 만에** 되돌릴 수 있는 자리다. 암호화되지 않으므로
#        같은 서버 안에서만 쓴다(0700 root). 서버가 통째로 죽는 사고에는 1) 만 쓸모가 있다.
#
# 도구 결정 (PRD S-10-C "배포판 패키지만·새 패키지 0", ARCH 11.1 2026-09-11)
#   문서의 `restic`(중복 제거 증분 백업)은 이 VM 에 없고 설치하지 않는다.
#   → `tar` 전체 + `rsync --delete` 미러로 대체한다. 진짜 증분(변경분만 저장)은 아니므로
#     보관 개수를 7개로 제한해 디스크를 지킨다. 운영 전환 시 restic 재검토.
#
# 감사 기록:  backup.run allow | backup.run error backup_failed
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="/etc/tbox-v2"
BACKUP_ROOT="/var/backups/tbox-v2"
FILES_DIR="${BACKUP_ROOT}/files"
MIRROR_DIR="${BACKUP_ROOT}/files-mirror"
PASS_FILE="${CONF_DIR}/backup.key"

DATA_PARENT="/var/lib/tbox-v2"     # tar 의 -C 대상
DATA_NAME="storage"                # 그 안에서 담을 디렉터리
SOURCE_DIR="${DATA_PARENT}/${DATA_NAME}"

KEEP=7
DO_MIRROR=1
for arg in "$@"; do
  case "$arg" in
    --no-mirror) DO_MIRROR=0 ;;
  esac
done
if [ "${1:-}" = "--keep" ] && [ -n "${2:-}" ]; then
  case "$2" in
    ''|*[!0-9]*) printf '[중단] --keep 은 정수다\n' >&2; exit 2 ;;
    *) KEEP="$2" ;;
  esac
fi

say() { printf '   %s\n' "$*"; }
die() { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

audit() {  # audit <allow|error> [reason] <detail-json>
  local result="$1"; shift
  local reason=""
  if [ "$result" != "allow" ]; then reason="$1"; shift; fi
  local detail="$1"
  if [ -n "$reason" ]; then
    php "${REPO_DIR}/scripts/audit_cli.php" backup.run "$result" "$reason" \
      --target files --detail "$detail" || printf '[경고] 감사 기록 실패\n' >&2
  else
    php "${REPO_DIR}/scripts/audit_cli.php" backup.run "$result" \
      --target files --detail "$detail" || printf '[경고] 감사 기록 실패\n' >&2
  fi
}

fail() {  # fail <단계> <설명>
  local step="$1" msg="$2"
  printf '[실패] %s: %s\n' "$step" "$msg" >&2
  audit error backup_failed "$(printf '{"target":"files","step":"%s"}' "$step")"
  exit 1
}

[ "$(id -u)" -eq 0 ] || die "root 로 실행해야 한다:  sudo bash scripts/backup_files.sh"
[ -d "$SOURCE_DIR" ]  || fail "source" "저장소가 없다: ${SOURCE_DIR}"
[ -r "$PASS_FILE" ]   || fail "key" "백업 키가 없다: ${PASS_FILE} (deploy/install.sh 가 만든다)"
command -v gpg   >/dev/null 2>&1 || fail "gpg" "gpg 가 없다"
command -v tar   >/dev/null 2>&1 || fail "tar" "tar 가 없다"
command -v rsync >/dev/null 2>&1 || fail "rsync" "rsync 가 없다"

install -d -o root -g root -m 0700 "$BACKUP_ROOT" "$FILES_DIR" "$MIRROR_DIR"

STAMP="$(date +%Y%m%d)"
NAME="files-${STAMP}.tar.gz.gpg"
OUT="${FILES_DIR}/${NAME}"
TMP="${OUT}.partial"

printf '\n== 파일 백업 %s ==\n' "$STAMP"
say "대상: ${SOURCE_DIR} ($(find "$SOURCE_DIR" -type f | wc -l)개 파일, $(du -sh "$SOURCE_DIR" | awk '{print $1}'))"

# ── 1) 암호화 전체 사본 (평문 tar 를 디스크에 남기지 않는다 — 한 파이프) ──
umask 077
set +e
tar -C "$DATA_PARENT" -czf - "$DATA_NAME" \
  | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
        --passphrase-file "$PASS_FILE" --output "$TMP"
RC=("${PIPESTATUS[@]}")
set -e
umask 022

if [ "${RC[0]}" -ne 0 ]; then rm -f "$TMP"; fail "tar" "종료 코드 ${RC[0]}"; fi
if [ "${RC[1]}" -ne 0 ]; then rm -f "$TMP"; fail "gpg" "종료 코드 ${RC[1]}"; fi
[ -s "$TMP" ] || { rm -f "$TMP"; fail "output" "산출물이 비어 있다"; }

mv "$TMP" "$OUT"
chmod 0600 "$OUT"
( cd "$FILES_DIR" && sha256sum "$NAME" > "${NAME}.sha256" )
chmod 0600 "${OUT}.sha256"

BYTES="$(stat -c %s "$OUT")"
SUM="$(awk '{print $1}' "${OUT}.sha256")"
say "산출물: ${OUT}"
say "크기:   ${BYTES} 바이트"
say "sha256: ${SUM}"

# ── 2) 로컬 미러 (rsync) ──
MIRROR_FILES=0
if [ "$DO_MIRROR" -eq 1 ]; then
  # --delete: 원본에서 사라진 파일은 미러에서도 지운다. 그래서 미러는 "지금 상태의 사본" 이지
  #           "과거를 되돌릴 수 있는 백업" 이 아니다 — 되돌리기는 1) 의 날짜별 산출물이 한다.
  # 끝의 슬래시(source/)는 "디렉터리 **안**을" 이라는 뜻이다. 빼면 한 겹 더 들어간다.
  if ! rsync -a --delete "${SOURCE_DIR}/" "${MIRROR_DIR}/"; then
    fail "rsync" "미러 동기화 실패"
  fi
  # rsync -a 는 원본 디렉터리의 소유·권한을 목적지 최상위에도 반영한다. 미러 **입구**만은
  # root 0700 으로 되돌린다 — 백업 자리는 전용 계정만 들어간다 (PRD S-08 "백업 디렉터리 0700").
  # 안쪽 파일의 소유(tbox-php)는 그대로 둔다: 복원할 때 그 소유가 그대로 필요하기 때문이다.
  chown root:root "$MIRROR_DIR"
  chmod 0700 "$MIRROR_DIR"
  MIRROR_FILES="$(find "$MIRROR_DIR" -type f | wc -l)"
  say "미러: ${MIRROR_DIR} (${MIRROR_FILES}개 파일)"
else
  say "미러: --no-mirror 로 건너뜀"
fi

# ── 보관 정책 — 최근 $KEEP 개 (ARCH 11.1 "보관 7개") ──
n=0
while IFS= read -r f; do
  n=$((n + 1))
  if [ "$n" -gt "$KEEP" ]; then
    rm -f -- "$f" "${f}.sha256"
    say "보관 정책: 삭제 $(basename "$f")"
  fi
done < <(find "$FILES_DIR" -maxdepth 1 -type f -name 'files-*.tar.gz.gpg' -printf '%f\n' \
           | sort -r | sed "s#^#${FILES_DIR}/#")

audit allow "$(printf '{"target":"files","artifact":"%s","checksum":"%s","bytes":%s,"mirror_files":%s,"tool":"tar -cz | gpg AES256 + rsync -a --delete"}' \
  "$NAME" "$SUM" "$BYTES" "$MIRROR_FILES")"

say "완료. 보관 중: $(find "$FILES_DIR" -maxdepth 1 -name 'files-*.tar.gz.gpg' | wc -l)개"
say "디스크: $(df -h / | awk 'NR==2{print $3" / "$2" ("$5" 사용)"}')"
