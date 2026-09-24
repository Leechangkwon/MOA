# tests/lib/secrets.sh — 비밀을 명령 인자에 싣지 않는 도우미 (점검 봇 1-B-1 · SECURITY R-62 · 봇 규칙 R15)
#
# 왜 있나
#   명령 인자는 `ps`·/proc/<pid>/cmdline 으로 같은 호스트의 **다른 계정도** 읽는다. sudo 로 부른 명령의 인자는
#   auth.log 의 `COMMAND=` 에 남는다(2026-09-24 확인: auth.log.1 에 옛 qa_ 비밀 14줄). 그래서 시험은 비밀
#   (TOTP 비밀 · 검토 계정 비밀번호 · API 토큰 · 공유 링크 토큰)을 **표준입력**이나 **0600 임시 파일**로만 넘긴다.
#   값을 파이프에 쓰는 `printf` 는 bash 내장이라 새 프로세스가 생기지 않는다 — 인자 목록에 비밀이 없다.
#
# 쓰는 법 — 각 시험의 ROOT 계산 바로 뒤, review_accounts.sh 다음 줄
#     . "$ROOT/tests/lib/secrets.sh" || { echo "…"; exit 3; }
#   회귀 때 시험이 `$INST/app/tests/<이름>.temp.sh` 로 복사돼 돌아도 이 파일은 그 사본의
#   `$INST/app/tests/lib/` 에 있고, 아래 QSX_ROOT 는 이 파일 자리에서 계산하므로 그 사본을 가리킨다.
#
# 주는 것 — 비밀은 **변수 이름**으로 받는다(값을 부르는 줄에 쓰지 않는다 — 흐름을 사람·R15 가 쫓기 쉽다)
#   totp_now <변수>                       그 변수의 TOTP 비밀로 지금 코드를 찍는다. 출력은 scripts/totp_code.php 와
#                                         같은 두 줄(코드 · 「남은 시간: n초 …」), 종료 코드도 그대로
#   curl_json_secret <변수> <curl 인자…>  변수에 든 요청 본문을 `--data-binary @-`(표준입력)로 보낸다.
#                                         `-d "문자열"` 과 같은 바이트다(printf '%s' — 끝 줄바꿈 없음)
#   curl_hdr_secret <헤더> <변수> <curl 인자…>
#                                         「헤더: 값」 한 줄을 `-H @-`(표준입력, curl 7.55+ · 여기 7.81)로 보낸다
#   curl_url_secret <변수> <curl 인자…>   주소에 비밀(공유 링크 토큰 등)이 든 경우 — 주소를 `--config -` 의
#                                         `url = "…"` 로 표준입력에 싣는다
#   curl_form_secret <필드> <변수> <curl 인자…>
#                                         multipart 필드 하나의 값을 `-F '필드=<-'`(표준입력)로 보낸다
#   py_secret <변수…> -- <명령…>          변수 값을 **한 줄씩 차례로** 명령의 표준입력에 준다(파이썬 도우미용)
#   secret_file <변수> <폴더>             <폴더> 안에 0600 임시 파일을 만들어 값 한 줄을 쓰고 경로를 찍는다
#                                         (`grep -F -f <파일>` 처럼 파일로 받는 명령용). 폴더는 부르는 시험의
#                                         trap 이 지우는 mktemp -d 폴더여야 한다(0700 이 아니면 거부)
#   공통: 비밀 변수 이름은 `_sg_`·`_sx_` 로 시작하면 안 된다(도우미 지역 이름 — 아래 _secret_get 주석).
#         한 명령은 표준입력을 하나만 쓴다 — 두 가지를 동시에 표준입력으로 보낼 수 없다.
#         변수 이름이 식별자가 아니거나 값이 비었거나 줄바꿈이 섞였으면 **보내지 않고** rc 90 을 낸다
#         (빈 비밀번호로 요청이 나가 「틀린 비밀번호」로 잘못 읽히는 것을 막는다). 오류 문구에 값은 넣지 않는다.
#
# 이 파일은 실행하지 않고 source 만 한다(실행 비트·shebang 없음). 네트워크·DB 에 닿지 않는다(부르는 쪽이 닿는다).

QSX_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)" || return 1 2>/dev/null || exit 1

