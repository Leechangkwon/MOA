#!/usr/bin/env bash
# tests/upgrade_smoke.sh — 업그레이드 스모크 (점검 봇 · PRD 13.7 항목 4 의 동적 짝 · docs/checkbot/upgrade-smoke-design.md A 방식)
#
# 묻는 것: 직전 발행 N-1 을 캐시한 브라우저로 새 판 N 의 핵심 화면을 열면 단추가 도는가.
#   UR-0001(7-C v2.19.0) 은 옛 부품을 캐시한 폰에서 커뮤니티 JS 가 통째로 멈춘 사고다. 다른 브라우저 시험은
#   전부 캐시 없는 새 문맥이라 이 길이 원리상 비어 있었다. 정적 짝은 R17(버전 없는 상대 import × 긴 캐시).
#
# 어떻게(route 흉내 0 — 진짜 디스크 캐시):
#   ① 같은 포트에 N-1 트리를 띄우고 프로필 브라우저(launch_persistent_context)로 동선 화면을 돌아 캐시를 채운다
#   ② 서버만 내리고 **같은 출처·포트**에 N 트리를 띄운다
#   ③ 같은 프로필로 동선을 다시 연다 — upgrade 판정 · ④ 새 문맥으로 같은 동선 — fresh 판정(대조)
#   fill 과 upgrade 는 브라우저를 따로 띄운다(같은 영속 프로필) — 메모리 캐시는 버리고 디스크 캐시만 남기는 것이
#   「어제 캐시한 사람이 오늘 연다」에 가깝다(설계 9장).
#   임시 서버 라우터는 운영 nginx(deploy/sites/tbox-v2.conf)의 `/tbox/assets/` 캐시 머리(expires ·
#   Last-Modified · ETag)와 `/assets/` 308 을 흉내 내고, 받은 자산 주소를 기록한다. ③ 에서 브라우저가 받았는데
#   서버 기록에 없는 자산 = 캐시가 답한 부품(CACHED 줄) — 실패의 원인 부품을 가리킨다.
#
# 입력(환경 변수)
#   N_REF    N 으로 쓸 커밋·태그. 비우면 지금 작업 트리(발행 직전 확인용 — 기본)
#   OLD_TAG  N-1. 비우면 N 에서 닿는 가장 최근 v* 태그 — 그 태그가 곧 N 커밋이면 그 앞 태그(설계 4장 · D-3 N-1 만)
#   FLOWS    동선 목록(기본 tests/upgrade/flows.json — D-4 화면을 만드는 단계가 한 줄씩)
#   PORT     기본 8104 · **8100~8199 만**(80·443 운영 nginx, 8082 운영 앱을 임시 서버로 믿지 않게 — 봇 검토 중간)
# 남의 서버를 믿지 않는다(봇 검토 중간): 시작 전 모든 주소에서 PORT 를 듣는 소켓 0(`ss sport = :PORT`) ·
#   라우터는 이번 실행만의 표식 머리(X-Upgrade-Run)를 모든 응답에 내고, 준비 확인과 브라우저(흐름마다 처음 여는 화면 응답)가 대조한다 —
#   브라우저는 표식이 틀리면 그 단계를 멈춘다(남은 흐름에 쿠키를 보내지 않는다) ·
#   준비 확인은 `kill -0` 으로 임시 서버가 살아 있는지도 본다. 표식이 맞기 전에는 로그인(비밀번호)을 보내지 않는다.
# 결과(1-C · DESIGN N-4): 흐름마다 `RESULT_ITEM <흐름> pass|fail|skip <사유코드> fresh=… upgrade=… reason=…` ·
#   **끝 줄** `RESULT pass= fail= skip=`(확인 줄 합계 — 바로 위 한국어 합계 줄과 같은 수).
#   사유코드(설계 7장 — 사고와 같은 모양만 호환성 실패로 센다 · HO-HF0-006 교훈):
#     LINK  모듈 연결 오류(`does not provide an export` 류) · REQ0 누른 뒤(또는 열자마자) 기대한 요청 0건
#       → fresh=pass 이면 RESULT_ITEM fail — 이 시험의 본래 대상(배포 사이 호환성)
#     OTHER 그 밖의 끊김(누를 것 없음 · 누르지 못함 · 화면·기대 요청 응답 ≥400 · 로그인 화면 · 표식 머리 없음)
#       → RESULT_ITEM skip(호환성 실패로 세지 않는다) · **시험 전체는 실패**(사람이 볼 것)
#     FRESH 새 문맥에서도 실패 — 코드 결함이거나 준비가 틀린 것(세션 무효·스키마 어긋남)이라 upgrade 판정을 믿을 수 없다
#       → RESULT_ITEM skip · **시험 전체는 실패**(모두 skip 인데 통과로 끝나는 헛통과를 막는다 · 독립 검토 H1)
# 캐시가 실제로 쓰였다는 양성 대조(검토 H2): upgrade 단계에서 서버를 거치지 않고 캐시가 답한 자산이 1건 이상이어야 한다.
#   fill 단계는 흐름마다 자산을 받았고 로그인 화면·오류 화면이 아니어야 한다(검토 M1). 머리 확인 curl 은 자산 기록에서 뺀다.
# 콘솔 오류·그 밖의 pageerror 는 WARN 으로만(설계 2장-1 과 다른 점 — 9장). 서비스 워커는 0 이어야 한다(설계 3장 —
#   생기면 이 시험의 「HTTP 캐시만」 가정이 깨지므로 시험 실패).
# D-2: 처음 두 단계는 경고만(catalog status=advisory) — 이 스크립트는 실패면 1 을 돌려주고, 막을지는 부르는 쪽이 정한다.
#
# ★ 운영 DB 를 건드리지 않는다 — stale_modules.sh 와 같은 틀: 운영 DB(tbox_re)는 pg_dump 로 **읽기만**(파이프 — 평문
#   덤프 파일 0), 임시 DB `tbox_upgrade_<PORT>_test`(AL-9 시험 DB 이름 규칙) · 임시 폴더. 운영 8082 요청 0. 검토 계정 claude_employee01(F-71).
#   임시 DB 는 운영 스키마 하나로 두 판을 모두 돌린다(마이그레이션 기록 표가 없어 「N-1 까지 → N」 을 가를 수 없다).
#   N-1 뒤 새 SQL 파일은 이름만 알리고 계속한다 — 운영에 아직 없는 스키마가 N 에 필요하면 fresh 대조가 실패해
#   시험 전체가 실패로 끝난다(위 H1 규칙). 설계 4장의 「N-1 마이그레이션 → N 마이그레이션」 과 다른 점이다.
# ★ 캐시 흉내의 두 값은 운영에서 읽는다(검토 M3·M4):
#   - 자산 max-age 는 deploy/sites/tbox-v2.conf 의 `location /tbox/assets/` 블록 `expires` 에서 계산한다. 값이 글자(`7d`)면
#     그대로, 변수(`$tbox_asset_expires` — Q:F-4 U-4′)면 deploy/sites/tbox-common.conf 의 `map $arg_v $그변수 { … }` 에서
#     기본값과 정규식 → 값을 읽고(tests/upgrade/asset_cache.py), 라우터가 nginx 와 같은 차례(정규식을 적힌 차례로 → 없으면 기본값)로
#     `?v=` 를 판정한다. 흉내 낼 수 없는 모양(다른 원본 변수 · hostnames · include · 같은 글자 줄 · off/epoch)이면 준비물 없음(3)으로 멈춘다.
#     로컬용 tbox-v2-local.conf(8082)도 같은 두 모양으로 읽어 공개용과 대조만 한다(다르면 ⚠ 알림)
#   - 셸은 app.js·app.css·editor.js 에 `?v=<파일 시각>` 을 붙인다. git archive 는 모든 파일에 커밋 시각을 찍으므로
#     그대로 두면 이 셋은 두 판 사이에서 늘 주소가 달라 캐시 대상에서 빠진다. 운영에서는 바뀌지 않은 파일의 시각이
#     그대로이므로, N-1 트리에서 N 과 내용이 같은 자산은 N 의 파일 시각으로 맞춘다.
# ★ 비밀(비밀번호·OTP 비밀·세션 쿠키)은 명령 인자·환경 변수에 싣지 않는다 — tests/lib/secrets.sh 의 py_secret(표준입력).
# 정리: trap EXIT 가 서버·임시 DB(이 실행이 만든 것만)·임시 폴더를 지운다. kill -9 로 끊기면 0700 임시 폴더(두 트리의
#   임시 .env 사본 두 벌(600) · 브라우저 프로필 · 세션 헤더 파일)와 임시 DB 가 남는다. 임시 DB 는 같은 PORT 의 다음 실행이
#   dropdb 로 지운다. 이 호스트의 /tmp 는 부팅 때만 비워지므로(tmpfiles `D /tmp`) 임시 폴더는 **다음 실행이** 치운다 —
#   `tbox-upgrade.*` 중 내 것이고 owner.pid 의 프로세스가 없는 것만(검토 L5 · 봇 검토 낮음).
# 나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 인스턴스 실패
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다"; exit 3; }
PORT="${PORT:-8104}"
# 임시 서버 포트는 좁은 범위만(봇 검토 중간) — 80·443(운영 nginx)·8082(운영 앱)는 범위 밖이라 쓸 수 없다
case "$PORT" in
  81[0-9][0-9]) ;;
  *) echo "PORT=$PORT — 임시 서버 포트는 8100~8199 만 쓴다(80·443·8082 금지)"; exit 3 ;;
