# shellcheck shell=bash
# tests/lib/prod_guard.sh — 시험이 **운영 파일**(/etc/tbox-v2 · /var/lib/tbox-v2 · /var/log/tbox-v2)에 쓰기 전의 가드
#                            (디버깅 묶음 T 9번 · 2026-09-26 사고)
#
# 사고: 13:52 다른 묶음의 회귀 틀이 tests/f51.sh·f52.sh 의 BASE·DB 만 임시 인스턴스로 바꿔 돌렸다. 두 시험은 2단계를
#   다시 등록한 뒤 운영 /etc/tbox-v2/qa-totp.txt 를 `sudo install` 로 새 비밀(임시 DB 의 것)로 덮어써 운영 검토 계정
#   로그인이 깨졌다(16:57 복구). 파일 자리가 상수라 대상이 임시여도 운영 파일에 썼다.
#
# 규칙
#   · 「운영 대상」 = BASE 가 운영 검토 주소 **그리고** DB 가 tbox_re. 운영 검토 포트는 저장소 deploy/sites/tbox-v2-local.conf 의
#     `listen 127.0.0.1:<포트>;` 에서 읽는다 — 숫자를 글자로 적지 않아, 회귀 틀의 글자 치환(`s#127.0.0.1:<운영 포트>#<임시>#g`)이
#     BASE 와 함께 이 기준값까지 바꿔 「둘이 같다」가 되는 일이 없다. DB 기준은 `PG_PROD_DB_NAME`(치환 무늬 `DB="…"` 모양이 아니다).
#   · 운영 파일 자리는 **운영 대상일 때만** 기본값이다. 아니면 시험은 쓰기를 건너뛰거나(메모리) mktemp 자리를 쓴다.
#   · 운영 자리에 쓰려는 **그 순간** prod_write_guard 가 BASE · DB · 운영 앱 .env 의 DB_NAME · 시험을 부른 트리(ROOT 가 운영 트리인가)를
#     다시 보고, 하나라도 어긋나면 거부한다(부르는 쪽은 멈춘다 — f51·f52 는 exit 3, stage26 정리는 지우지 않고 알림).
#   · 운영 대상이면 부르는 쪽은 **운영 상태를 바꾸기 전에**(결정 절 바로 뒤) prod_write_guard 를 한 번 먼저 부른다 — 운영 계정 2단계를 이미
#     바꾼 뒤에 쓰기 직전 확인에서 멈추면 운영 파일이 옛 값으로 남는다(13:52 의 반대 방향 · 독립 검토 중간 1).
#   · BASE 는 별칭을 정규화해 견준다(localhost·[::1] → 127.0.0.1 · 끝 `/` 뗌 · 스킴·호스트 소문자) — 운영 별칭 + 임시 DB 가 「temp」로
#     잘못 가려지지 않게(독립 검토 L4).
#
# 쓰는 법: BASE·DB·ROOT 를 정한 **뒤** `. "$ROOT/tests/lib/prod_guard.sh"`. 셸 설정(set -e/-u · trap · umask)을 바꾸지 않는다.
# 함수
#   prod_target                  0 = 운영 대상(BASE·DB 둘 다)
#   pg_target_kind               prod · temp · unknown 을 찍는다(unknown 이면 시작하지 않는다)
#   pg_is_prod_path <경로>       0 = 운영 자리(/etc/tbox-v2 · /var/lib/tbox-v2 · /var/log/tbox-v2 아래 — 링크를 푼 자리도 본다)
#   prod_write_guard <경로>      운영 자리가 아니면 0. 운영 자리면 운영 대상 · 운영 앱 .env 의 DB_NAME = DB 일 때만 0, 아니면 이유를 표준 오류에 찍고 1
#   pg_norm_base <주소>          정규화한 BASE
# 단위 확인용 손잡이(시험 밖에서 가짜 경로로 가드만 볼 때): PG_PROD_APP_ENV(운영 앱 .env 자리) · PG_PROD_LOCAL_CONF(운영 검토 포트를 읽는 nginx 설정)
#   · PG_PROD_TREE(운영 트리 자리 — PG_GUARD_SELFTEST=1 일 때만 받는다)

PG_PROD_DB_NAME=tbox_re
PG_PROD_APP_ENV="${PG_PROD_APP_ENV:-/var/www/qaredi/tbox-v2/.env}"
PG_PROD_LOCAL_CONF="${PG_PROD_LOCAL_CONF:-${ROOT:-.}/deploy/sites/tbox-v2-local.conf}"
# 운영 파일에 쓰는 시험은 이 트리에서 부를 때만(독립 검토 L3). 이 값의 덮어쓰기(PG_PROD_TREE)는 가드 단위 확인
#   (tests/prod_guard_selftest.sh — PG_GUARD_SELFTEST=1)에서만 받는다 — 어디선가 새어 든 환경 변수로 운영 트리 확인을 비켜 가지 않게(재확인 낮음)
if [ "${PG_GUARD_SELFTEST:-}" = 1 ] && [ -n "${PG_PROD_TREE:-}" ]; then :; else PG_PROD_TREE=/var/www/qaredi/tbox-v2; fi
PG_PROD_PATH_RE='^/(etc|var/lib|var/log)/tbox-v2(/|$)'
# 운영 저장소(파일 바이트) — 시험 본문에 글자로 두지 않고 여기서만(회귀 틀의 「운영 저장 경로가 남았나」 검사가 사본에서 오탐하지 않게)
PG_PROD_STORAGE=/var/lib/tbox-v2/storage

