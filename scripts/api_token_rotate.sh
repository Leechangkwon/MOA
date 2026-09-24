#!/usr/bin/env bash
# scripts/api_token_rotate.sh — 프로그램용 업로드 API 토큰 만들기·바꾸기 (PRD F-49, ARCH 4.2)
#
# 하는 일
#   ① 무작위 48자(영문 대소문자+숫자) 토큰을 만든다
#   ② 저장소의 `.env` 에서 `API_TOKEN=` **그 한 줄만** 새 값으로 바꾼다(없으면 끝에 더한다)
#   ③ 첫 인자를 주면 `API_TOKEN_USER=` 줄도 같은 방식으로 바꾼다(업로드가 귀속될 계정 아이디)
#
# ★ F-59(2026-09-16) — 이 스크립트가 다루는 것은 **공용 토큰**(.env 의 API_TOKEN)이고, 그것은
#   이제 **이행용**이다. 기본은 계정마다 발급하는 **개인 토큰**(관리 → 계정 → 프로그램 토큰)이며,
#   개인 토큰만이 회수·귀속을 가지고 삭제 창구 둘을 쓸 수 있다. 프로그램에 개인 토큰을 심었으면
#   `.env` 의 `API_TOKEN`·`API_TOKEN_USER` 두 줄을 지운다 — 그러면 공용 경로가 닫힌다(R-82).
#   이 스크립트의 동작은 그대로다(개인 토큰은 화면에서 발급하며 이 파일이 만들지 않는다).
#
# ★ 토큰 값은 화면·로그·파일 어디에도 출력하지 않는다 (PRD S-09-11, SECURITY R-62 · R-82).
#   값을 알아야 하면 서버에서 직접 본다:   cat /var/www/tbox-v2/.env
#   채팅·문서·커밋 메시지에 옮겨 적지 않는다.
#
# ★ 바꾸는 순간 **예전 토큰은 즉시 무효**다. 그 토큰을 쓰던 프로그램은 401 을 받게 되므로
#   새 값을 먼저 옮겨 심을 준비를 하고 실행한다.
#
# ★ 재시작·reload 는 필요 없다 — 앱(src/Env.php)이 요청마다 `.env` 를 읽는다.
#
# 사용
#   bash scripts/api_token_rotate.sh                 # 토큰만 새로 만든다
#   bash scripts/api_token_rotate.sh qa_employee01   # 토큰 + 귀속 계정 아이디
#
# 권한: `.env` 는 0640(소유자 쓰기, 그룹 tbox-php 읽기)이다. 이 스크립트는 **파일을 새로
#       만들지 않고 기존 파일 안에 내용을 덮어쓴다**(`> "$ENV_FILE"`) — 그래서 소유자·그룹·
#       권한(0640)이 그대로 유지된다. 파일을 지웠다 새로 만드는 방식(mv·sed -i)은
#       상황에 따라 소유자나 권한이 바뀔 수 있어 쓰지 않는다.
set -eu
umask 077

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
NEW_USER="${1-}"

[ -f "$ENV_FILE" ] || { echo "오류: $ENV_FILE 이(가) 없습니다. 먼저 배포(deploy/install.sh)를 끝내세요." >&2; exit 1; }
[ -w "$ENV_FILE" ] || { echo "오류: $ENV_FILE 에 쓸 수 없습니다. 파일 소유자로 실행하세요." >&2; exit 1; }

# 귀속 계정 아이디는 Auth::USERNAME_RE 와 같은 형태만 받는다 — .env 에 아무 문자열이나
# 들어가지 않게 한다(줄바꿈이 섞이면 설정 파일 한 줄이 두 줄이 된다).
if [ -n "$NEW_USER" ] && ! printf '%s' "$NEW_USER" | grep -qE '^[a-z0-9_.-]{3,64}$'; then
  echo "오류: 계정 아이디 형태가 아닙니다 (소문자·숫자·_ . - 3~64자)" >&2
  exit 2
fi

command -v openssl >/dev/null 2>&1 || { echo "오류: openssl 이 없습니다." >&2; exit 1; }

# 48자. base64 의 `+ /` 와 개행을 털어 내고 앞에서 48자를 자른다 — 헤더 값으로 그대로
# 실을 수 있는 글자만 남긴다. 64바이트(≈86자)를 뽑으므로 48자를 채우지 못할 일은 없다.
TOKEN="$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c 48)"
[ "${#TOKEN}" -eq 48 ] || { echo "오류: 토큰을 만들지 못했습니다." >&2; exit 1; }

# 임시 사본은 /tmp 가 아니라 .env 와 **같은 폴더**에 둔다(같은 소유자·같은 신뢰 경계). 쓰기와 덮어쓰기 사이에
# kill -9·전원 문제가 나도 새 토큰·DB 자격이 든 사본이 /tmp 에 남지 않고, 다음 실행이 아래에서 치운다(점검 봇 R20 · BF-0010).
ENV_DIR="$(dirname "$ENV_FILE")"
#   10분이 넘은 것만 지운다 — 거의 같은 때 돈 다른 회전의 사본을 지우면 그쪽의 `cat "$TMP" > .env` 가 .env 를 비운다(독립 검토 2026-09-25)
find "$ENV_DIR" -maxdepth 1 -type f -name '.env.rotate.*' -mmin +10 -delete
TMP="$(mktemp -p "$ENV_DIR" .env.rotate.XXXXXX)"
trap 'rm -f "$TMP"' EXIT

# 값은 인자(argv)가 아니라 **환경 변수**로 awk 에 넘긴다 — `ps` 로 실행 중인 명령줄을 보는
# 사람에게 토큰이 보이지 않게 하려는 것이다. 출력도 파일(TMP)로만 간다.
TBOX_NEW_TOKEN="$TOKEN" TBOX_NEW_USER="$NEW_USER" awk '
  BEGIN { tok = 0; usr = 0; want_usr = (ENVIRON["TBOX_NEW_USER"] != "") }
  /^API_TOKEN=/      { print "API_TOKEN=" ENVIRON["TBOX_NEW_TOKEN"]; tok = 1; next }
  /^API_TOKEN_USER=/ {
      if (want_usr) { print "API_TOKEN_USER=" ENVIRON["TBOX_NEW_USER"] } else { print }
      usr = 1; next
  }
  { print }
  END {
      if (!tok) { print "API_TOKEN=" ENVIRON["TBOX_NEW_TOKEN"] }
      if (want_usr && !usr) { print "API_TOKEN_USER=" ENVIRON["TBOX_NEW_USER"] }
  }
' "$ENV_FILE" > "$TMP"

# 새 내용이 비었거나 줄 수가 줄었으면(= 무언가 잘못됐으면) 덮어쓰지 않는다.
old_lines="$(wc -l < "$ENV_FILE")"
new_lines="$(wc -l < "$TMP")"
[ "$new_lines" -ge "$old_lines" ] || { echo "오류: 새 내용이 짧아 덮어쓰지 않았습니다 (.env 는 그대로)." >&2; exit 1; }

# 기존 파일 안에 내용만 덮어쓴다 → 소유자·그룹·권한(0640) 유지.
cat "$TMP" > "$ENV_FILE"

echo "API_TOKEN 갱신됨 — 값은 서버에서 cat .env 로 확인"
if [ -n "$NEW_USER" ]; then
  echo "API_TOKEN_USER 도 갱신됨"
fi
echo "권한 확인: $(stat -c '%U:%G %a' "$ENV_FILE")"
exit 0