esac
# 무엇이든 건드리기 전에 — 모든 주소(0.0.0.0 · :: · 다른 IP)에서 이 포트를 듣는 소켓 — 127.0.0.1 만 보면 남의 서버 응답으로 진행할 수 있다(봇 검토 중간)
LISTEN=$(ss -Hltn "sport = :$PORT" 2>/dev/null) || { echo "ss 로 포트 $PORT 사용을 확인하지 못했다 — 시작하지 않는다"; exit 3; }
if [ -n "$LISTEN" ]; then
  echo "포트 $PORT 을 이미 누가 듣고 있다(모든 주소 기준: $(printf '%s\n' "$LISTEN" | awk '{print $4}' | paste -sd' ' -)) — 앞선 시험이 남긴 서버를 먼저 내린다"; exit 3
fi
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만** 한다
DB="tbox_upgrade_${PORT}_test"   # 임시 — 여기에만 쓴다. PORT 로 갈라 나란히 돌아도 남의 DB 를 지우지 않는다(검토 H3) · `_test` 끝은 AL-9 SCRATCH_DB_RE
WHO="claude_employee01"
FLOWS="${FLOWS:-$ROOT/tests/upgrade/flows.json}"
N_REF="${N_REF:-}"
OLD_TAG="${OLD_TAG:-}"
# 이번 실행만의 표식 — 라우터가 모든 응답에 X-Upgrade-Run 으로 내고 준비 확인·브라우저가 대조한다(비밀 아님) · 임시 폴더보다 먼저(실패해도 폴더가 남지 않게)
RUN_MARK=$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')
[ "${#RUN_MARK}" = 24 ] || { echo "표식을 만들지 못했다(/dev/urandom)"; exit 3; }
TMP_BASE="${TMPDIR:-/tmp}"
# kill -9 로 끊긴 앞선 실행의 임시 폴더(임시 .env 사본 포함)를 치운다 — 내 것 · 실제 폴더(링크 아님) · owner.pid 의 프로세스가 없는 것만.
# pid 파일이 없거나(막 만들어지는 중) 그 pid 가 살아 있으면(다른 실행 · pid 재사용) 건드리지 않는다.
while IFS= read -r -d '' old; do
  opid=$(head -c 16 "$old/owner.pid" 2>/dev/null | tr -cd '0-9')
  [ -n "$opid" ] || continue
  kill -0 "$opid" 2>/dev/null && continue
  [ -n "$(ps -o pid= -p "$opid" 2>/dev/null)" ] && continue   # 남의 사용자 프로세스(kill -0 가 EPERM)도 살아 있는 것으로
  rm -rf -- "$old"