# 운영 검토 포트 — 저장소의 로컬 사이트 설정 listen 줄(없으면 빈 값 → 운영 대상이 될 수 없다)
#   `listen 127.0.0.1:8082;` · `listen 127.0.0.1:8082 default_server;` 둘 다 읽는다(독립 검토 L2)
pg_prod_port() {
  sed -nE 's/^[[:space:]]*listen[[:space:]]+127\.0\.0\.1:([0-9]{2,5})[[:space:];].*/\1/p' "$PG_PROD_LOCAL_CONF" 2>/dev/null | head -1
}

# BASE 정규화 — 스킴·호스트 소문자 · localhost·[::1] → 127.0.0.1 · 끝 `/` 떼기
pg_norm_base() {
  local b="${1:-}" rest scheme hostport path host
  while [[ "$b" == */ ]]; do b="${b%/}"; done
  [[ "$b" =~ ^([A-Za-z][A-Za-z0-9+.-]*)://([^/]*)(.*)$ ]] || { printf '%s' "$b"; return 0; }
  scheme=$(printf '%s' "${BASH_REMATCH[1]}" | tr 'A-Z' 'a-z')
  hostport=$(printf '%s' "${BASH_REMATCH[2]}" | tr 'A-Z' 'a-z')
  path="${BASH_REMATCH[3]}"
  case "$hostport" in
    localhost|localhost:*) hostport="127.0.0.1${hostport#localhost}" ;;
    '[::1]'|'[::1]:'*) hostport="127.0.0.1${hostport#\[::1\]}" ;;
  esac
  printf '%s://%s%s' "$scheme" "$hostport" "$path"
}

prod_target() {
  local port
  port=$(pg_prod_port)
  [ -n "$port" ] && [ "$(pg_norm_base "${BASE:-}")" = "http://127.0.0.1:$port/tbox" ] && [ "${DB:-}" = "$PG_PROD_DB_NAME" ]
}

# 대상 종류 — prod(BASE·DB 둘 다 운영) · temp(둘 다 운영 아님) · unknown(운영 검토 포트를 못 읽음 · 둘 중 하나만 운영).
#   unknown 이면 부르는 쪽은 **시작하지 않는다** — 「운영이 아니다」로 잘못 보고 운영 계정의 2단계만 바꾸고 새 비밀을
#   메모리에만 두면, 운영 파일이 옛 비밀로 남아 같은 사고가 반대 방향으로 난다.
pg_target_kind() {
  local port base_prod=0 db_prod=0
  port=$(pg_prod_port)
  [ -n "$port" ] || { echo unknown; return 0; }
  [ "$(pg_norm_base "${BASE:-}")" = "http://127.0.0.1:$port/tbox" ] && base_prod=1
  [ "${DB:-}" = "$PG_PROD_DB_NAME" ] && db_prod=1
  case "$base_prod$db_prod" in 11) echo prod ;; 00) echo temp ;; *) echo unknown ;; esac
}

pg_is_prod_path() {
  local p="$1" r
  [ -n "$p" ] || return 1
  [[ "$p" =~ $PG_PROD_PATH_RE ]] && return 0
  r=$(realpath -m -- "$p" 2>/dev/null) || return 1
  [[ "$r" =~ $PG_PROD_PATH_RE ]]
}

prod_write_guard() {
  local p="$1" why="" envdb="" tree=""
  pg_is_prod_path "$p" || return 0
  if ! prod_target; then
    why="대상이 운영이 아니다(BASE=${BASE:-없음} · DB=${DB:-없음})"
  else
    envdb=$(sed -n 's/^DB_NAME=//p' "$PG_PROD_APP_ENV" 2>/dev/null | head -1)
    tree=$(realpath -e -- "${ROOT:-/nonexistent}" 2>/dev/null)
    if [ "$envdb" != "${DB:-}" ]; then
      why="운영 앱 .env($PG_PROD_APP_ENV)의 DB_NAME(${envdb:-못 읽음})이 시험 DB(${DB:-없음})와 다르다"
    elif [ -z "$tree" ] || [ "$tree" != "$(realpath -m -- "$PG_PROD_TREE" 2>/dev/null)" ]; then
      why="시험을 부른 트리(${tree:-${ROOT:-없음}})가 운영 트리($PG_PROD_TREE)가 아니다"
    fi
  fi
  if [ -n "$why" ]; then
    printf '  ✖ 운영 파일 쓰기 가드 — %s 에 쓰지 않는다: %s\n' "$p" "$why" >&2
    return 1
  fi
  return 0
}