# 변수 이름 검사 + 값 꺼내기. $1 변수 이름  $2 받을 변수 이름. 값은 printf -v 로만 옮긴다.
# ★ 이 함수의 지역 변수는 `_sg_` 로 시작한다 — bash 는 동적 범위라, 받을 변수 이름($2)과 같은 이름의 지역
#   변수가 여기 있으면 printf -v 가 **이 함수의 지역**에 써서 부른 쪽에 값이 가지 않는다(1-B-1 자체 시험에서
#   `_sx_v` 로 실제로 겪음). 부르는 쪽은 `_sg_` 이름을 쓰지 않는다.
_secret_get() {
  local _sg_n="${1:-}" _sg_to="${2:-}"
  case "$_sg_n" in
    ''|[0-9]*|_sg_*|_sx_*|*[!A-Za-z0-9_]*) echo "secrets.sh: 변수 이름 모양이 아니다(_sg_·_sx_ 접두는 도우미 몫) — 보내지 않는다" >&2; return 90 ;;
  esac
  case "$_sg_to" in
    ''|[0-9]*|_sg_*|*[!A-Za-z0-9_]*) echo "secrets.sh: 받을 변수 이름 모양이 아니다" >&2; return 90 ;;
  esac
  local _sg_v="${!_sg_n-}"
  if [ -z "$_sg_v" ]; then
    echo "secrets.sh: $_sg_n 가 비었다 — 보내지 않는다" >&2; return 90
  fi
  case "$_sg_v" in
    *$'\n'*|*$'\r'*) echo "secrets.sh: $_sg_n 에 줄바꿈이 섞였다 — 보내지 않는다" >&2; return 90 ;;
  esac
  printf -v "$_sg_to" '%s' "$_sg_v"
}

totp_now() {
  local _sx_s; _secret_get "${1:-}" _sx_s || return $?
  printf '%s\n' "$_sx_s" | php "$QSX_ROOT/scripts/totp_code.php" -
}

curl_json_secret() {
  local _sx_b; _secret_get "${1:-}" _sx_b || return $?; shift
  printf '%s' "$_sx_b" | curl --data-binary @- "$@"
}

curl_hdr_secret() {
  local _sx_h="${1:-}" _sx_v
  case "$_sx_h" in
    ''|*[!A-Za-z0-9-]*) echo "secrets.sh: 헤더 이름 모양이 아니다 — 보내지 않는다" >&2; return 90 ;;
  esac
  _secret_get "${2:-}" _sx_v || return $?; shift 2
  printf '%s: %s\n' "$_sx_h" "$_sx_v" | curl -H @- "$@"
}

# curl 설정 파일의 따옴표 값 — \ 와 " 를 이스케이프한다(줄바꿈은 _secret_get 이 이미 막았다)
_secret_cfg_quote() {
  local _sx_q="$1"
  _sx_q=${_sx_q//\\/\\\\}; _sx_q=${_sx_q//\"/\\\"}
  printf '%s' "$_sx_q"
}

curl_url_secret() {
  local _sx_u; _secret_get "${1:-}" _sx_u || return $?; shift
  printf 'url = "%s"\n' "$(_secret_cfg_quote "$_sx_u")" | curl --config - "$@"
}

curl_form_secret() {
  local _sx_f="${1:-}" _sx_v
  case "$_sx_f" in
    ''|*[!A-Za-z0-9_-]*) echo "secrets.sh: 필드 이름 모양이 아니다 — 보내지 않는다" >&2; return 90 ;;
  esac
  _secret_get "${2:-}" _sx_v || return $?; shift 2
  printf '%s' "$_sx_v" | curl -F "$_sx_f=<-" "$@"
}

py_secret() {
  local _sx_names=() _sx_vals=() _sx_x _sx_v
  while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do _sx_names+=("$1"); shift; done
  [ "${1:-}" = "--" ] || { echo "secrets.sh: py_secret <변수…> -- <명령…> 모양이 아니다" >&2; return 90; }
  shift
  [ "$#" -gt 0 ] && [ "${#_sx_names[@]}" -gt 0 ] || { echo "secrets.sh: py_secret 에 변수나 명령이 없다" >&2; return 90; }
  for _sx_x in "${_sx_names[@]}"; do _secret_get "$_sx_x" _sx_v || return $?; _sx_vals+=("$_sx_v"); done
  printf '%s\n' "${_sx_vals[@]}" | "$@"
}

secret_file() {
  local _sx_v _sx_d="${2:-}" _sx_f
  _secret_get "${1:-}" _sx_v || return $?
  if [ -z "$_sx_d" ] || [ ! -d "$_sx_d" ] || [ -L "$_sx_d" ] || [ ! -O "$_sx_d" ] || [ "$(stat -c %a "$_sx_d")" != "700" ]; then
    echo "secrets.sh: secret_file 폴더는 내 소유 0700 mktemp -d 폴더여야 한다" >&2; return 90
  fi
  _sx_f=$(umask 077; mktemp "$_sx_d/.secret.XXXXXX") || return 90
  chmod 600 "$_sx_f" || { rm -f "$_sx_f"; return 90; }
  printf '%s\n' "$_sx_v" > "$_sx_f" || { rm -f "$_sx_f"; return 90; }
  printf '%s\n' "$_sx_f"
}