done < <(find "$TMP_BASE" -maxdepth 1 -mindepth 1 -type d -name 'tbox-upgrade.*' -user "$(id -u)" -print0 2>/dev/null)
WORK="$(mktemp -d "$TMP_BASE/tbox-upgrade.XXXXXXXX")" || { echo "임시 폴더를 만들 수 없다"; exit 3; }
chmod 700 "$WORK"
echo "$$" > "$WORK/owner.pid"
SERVER_PID=""
DB_CREATED=0
PASS=0
FAIL=0
SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

stop_server() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; SERVER_PID=""; fi
}
cleanup() {
  stop_server
  [ "$DB_CREATED" = 1 ] && sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# ════════════════════════════════════════════════════════════════
hr "업그레이드 스모크 — 준비 (운영 DB 에는 쓰지 않는다)"

python3 -c 'import playwright' 2>/dev/null || { export PATH="$HOME/.local/bin:$PATH"; }
python3 -c 'import playwright' 2>/dev/null || { echo "playwright 가 없다 — 이 시험은 브라우저가 없으면 성립하지 않는다"; exit 3; }
[ -f "$FLOWS" ] || { echo "동선 목록이 없다: $FLOWS"; exit 3; }
python3 -c 'import json,sys; f=json.load(open(sys.argv[1]))["flows"]; assert f and all("id" in x and "url" in x for x in f)' "$FLOWS" 2>/dev/null \
  || { echo "동선 목록 모양이 틀렸다(flows[].id·url): $FLOWS"; exit 3; }

sub "N 과 N-1"
if [ -n "$N_REF" ]; then
  N_COMMIT=$(git -C "$ROOT" rev-parse --verify -q "$N_REF^{commit}") || { echo "N_REF=$N_REF 를 찾을 수 없다"; exit 3; }
  N_LABEL="$N_REF($(git -C "$ROOT" log -1 --format=%h "$N_COMMIT"))"
  N_EXACT=1
else
  N_COMMIT=$(git -C "$ROOT" rev-parse HEAD)
  N_LABEL="작업 트리(HEAD $(git -C "$ROOT" log -1 --format=%h HEAD))"
  # 커밋 안 된 변경이 있으면 작업 트리는 HEAD 와 다른 판이다
  if [ -z "$(git -C "$ROOT" status --porcelain --untracked-files=no)" ]; then N_EXACT=1; else N_EXACT=0; fi
fi
if [ -z "$OLD_TAG" ]; then
  OLD_TAG=$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v*' "$N_COMMIT" 2>/dev/null)
  if [ -n "$OLD_TAG" ] && [ "$N_EXACT" = 1 ] && [ "$(git -C "$ROOT" rev-parse "$OLD_TAG^{commit}")" = "$N_COMMIT" ]; then
    OLD_TAG=$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v*' "$OLD_TAG^" 2>/dev/null)
  fi
fi
[ -n "$OLD_TAG" ] && git -C "$ROOT" rev-parse --verify -q "$OLD_TAG^{commit}" >/dev/null \
  || { echo "N-1 태그를 찾을 수 없다(OLD_TAG=${OLD_TAG:-없음} · 얕은 클론?)"; exit 3; }
note "N = $N_LABEL · N-1 = $OLD_TAG($(git -C "$ROOT" log -1 --format=%h "$OLD_TAG"))"
# 모서리 알림(검토 L2): 같은 커밋에 태그가 둘이거나 가지에서 찍힌 태그는 describe 가 건너뛸 수 있다
TOP_MERGED=$(git -C "$ROOT" tag --merged "$N_COMMIT" --sort=-v:refname -l 'v*' 2>/dev/null | head -1)
[ -n "$TOP_MERGED" ] && [ "$TOP_MERGED" != "$OLD_TAG" ] && [ "$(git -C "$ROOT" rev-parse "$TOP_MERGED^{commit}")" != "$N_COMMIT" ] \
  && note "⚠ N 에 합쳐진 가장 높은 태그는 $TOP_MERGED — N-1 이 맞는지 확인(OLD_TAG 로 지정 가능)"
VER_FILE=$( [ -n "$N_REF" ] && git -C "$ROOT" show "$N_COMMIT:VERSION" 2>/dev/null || cat "$ROOT/VERSION" 2>/dev/null )
[ -n "$VER_FILE" ] && note "N 의 VERSION = $VER_FILE"

# N-1 뒤 새 마이그레이션 — 알리기만 한다(위 ★ · 검토 M2)
NEW_MIG=$(git -C "$ROOT" diff --name-only --diff-filter=A "$OLD_TAG" "$N_COMMIT" -- 'db/*.sql' 'db/**/*.sql' 2>/dev/null | grep -v 'grants\.sql$' || true)
[ -n "$NEW_MIG" ] && note "N-1 뒤 새 SQL 파일: $(echo $NEW_MIG) — 임시 DB(운영 스키마)에 이미 있는 것으로 보고 계속한다. 없으면 fresh 대조가 실패한다"

# 운영 nginx 의 자산 캐시 기간(검토 M4 · Q:F-4 U-4′) — 위 ★. 글자 `expires 7d` 와 변수 `expires $tbox_asset_expires`(map $arg_v) 둘 다
ASSET_CACHE=$(python3 -B "$ROOT/tests/upgrade/asset_cache.py" parse "$ROOT/deploy/sites/tbox-v2.conf" "$ROOT/deploy/sites/tbox-common.conf" "$ROOT/public/assets" 2>"$WORK/asset_cache.err") \
  || { echo "deploy/sites 에서 자산 캐시 기간을 읽지 못했다 — $(head -c 300 "$WORK/asset_cache.err")"; exit 3; }
asset_age() { python3 -B "$ROOT/tests/upgrade/asset_cache.py" age "$ASSET_CACHE" "$1" "${2:-/tbox/assets/app.js}"; }   # 그 ?v= 값(과 경로)에 nginx 가 낼 max-age(기대값)
MAXAGE=$(asset_age "")
[ -n "$MAXAGE" ] && [ "$MAXAGE" -gt 0 ] 2>/dev/null || { echo "자산 캐시 기본값을 계산하지 못했다"; exit 3; }
# 라우터(PHP PCRE)가 정규식을 읽을 수 있는지 먼저 본다 — 못 읽으면 preg_match 가 false 라 조용히 기본값이 된다
printf '%s' "$ASSET_CACHE" | php -r '$c = json_decode(stream_get_contents(STDIN), true); foreach ($c["rules"] as $r) { if (@preg_match("\x01" . $r["re"] . "\x01" . ($r["ci"] ? "i" : ""), "") === false) { fwrite(STDERR, $r["re"]); exit(1); } }' 2>"$WORK/asset_cache.err" \
  || { echo "map 정규식을 PHP 가 읽지 못했다: $(head -c 200 "$WORK/asset_cache.err")"; exit 3; }
note "운영 자산 캐시 — $(printf '%s' "$ASSET_CACHE" | python3 -c 'import json,sys; c=json.load(sys.stdin); print("%s · 기본 max-age=%d%s" % (c["from"], c["default"], "".join(" · v ~ %s%s → max-age=%d" % (r["re"], " (대소문자 무시)" if r["ci"] else "", r["age"]) for r in c["rules"])))')"
# 로컬용(8082 · tbox-v2-local.conf)도 같은 두 모양으로 읽어 대조만 한다 — 이 시험이 흉내 내는 것은 공개용(tbox-v2.conf)이다
if [ -f "$ROOT/deploy/sites/tbox-v2-local.conf" ]; then
  LOCAL_CACHE=$(python3 -B "$ROOT/tests/upgrade/asset_cache.py" parse "$ROOT/deploy/sites/tbox-v2-local.conf" "$ROOT/deploy/sites/tbox-common.conf" "$ROOT/public/assets" 2>"$WORK/asset_cache.err")
  if [ -z "$LOCAL_CACHE" ]; then note "⚠ tbox-v2-local.conf 의 자산 캐시를 읽지 못했다 — $(head -c 200 "$WORK/asset_cache.err") (공개용만 흉내 낸다)"
  elif ! python3 -B "$ROOT/tests/upgrade/asset_cache.py" same "$ASSET_CACHE" "$LOCAL_CACHE"; then note "⚠ tbox-v2-local.conf(8082) 의 자산 캐시가 공개용과 다르다 — 이 시험은 공개용(tbox-v2.conf)을 흉내 낸다"
  else note "로컬용(tbox-v2-local.conf) 자산 캐시도 같다"; fi
fi

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }

sub "두 트리를 임시 폴더에 (작업 트리는 건드리지 않는다)"
mkdir -p "$WORK/old/app" "$WORK/new/app" "$WORK/storage" "$WORK/log" "$WORK/profile"
git -C "$ROOT" archive "$OLD_TAG" | tar -C "$WORK/old/app" -xf - || { echo "N-1 트리를 꺼내지 못했다"; exit 3; }
if [ -n "$N_REF" ]; then
  git -C "$ROOT" archive "$N_COMMIT" | tar -C "$WORK/new/app" -xf - || { echo "N 트리를 꺼내지 못했다"; exit 3; }
else
  tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$WORK/new/app" -xf -
fi
PLAN_DIR="$(cd "$ROOT/.." && pwd)/community"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
for side in old new; do
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
      -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$WORK/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$WORK/log/audit.log#" \
      "${QCHECK_ENV_SRC:-$ROOT/.env}" > "$WORK/$side/app/.env"
  chmod 600 "$WORK/$side/app/.env"
  grep -q "^DB_NAME=$DB\$" "$WORK/$side/app/.env" || { echo "임시 .env($side) 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
  ln -s "$PLAN_DIR" "$WORK/$side/community" 2>/dev/null
  # 운영 nginx 흉내 — /tbox/assets/ 는 deploy/sites 의 캐시 머리 + 검증자, /assets/ 는 308, 그 밖은 index.php 한 입구
  cat > "$WORK/$side/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
// 이번 실행만의 표식(부르는 셸이 만든 난수 — 비밀 아님) — 모든 응답에. 준비 확인·브라우저가 대조해 남의 서버를 믿지 않는다
header('X-Upgrade-Run: ' . (string) getenv('UPGRADE_RUN_MARK'));
if (is_string($path) && strpos($path, '/assets/') === 0) {
    header('Location: /tbox' . $uri, true, 308);
    return true;
}
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $log = getenv('UPGRADE_ASSET_LOG');
    if ($log) { @file_put_contents($log, $uri . "\n", FILE_APPEND | LOCK_EX); }
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'woff2' => 'font/woff2', 'html' => 'text/html', 'webmanifest' => 'application/manifest+json'];
            $mtime = filemtime($file);
            $size = filesize($file);
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            // nginx `expires` 가 내는 두 머리(값은 부르는 셸이 deploy/sites 에서 읽어 넘긴다) + nginx 정적 파일의 검증자(ETag 는 "<mtime 16진>-<크기 16진>")
            // Q:F-4 U-4′ — `expires $tbox_asset_expires` 이면 nginx map $arg_v 와 같은 판정: 정규식을 적힌 차례로, 처음 맞는 것의 값 · 없으면 기본값.
            // $arg_v 는 nginx 처럼 쿼리 원문에서 이름이 v(대소문자 무시)이고 `=` 가 붙은 첫 인자 — %XX 는 풀지 않는다($_GET 은 풀고 마지막 값을 쓴다)
            // `location = /tbox/assets/<파일>` 은 그 파일만의 판정(nginx 는 정확 일치가 이긴다) · 값이 비면 nginx map 은 정규식을 보지 않고 default
            $cfg = json_decode((string) getenv('UPGRADE_ASSET_CACHE'), true);
            if (is_array($cfg) && isset($cfg['exact'][$path])) { $cfg = $cfg['exact'][$path]; }
            $age = is_array($cfg) ? (int) $cfg['default'] : 0;
            $argv = '';
            foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $part) {
                $eq = strpos($part, '=');
                if ($eq !== false && strcasecmp(substr($part, 0, $eq), 'v') === 0) { $argv = substr($part, $eq + 1); break; }
            }
            foreach (($argv !== '' && is_array($cfg) ? $cfg['rules'] : []) as $rule) {
                if (preg_match("\x01" . $rule['re'] . "\x01" . ($rule['ci'] ? 'i' : ''), $argv) === 1) { $age = (int) $rule['age']; break; }
            }
            header('Cache-Control: max-age=' . $age);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $age) . ' GMT');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header(sprintf('ETag: "%x-%x"', $mtime, $size));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
done
# 바뀌지 않은 자산은 운영처럼 파일 시각도 그대로 — N-1 에서 N 과 내용이 같은 자산은 N 의 시각으로(검토 M3)
same=0
while IFS= read -r -d '' f; do
  rel="${f#"$WORK/old/app/"}"
  if [ -f "$WORK/new/app/$rel" ] && cmp -s "$f" "$WORK/new/app/$rel"; then touch -r "$WORK/new/app/$rel" "$f"; same=$((same+1)); fi
done < <(find "$WORK/old/app/public/assets" -type f -print0)
note "두 판에서 내용이 같은 자산 ${same}개 — 파일 시각을 맞췄다"
note "N-1 트리 $(find "$WORK/old/app/public/assets" -type f | wc -l)개 자산 · N 트리 $(find "$WORK/new/app/public/assets" -type f | wc -l)개 자산"

sub "운영 DB 를 **읽어** 임시 DB 를 만든다"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
DB_CREATED=1
# 평문 덤프를 디스크에 두지 않는다 — 파이프로 곧바로 임시 DB 에(scripts/backup_db.sh 원칙 · 독립 검토 Q:F-4 A M-1)
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다 (pg_dump | psql)"; exit 3; }
set +o pipefail
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'
# restore.err 는 찍지 않고 세기만 한다 — psql 오류의 DETAIL·CONTEXT 줄에 운영 행 값이 섞일 수 있다(봇 검토 낮음)
n_restore_err=$(grep -c 'ERROR:' "$WORK/restore.err" 2>/dev/null); n_restore_err=${n_restore_err:-0}
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_pages" ] || { echo "임시 DB 로 옮기지 못했다 — 복원 오류 ${n_restore_err}줄(내용은 행 값이 섞일 수 있어 찍지 않는다)"; exit 3; }
note "임시 DB $DB — 페이지 ${n_pages}장 · 복원 오류 ${n_restore_err}줄(ON_ERROR_STOP=0 — 내용은 찍지 않는다)"

hdr_of() {   # $1 = 주소 · $2 = 머리 이름(소문자) — 응답 머리 값 하나(본문은 버린다)
  curl -s -o /dev/null -D - -A "$UA" "$1" | tr -d '\r' | awk -v h="$2" 'tolower($0) ~ "^" h ": " {sub(/^[^:]*: /, ""); print; exit}'
}
start_server() {   # $1 = old|new — 같은 포트
  : > "$WORK/assets.$1.log"
  ( cd "$WORK/$1/app" && UPGRADE_ASSET_LOG="$WORK/assets.$1.log" UPGRADE_ASSET_CACHE="$ASSET_CACHE" UPGRADE_RUN_MARK="$RUN_MARK" \
      exec php -S "127.0.0.1:$PORT" -t public router.php > "$WORK/server.$1.log" 2>&1 ) &
  SERVER_PID=$!
  # 준비 확인: 이 실행의 서버가 살아 있고(kill -0) · 미로그인 커뮤니티 창구가 401 이고 · 표식 머리가 이번 실행 것 — 셋 다(봇 검토 중간)
  local ready="" code="" mark="" hdr=""
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 1
    kill -0 "$SERVER_PID" 2>/dev/null || { ready="임시 서버가 죽었다(포트를 못 잡았나?)"; break; }
    hdr=$(curl -s -o /dev/null -D - -A "$UA" "$CBASE/api/pages" | tr -d '\r')
    code=$(printf '%s\n' "$hdr" | awk 'NR==1{print $2}')
    mark=$(printf '%s\n' "$hdr" | awk 'tolower($0) ~ /^x-upgrade-run: /{print $2; exit}')
    if [ "$code" = "401" ] && [ "$mark" = "$RUN_MARK" ]; then ready=ok; break; fi
    [ -n "$code" ] && ready="응답 ${code} · 표식 $([ "$mark" = "$RUN_MARK" ] && echo 맞음 || echo "다름(남의 서버?)")"
  done
  expect "임시 인스턴스($1)가 떴다 (이 실행의 서버 · 미로그인 커뮤니티 창구 401 · 표식 머리)" "ok" "${ready:-응답 없음}"
  [ "$ready" = "ok" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$WORK/server.$1.log"; exit 4; }
  # 캐시 머리 — 버전 없는 주소와 셸·부품이 내는 두 모양(파일 시각 10자리 · 내용 해시), 모양이 다른 값. 기대값은 asset_cache.py 가 따로 계산
  local v want cc url
  for v in "" 1727160000 0123456789ab "../x"; do
    want=$(asset_age "$v")
    url="$SITE/tbox/assets/app.js"; [ -n "$v" ] && url="$url?v=$v"
    cc=$(hdr_of "$url" cache-control)
    expect "  자산 캐시 머리가 운영과 같다(v=${v:-없음} → max-age=$want)" "max-age=$want" "$cc"
  done
  # 정확 일치 location(`location = /tbox/assets/<파일>` — 생성기 등)은 그 파일만의 기간
  local ex
  for ex in $(printf '%s' "$ASSET_CACHE" | python3 -c 'import json,sys; print(" ".join(sorted(json.load(sys.stdin).get("exact", {}))))'); do
    [ -f "$WORK/$1/app/public/assets/${ex#/tbox/assets/}" ] || continue
    want=$(asset_age "" "$ex")
    cc=$(hdr_of "$SITE$ex" cache-control)
    expect "  자산 캐시 머리가 운영과 같다(정확 일치 ${ex#/tbox/assets/} → max-age=$want)" "max-age=$want" "$cc"
  done
  # 머리 확인 curl 은 자산 기록에서 뺀다 — 남기면 「N-1 자산 1건 이상」이 저절로 참이 되고 캐시 적중이 줄어든다(봇 검토 낮음)
  : > "$WORK/assets.$1.log"
}

# ════════════════════════════════════════════════════════════════
hr "① N-1($OLD_TAG) 을 띄우고 로그인 ($WHO — 비밀은 표준입력으로만)"
start_server old

# 비밀번호를 보내기 직전에 한 번 더 — 이 실행의 서버가 살아 있는가(준비 확인 뒤 틈 · 독립 검토 참고 5)
kill -0 "$SERVER_PID" 2>/dev/null || { echo "  ✖ 임시 서버가 죽었다 — 로그인하지 않는다"; exit 4; }
PW=$(sudo -n awk -v u="$WHO" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
SEC=$(sudo -n awk -v u="$WHO" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "$PW" ] || { echo "  ✖ $WHO 비밀번호를 읽지 못했다(sudo) — 중단"; exit 3; }
LOGIN_PY='
import base64, hashlib, hmac, json, re, struct, sys, time, urllib.request
base, ua, who = sys.argv[1], sys.argv[2], sys.argv[3]
pw = sys.stdin.readline().rstrip("\n"); sec = sys.stdin.readline().rstrip("\n")
# http_proxy 류 환경 변수를 따르지 않는다 — 비밀번호는 127.0.0.1 의 임시 서버로만(독립 검토 참고 5)
urllib.request.install_opener(urllib.request.build_opener(urllib.request.ProxyHandler({})))
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
for attempt in range(3):
    body = {"username": who, "password": pw}
    if sec:
        body["otp"] = totp(sec, time.time())
    req = urllib.request.Request(base + "/api/login", data=json.dumps(body).encode(), method="POST",
                                 headers={"Content-Type": "application/json", "User-Agent": ua})
    cookie, data = "", {}
    try:
        resp = urllib.request.urlopen(req, timeout=10)
        data = json.loads(resp.read().decode())
        for h in resp.headers.get_all("Set-Cookie") or []:
            m = re.match(r"__Host-tbox_session=([^;]+)", h)
            if m:
                cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        raw = urllib.request.urlopen(urllib.request.Request(base + "/api/me", headers={"User-Agent": ua, "Cookie": "__Host-tbox_session=" + cookie}), timeout=10).read().decode()
        m = re.search(r"\"csrf_token\":\"([0-9a-f]+)\"", raw)
        print("SESSION=" + cookie)
        print("CSRF=" + (m.group(1) if m else ""))
        sys.exit(0)
    wait = 31 - int(time.time()) % 30
    print("  · 로그인 재시도 — 다음 코드 창까지 %d초" % wait, file=sys.stderr)
    time.sleep(wait)
sys.exit(1)
'
# TOTP 비밀이 없는 계정이면 둘째 줄 없이(파이썬은 빈 값으로 읽는다 — 검토 L1)
if [ -n "$SEC" ]; then LOGIN_OUT=$(py_secret PW SEC -- python3 -c "$LOGIN_PY" "$BASE" "$UA" "$WHO")
else LOGIN_OUT=$(py_secret PW -- python3 -c "$LOGIN_PY" "$BASE" "$UA" "$WHO"); fi
unset PW SEC
SESSION=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^SESSION=//p')
CSRF=$(printf '%s\n' "$LOGIN_OUT" | sed -n 's/^CSRF=//p')
unset LOGIN_OUT
[ -n "$SESSION" ] || { echo "  ✖ $WHO 로그인 실패 — 중단"; exit 4; }
expect "로그인 세션 · CSRF 토큰을 받았다" "yes" "$([ -n "$SESSION" ] && [ -n "$CSRF" ] && echo yes || echo no)"
# 세션은 두 트리가 같은 임시 DB 의 sessions 행을 보므로 N 으로 바꿔도 그대로 쓴다(운영 배포와 같다)
( umask 077; printf 'Cookie: __Host-tbox_session=%s\nX-CSRF-Token: %s\n' "$SESSION" "$CSRF" > "$WORK/write.hdr" )
unset CSRF

browser() {   # $1 = 단계 · $2 = 자산 기록 · 결과를 $WORK/<단계>.txt 에 · 표식은 브라우저가 흐름마다 처음 여는 화면 응답으로 대조한다
  kill -0 "$SERVER_PID" 2>/dev/null || { echo "  ✖ 임시 서버가 죽었다 — 중단"; exit 4; }
  py_secret SESSION -- python3 "$ROOT/tests/upgrade/browser_upgrade.py" "$SITE" "$UA" "$1" "$WORK/profile" "$FLOWS" "$2" "$RUN_MARK" \
    > "$WORK/$1.txt" 2>&1
  local rc=$?
  sed 's/^/    /' "$WORK/$1.txt"
  expect "  브라우저($1)가 끝까지 돌았다" "0" "$rc"
  # 서비스 워커가 있으면 HTTP 캐시 밖에서 옛 부품을 답할 수 있다 — 이 시험의 가정이 깨지므로 0 이어야(설계 3장)
  expect "  서비스 워커 등록 0 ($1 — 못 읽은 흐름(-1)도 실패)" "0" "$(awk '$1=="SW" && $3!="0"{n++} END{print n+0}' "$WORK/$1.txt")"
}

sub "캐시 채우기 — 프로필 브라우저로 N-1 화면을 돈다(단추는 누르지 않는다)"
browser fill "$WORK/assets.old.log"
n_old_assets=$(sort -u "$WORK/assets.old.log" | wc -l)
expect "N-1 자산을 서버에서 받아 캐시에 넣었다(1건 이상)" "yes" "$([ "$n_old_assets" -ge 1 ] && echo yes || echo no)"
note "N-1 에서 받은 자산 ${n_old_assets}종"
expect "캐시 채우기 — 흐름마다 자산을 받았고 로그인·오류 화면이 아니었다(FILLFAIL 0)" "0" "$(grep -c '^FILLFAIL ' "$WORK/fill.txt")"

# ════════════════════════════════════════════════════════════════
hr "② 같은 포트에서 N 으로 바꾼다 (새로고침·캐시 비우기 없이)"
stop_server
start_server new

hr "③ upgrade — N-1 캐시를 든 브라우저로 N"
browser upgrade "$WORK/assets.new.log"
n_hits=$(awk '$1=="CACHEHITS"{s+=$3} END{print s+0}' "$WORK/upgrade.txt")
expect "양성 대조 — 캐시가 답한 자산이 있다(서버를 거치지 않음, 1건 이상)" "yes" "$([ "$n_hits" -ge 1 ] && echo yes || echo no)"
note "캐시가 답한 자산 연인원 ${n_hits}건"
hr "④ fresh — 캐시 없는 새 문맥으로 N(대조)"
browser fresh "$WORK/assets.new.log"

# ════════════════════════════════════════════════════════════════
hr "판정 (설계 5·7장 — 사고와 같은 모양(LINK·REQ0)만 호환성 실패로 센다)"
flow_field() {   # $1 = 단계 결과 파일 · $2 = 흐름 · $3 = st|code|why — `FLOW <흐름> <pass|fail> <사유코드> <이유>` 에서
  awk -v f="$2" -v k="$3" '$1=="FLOW" && $2==f { if (k=="st") print $3; else if (k=="code") print $4; else { $1=$2=$3=$4=""; sub(/^ +/,""); print }; exit }' "$1"
}
ITEMS_PASS=0; ITEMS_FAIL=0; ITEMS_OTHER=0; ITEMS_FRESH=0; n_flows=0
for id in $(python3 -c 'import json,sys; print(" ".join(f["id"] for f in json.load(open(sys.argv[1]))["flows"]))' "$FLOWS"); do
  n_flows=$((n_flows+1))
  up=$(flow_field "$WORK/upgrade.txt" "$id" st); up_code=$(flow_field "$WORK/upgrade.txt" "$id" code); up_why=$(flow_field "$WORK/upgrade.txt" "$id" why)
  fr=$(flow_field "$WORK/fresh.txt" "$id" st);   fr_code=$(flow_field "$WORK/fresh.txt" "$id" code);   fr_why=$(flow_field "$WORK/fresh.txt" "$id" why)
  [ -n "$up" ] || { up="fail"; up_code="OTHER"; up_why="upgrade 결과에 이 흐름 줄이 없다(브라우저가 멈췄나)"; }
  [ -n "$fr" ] || { fr="fail"; fr_code="OTHER"; fr_why="fresh 결과에 이 흐름 줄이 없다(브라우저가 멈췄나)"; }
  cached=$(awk -v f="$id" '$1=="CACHED" && $2==f {print $3}' "$WORK/upgrade.txt" | head -6 | paste -sd, -)
  if [ "$fr" = "pass" ] && [ "$up" = "pass" ]; then
    ITEMS_PASS=$((ITEMS_PASS+1)); item="pass"; code="-"; reason="-"
  elif [ "$fr" != "pass" ]; then
    ITEMS_FRESH=$((ITEMS_FRESH+1)); item="skip"; code="FRESH"
    reason="새 문맥에서도 실패 — 코드 결함이거나 준비 오류라 upgrade 판정을 믿을 수 없다: [$fr_code] ${fr_why:-?} · upgrade [${up_code:--}] ${up_why:--}"
  else
    case "$up_code" in
      LINK|REQ0) ITEMS_FAIL=$((ITEMS_FAIL+1)); item="fail"; code="$up_code"
                 reason="${up_why:-?} · 캐시가 답한 부품: ${cached:-없음}" ;;
      *)         ITEMS_OTHER=$((ITEMS_OTHER+1)); item="skip"; code="OTHER"
                 reason="사고 모양이 아닌 끊김(호환성 실패로 세지 않는다 — 사람이 볼 것): ${up_why:-?} · 캐시가 답한 부품: ${cached:-없음}" ;;
    esac
  fi
  printf 'RESULT_ITEM %s %s %s fresh=%s upgrade=%s reason=%s\n' "$id" "$item" "$code" "$fr" "$up" "$reason"
done
note "동선 ${n_flows}개 — 통과 ${ITEMS_PASS} · 호환성 실패(LINK·REQ0) ${ITEMS_FAIL} · 판단 불가 $((ITEMS_OTHER+ITEMS_FRESH))(OTHER ${ITEMS_OTHER} · FRESH ${ITEMS_FRESH})"
expect "업그레이드 뒤 끊긴 동선 0 (LINK·REQ0 · fresh=pass — 배포 사이 호환성)" "0" "$ITEMS_FAIL"
expect "사고 모양이 아닌 끊김 0 (OTHER — 호환성 실패로 세지 않지만 시험은 실패)" "0" "$ITEMS_OTHER"
expect "새 문맥 대조가 모두 통과 (FRESH — 코드 결함·준비 오류면 판정을 믿을 수 없다)" "0" "$ITEMS_FRESH"

sub "만든 페이지는 앱 창구로 지운다"
for id in $(cat "$WORK/upgrade.txt" "$WORK/fresh.txt" "$WORK/fill.txt" | sed -n 's/^CREATED=//p' | sort -u); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -X DELETE -H 'Expect:' -H @"$WORK/write.hdr" "$CBASE/api/pages/$id")
  expect "DELETE /community/api/pages/$id" "yes" "$(case "$code" in 200|204) echo yes;; *) echo "no($code)";; esac)"
done
expect "인스턴스 로그에 uncaught 0" "0" "$(cat "$WORK"/server.*.log 2>/dev/null | grep -ci 'uncaught' | tr -d ' ')"
unset SESSION

hr "결과"
note "임시 DB($DB)·임시 폴더(두 트리·임시 .env 두 벌·브라우저 프로필)·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
# 끝 줄(DESIGN N-4) — 확인 줄 합계. 바로 위 한국어 합계 줄과 같은 수(results.py 가 둘을 대조한다)
printf 'RESULT pass=%d fail=%d skip=%d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
