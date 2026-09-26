#!/usr/bin/env bash
# tests/f70.sh — F-70 「휴대폰으로 경보 받기(설치형 웹 앱 + 웹 푸시)」 수락 기준 (지시서 docs/f70-push-alerts-directive.md G 표,
#                구현 기록 docs/f70-push-alerts-implementation.md, ARCH 5.32, SECURITY R-99)
#
#   T1  RFC 벡터 — tests/f70_webpush_vectors.php (RFC 8291 바이트 단위 · RFC 8292 서명 검증 · DER↔r‖s)
#   T2  받기 등록 검사(claude_admin01 · 임시 인스턴스) — 재인증 없이 403 next:reauth · 허용 밖 호스트·http·:8443·IP·user@host·1,025자·
#       p256dh 64바이트/곡선 밖 점·auth 15바이트·허용 밖 키 → 400 · 4번째 기기 400 · 같은 주소 다시 200 · 남의 받기 해지 404 ·
#       claude_manager01 403 · 목록 응답·감사 detail 에 주소·키 원문 0 · 시험 알림 202 → 10분 안 다시 429
#   T3  웹은 밖으로 안 나간다 — 새 핸들러에 `Fetch::` 0(grep) · 시험 알림 요청 동안 웹 서버 프로세스의 443 연결 0(ss 표본) ·
#       서비스 워커·매니페스트의 보안 머리 = 다른 PHP 응답 · Content-Type·Cache-Control
#   T4  Fetch::pushSend 거부 — PUSH_HOSTS 밖·http·포트(제품 코드 그대로 — DNS 전에 끝) · 사설 IP 로 풀리는 이름 · 3xx(따라가지 않음) ·
#       응답 본문 8KB 초과 끊음 · 보내는 본문 4KB 초과 · 허용 밖 머리 · 시간 초과(가짜 푸시 서버 — 시험 사본)
#   T5  배치 가르기(임시 alerts.log) — 처음 켤 때 옛 줄 0 · human·system·origin 없는 줄 → 즉시 · claude-* → 요약 칸 · AL-9 → 요약(DIGEST_RULES) ·
#       시간당 11번째 → 다음 정시 「외 n건」 · 18시 요약 한 번 · 0건이면 안 보냄 · **dry-run 전후 상태 파일 sha256 불변** · 머리(TTL·Urgency·
#       Content-Encoding·Authorization) · VAPID JWT 검증 · 암호를 풀어 본 내용(규칙·이름·출처 — 계정명·IP 0) · 시험 알림 대기표
#   T6  받는 사람 다시 확인 — Manager 의 받기 → not_admin 해지 · 비활성 Admin → not_admin · 410 → gone · 429/5xx 3회 뒤 버림 · 413 기록만
#       · AL-22(alerts.php 사본 — fail_since 24시간, origin system, 중복 억제) → 배치가 그 줄도 보냄
#   T7  비밀 격리(시험으로 할 수 있는 만큼) — 키 파일 0600 · 감사·로그·출력·상태 파일에 주소·키·auth 원문 0 · 배치 감사 detail = host·status·rule·kind
#       · DB 역할 권한(시험 역할로 — users 세 칸 밖·audit_logs SELECT·받기 DELETE 거부 · 문장 15s) · tbox_app 받기 DELETE 거부
#       · 실제 OS 계정 경계(tbox-push·tbox-php)는 **미검증**(운영 반영 스크립트의 확인 절이 본다)
#   T8  앱·서비스 워커 — 매니페스트(아이콘 PNG 192·512) · 워커 처리기 둘뿐(fetch 0) · 고정 주소 /tbox/admin · 제목 모양(빈 문서에 흉내 self)
#       · 헤드리스 /tbox/alerts-app 라이트·다크·390px 스크린샷(직접 본다) · 워커 등록/해제 · 콘솔 오류 0 · 인라인 style 0
#       ★ 브라우저에서 「알림 받기 켜기」는 누르지 않는다 — 크로미움의 구독은 구글 FCM 에 붙는다(바깥 푸시 서비스 요청 금지)
#   T9  실제 기기(관문 ③) — **대기**(사용자)
#   T11 저장소 쪽만 반영, 서버 쪽은 아직 — 마이그레이션 전 DB 사본 · 키 파일 없음 · push.conf 없음: /tbox/admin 200 · /tbox/alerts-app 200(「준비 안 됨」
#       안내) · 창구 key·subscriptions GET/POST·test·DELETE 503(500 아님) · alerts.php 오류 없이 · 화면(켜기·시험 막힘 · 창구 호출 0 · 세 가지 스크린샷)
#   ⑨  반영 스크립트 둘 — bash -n · 해시 고정값 = 이 트리 · 허용 목록이 이 판의 바뀐 파일을 다 덮는가 ·
#       호스트 스크립트 사본(경로만 임시): setfacl 없음 → exit 1 + 안내 · 가짜 setfacl 로 dry-run 통과 · 본 실행 · 이미 됨 · 반쪽 상태·
#       알려진 판 아님 → 1 · 확인 실패 → 되돌림(4)
#   T10 정적 — security_lint 0 · php -l · bash -n · esprima(JS) · wiki_check 0 · 지시서 본문 무변경. 회귀 시험(f69·stage26·help_button·f68 …)은 따로
#
# ★ 운영에 쓰지 않는다 · 바깥 푸시 서비스로 요청 0.
#   · 임시 DB 둘(tbox_f70_test = 운영 pg_dump **읽기** 파이프 · tbox_f70_host = 구조만 pg_dump -s) · 임시 인스턴스 127.0.0.1:8105 ·
#     시험 전용 DB 역할 둘(tbox_push_t<pid>·tbox_push_h<pid> — 운영 이름 tbox_push 는 만들지 않는다, 끝에 지운다, 역할 설정은
#     `IN DATABASE <임시>` 로만) · 가짜 푸시 서버 127.0.0.1:18443(자체 서명 TLS — 이름 셋의 SAN)
#   · 나란히 도는 복제본끼리 가르는 손잡이: 임시 DB 이름 앞 `tbox_` = QTEST_DB_PREFIX(예 tbox_d1t_) · 포트 F70_PORT·F70_RPORT·F70_PUSH_PORT ·
#     임시 .env 원본 QF2_ENV_SRC(복제본에는 .env 가 없다 — mktemp 폴더 0600 사본)
#   · 운영에 F-70 이 반영된 뒤로는 운영 사본에 받기 표와 **실제 받기**(운영 휴대폰 주소)가 딸려 온다 — ① 에서 임시 DB 에서만 F-70 이 더한 것을
#     걷어 「마이그레이션 전」을 시험이 직접 만들고, 끝에 가짜 푸시 서버가 받은 요청이 모두 시험 주소(/f70/…)인지 본다(디버깅 묶음 T)
#   · 보내는 배치·pushSend 는 **시험 사본에서만** Fetch.php 네 곳을 바꾼다(P-7 가져오기 실측과 같은 방식 — 제품 코드에 시험 구멍 0):
#       PORT 443 → 18443 · rebuild() 가 포트를 붙임 · resolve(): fcm·apple → 127.0.0.1, mozilla → 10.20.30.40(사설 — T4) ·
#       PRIVATE_CIDRS 에서 127.0.0.0/8 만 뺌. 그리고 alert_push.php 의 `$now = time();` 한 줄 → 환경 변수 F70_NOW(시각 흉내).
#     curl 은 `-d curl.cainfo=<가짜 인증서>` 만 믿는다 — 진짜 푸시 회사 인증서는 통과할 수 없다(세 겹: 이름 풀이 · 포트 · 인증서).
#   · 인스턴스 사본의 WebPush::PUBLIC_KEY_FILE 한 줄만 임시 공개 키 경로로 바꾼다.
#   · 시험 키(VAPID·기기 p256dh·auth)는 **실행할 때 새로 만든다**(저장소에 키 파일 0). RFC 공개 예시만 T1 파일 안에 있다.
# ★ 비밀은 명령 인자에 싣지 않는다 — 로그인·재인증은 표준입력(tests/lib/secrets.sh), 쿠키는 0600 머리 파일, SQL 은 psql 표준입력.
# ★ 검토 계정 claude_admin01 · claude_manager01 만. UA tbox-review/<날짜>.
# 준비물: sudo -n(postgres psql·pg_dump·createdb·dropdb · /etc/tbox-v2 비밀 읽기) · php · python3(+playwright — 없으면 T8 화면 SKIP) · openssl · curl · ss
# 걸리는 시간: 약 4~6분(TOTP 창 대기 · 시간 초과 시험 10초 · 브라우저). 임시 파일은 ${TMPDIR:-/tmp} 아래 mktemp 폴더 하나(끝나면 지움).
# 사용: bash tests/f70.sh [스크린샷 폴더]      나가는 값: 0 통과 · 1 실패 · 3 준비물 없음 · 4 준비 실패
set -u
export PYTHONDONTWRITEBYTECODE=1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시작하지 않는다 (R-73)"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시작하지 않는다 (R-62)"; exit 3; }

PORT="${F70_PORT:-8105}"
PPORT="${F70_PUSH_PORT:-18443}"
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"            # 운영 — **읽기만**
# 임시 DB 접두 — 나란히 도는 복제본끼리 이름을 가른다(예 QTEST_DB_PREFIX=tbox_d1t_). tbox_ 로 시작 · 소문자·숫자·밑줄 · _ 로 끝
DBP="${QTEST_DB_PREFIX:-tbox_}"
[[ "$DBP" =~ ^tbox_([a-z0-9]+_)*$ ]] || { echo "QTEST_DB_PREFIX 모양이 아니다(tbox_…_) — 시작하지 않는다"; exit 3; }
DB="${DBP}f70_test"          # 임시 — 인스턴스·배치
DBH="${DBP}f70_host"         # 임시(구조만) — 호스트 반영 스크립트 사본
DBR="${DBP}f70_repo"         # 임시 — 「저장소 쪽만 반영, 서버 쪽은 아직」(T11 — 마이그레이션 전의 사본)
RPORT="${F70_RPORT:-8106}"   # T11 두 번째 인스턴스
for p in "$PORT" "$PPORT" "$RPORT"; do case "$p" in 80|443|8082|8081|"") echo "운영 포트($p)·빈 포트는 쓰지 않는다"; exit 3 ;; esac; done
[ "$(printf '%s\n' "$PORT" "$PPORT" "$RPORT" | sort -u | wc -l | tr -d ' ')" = 3 ] || { echo "포트 셋이 서로 달라야 한다"; exit 3; }
ENV_SRC="${QF2_ENV_SRC:-$ROOT/.env}"   # 임시 .env 를 만들 원본(복제본은 mktemp 사본)
TROLE="tbox_push_t$$"       # 시험 전용 역할(배치) — 끝에 지운다
HROLE="tbox_push_h$$"       # 시험 전용 역할(호스트 스크립트 사본) — 끝에 지운다
ADMIN="claude_admin01"
MGR="claude_manager01"
OUT="${1:-$HOME/.claude/ui-shots/f70}"
TAG="$(date +%H%M%S)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/f70t.XXXXXX")"; chmod 700 "$WORK"
SH_DIR="$ROOT/review-scripts"; [ -d "$SH_DIR" ] || SH_DIR="$(dirname "$ROOT")/scripts"
HOST_SH="$SH_DIR/apply-f70-host-2026-09-24.sh"
REPO_SH="$SH_DIR/apply-f70-repo-2026-09-24.sh"
# F-70 이 바꾼 파일(경로 인자로 좁힘). 검토 복제본(review-scripts/ 있음)은 기준 31c785d 부터 이 판까지 ·
#   반영된 트리(운영·모의 반영)는 **HEAD 위 커밋 전 변경**(방금 얹은 패치 — 바뀐 파일 + 추적 안 된 새 파일)만 본다.
#   31c785d 뒤에 들어온 다른 단계의 커밋을 F-70 변경으로 세지 않게(2026-09-25 운영 b8a4a33 모의 반영에서 찾음).
#   반영 뒤 커밋까지 끝난 트리에서는 빈 목록 → 그 대조는 건너뜀으로 적는다. 반영 스크립트의 DIRTY 예외 둘은 뺀다.
if [ -d "$ROOT/review-scripts" ]; then F70_SCOPE="복제본 — 31c785d 부터"; else F70_SCOPE="반영 트리 — HEAD 위 커밋 전 변경"; fi
f70_changed() {
  if [ -d "$ROOT/review-scripts" ]; then
    git -C "$ROOT" diff --name-only 31c785d -- "$@" 2>/dev/null
  else
    { git -C "$ROOT" diff --name-only HEAD -- "$@"; git -C "$ROOT" ls-files --others --exclude-standard -- "$@"; } 2>/dev/null \
      | grep -vE '^(api-test\.txt|jar(/.*)?)$' | sort -u
  fi
}
INST="$WORK/inst"; PUSHAPP="$WORK/pushapp"
SERVER_PID=""; PSRV_PID=""
PASS=0; FAIL=0; SKIP=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
contains() {
  case "$3" in
    *"$2"*) printf '  ✔ %s  (「%s」 포함)\n' "$1" "$2"; PASS=$((PASS+1)) ;;
    *)      printf '  ✖ %s  (「%s」 없음 — 실제: %s)\n' "$1" "$2" "$(printf '%s' "$3" | head -c 300)"; FAIL=$((FAIL+1)) ;;
  esac
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }
sha()  { sha256sum "$1" 2>/dev/null | cut -d' ' -f1; }

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
  [ -n "$PSRV_PID" ] && kill "$PSRV_PID" 2>/dev/null
  [ -n "${RSERVER_PID:-}" ] && kill "$RSERVER_PID" 2>/dev/null
  sleep 0.5
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  sudo -n -u postgres dropdb --if-exists "$DBH" >/dev/null 2>&1
  sudo -n -u postgres dropdb --if-exists "$DBR" >/dev/null 2>&1
  printf 'DROP ROLE IF EXISTS %s;\nDROP ROLE IF EXISTS %s;\n' "$TROLE" "$HROLE" | sudo -n -u postgres psql -X -q -d postgres -f - >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

# SQL 은 표준입력으로(R15 — sudo 로 부른 명령의 인자는 auth.log COMMAND= 에 남는다)
psqlq()  { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DB" -t -A -f - 2>/dev/null; }
psqlh()  { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d "$DBH" -t -A -f - 2>/dev/null; }
psqlpg() { printf '%s\n' "$1" | sudo -n -u postgres psql -X -d postgres -t -A -v ON_ERROR_STOP=1 -f - 2>/dev/null; }
# 시험 역할(tbox_push_t…)로 붙는 psql — 비밀번호는 0600 pgpass 파일(PGPASSFILE) · SQL 은 표준입력
psqlt()  { printf '%s\n' "$1" | PGPASSFILE="$WORK/push.pgpass" psql -X -h 127.0.0.1 -p 5432 -U "$TROLE" -d "$DB" -t -A -f - 2>&1; }
mark()   { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }
code_of() { curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$@"; }
jq_py() {   # 표준입력 = JSON, $1 = 파이썬 식 (d)
  python3 -c '
import json,sys
try:
    d = json.load(sys.stdin)
except Exception:
    print(""); sys.exit(0)
try:
    v = eval(sys.argv[1])
except Exception:
    v = ""
print("" if v is None else v)
' "$1" 2>/dev/null
}

# ════════════════════════════════════════════════════════════════
hr "F-70 — 준비 (운영에는 쓰지 않는다 · 바깥 푸시 서비스 요청 0)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시작하지 않는다 (SECURITY R-80)"; exit 3; }
for b in php python3 openssl curl ss; do command -v "$b" >/dev/null 2>&1 || { echo "$b 가 없다"; exit 3; }; done
sudo -n -u postgres psql -X -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 || { echo "psql(postgres) 을 쓸 수 없다"; exit 3; }
review_account "$ADMIN" && review_account "$MGR" || { echo "검토 계정 판정이 어긋난다 — 중단"; exit 3; }
for p in "$PORT" "$PPORT" "$RPORT"; do ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p " && { echo "포트 $p 이 이미 쓰이고 있다"; exit 3; }; done
[ -f "$ENV_SRC" ] || { echo "$ENV_SRC 가 없다 — 임시 .env 원본이 없다(복제본은 QF2_ENV_SRC)"; exit 3; }
note "운영 최근 10분 비-검토 actor 행: $(printf '%s\n' "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND $REVIEW_NOT_SQL" | sudo -n -u postgres psql -X -d "$SRC_DB" -t -A -f - 2>/dev/null) (참고 — 이 시험은 운영 DB 에 쓰지 않는다)"

sub "① 임시 DB — 운영을 **읽어** 파이프(덤프 파일 0) · 구조만 한 벌 더(⑨)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres dropdb --if-exists "$DBH" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 4; }
sudo -n -u postgres createdb -O tbox_owner "$DBH" >/dev/null 2>&1 || { echo "임시 DB(⑨)를 만들 수 없다"; exit 4; }
set -o pipefail
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -X -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err" \
  || { set +o pipefail; echo "운영 DB 를 읽어 임시 DB 로 옮기지 못했다"; exit 4; }
sudo -n -u postgres pg_dump -s -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -X -q -d "$DBH" -v ON_ERROR_STOP=0 >/dev/null 2>>"$WORK/restore.err" \
  || { set +o pipefail; echo "구조만 옮기지 못했다(⑨)"; exit 4; }
set +o pipefail
n_users=$(psqlq "SELECT count(*) FROM public.users")
[ -n "$n_users" ] && [ "$n_users" -gt 0 ] 2>/dev/null || { echo "임시 DB 로 옮기지 못했다"; exit 4; }
note "임시 DB $DB — 계정 ${n_users}명 · $DBH — 구조만"
# 「마이그레이션 전」을 시험이 직접 만든다(디버깅 묶음 T, 2026-09-26 기능 점검 — B1 보낸 수 7 · T11 · AL-22 실패의 원인).
#   운영에 F-70 이 반영된 뒤로는 운영 사본에 받기 표 둘 · **실제 받기**(운영 휴대폰 주소) · 대기표 줄 · 운영 역할 tbox_push 권한이 딸려 온다.
#   이 시험은 ② 마이그레이션 · T5 보낸 수 · T11(마이그레이션 전 사본)이 모두 「표 없음」에서 시작한다고 전제하므로, **임시 DB 에서만**
#   마이그레이션이 더한 것(표 둘 — 받기·대기표 줄과 그 권한이 함께 사라진다 · tbox_push 의 users·audit_logs·순서·스키마 권한)을 걷는다.
#   걷은 받기 주소는 읽지도 적지도 않는다(개수만). 그 주소로 요청이 나가지 않았는지는 끝(「운영 받기로 간 요청 0」)에서 가짜 푸시 서버 기록으로 본다.
case "$DB" in "$SRC_DB"|*[!a-z0-9_]*) echo "임시 DB 이름이 이상하다($DB) — 중단"; exit 4 ;; esac
F70_PRE=$(psqlq "SELECT CASE WHEN to_regclass('public.alert_push_subscriptions') IS NULL THEN 'none' ELSE
                   (SELECT count(*) FROM alert_push_subscriptions)::text || '|' || (SELECT count(*) FROM alert_push_subscriptions WHERE revoked_at IS NULL)::text
                   || '|' || coalesce((SELECT count(*) FROM alert_push_outbox)::text, '0') END")
F70_PUSH_PRIVS_SQL="SELECT (SELECT count(*) FROM information_schema.role_table_grants WHERE grantee = 'tbox_push')
                         + (SELECT count(*) FROM information_schema.column_privileges WHERE grantee = 'tbox_push')
                         + (SELECT count(*) FROM information_schema.usage_privileges WHERE grantee = 'tbox_push')"
if [ "$F70_PRE" = "none" ]; then
  note "운영 사본에 F-70 표 없음(운영 반영 전) — 그대로 「마이그레이션 전」"
else
  note "운영 사본에 F-70 이 딸려 왔다 — 받기 ${F70_PRE%%|*}줄(해지 안 된 것 $(printf '%s' "$F70_PRE" | cut -d'|' -f2)) · 대기표 ${F70_PRE##*|}줄 → 임시 DB 에서만 걷는다"
  psqlq "DROP TABLE IF EXISTS public.alert_push_outbox, public.alert_push_subscriptions;
         DO \$\$ BEGIN
           IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
             REVOKE ALL ON public.users, public.audit_logs FROM tbox_push;
             REVOKE ALL ON SEQUENCE public.audit_logs_id_seq FROM tbox_push;
             REVOKE ALL ON SCHEMA public FROM tbox_push;
           END IF;
         END \$\$;" >/dev/null
fi
expect "임시 DB 가 「마이그레이션 전」 — 받기·대기표 표 0 · 운영 역할 tbox_push 권한 0" "0|0" \
  "$(psqlq "SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename IN ('alert_push_subscriptions', 'alert_push_outbox')")|$(psqlq "$F70_PUSH_PRIVS_SQL")"
# T11 용 — 마이그레이션 **전**의 사본(표 없음). 이 순간 $DB 에 붙은 연결이 없어야 틀로 쓸 수 있다
sudo -n -u postgres dropdb --if-exists "$DBR" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner -T "$DB" "$DBR" >/dev/null 2>&1 || { echo "임시 DB(T11)를 만들 수 없다"; exit 4; }
ADMIN_ID=$(psqlq "SELECT id FROM users WHERE username = '$ADMIN'")
MGR_ID=$(psqlq "SELECT id FROM users WHERE username = '$MGR'")
[ -n "$ADMIN_ID" ] && [ -n "$MGR_ID" ] || { echo "임시 DB 에 검토 계정이 없다"; exit 4; }

sub "② 마이그레이션 — 역할 이름만 시험 전용으로 · 역할 설정은 IN DATABASE $DB (전역 ALTER ROLE 0)"
MIG="$ROOT/db/migrations/2026-09-30_f70_push.sql"
mk_mig() {  # $1 역할 $2 DB
  sed -e "s/tbox_push/$1/g" -e "s/^ALTER ROLE $1 SET /ALTER ROLE $1 IN DATABASE $2 SET /" "$MIG"
}
mk_mig "$TROLE" "$DB" > "$WORK/mig-t.sql"
expect "사본 마이그레이션 — 역할 설정 두 줄이 IN DATABASE $DB · 운영 이름 tbox_push 0" "2|0" \
  "$(grep -c "^ALTER ROLE $TROLE IN DATABASE $DB SET" "$WORK/mig-t.sql")|$(grep -cw 'tbox_push' "$WORK/mig-t.sql")"
sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f - < "$WORK/mig-t.sql" >/dev/null 2>"$WORK/mig.err"
expect "마이그레이션 적용 (종료 코드)" "0" "$?"
[ -s "$WORK/mig.err" ] && sed 's/^/    | /' "$WORK/mig.err" | head -5
sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f - < "$WORK/mig-t.sql" >/dev/null 2>&1
expect "마이그레이션 두 번째 — 멱등(종료 코드 0)" "0" "$?"
expect "표 둘 · 소유자 tbox_owner" "alert_push_outbox|tbox_owner alert_push_subscriptions|tbox_owner" \
  "$(psqlq "SELECT string_agg(tablename || '|' || tableowner, ' ' ORDER BY tablename) FROM pg_tables WHERE tablename LIKE 'alert_push_%'")"
expect "시험 역할은 NOLOGIN 으로 생겼다(비밀번호 없음)" "f|(없음)" "$(psqlpg "SELECT CASE WHEN rolcanlogin THEN 't' ELSE 'f' END || '|' || coalesce(rolpassword, '(없음)') FROM pg_authid WHERE rolname = '$TROLE'")"
expect "역할 시간 제한 — $DB 에만(전역 0)" "idle_in_transaction_session_timeout=60s,statement_timeout=15s|(none)" \
  "$(psqlpg "SELECT string_agg(x, ',' ORDER BY x) FROM pg_db_role_setting s JOIN pg_roles r ON r.oid = s.setrole JOIN pg_database d ON d.oid = s.setdatabase, unnest(s.setconfig) x WHERE r.rolname = '$TROLE' AND d.datname = '$DB'")|$(psqlpg "SELECT coalesce(array_to_string(rolconfig, ','), '(none)') FROM pg_roles WHERE rolname = '$TROLE'")"
privs() {  # 역할 $1 의 F-70 관련 표·열 권한 한 줄(정렬)
  psqlq "SELECT string_agg(x, ' ' ORDER BY x) FROM (
           SELECT table_name || ':' || privilege_type AS x FROM information_schema.role_table_grants
            WHERE grantee = '$1' AND table_name IN ('alert_push_subscriptions','alert_push_outbox','users','audit_logs','files','sessions')
           UNION ALL
           SELECT table_name || '.' || column_name || ':' || privilege_type FROM information_schema.column_privileges
            WHERE grantee = '$1' AND table_name IN ('alert_push_subscriptions','alert_push_outbox','users')
              AND (table_name, privilege_type) NOT IN (SELECT table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = '$1')) q"
}
P_PUSH=$(privs "$TROLE"); P_APP=$(privs tbox_app)
note "시험 역할 권한: $P_PUSH"
for w in "alert_push_subscriptions:SELECT" "alert_push_subscriptions.last_ok_at:UPDATE" "alert_push_subscriptions.fail_count:UPDATE" \
         "alert_push_subscriptions.fail_since:UPDATE" "alert_push_subscriptions.revoked_at:UPDATE" "alert_push_subscriptions.revoked_reason:UPDATE" \
         "alert_push_outbox:SELECT" "alert_push_outbox.sent_at:UPDATE" "users.id:SELECT" "users.role:SELECT" "users.is_active:SELECT" "audit_logs:INSERT"; do
  case " $P_PUSH " in *" $w "*) PASS=$((PASS+1)) ;; *) printf '  ✖ 시험 역할에 %s 없음\n' "$w"; FAIL=$((FAIL+1)) ;; esac
done
expect "시험 역할 — 그 밖 0 (DELETE·INSERT 받기·users 다른 칸·audit SELECT·files·sessions)" "0" \
  "$(printf '%s\n' $P_PUSH | grep -cE 'DELETE|^alert_push_subscriptions(\.[a-z_]+)?:INSERT|^users\.(password_hash|mfa_secret|username)|^audit_logs:SELECT|^files|^sessions|^alert_push_subscriptions\.endpoint:UPDATE')"
expect "tbox_app — 받기 SELECT · INSERT(모양 칸) · UPDATE(해지 두 칸) · 대기표 SELECT · INSERT(user_id) · DELETE 0" "yes|0" \
  "$(for w in alert_push_subscriptions:SELECT alert_push_subscriptions.endpoint:INSERT alert_push_subscriptions.revoked_at:UPDATE alert_push_subscriptions.revoked_reason:UPDATE alert_push_outbox:SELECT alert_push_outbox.user_id:INSERT; do case " $P_APP " in *" $w "*) : ;; *) echo "no($w)"; exit 0 ;; esac; done; echo yes)|$(printf '%s\n' $P_APP | grep -E '^alert_push' | grep -cE 'DELETE|last_ok_at:UPDATE|fail_count:UPDATE|sent_at:UPDATE')"
sub "db/grants.sql 의 F-70 절(DO 블록)만 떼어 같은 역할로 — 같은 권한이 된다"
awk '/^-- ─── F-70 휴대폰 경보/{f=1} f' "$ROOT/db/grants.sql" | sed "s/tbox_push/$TROLE/g" > "$WORK/grants-f70.sql"
expect "떼어 낸 절에 DB 이름 글자 0(current_database 로)" "0" "$(grep -c 'tbox_re' "$WORK/grants-f70.sql")"
sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -f - < "$WORK/grants-f70.sql" >/dev/null 2>"$WORK/grants.err"
expect "grants.sql F-70 절 적용 (종료 코드)" "0" "$?"
expect "적용 뒤 권한이 마이그레이션과 같다(시험 역할 · tbox_app)" "$P_PUSH|$P_APP" "$(privs "$TROLE")|$(privs tbox_app)"
# 시험 역할 로그인 — 비밀번호는 표준입력으로만(apply-f70-host 와 같은 모양)
TPW=$(openssl rand -hex 24)
printf "ALTER ROLE %s LOGIN PASSWORD '%s';\n" "$TROLE" "$TPW" | sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d postgres -f - >/dev/null 2>&1
( umask 077; printf '%s:%s:%s:%s:%s\n' 127.0.0.1 5432 "$DB" "$TROLE" "$TPW" > "$WORK/push.pgpass" )
unset TPW
expect "시험 역할로 붙는다 · 문장 15s · 유휴 60s" "$TROLE|15s|1min" "$(psqlt "SELECT current_user || '|' || current_setting('statement_timeout') || '|' || current_setting('idle_in_transaction_session_timeout')")"
contains "시험 역할 — users.password_hash 읽기 거부" "permission denied" "$(psqlt "SELECT password_hash FROM users LIMIT 1")"
contains "시험 역할 — audit_logs 읽기 거부(쓰기만)" "permission denied" "$(psqlt "SELECT id FROM audit_logs LIMIT 1")"
contains "시험 역할 — 받기 DELETE 거부" "permission denied" "$(psqlt "DELETE FROM alert_push_subscriptions")"
contains "시험 역할 — 받기 endpoint UPDATE 거부" "permission denied" "$(psqlt "UPDATE alert_push_subscriptions SET endpoint = endpoint")"
contains "시험 역할 — files 읽기 거부" "permission denied" "$(psqlt "SELECT id FROM files LIMIT 1")"
expect "시험 역할 — users (id, role, is_active) 는 읽힌다" "Admin|t" "$(psqlt "SELECT role || '|' || CASE WHEN is_active THEN 't' ELSE 'f' END FROM users WHERE id = $ADMIN_ID")"

sub "③ 저장소 사본 · 임시 인스턴스 (포트 $PORT)"
mkdir -p "$INST/app" "$INST/storage" "$INST/quarantine" "$INST/tmp" "$INST/log" "$WORK/etc" "$WORK/state" "$WORK/pushlog" "$WORK/ua" "$WORK/push" "$WORK/hdr"
chmod 700 "$WORK/hdr" "$WORK/etc"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(WORK 0700 · 사본 0600 로 만듦 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
( umask 077; sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" "$ENV_SRC" > "$INST/app/.env" )
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 실패"; exit 4; }
touch "$INST/log/audit.log" "$INST/log/alerts.log"
# 사본 한 곳 — 공개 키 파일 경로(코드 상수)만 임시로
sed -i "s#public const PUBLIC_KEY_FILE = '/etc/tbox-v2/vapid-public.txt';#public const PUBLIC_KEY_FILE = '$WORK/etc/vapid-public.txt';#" "$INST/app/src/WebPush.php"
expect "인스턴스 사본 — 공개 키 경로 한 줄만 바뀜" "1" "$(diff "$ROOT/src/WebPush.php" "$INST/app/src/WebPush.php" | grep -c '^>')"
cat > "$INST/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'webmanifest' => 'application/manifest+json', 'html' => 'text/html'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
READY=""
for _ in 1 2 3 4 5 6 7 8 9 10; do sleep 1; READY=$(code_of "$BASE/api/me"); [ "$READY" = "401" ] && break; done
expect "임시 인스턴스가 떴다 (미로그인 /api/me 401)" "401" "$READY"
[ "$READY" = "401" ] || { tail -5 "$INST/server.log"; exit 4; }

sub "④ 시험 키 — vapid_keygen.php 로 임시 폴더에(저장소 파일 0) · 기기 키 6벌(openssl, 실행 때)"
KG_OUT=$(php "$INST/app/scripts/vapid_keygen.php" --private-out "$WORK/etc/vapid-private.pem" --public-out "$WORK/etc/vapid-public.txt" 2>&1)
expect "vapid_keygen 만들기 (종료 코드)" "0" "$?"
expect "비밀 키 0600 · 공개 키 0644" "600|644" "$(stat -c %a "$WORK/etc/vapid-private.pem")|$(stat -c %a "$WORK/etc/vapid-public.txt")"
expect "출력에 비밀 키 글자 0(BEGIN·PEM 몸)" "0" "$(printf '%s\n' "$KG_OUT" | grep -cE 'BEGIN|PRIVATE|^[A-Za-z0-9+/]{40,}')"
note "vapid_keygen 출력: $KG_OUT"
php "$INST/app/scripts/vapid_keygen.php" --private-out "$WORK/etc/vapid-private.pem" --public-out "$WORK/etc/x.txt" >/dev/null 2>&1
expect "이미 있으면 덮어쓰지 않는다 (종료 코드 1)" "1" "$?"
expect "짝 확인 --check" "0" "$(php "$INST/app/scripts/vapid_keygen.php" --check --private "$WORK/etc/vapid-private.pem" --public "$WORK/etc/vapid-public.txt" >/dev/null 2>&1; echo $?)"
openssl ecparam -name prime256v1 -genkey -noout -out "$WORK/etc/other.pem" 2>/dev/null
expect "짝이 아닌 비밀 키 → --check 1" "1" "$(php "$INST/app/scripts/vapid_keygen.php" --check --private "$WORK/etc/other.pem" --public "$WORK/etc/vapid-public.txt" >/dev/null 2>&1; echo $?)"
VAPID_PUB=$(cat "$WORK/etc/vapid-public.txt")
cat > "$WORK/ua.php" <<'PHP'
<?php
// 기기 흉내 키 — 실행할 때 새로. <폴더> <이름…> → <이름>.pem(0600) · <이름>.p256dh · <이름>.auth (base64url)
require $argv[1] . '/src/WebPush.php';
$dir = $argv[2];
umask(0077);
foreach (array_slice($argv, 3) as $name) {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($k, $pem);
    file_put_contents("$dir/$name.pem", $pem);
    file_put_contents("$dir/$name.p256dh", WebPush::b64uEncode(WebPush::rawPublicOf($k)));
    file_put_contents("$dir/$name.auth", WebPush::b64uEncode(random_bytes(16)));
}
PHP
php "$WORK/ua.php" "$INST/app" "$WORK/ua" d1 d2 d3 d4 x1 x2 x3 x4 x5 x6 x7 m1
expect "기기 흉내 키 12벌" "12" "$(ls "$WORK/ua"/*.p256dh | wc -l | tr -d ' ')"
# 기기 흉내 키는 파일로만 오간다(R15 — 명령 인자에 싣지 않는다). 값이 필요한 곳은 파일 경로를 넘기거나 표준입력으로
kf() { printf '%s' "$WORK/ua/$1.$2"; }                     # $1 기기 $2 p256dh|auth → 파일 경로
kpfx() {  # $1 기기 $2 글자 수 → p256dh 앞 조각만 담은 무늬 파일 경로(grep -F -f 용)
  head -c "$2" "$WORK/ua/$1.p256dh" > "$WORK/ua/$1.p256dh.pfx$2"; printf '%s' "$WORK/ua/$1.p256dh.pfx$2"
}
b2h_dev() {  # $1 기기 $2 p256dh|auth → 바이트의 hex(SQL decode 용 — 표준입력으로 psql 에 간다)
  php -r 'require $argv[1]; echo bin2hex(WebPush::b64uDecode(trim(file_get_contents($argv[2]))));' "$INST/app/src/WebPush.php" "$(kf "$1" "$2")"
}

sub "⑤ 가짜 푸시 서버 127.0.0.1:$PPORT (자체 서명 — 이름 셋의 SAN)"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=fcm.googleapis.com" \
  -addext "subjectAltName=DNS:fcm.googleapis.com,DNS:web.push.apple.com,DNS:updates.push.services.mozilla.com" \
  -keyout "$WORK/push/key.pem" -out "$WORK/push/cert.pem" >/dev/null 2>&1 || { echo "인증서 실패"; exit 4; }
cat > "$WORK/pushsrv.py" <<'PY'
# 가짜 푸시 서버 — 127.0.0.1 만. 경로 둘째 조각이 응답을 정한다: ok 201 · gone 410 · err 500 · rate 429 · toolarge 413 ·
# redir 301(Location) · big 201 + 100KB 본문 · slow 12초 뒤 201 · rate 는 Retry-After: 120 — ratecap(999999초) · ratedate(HTTP 날짜 +600초) ·
# ratebad(모양 아님) 도 429. 받은 요청마다 한 줄 JSON(머리 몇 개 · 본문 길이) + 본문 파일.
import email.utils, http.server, json, os, socketserver, ssl, sys, time
port, cert, key, outdir = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
seq = [0]
class H(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *a):
        pass
    def do_POST(self):
        n = int(self.headers.get("Content-Length", "0") or 0)
        body = self.rfile.read(n) if n > 0 else b""
        seq[0] += 1
        with open(os.path.join(outdir, "body-%04d.bin" % seq[0]), "wb") as fh:
            fh.write(body)
        parts = self.path.split("/")
        mode = parts[2] if len(parts) > 2 else ""
        rec = {"n": seq[0], "path": self.path, "host": self.headers.get("Host", ""), "len": len(body),
               "ttl": self.headers.get("TTL"), "urgency": self.headers.get("Urgency"),
               "ce": self.headers.get("Content-Encoding"), "ct": self.headers.get("Content-Type"),
               "auth": self.headers.get("Authorization"), "ua": self.headers.get("User-Agent"), "mode": mode}
        with open(os.path.join(outdir, "log.jsonl"), "a") as fh:
            fh.write(json.dumps(rec) + "\n")
        codes = {"ok": 201, "gone": 410, "err": 500, "rate": 429, "ratecap": 429, "ratedate": 429, "ratebad": 429,
                 "toolarge": 413, "redir": 301, "big": 201, "slow": 201}
        if mode == "slow":
            time.sleep(12)
        c = codes.get(mode, 404)
        payload = b"x" * 100000 if mode == "big" else b""
        self.send_response(c)
        if mode == "redir":
            self.send_header("Location", "https://fcm.googleapis.com/f70/ok/after-redirect")
        ra = {"rate": "120", "ratecap": "999999", "ratedate": email.utils.formatdate(time.time() + 600, usegmt=True), "ratebad": "soon"}
        if mode in ra:
            self.send_header("Retry-After", ra[mode])
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        try:
            self.wfile.write(payload)
        except Exception:
            pass
class S(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True
srv = S(("127.0.0.1", port), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
PY
python3 "$WORK/pushsrv.py" "$PPORT" "$WORK/push/cert.pem" "$WORK/push/key.pem" "$WORK/push" 2>"$WORK/push/server.err" &
PSRV_PID=$!
sleep 1
kill -0 "$PSRV_PID" 2>/dev/null || { echo "가짜 푸시 서버가 뜨지 않았다"; cat "$WORK/push/server.err"; exit 4; }
reqs() { [ -f "$WORK/push/log.jsonl" ] && wc -l < "$WORK/push/log.jsonl" | tr -d ' ' || echo 0; }

sub "⑥ 보내는 쪽 시험 사본 (Fetch.php 네 곳 · alert_push.php 시각 한 줄 — 제품 코드는 그대로)"
mkdir -p "$PUSHAPP"
tar -C "$INST/app" -cf - . | tar -C "$PUSHAPP" -xf -
python3 - "$PUSHAPP/src/Fetch.php" "$PPORT" <<'PY'
import sys
p, port = sys.argv[1], sys.argv[2]
s = open(p, encoding='utf-8').read()
rep = [
  ("    private const PORT   = 443;", "    private const PORT   = %s;" % port),
  ("        return self::SCHEME . '://' . $host . $path . $query;", "        return self::SCHEME . '://' . $host . ':' . self::PORT . $path . $query;"),
  ("    private static function resolve(string $host): array\n    {\n",
   "    private static function resolve(string $host): array\n    {\n"
   "        $fake = ['fcm.googleapis.com' => ['127.0.0.1'], 'web.push.apple.com' => ['127.0.0.1'], 'updates.push.services.mozilla.com' => ['10.20.30.40']];\n"
   "        if (isset($fake[$host])) { return $fake[$host]; }\n"),
  ("'100.64.0.0/10', '127.0.0.0/8', ", "'100.64.0.0/10', "),
]
for a, b in rep:
    assert s.count(a) == 1, a
    s = s.replace(a, b)
open(p, 'w', encoding='utf-8').write(s)
PY
expect "시험 사본 Fetch.php — 바뀐 줄 4덩이(포트·주소 조립·이름 풀이·사설 대역)" "yes" \
  "$(d=$(diff "$ROOT/src/Fetch.php" "$PUSHAPP/src/Fetch.php" | grep '^>' ); printf '%s' "$d" | grep -q "PORT   = $PPORT" && printf '%s' "$d" | grep -q "':' . self::PORT" && printf '%s' "$d" | grep -q "10.20.30.40" && echo yes || echo no)"
sed -i 's#^\$now = time();$#$now = (int) (getenv('"'"'F70_NOW'"'"') !== false ? strtotime((string) getenv('"'"'F70_NOW'"'"')) : time());#' "$PUSHAPP/scripts/alert_push.php"
expect "시험 사본 alert_push.php — 시각 한 줄만" "1" "$(diff "$ROOT/scripts/alert_push.php" "$PUSHAPP/scripts/alert_push.php" | grep -c '^>')"
for f in src/Fetch.php scripts/alert_push.php; do php -l "$PUSHAPP/$f" >/dev/null || { echo "시험 사본 문법 오류 $f"; exit 4; }; done
cat > "$WORK/push.conf" <<CONF
# 시험용 push.conf (임시) — 형식은 deploy/push.conf 와 같다
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=$TROLE
PGPASSFILE=$WORK/push.pgpass
VAPID_PRIVATE_KEY_FILE=$WORK/etc/vapid-private.pem
VAPID_PUBLIC_KEY_FILE=$WORK/etc/vapid-public.txt
STATE_DIR=$WORK/state
AUDIT_LOG_FILE=$WORK/pushlog/audit.log
ERROR_LOG=$WORK/pushlog/error.log
ALERTS_LOG=$INST/log/alerts.log
DIGEST_RULES=AL-9
CONF
touch "$WORK/pushlog/audit.log"
STATE="$WORK/state/alert-push-state.json"
# 배치 한 번 — $1 = 흉내 시각(ISO) · 나머지 = 인자. 출력은 파일로도 남긴다(T7 grep)
BATCH_N=0
batch() {
  local when="$1"; shift
  BATCH_N=$((BATCH_N + 1))
  F70_NOW="$when" php -d curl.cainfo="$WORK/push/cert.pem" "$PUSHAPP/scripts/alert_push.php" --conf "$WORK/push.conf" "$@" \
    > "$WORK/batch-$BATCH_N.out" 2>&1
  local rc=$?
  cat "$WORK/batch-$BATCH_N.out"
  return $rc
}
alert_line() {  # $1 규칙 $2 origin(없으면 빈 글자 = 옛 형식) $3 근거 표식 — alerts.log 한 줄(cli_raise_alert 와 같은 키)
  python3 - "$INST/log/alerts.log" "$1" "$2" "$3" <<'PY'
import json, sys, time
path, rule, origin, tag = sys.argv[1:5]
line = {"raised_at": time.strftime("%Y-%m-%dT%H:%M:%S+09:00"), "rule": rule,
        "evidence": {"rule_text": "시험 근거", "subject": tag, "user": "evil-user-" + tag, "ip": "203.0.113.77", "command": "/usr/bin/psql -d x_test"},
        "dispatch": "log_only", "request_id": "00000000-0000-4000-8000-000000000000"}
if origin:
    line["origin"] = origin
    line["title"] = ("[Claude] " if origin.startswith("claude-") else "") + "시험 근거"
with open(path, "a", encoding="utf-8") as fh:
    fh.write(json.dumps(line, ensure_ascii=False) + "\n")
PY
}
# 가짜 서버 로그 n번째 이후의 요청 모양 요약 — "<mode>:<kind>" 목록(암호를 풀어 본다)
cat > "$WORK/decrypt.php" <<'PHP'
<?php
// <앱> <가짜 서버 폴더> <기기 키 폴더> <이 번호 뒤부터> → 요청마다 한 줄: n mode kind rule name origin n2 (풀지 못하면 ?)
require $argv[1] . '/src/WebPush.php';
[$app, $dir, $ua, $after] = [$argv[1], $argv[2], $argv[3], (int) $argv[4]];
$map = [];   // 경로 끝 조각 → 기기 이름 (등록 때 적어 둔 표)
if (is_file("$dir/devices.txt")) {
    foreach (file("$dir/devices.txt", FILE_IGNORE_NEW_LINES) as $l) { [$tail, $name] = explode(' ', $l); $map[$tail] = $name; }
}
foreach (file("$dir/log.jsonl", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $r = json_decode($l, true);
    if ($r['n'] <= $after) { continue; }
    $tail = basename(parse_url($r['path'], PHP_URL_PATH));
    $name = $map[$tail] ?? null;
    $out = '?';
    if ($name !== null) {
        $body = file_get_contents(sprintf('%s/body-%04d.bin', $dir, $r['n']));
        $uaKey = openssl_pkey_get_private(file_get_contents("$ua/$name.pem"));
        $uaPub = WebPush::b64uDecode(trim(file_get_contents("$ua/$name.p256dh")));
        $auth = WebPush::b64uDecode(trim(file_get_contents("$ua/$name.auth")));
        $salt = substr($body, 0, 16); $asPub = substr($body, 21, 65);
        $ecdh = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPub), $uaKey, 32);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPub . $asPub, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $rec = substr($body, 86);
        $pt = openssl_decrypt(substr($rec, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rec, -16));
        if (is_string($pt) && substr($pt, -1) === "\x02") {
            $d = json_decode(substr($pt, 0, -1), true);
            $out = sprintf('%s|%s|%s|%s|%s', $d['kind'], $d['rule'] ?? '-', $d['name'] ?? '-', $d['origin'] ?? '-', $d['n']);
            file_put_contents("$dir/plain-" . $r['n'] . '.json', substr($pt, 0, -1));
        }
    }
    printf("%d %s %s %s\n", $r['n'], $r['mode'], $name ?? '-', $out);
}
PHP
decoded() { php "$WORK/decrypt.php" "$INST/app" "$WORK/push" "$WORK/ua" "$1"; }

# ════════════════════════════════════════════════════════════════
hr "T1 — RFC 벡터 (tests/f70_webpush_vectors.php)"
T1_OUT=$(php "$INST/app/tests/f70_webpush_vectors.php" 2>&1); T1_RC=$?
printf '%s\n' "$T1_OUT" | grep -E '✖' | sed 's/^/    /'
expect "T1 종료 코드" "0" "$T1_RC"
contains "T1 끝 줄" "· 실패 0" "$(printf '%s\n' "$T1_OUT" | grep -E '^통과' | tail -1)"
note "$(printf '%s\n' "$T1_OUT" | grep -E '^통과' | tail -1)"

# ════════════════════════════════════════════════════════════════
hr "T2 — 받기 등록 검사 ($ADMIN · 임시 인스턴스)"
cat > "$WORK/login.py" <<'PY'
# 로그인 — 비밀번호·TOTP 비밀은 표준입력 두 줄(인자·환경 변수 0). 쿠키·CSRF 는 0600 머리 파일로만(명령 인자 0)
import base64, hashlib, hmac, json, os, re, struct, sys, time, urllib.request
base, ua, who, out = sys.argv[1:5]
pw = sys.stdin.readline().rstrip("\n"); sec = sys.stdin.readline().rstrip("\n")
def totp(secret, t):
    key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = mac[-1] & 15
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
for attempt in range(3):
    body = {"username": who, "password": pw, "otp": totp(sec, time.time())}
    req = urllib.request.Request(base + "/api/login", data=json.dumps(body).encode(), method="POST", headers={"Content-Type": "application/json", "User-Agent": ua})
    cookie, data = "", {}
    try:
        resp = urllib.request.urlopen(req, timeout=20)
        data = json.loads(resp.read().decode())
        for h in resp.headers.get_all("Set-Cookie") or []:
            m = re.match(r"tbox_session=([^;]+)", h)
            if m: cookie = m.group(1)
    except Exception:
        pass
    if data.get("ok") is True and cookie:
        csrf = ""
        try:
            me = urllib.request.Request(base + "/api/me", headers={"Cookie": "tbox_session=" + cookie, "User-Agent": ua})
            csrf = str(json.loads(urllib.request.urlopen(me, timeout=20).read().decode()).get("csrf_token") or "")
        except Exception:
            csrf = ""
        if not re.fullmatch(r"[0-9a-f]{16,128}", csrf):
            print("LOGIN_FAIL_CSRF"); sys.exit(1)
        old = os.umask(0o077)
        with open(os.path.join(out, who + ".cookie"), "w") as f: f.write("Cookie: tbox_session=%s\n" % cookie)
        with open(os.path.join(out, who + ".csrf"), "w") as f: f.write("X-CSRF-Token: %s\n" % csrf)
        os.umask(old)
        print("LOGIN_OK"); sys.exit(0)
    time.sleep(31 - int(time.time()) % 30)
print("LOGIN_FAIL"); sys.exit(1)
PY
login() {  # $1 계정
  local L
  PW=$(sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  SEC=$(sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  L=$(py_secret PW SEC -- python3 "$WORK/login.py" "$BASE" "$UA" "$1" "$WORK/hdr")
  unset PW SEC
  printf '%s' "$L"
}
expect "$ADMIN 로그인" "LOGIN_OK" "$(login "$ADMIN")"
HC="$WORK/hdr/$ADMIN.cookie"
HCS="$WORK/hdr/$ADMIN.csrf"     # 「X-CSRF-Token: …」 한 줄(0600) — 값은 셸 변수·명령 인자에 싣지 않는다
[ -s "$HCS" ] || { echo "CSRF 를 받지 못했다 — 중단"; exit 4; }
# 창구 부르기 — 결과는 "상태 본문"(본문은 한 줄)
api() {  # $1 메서드 $2 경로 [$3 본문]
  local extra=()
  [ "$1" != "GET" ] && extra+=(-H @"$HCS")
  if [ $# -ge 3 ]; then
    curl -s -o "$WORK/api.body" -w '%{http_code}' -A "$UA" -H @"$HC" "${extra[@]}" -H 'Content-Type: application/json' -X "$1" --data-binary @- "$BASE$2" <<< "$3"
  else
    curl -s -o "$WORK/api.body" -w '%{http_code}' -A "$UA" -H @"$HC" "${extra[@]}" -X "$1" "$BASE$2"
  fi
}
body() { cat "$WORK/api.body"; }
sub_json() {  # $1 endpoint $2 p256dh 를 줄 기기 $3 auth 를 줄 기기 [$4 더 붙일 키 JSON 조각] — endpoint 는 표준입력, 키는 파일에서(인자에 값 0)
  printf '%s\n' "$1" | python3 -c 'import json,sys
d = {"endpoint": sys.stdin.readline().rstrip("\n"),
     "keys": {"p256dh": open(sys.argv[1]).read().strip(), "auth": open(sys.argv[2]).read().strip()}}
if len(sys.argv) > 3 and sys.argv[3]: d.update(json.loads(sys.argv[3]))
print(json.dumps(d))' "$(kf "$2" p256dh)" "$(kf "$3" auth)" "${4:-}"
}
M0=$(mark)

sub "재인증 없이 — 403 next:reauth (관문 [3-2])"
expect "재인증 전 GET /api/admin/push/key → 403" "403" "$(api GET /api/admin/push/key)"
expect "  next = reauth" "reauth" "$(body | jq_py "d.get('next')")"
expect "재인증 전 POST 받기 → 403 next:reauth" "403|reauth" "$(api POST /api/admin/push/subscriptions "$(sub_json "https://fcm.googleapis.com/f70/ok/pre-$TAG" d1 d1)")|$(body | jq_py "d.get('next')")"
expect "재인증 전 화면 GET /alerts-app → 302 /reauth?to=alerts" "302|/tbox/reauth?to=alerts" \
  "$(curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' -A "$UA" -H @"$HC" "$BASE/alerts-app" | sed "s#$SITE##")"
PW=$(sudo -n awk -v u="$ADMIN" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
RB=$(py_secret PW -- python3 -c 'import json,sys; print(json.dumps({"password": sys.stdin.readline().rstrip("\n")}))')
unset PW
RR=$(curl_json_secret RB -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$HC" -H @"$HCS" -H 'Content-Type: application/json' -X POST "$BASE/api/me/reauth")
unset RB
expect "재인증 POST /api/me/reauth → 200" "200" "$RR"

sub "키 · 받기 등록"
expect "GET /api/admin/push/key → 200 · 공개 키 = 임시 공개 키" "200|$VAPID_PUB" "$(api GET /api/admin/push/key)|$(body | jq_py "d.get('public_key')")"
reg() {  # $1 endpoint $2 기기 [$3 더 붙일 키] → 상태
  api POST /api/admin/push/subscriptions "$(sub_json "$1" "$2" "$2" "${3:-}")"
}
: > "$WORK/push/devices.txt"
E_D1="https://fcm.googleapis.com/f70/ok/d1-$TAG"; E_D2="https://web.push.apple.com/f70/ok/d2-$TAG"; E_D3="https://fcm.googleapis.com/f70/ok/d3-$TAG"
for pair in "d1 $E_D1" "d2 $E_D2" "d3 $E_D3"; do set -- $pair; echo "$(basename "$2") $1" >> "$WORK/push/devices.txt"; done
expect "1번째 기기(fcm) → 201" "201" "$(reg "$E_D1" d1)"
ID_D1=$(body | jq_py "d['subscription']['id']")
contains "  응답에 주소·키 원문 0(구독 행)" "device_hint" "$(body)"
expect "  응답 본문에 endpoint·p256dh·auth 글자 0" "0|0|0" "$(body | grep -c 'f70/ok')|$(body | grep -cF -f "$(kpfx d1 20)")|$(body | grep -cF -f "$(kf d1 auth)")"
expect "2번째 기기(apple) → 201" "201" "$(reg "$E_D2" d2)"
ID_D2=$(body | jq_py "d['subscription']['id']")
expect "3번째 기기(fcm) → 201" "201" "$(reg "$E_D3" d3)"
ID_D3=$(body | jq_py "d['subscription']['id']")
expect "같은 주소 다시 → 200 already (새 행 없음)" "200|True" "$(reg "$E_D1" d1)|$(body | jq_py "d.get('already')")"
expect "4번째 기기 → 400 too_many" "400" "$(reg "https://fcm.googleapis.com/f70/ok/d4-$TAG" d4)"
contains "  문구" "3대까지" "$(body)"

sub "거부 — 모양·주소·키 (전부 400, 감사 사유)"
E1025=$(python3 -c 'p = "https://fcm.googleapis.com/f70/ok/"; print(p + "a" * (1025 - len(p)))')
while IFS='|' read -r what ep key extra want; do
  [ -n "$what" ] || continue
  st=$(reg "$ep" "$key" "$extra")
  expect "$what → 400" "400" "$st"
done <<EOF
허용 밖 호스트 evil.example.com|https://evil.example.com/f70/ok/x|x1||host_not_allowed
하위 도메인이 바뀐 Edge 류(xx.notify.windows.com)|https://db5p.notify.windows.com/w/?token=x|x1||host_not_allowed
http 스킴|http://fcm.googleapis.com/f70/ok/x|x1||bad_endpoint
포트 :8443|https://fcm.googleapis.com:8443/f70/ok/x|x1||bad_endpoint
IP 리터럴|https://127.0.0.1/f70/ok/x|x1||bad_endpoint
user@host|https://u@fcm.googleapis.com/f70/ok/x|x1||bad_endpoint
허용 밖 키(expirationTime)|https://fcm.googleapis.com/f70/ok/x|x1|{"expirationTime":null}|bad_request
EOF
expect "주소 1,025자 → 400" "400" "$(reg "$E1025" x1)"
expect "  (길이 확인) 1,025자" "1025" "$(printf '%s' "$E1025" | wc -c | tr -d ' ')"
# 모양이 틀린 키도 파일로 만든다(가짜 기기 이름 p64 · poff · a15)
php -r 'require $argv[1]; echo WebPush::b64uEncode(substr(WebPush::b64uDecode(trim(file_get_contents($argv[2]))), 0, 64));' "$INST/app/src/WebPush.php" "$(kf x2 p256dh)" > "$(kf p64 p256dh)"
expect "p256dh 64바이트 → 400" "400" "$(api POST /api/admin/push/subscriptions "$(sub_json "https://fcm.googleapis.com/f70/ok/x" p64 x2)")"
php -r 'require $argv[1]; $r = WebPush::b64uDecode(trim(file_get_contents($argv[2]))); $r[64] = chr(ord($r[64]) ^ 1); echo WebPush::b64uEncode($r);' "$INST/app/src/WebPush.php" "$(kf x2 p256dh)" > "$(kf poff p256dh)"
expect "p256dh 곡선 밖 점 → 400" "400" "$(api POST /api/admin/push/subscriptions "$(sub_json "https://fcm.googleapis.com/f70/ok/x" poff x2)")"
php -r 'require $argv[1]; echo WebPush::b64uEncode(random_bytes(15));' "$INST/app/src/WebPush.php" > "$(kf a15 auth)"
expect "auth 15바이트 → 400" "400" "$(api POST /api/admin/push/subscriptions "$(sub_json "https://fcm.googleapis.com/f70/ok/x" x2 a15)")"
expect "JSON 아닌 본문 → 400" "400" "$(api POST /api/admin/push/subscriptions 'endpoint=x')"
expect "살아 있는 받기 = 3 (거부는 행을 만들지 않는다)" "3" "$(psqlq "SELECT count(*) FROM alert_push_subscriptions WHERE user_id = $ADMIN_ID AND revoked_at IS NULL")"
expect "감사 거부 사유(이번 실행) — host_not_allowed 3(IP 리터럴 포함 — Fetch 판정 그대로) · bad_endpoint 3 · bad_request 2(JSON 아닌 본문은 입구에서 400 — 감사 전) · bad_key 3 · too_many 1" "bad_endpoint=3 bad_key=3 bad_request=2 host_not_allowed=3 too_many=1" \
  "$(psqlq "SELECT string_agg(reason || '=' || n, ' ' ORDER BY reason) FROM (SELECT reason, count(*) n FROM audit_logs WHERE id > $M0 AND action = 'alert.push_subscribe' AND result = 'deny' GROUP BY reason) q")"
expect "허용 밖 호스트 거부의 대상 = 정규화한 호스트(127.0.0.1 · db5p.notify.windows.com · evil.example.com)" "127.0.0.1 db5p.notify.windows.com evil.example.com" \
  "$(psqlq "SELECT string_agg(target_name, ' ' ORDER BY target_name) FROM audit_logs WHERE id > $M0 AND action = 'alert.push_subscribe' AND reason = 'host_not_allowed'")"

sub "목록 · 해지 · 남의 받기"
expect "GET 받기 목록 → 200 · 3개" "200|3" "$(api GET /api/admin/push/subscriptions)|$(body | jq_py "len(d['subscriptions'])")"
expect "  목록 항목 키 = id · host · device_hint · created_at · last_ok_at" "created_at,device_hint,host,id,last_ok_at" \
  "$(body | jq_py "','.join(sorted(d['subscriptions'][0].keys()))")"
expect "  목록 본문에 주소·키 글자 0" "0|0|0" "$(body | grep -c 'f70/')|$(body | grep -cF -f "$(kpfx d2 20)")|$(body | grep -cF -f "$(kf d2 auth)")"
# 남의 받기 — 임시 DB 에 claude_manager01 의 받기를 직접 넣는다(Manager — T6 의 「역할 Manager」 갈래도 이 행)
E_M1="https://fcm.googleapis.com/f70/ok/m1-$TAG"; echo "$(basename "$E_M1") m1" >> "$WORK/push/devices.txt"
psqlq "INSERT INTO alert_push_subscriptions (user_id, endpoint, host, p256dh, auth, device_hint)
       VALUES ($MGR_ID, '$E_M1', 'fcm.googleapis.com', decode('$(b2h_dev m1 p256dh)', 'hex'),
               decode('$(b2h_dev m1 auth)', 'hex'), 'Android Chrome')" >/dev/null
ID_M1=$(psqlq "SELECT id FROM alert_push_subscriptions WHERE endpoint = '$E_M1'")
expect "남의 받기 해지 → 404 · 행은 살아 있음" "404|(살아 있음)" "$(api DELETE "/api/admin/push/subscriptions/$ID_M1")|$(psqlq "SELECT CASE WHEN revoked_at IS NULL THEN '(살아 있음)' ELSE 'revoked' END FROM alert_push_subscriptions WHERE id = $ID_M1")"
expect "없는 번호 해지 → 404(같은 문구)" "404|$(body | jq_py "d.get('error')")" "$(api DELETE /api/admin/push/subscriptions/999999999)|$(body | jq_py "d.get('error')")"
expect "번호 모양 밖(abc) → 404(라우트 없음)" "404" "$(api DELETE /api/admin/push/subscriptions/abc)"
expect "내 받기(d3) 해지 → 200 · revoked_reason user" "200|user" "$(api DELETE "/api/admin/push/subscriptions/$ID_D3")|$(psqlq "SELECT revoked_reason FROM alert_push_subscriptions WHERE id = $ID_D3")"
expect "같은 것 다시 해지 → 404" "404" "$(api DELETE "/api/admin/push/subscriptions/$ID_D3")"
expect "해지 감사 — allow 1 · deny not_found 3" "1|3" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M0 AND action = 'alert.push_unsubscribe' AND result = 'allow'")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M0 AND action = 'alert.push_unsubscribe' AND result = 'deny' AND reason = 'not_found'")"
expect "받기 행은 지워지지 않는다(DELETE 0 — 해지는 revoked_at)" "4" "$(psqlq "SELECT count(*) FROM alert_push_subscriptions WHERE user_id IN ($ADMIN_ID, $MGR_ID)")"

sub "감사 detail — 주소·키 원문 0 · 등록은 {host, device_hint}"
AUD=$(psqlq "SELECT coalesce(detail::text, '') || ' ' || summary || ' ' || coalesce(target_name, '') FROM audit_logs WHERE id > $M0 AND action LIKE 'alert.push%'")
LEAK=0
for k in d1 d2 d3 d4 x1 x2; do
  printf '%s' "$AUD" | grep -qF -f "$(kpfx "$k" 24)" && LEAK=$((LEAK+1))
  printf '%s' "$AUD" | grep -qF -f "$(kf "$k" auth)" && LEAK=$((LEAK+1))
done
printf '%s' "$AUD" | grep -qE '/f70/|https://' && LEAK=$((LEAK+1))
expect "감사(이번 실행 alert.push_*)에 p256dh·auth·주소 글자 0" "0" "$LEAK"
expect "등록 감사 detail 키 = host · device_hint (+ 자동 route·actor_team·login_ref)" "actor_team,device_hint,host,login_ref,route" \
  "$(psqlq "SELECT string_agg(k, ',' ORDER BY k) FROM (SELECT jsonb_object_keys(detail) k FROM audit_logs WHERE id > $M0 AND action = 'alert.push_subscribe' AND result = 'allow' ORDER BY id LIMIT 5) q" | tr ',' '\n' | sort -u | paste -sd, -)"

sub "시험 알림 — 대기표 한 행 · 10분 안 다시 429"
expect "POST /api/admin/push/test → 202" "202" "$(api POST /api/admin/push/test '{}')"
expect "  대기표 한 행(보내지 않음 — sent_at 없음)" "1|(없음)" "$(psqlq "SELECT count(*) FROM alert_push_outbox WHERE user_id = $ADMIN_ID")|$(psqlq "SELECT coalesce(sent_at::text, '(없음)') FROM alert_push_outbox WHERE user_id = $ADMIN_ID")"
expect "10분 안 다시 → 429 · retry_after · Retry-After 머리" "429|yes" \
  "$(api POST /api/admin/push/test '{}')|$(body | jq_py "'yes' if d.get('retry_after', 0) > 0 else 'no'")"
expect "  대기표 여전히 한 행 · 감사 rate_limited 1" "1|1" "$(psqlq "SELECT count(*) FROM alert_push_outbox WHERE user_id = $ADMIN_ID")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M0 AND action = 'alert.push_test' AND reason = 'rate_limited'")"
expect "가짜 푸시 서버 요청 0 (웹은 보내지 않았다)" "0" "$(reqs)"

sub "$MGR (Manager) — 창구 403"
expect "$MGR 로그인" "LOGIN_OK" "$(login "$MGR")"
HCM="$WORK/hdr/$MGR.cookie"
HCSM="$WORK/hdr/$MGR.csrf"
expect "$MGR GET 받기 목록 → 403" "403" "$(code_of -H @"$HCM" "$BASE/api/admin/push/subscriptions")"
expect "$MGR POST 받기 → 403" "403" "$(sub_json "https://fcm.googleapis.com/f70/ok/mm-$TAG" x3 x3 | curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$HCM" -H @"$HCSM" -H 'Content-Type: application/json' --data-binary @- -X POST "$BASE/api/admin/push/subscriptions")"
expect "$MGR 화면 GET /alerts-app → 403" "403" "$(code_of -H @"$HCM" "$BASE/alerts-app")"
expect "$MGR 서비스 워커 GET /alerts-sw.js → 403" "403" "$(code_of -H @"$HCM" "$BASE/alerts-sw.js")"
expect "$MGR 시험 POST → 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$HCM" -H @"$HCSM" -H 'Content-Type: application/json' --data-binary '{}' -X POST "$BASE/api/admin/push/test")"
expect "  감사 authz.route deny 5(이번 실행 · $MGR)" "5" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $M0 AND action = 'authz.route' AND result = 'deny' AND actor_name = '$MGR'")"
expect "CSRF 없이 POST(Admin) → 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$HC" -H 'Content-Type: application/json' --data-binary '{}' -X POST "$BASE/api/admin/push/test")"
expect "로그인 없이 GET 키 → 401" "401" "$(code_of "$BASE/api/admin/push/key")"

# ════════════════════════════════════════════════════════════════
hr "T3 — 웹은 밖으로 안 나간다"
expect "새 핸들러 일곱에 Fetch:: 0 (주석 포함 글자 그대로)" "0" "$(cat "$ROOT"/src/handlers/push_*.php | grep -c 'Fetch::')"
expect "새 핸들러에 curl_·소켓·file_get_contents('http 0" "0" "$(grep -cE 'curl_|fsockopen|stream_socket|file_get_contents\(.http' "$ROOT"/src/handlers/push_*.php | awk -F: '{s+=$2} END {print s+0}')"
expect "WebPush.php 에 네트워크 함수 0" "0" "$(grep -cE 'curl_|fsockopen|stream_socket|file_get_contents\(.http|dns_get_record' "$ROOT/src/WebPush.php")"
sub "시험 알림 요청 동안 웹 서버 프로세스(php -S, pid $SERVER_PID)의 443 연결 — ss 표본"
psqlq "UPDATE alert_push_outbox SET requested_at = requested_at - interval '11 minutes' WHERE user_id = $ADMIN_ID" >/dev/null   # 10분 창을 넘긴다(임시 DB)
: > "$WORK/ss.txt"
( for _ in $(seq 1 60); do ss -tnp state established '( dport = :443 or dport = :'"$PPORT"' )' 2>/dev/null | grep -c "pid=$SERVER_PID," >> "$WORK/ss.txt"; sleep 0.02; done ) &
SSP=$!
T3C=$(api POST /api/admin/push/test '{}')
wait "$SSP"
expect "시험 알림 POST → 202" "202" "$T3C"
expect "표본 60번 동안 웹 서버의 443·푸시 포트 연결 0" "0" "$(awk '{s+=$1} END {print s+0}' "$WORK/ss.txt")"
expect "가짜 푸시 서버 요청 여전히 0" "0" "$(reqs)"
sub "서비스 워커·매니페스트 — 보안 머리 = 다른 PHP 응답 · Content-Type · Cache-Control"
sec_h() { curl -s -D - -o /dev/null -A "$UA" -H @"$HC" "$1" | tr -d '\r' | grep -iE '^(content-security-policy|x-content-type-options|x-frame-options|referrer-policy):' | sort; }
H_ME=$(sec_h "$BASE/api/me"); H_SW=$(sec_h "$BASE/alerts-sw.js"); H_MF=$(sec_h "$BASE/alerts-app.webmanifest"); H_PG=$(sec_h "$BASE/alerts-app")
expect "보안 머리 넷이 있다(/api/me 기준)" "4" "$(printf '%s\n' "$H_ME" | grep -c .)"
# 기준이 비면(/api/me 가 답하지 않음) 빈 값끼리 같다고 통과하지 않게 대체 글자로 둔다(점검 봇 v3 R23 ④)
expect "서비스 워커 보안 머리 = /api/me" "${H_ME:-(기준 없음)}" "$H_SW"
expect "매니페스트 보안 머리 = /api/me" "${H_ME:-(기준 없음)}" "$H_MF"
expect "화면 /alerts-app 보안 머리 = /api/me" "${H_ME:-(기준 없음)}" "$H_PG"
hdrv() { local v; v=$(curl -s -D - -o /dev/null -A "$UA" -H @"$HC" "$1" | tr -d '\r' | sed -n "s/^$2: //Ip" | head -1); printf '%s' "${v:-(none)}"; }   # 머리가 없으면 (none) — 빈 값끼리 같다고 하지 않게
expect "서비스 워커 Content-Type · Cache-Control" "application/javascript; charset=utf-8|no-cache" "$(hdrv "$BASE/alerts-sw.js" Content-Type)|$(hdrv "$BASE/alerts-sw.js" Cache-Control)"
expect "매니페스트 Content-Type · Cache-Control" "application/manifest+json|no-cache" "$(hdrv "$BASE/alerts-app.webmanifest" Content-Type)|$(hdrv "$BASE/alerts-app.webmanifest" Cache-Control)"
expect "Service-Worker-Allowed 머리 없음(범위가 스크립트 폴더 안) · 같은 응답에 Content-Type 은 있음(요청이 닿았다)" "(none)|yes" \
  "$(hdrv "$BASE/alerts-sw.js" Service-Worker-Allowed)|$(case "$(hdrv "$BASE/alerts-sw.js" Content-Type)" in application/javascript*) echo yes ;; *) echo no ;; esac)"
expect "원본 src/push/alerts-sw.js 는 /tbox/assets/ 로 안 나간다 → 404" "404" "$(code_of "$BASE/assets/alerts-sw.js")"
expect "src/bootstrap.php(CSP 한 곳) · nginx 사이트 설정 — 이 판에서 무변경(Q:T-7 · $F70_SCOPE)" "0" \
  "$(f70_changed src/bootstrap.php deploy/sites/ | grep -c . )"

# ════════════════════════════════════════════════════════════════
hr "T4 — Fetch::pushSend 거부"
cat > "$WORK/send.php" <<'PHP'
<?php
// <앱> <주소> [본문 바이트 수] [머리 JSON] → "status N" 또는 "denied 사유 [상태]"
define('TBOX_REQUEST_ID', '00000000-0000-4000-8000-00000000f700');
require $argv[1] . '/src/Fetch.php';
$len = isset($argv[3]) ? (int) $argv[3] : 100;
$headers = isset($argv[4]) ? json_decode($argv[4], true) : ['TTL' => '60', 'Content-Encoding' => 'aes128gcm'];
$t = microtime(true);
try {
    $r = Fetch::pushSend($argv[2], $headers, str_repeat("\x01", $len));
    printf("status %d ra=%s %.1fs\n", $r['status'], $r['retry_after'] ?? '-', microtime(true) - $t);
} catch (FetchDenied $e) {
    printf("denied %s %s %.1fs\n", $e->reason, $e->httpStatus ?? '-', microtime(true) - $t);
}
PHP
S0=$(reqs)
sendp() { php "$WORK/send.php" "$INST/app" "$@" 2>/dev/null | cut -d' ' -f1-3; }            # 제품 코드 그대로
sends() { php -d curl.cainfo="$WORK/push/cert.pem" "$WORK/send.php" "$PUSHAPP" "$@" 2>/dev/null | cut -d' ' -f1-3; }   # 시험 사본
expect "제품 코드 — PUSH_HOSTS 밖(evil.example.com) → host_not_allowed (DNS 전에 끝)" "denied host_not_allowed -" "$(sendp https://evil.example.com/x)"
expect "제품 코드 — http → scheme" "denied scheme -" "$(sendp http://fcm.googleapis.com/x)"
expect "제품 코드 — :8443 → port" "denied port -" "$(sendp https://fcm.googleapis.com:8443/x)"
expect "제품 코드 — IP 리터럴 → host_not_allowed" "denied host_not_allowed -" "$(sendp https://127.0.0.1/x)"
expect "제품 코드 — 본문 4KB 초과 → too_large (보내지 않음)" "denied too_large -" "$(sendp https://fcm.googleapis.com/x 4097)"
expect "제품 코드 — 허용 밖 머리(허용 호스트) → bad_request" "denied bad_request -" "$(php "$WORK/send.php" "$INST/app" https://fcm.googleapis.com/x 10 '{"Cookie":"a"}' 2>/dev/null | cut -d' ' -f1-3)"
expect "제품 코드 — 머리 값 줄바꿈 → bad_request" "denied bad_request -" "$(php "$WORK/send.php" "$INST/app" https://fcm.googleapis.com/x 10 '{"TTL":"60\r\nX: y"}' 2>/dev/null | cut -d' ' -f1-3)"
expect "  여기까지 가짜 서버 요청 0" "$S0" "$(reqs)"
expect "시험 사본 — 201 (정상 길)" "status 201" "$(sends https://fcm.googleapis.com/f70/ok/t4 | cut -d' ' -f1-2)"
expect "시험 사본 — 사설 IP 로 풀리는 이름(mozilla → 10.20.30.40) → private_ip · 요청 0" "denied private_ip -|$((S0 + 1))" "$(sends https://updates.push.services.mozilla.com/f70/ok/t4)|$(reqs)"
expect "시험 사본 — 301 → redirect (따라가지 않는다 — 요청 1번)" "denied redirect 301|$((S0 + 2))" "$(sends https://fcm.googleapis.com/f70/redir/t4)|$(reqs)"
expect "  after-redirect 로 간 요청 0" "0" "$(grep -c 'after-redirect' "$WORK/push/log.jsonl")"
expect "시험 사본 — 429 Retry-After 120 → 정수 120 · 201 은 없음" "status 429 ra=120|status 201 ra=-" "$(sends https://fcm.googleapis.com/f70/rate/t4)|$(sends https://fcm.googleapis.com/f70/ok/t4)"
expect "  Retry-After 999999 → 상한 3600 · HTTP 날짜 +600초 → 590~600 · 모양 아님 → 없음" "status 429 ra=3600|yes|status 429 ra=-" \
  "$(sends https://fcm.googleapis.com/f70/ratecap/t4)|$(sends https://fcm.googleapis.com/f70/ratedate/t4 | awk '{sub(/^ra=/,"",$3); print ($3+0 >= 590 && $3+0 <= 600) ? "yes" : "no(" $3 ")"}')|$(sends https://fcm.googleapis.com/f70/ratebad/t4)"
expect "시험 사본 — 응답 본문 100KB → too_large(8KB 에서 끊음)" "denied too_large 201" "$(sends https://fcm.googleapis.com/f70/big/t4)"
T4S=$(php -d curl.cainfo="$WORK/push/cert.pem" "$WORK/send.php" "$PUSHAPP" https://fcm.googleapis.com/f70/slow/t4 2>/dev/null)
expect "시험 사본 — 12초 응답 → fetch_failed (전체 10초)" "denied fetch_failed" "$(printf '%s' "$T4S" | cut -d' ' -f1-2)"
expect "  걸린 시간 9.5~11.5초" "yes" "$(printf '%s' "$T4S" | awk '{t=$NF; sub(/s$/,"",t); t=t+0; print (t >= 9.5 && t <= 11.5) ? "yes" : "no(" t ")"}')"
expect "시험 사본 — 인증서가 가짜 CA 가 아니면 실패(cainfo 없이 → fetch_failed)" "denied fetch_failed" "$(php "$WORK/send.php" "$PUSHAPP" https://fcm.googleapis.com/f70/ok/t4 2>/dev/null | cut -d' ' -f1-2)"
LASTREQ=$(tail -1 "$WORK/push/log.jsonl")
expect "보낸 머리 — Content-Type octet-stream · User-Agent qaredi-push/1" "application/octet-stream|qaredi-push/1" "$(printf '%s' "$LASTREQ" | jq_py "d['ct'] + '|' + d['ua']")"

# ════════════════════════════════════════════════════════════════
hr "T5 — 배치 가르기 · 줄이기 · 하루 요약 · dry-run 은 아무것도 쓰지 않는다"
psqlq "DELETE FROM alert_push_outbox WHERE user_id = $ADMIN_ID AND id <> (SELECT min(id) FROM alert_push_outbox WHERE user_id = $ADMIN_ID)" >/dev/null   # 시험 요청은 하나만 남긴다(임시 DB)
for i in 1 2 3; do alert_line AL-1 human "old$i-$TAG"; done
expect "옛 줄 3개가 있다" "3" "$(wc -l < "$INST/log/alerts.log" | tr -d ' ')"
sub "B0 처음 켤 때 — dry-run 은 상태 파일을 만들지 않는다 · 본 실행은 위치 = 파일 끝(옛 줄 0)"
S0=$(reqs); A0=$(mark)
B=$(batch 2026-09-26T09:00:30+09:00 --dry-run --once); BRC=$?
expect "B0 dry-run 종료 코드" "0" "$BRC"
contains "B0 dry-run — 처음 켬" "처음 켬" "$B"
expect "B0 dry-run — 상태 파일 없음 · 잠금 파일 없음 · 대기표 그대로 · 감사 0 · 요청 0" "no|no|1|$A0|$S0" \
  "$([ -e "$STATE" ] && echo yes || echo no)|$([ -e "$WORK/state/alert-push.lock" ] && echo yes || echo no)|$(psqlq "SELECT count(*) FROM alert_push_outbox WHERE sent_at IS NULL")|$(mark)|$(reqs)"
B=$(batch 2026-09-26T09:00:30+09:00 --once); BRC=$?
expect "B0 본 실행 종료 코드" "0" "$BRC"
contains "B0 — 새 줄 0 (옛 줄은 보내지 않는다)" "새 줄 0개" "$B"
expect "B0 — 상태 파일 0600 · 위치 = alerts.log 크기" "600|$(stat -c %s "$INST/log/alerts.log")" "$(stat -c %a "$STATE")|$(jq_py "d['log']['offset']" < "$STATE")"
contains "B0 — 대상 받기 2개(fcm 1 · apple 1) · Manager 받기 해지 1" "활성 Admin 이 아닌 사람의 받기 1개" "$B"
expect "B0 — Manager 받기 → not_admin" "not_admin" "$(psqlq "SELECT revoked_reason FROM alert_push_subscriptions WHERE id = $ID_M1")"
expect "B0 — 시험 알림 2건(d1·d2) 보냄 · 대기표 sent_at 적힘" "2|0" "$(( $(reqs) - S0 ))|$(psqlq "SELECT count(*) FROM alert_push_outbox WHERE sent_at IS NULL")"
DEC=$(decoded "$S0")
note "B0 푸시: $(printf '%s' "$DEC" | tr '\n' ';')"
expect "B0 — 풀어 본 내용 = test (두 기기 모두)" "2" "$(printf '%s\n' "$DEC" | grep -c ' test|-|-|-|1$')"
expect "B0 — 감사 alert.push_send allow 2 · alert.push_revoke 1 (행위자 없음 · UA cli:alert_push.php)" "2|1|0" \
  "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_send' AND result = 'allow' AND actor_name IS NULL AND user_agent = 'cli:alert_push.php'")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_revoke'")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND actor_name IS NOT NULL")"
expect "B0 — 자기 감사 사본 줄 = DB 행 수" "$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0")" "$(wc -l < "$WORK/pushlog/audit.log" | tr -d ' ')"

sub "B1 가르기 — human · system · origin 없는 줄 → 즉시 / claude-* · AL-9 → 요약 칸"
alert_line AL-1 human "h-$TAG"; alert_line AL-8 system "s-$TAG"; alert_line AL-6 "" "legacy-$TAG"
alert_line AL-10 claude-review "cr-$TAG"; alert_line AL-9 claude-session "cs-$TAG"; alert_line AL-9 human "sudo-$TAG"
echo 'not json' >> "$INST/log/alerts.log"
H_BEFORE=$(sha "$STATE"); S0=$(reqs); A0=$(mark)
B=$(batch 2026-09-26T09:01:30+09:00 --dry-run --once)
contains "B1 dry-run — 새 줄 7 · 즉시 3 · 요약 칸 +3 (Claude 2 · 요약 규칙 1) · 읽지 못한 줄 1" "새 줄 7개 — 즉시 3 · 하루 요약 칸 +3 (Claude 2 · 요약 규칙 1) · 읽지 못한 줄 1" "$B"
contains "B1 dry-run — 보낼 알림 6건 · Fetch 를 부르지 않는다" "보낼 알림 6건" "$B"
expect "B1 dry-run 전후 상태 파일 sha256 불변 · 요청 0 · 감사 0" "$H_BEFORE|$S0|$A0" "$(sha "$STATE")|$(reqs)|$(mark)"
B=$(batch 2026-09-26T09:01:30+09:00 --once)
expect "B1 본 실행 — 보냄 6(3 규칙 × 기기 2)" "6" "$(( $(reqs) - S0 ))"
DEC=$(decoded "$S0")
note "B1 푸시: $(printf '%s' "$DEC" | tr '\n' ';')"
expect "B1 — AL-1 사람 · AL-8 시스템 · AL-6 사람(옛 형식) 각 2" "2|2|2" \
  "$(printf '%s\n' "$DEC" | grep -c 'alert|AL-1|권한 우회 시도|human|1')|$(printf '%s\n' "$DEC" | grep -c 'alert|AL-8|백업 실패|system|1')|$(printf '%s\n' "$DEC" | grep -c 'alert|AL-6|정합성 불일치|human|1')"
expect "B1 — Claude·AL-9 줄은 즉시 가지 않았다" "0" "$(printf '%s\n' "$DEC" | grep -cE 'AL-10|AL-9')"
expect "B1 — 요약 칸 claude 2 · other 1" "2|1" "$(jq_py "str(d['digest']['claude']) + '|' + str(d['digest']['other'])" < "$STATE")"
PLAIN=$(cat "$WORK"/push/plain-*.json 2>/dev/null)
expect "B1 — 풀어 본 내용에 근거 글자(계정·IP·명령·표식) 0" "0" "$(printf '%s' "$PLAIN" | grep -cE "evil-user|203\.0\.113|psql|$TAG|시험 근거")"
FIRST=$(sed -n "$((S0 + 1))p" "$WORK/push/log.jsonl")
expect "B1 — 머리 TTL 3600 · Urgency high · Content-Encoding aes128gcm" "3600|high|aes128gcm" "$(printf '%s' "$FIRST" | jq_py "d['ttl'] + '|' + d['urgency'] + '|' + d['ce']")"
printf '%s' "$FIRST" | jq_py "d['auth']" > "$WORK/authz.txt"
cat > "$WORK/jwt.php" <<'PHP'
<?php
// Authorization 머리(파일 한 줄) · 공개 키(base64url) → aud exp sub 검증 결과
require $argv[1] . '/src/WebPush.php';
$h = trim(file_get_contents($argv[2]));
if (!preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $h, $m)) { echo "모양아님\n"; exit; }
[$hd, $bd, $sg] = explode('.', $m[1]);
$c = json_decode(WebPush::b64uDecode($bd), true);
$pub = WebPush::publicKeyFromRaw(WebPush::b64uDecode($argv[3]));
$ok = openssl_verify($hd . '.' . $bd, WebPush::rsToDer(WebPush::b64uDecode($sg)), $pub, OPENSSL_ALGO_SHA256);
printf("%s|%s|%s|%s|%d\n", $m[2] === $argv[3] ? 'k=공개키' : 'k다름', $c['aud'], $c['sub'], $ok === 1 ? '서명맞음' : '서명틀림', $c['exp'] - (int) $argv[4]);
PHP
expect "B1 — VAPID: k = 공개 키 · aud = https://fcm.googleapis.com · sub = 사이트 주소 · 서명 검증 · exp = 흉내 시각 + 12시간" \
  "k=공개키|https://fcm.googleapis.com|https://changkwon.mso.so|서명맞음|43200" \
  "$(php "$WORK/jwt.php" "$INST/app" "$WORK/authz.txt" "$VAPID_PUB" "$(date -d 2026-09-26T09:01:30+09:00 +%s)")"
expect "B1 — apple 받기의 aud = https://web.push.apple.com" "https://web.push.apple.com" \
  "$(grep '"web.push.apple.com' "$WORK/push/log.jsonl" | tail -1 | jq_py "d['auth']" > "$WORK/authz2.txt"; php "$WORK/jwt.php" "$INST/app" "$WORK/authz2.txt" "$VAPID_PUB" 0 | cut -d'|' -f2)"
expect "B1 — 받기 last_ok_at 적힘 · fail_count 0" "2|0" "$(psqlq "SELECT count(*) FROM alert_push_subscriptions WHERE id IN ($ID_D1, $ID_D2) AND last_ok_at IS NOT NULL")|$(psqlq "SELECT sum(fail_count) FROM alert_push_subscriptions WHERE id IN ($ID_D1, $ID_D2)")"
AL=$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_send' AND (detail - 'host' - 'status' - 'rule' - 'kind' - 'route') <> '{}'::jsonb")
expect "B1 — 보내기 감사 detail = host · status · rule · kind (+ route) 뿐" "0" "$AL"

sub "B2·B3 시간당 10건 — 11번째는 다음 정시에 「외 n건」"
for i in $(seq 1 11); do alert_line AL-1 human "cap$i-$TAG"; done
S0=$(reqs)
B=$(batch 2026-09-26T10:05:30+09:00 --dry-run --once)
contains "B2 dry-run — 즉시 10 · 시간당 상한으로 미룸 2(기기 둘 × 1)" "시간당 상한으로 미룸 2" "$B"
B=$(batch 2026-09-26T10:05:30+09:00 --once)
expect "B2 — 보냄 20(기기 둘 × 10)" "20" "$(( $(reqs) - S0 ))"
expect "B2 — 상태의 넘친 개수(기기마다 1)" "1|1" "$(jq_py "'|'.join(str(v['overflow']) for k, v in sorted(d['hour'].items()))" < "$STATE")"
S0=$(reqs)
B=$(batch 2026-09-26T10:40:30+09:00 --once)
expect "B2′ 같은 시간 안 — 외 n건을 아직 보내지 않는다" "0" "$(( $(reqs) - S0 ))"
B=$(batch 2026-09-26T11:00:30+09:00 --once)
DEC=$(decoded "$S0")
expect "B3 다음 정시 — 「외 1건」 기기마다 한 건(kind more · n 1)" "2" "$(printf '%s\n' "$DEC" | grep -c ' more|-|-|-|1$')"

sub "B4 하루 요약 — 18시 첫 실행에 한 번 · 0건이면 안 보냄"
S0=$(reqs)
B=$(batch 2026-09-26T17:59:30+09:00 --once)
expect "B4 17:59 — 요약 없음" "0" "$(( $(reqs) - S0 ))"
B=$(batch 2026-09-26T18:00:30+09:00 --dry-run --once)
contains "B4 18:00 dry-run — 요약 3건 보냄 예정" "3건 보냄 예정" "$B"
expect "  dry-run 뒤 요약 칸 그대로(2·1 · last_date 없음)" "2|1|None" "$(jq_py "'%s|%s|%s' % (d['digest']['claude'], d['digest']['other'], d['digest']['last_date'])" < "$STATE")"
B=$(batch 2026-09-26T18:00:30+09:00 --once)
DEC=$(decoded "$S0")
expect "B4 18:00 — 요약 기기마다 한 건 · n 3 · 사람 AL-9 가 섞여 origin human(「[요약]」)" "2" "$(printf '%s\n' "$DEC" | grep -c ' digest|-|-|human|3$')"
DIG=$(sed -n "$((S0 + 1))p" "$WORK/push/log.jsonl")
expect "B4 — 요약 TTL 86400 · Urgency 없음" "86400|None" "$(printf '%s' "$DIG" | jq_py "d['ttl'] + '|' + str(d['urgency'])")"
S0=$(reqs)
B=$(batch 2026-09-26T18:05:30+09:00 --once)
expect "B4 18:05 — 두 번째 요약 없음" "0" "$(( $(reqs) - S0 ))"
alert_line AL-10 claude-review "cr2-$TAG"
B=$(batch 2026-09-26T19:00:30+09:00 --once)
B=$(batch 2026-09-27T18:01:30+09:00 --once)
DEC=$(decoded "$S0")
expect "B4 다음 날 18시 — 전부 Claude 1건 → 「[Claude]」(origin claude-review · n 1)" "2" "$(printf '%s\n' "$DEC" | grep -c ' digest|-|-|claude-review|1$')"
S0=$(reqs)
B=$(batch 2026-09-28T18:01:30+09:00 --once)
contains "B4 그다음 날 — 0건이라 보내지 않는다" "0건이라 보내지 않는다" "$B"
expect "  요청 0" "0" "$(( $(reqs) - S0 ))"

# ════════════════════════════════════════════════════════════════
hr "T6 — 받는 사람 다시 확인 · 전달 결과 처리 · AL-22"
add_sub() {  # $1 기기 $2 endpoint $3 host
  echo "$(basename "$2") $1" >> "$WORK/push/devices.txt"
  psqlq "INSERT INTO alert_push_subscriptions (user_id, endpoint, host, p256dh, auth, device_hint)
         VALUES ($ADMIN_ID, '$2', '$3', decode('$(b2h_dev "$1" p256dh)', 'hex'), decode('$(b2h_dev "$1" auth)', 'hex'), 'Android Chrome') RETURNING id" | head -1
}
ID_GONE=$(add_sub x1 "https://fcm.googleapis.com/f70/gone/x1-$TAG" fcm.googleapis.com)
ID_ERR=$(add_sub x2 "https://fcm.googleapis.com/f70/err/x2-$TAG" fcm.googleapis.com)
ID_BIG=$(add_sub x3 "https://web.push.apple.com/f70/toolarge/x3-$TAG" web.push.apple.com)
ID_RED=$(add_sub x4 "https://fcm.googleapis.com/f70/redir/x4-$TAG" fcm.googleapis.com)
ID_RATE=$(add_sub x5 "https://web.push.apple.com/f70/rate/x5-$TAG" web.push.apple.com)
ID_PRIV=$(add_sub x6 "https://updates.push.services.mozilla.com/f70/ok/x6-$TAG" updates.push.services.mozilla.com)
alert_line AL-13 human "t6-$TAG"
A0=$(mark); S0=$(reqs)
B=$(batch 2026-09-29T12:00:30+09:00 --once)
note "T6 첫 실행: $(printf '%s\n' "$B" | grep '^보냄')"
expect "410 → 해지 gone · 감사 push_revoke(gone) 1" "gone|1" "$(psqlq "SELECT revoked_reason FROM alert_push_subscriptions WHERE id = $ID_GONE")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_revoke' AND detail->>'revoked_reason' = 'gone'")"
expect "500 → fail_count 1 · fail_since 적힘 · 살아 있음" "1|t|(살아 있음)" "$(psqlq "SELECT fail_count || '|' || CASE WHEN fail_since IS NOT NULL THEN 't' ELSE 'f' END || '|' || coalesce(revoked_reason, '(살아 있음)') FROM alert_push_subscriptions WHERE id = $ID_ERR")"
expect "413 → 기록만(fail_count 0 · 다시 보내지 않음)" "0" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_BIG")"
expect "301 → 실패(fail_count 1) · 따라가지 않음" "1" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_RED")"
expect "429 → fail_count 1" "1" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_RATE")"
expect "사설 IP 로 풀림(mozilla) → fail_count 1 · 그 받기로 간 요청 0" "1|0" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_PRIV")|$(grep -c "x6-$TAG" "$WORK/push/log.jsonl")"
expect "대기에 남은 것 = 다시 보낼 셋(500 · 429 · 사설 IP)" "3" "$(jq_py "len([p for p in d['pending'] if p['tries'] == 1])" < "$STATE")"
expect "  429 는 Retry-After(120초) 뒤 · 500 은 기본 50초 뒤" "120|50" \
  "$(jq_py "[p['next_at'] for p in d['pending'] if p['sub'] == $ID_RATE][0] - $(date -d 2026-09-29T12:00:30+09:00 +%s)" < "$STATE")|$(jq_py "[p['next_at'] for p in d['pending'] if p['sub'] == $ID_ERR][0] - $(date -d 2026-09-29T12:00:30+09:00 +%s)" < "$STATE")"
expect "감사 error 사유 — http_status 4(410·500·413·429) · redirect 1 · private_ip 1" "http_status=4 private_ip=1 redirect=1" \
  "$(psqlq "SELECT string_agg(reason || '=' || n, ' ' ORDER BY reason) FROM (SELECT reason, count(*) n FROM audit_logs WHERE id > $A0 AND action = 'alert.push_send' AND result = 'error' GROUP BY reason) q")"
B=$(batch 2026-09-29T12:00:50+09:00 --once)
expect "50초 안 다음 실행 — 아직 다시 보내지 않는다" "1" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_ERR")"
B=$(batch 2026-09-29T12:01:30+09:00 --once)
B=$(batch 2026-09-29T12:02:30+09:00 --once)
expect "500 — 세 번 시도 뒤 버림(fail_count 3) · 대기에는 429 하나만(Retry-After 로 12:01:30 을 건너뛰어 아직 두 번째)" "3|1|2" \
  "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_ERR")|$(jq_py "len(d['pending'])" < "$STATE")|$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_RATE")"
B=$(batch 2026-09-29T12:03:30+09:00 --once)
expect "네 번째는 없다(fail_count 3 그대로)" "3" "$(psqlq "SELECT fail_count FROM alert_push_subscriptions WHERE id = $ID_ERR")"

sub "AL-22 — alerts.php 사본(root 아님 · --state 임시 · --rule AL-22)"
AS="$WORK/alerts-state.json"
out=$(cd "$INST/app" && php scripts/alerts.php --rule AL-22 --state "$AS" 2>&1)
contains "AL-22 — 아직 24시간 전이라 발동 0" "24시간 넘게 실패 중 0개" "$out"
psqlq "UPDATE alert_push_subscriptions SET fail_since = now() - interval '25 hours' WHERE revoked_at IS NULL AND user_id = $ADMIN_ID" >/dev/null
L0=$(wc -l < "$INST/log/alerts.log")
out=$(cd "$INST/app" && php scripts/alerts.php --rule AL-22 --state "$AS" 2>&1)
contains "AL-22 — 살아 있는 받기 전부 24시간 실패 → 발동" "★ AL-22 push 발동 origin=system" "$out"
expect "  alerts.log 새 줄 1 · origin system · 제목 = 규칙 문장 · 근거에 주소 0" "1|system|yes|0" \
  "$(( $(wc -l < "$INST/log/alerts.log") - L0 ))|$(tail -1 "$INST/log/alerts.log" | jq_py "d['origin']")|$(tail -1 "$INST/log/alerts.log" | jq_py "'yes' if d['title'] == d['evidence']['rule_text'] else 'no'")|$(tail -1 "$INST/log/alerts.log" | grep -c 'f70/')"
out=$(cd "$INST/app" && php scripts/alerts.php --rule AL-22 --state "$AS" 2>&1)
contains "AL-22 — 바로 다시 → 중복 억제(이 규칙만 6시간)" "AL-22 push — 6시간 안에 이미 발동해 건너뛴다" "$out"
python3 - "$AS" <<'PY'
import json, sys, datetime
p = sys.argv[1]; d = json.load(open(p))
t = datetime.datetime.fromisoformat(d['AL-22|push']) - datetime.timedelta(minutes=31)
d['AL-22|push'] = t.isoformat(); json.dump(d, open(p, 'w'))
PY
out=$(cd "$INST/app" && php scripts/alerts.php --rule AL-22 --state "$AS" 2>&1)
contains "  31분 전 발동 — 다른 규칙이면 다시 나지만 AL-22 는 아직 억제" "AL-22 push — 6시간 안에 이미 발동해 건너뛴다" "$out"
psqlq "UPDATE alert_push_subscriptions SET revoked_at = now(), revoked_reason = 'user' WHERE id IN ($ID_ERR, $ID_BIG, $ID_RED, $ID_RATE, $ID_PRIV)" >/dev/null   # 실패 받기는 걷는다(임시 DB) — 아래는 d1·d2 만
S0=$(reqs)
B=$(batch 2026-09-29T13:00:30+09:00 --once)
DEC=$(decoded "$S0")
expect "배치가 AL-22 줄도 보낸다(시스템 · 즉시) — 성공한 받기 d1·d2" "2" "$(printf '%s\n' "$DEC" | grep -c 'alert|AL-22|휴대폰 경보 전달 실패|system|1')"
expect "  성공한 받기의 fail_since 는 비워진다(복구)" "(비어 있음)" "$(psqlq "SELECT coalesce(fail_since::text, '(비어 있음)') FROM alert_push_subscriptions WHERE id = $ID_D1")"
alert_line AL-22 system recovered
S0=$(reqs)
B=$(batch 2026-09-29T13:01:30+09:00 --once)
DEC=$(decoded "$S0")
expect "복구 줄(대상 recovered)은 갈래 이름 「휴대폰 경보 전달 복구」로 — d1·d2" "2" "$(printf '%s\n' "$DEC" | grep -c 'alert|AL-22|휴대폰 경보 전달 복구|system|1')"

sub "비활성 Admin — 다음 실행에 보내지 않고 not_admin 해지"
psqlq "UPDATE users SET is_active = false WHERE id = $ADMIN_ID" >/dev/null
alert_line AL-1 human "inactive-$TAG"
S0=$(reqs)
B=$(batch 2026-09-29T14:00:30+09:00 --once)
expect "보냄 0 · d1·d2 → not_admin" "0|not_admin|not_admin" "$(( $(reqs) - S0 ))|$(psqlq "SELECT revoked_reason FROM alert_push_subscriptions WHERE id = $ID_D1")|$(psqlq "SELECT revoked_reason FROM alert_push_subscriptions WHERE id = $ID_D2")"
psqlq "UPDATE users SET is_active = true WHERE id = $ADMIN_ID" >/dev/null
B=$(batch 2026-09-29T14:01:30+09:00 --once)
contains "받기 0 — 대상 받기 0개" "대상 받기 0개" "$B"
expect "배치 종료 코드는 언제나 0(실패가 있어도)" "0" "$(grep -L '휴대폰 경보 배치 끝' "$WORK"/batch-*.out | wc -l | tr -d ' ')"

sub "배치가 시작조차 못 할 때 — 오류 기록 파일(push.conf ERROR_LOG) · dry-run 은 거기에도 쓰지 않는다"
sed "s#^PGPASSFILE=.*#PGPASSFILE=$WORK/no-such.pgpass#" "$WORK/push.conf" > "$WORK/push-badpg.conf"
EL="$WORK/pushlog/error.log"; EL0=$( [ -f "$EL" ] && wc -l < "$EL" | tr -d ' ' || echo 0)
php "$PUSHAPP/scripts/alert_push.php" --conf "$WORK/push-badpg.conf" --dry-run --once > "$WORK/badpg-dry.out" 2>&1; RC=$?
expect "pgpass 없음 · dry-run → 3 · 오류 기록 줄 그대로" "3|$EL0" "$RC|$( [ -f "$EL" ] && wc -l < "$EL" | tr -d ' ' || echo 0)"
php "$PUSHAPP/scripts/alert_push.php" --conf "$WORK/push-badpg.conf" --once > "$WORK/badpg.out" 2>&1; RC=$?
expect "pgpass 없음 · 본 실행 → 3 · 오류 기록 +1 줄(설정 오류 · request_id)" "3|$((EL0 + 1))|1" \
  "$RC|$(wc -l < "$EL" | tr -d ' ')|$(tail -1 "$EL" | grep -c '\[tbox-push\] request_id=[0-9a-f-]\{36\} \[설정 오류\] DB 비밀번호 파일(PGPASSFILE)을 읽을 수 없다')"
expect "  오류 기록에 경로·값 0(pgpass 경로 글자 없음)" "0" "$(grep -c 'no-such.pgpass' "$EL")"

sub "AL-22 stale — 보내는 배치가 돌지 못한다(설정이 있는데 상태 파일이 15분 넘게 그대로)"
# 사본 한 줄 — 설정 파일 자리(코드 상수)만 시험 자리(심볼릭 링크 — 가리키는 곳을 바꿔 가며)로
sed "s#^const AL22_PUSH_CONF = '/etc/tbox-v2/push.conf';#const AL22_PUSH_CONF = '$WORK/pushconf-cur';#" "$INST/app/scripts/alerts.php" > "$INST/app/scripts/alerts_t22.php"
expect "시험 사본 alerts_t22.php — 설정 자리 한 줄만" "1" "$(diff "$INST/app/scripts/alerts.php" "$INST/app/scripts/alerts_t22.php" | grep -c '^>')"
AS2="$WORK/alerts-state2.json"
a22() { ( cd "$INST/app" && php scripts/alerts_t22.php --rule AL-22 --state "$AS2" 2>&1 ); }
ln -sfn "$WORK/no-such-push.conf" "$WORK/pushconf-cur"
L0=$(wc -l < "$INST/log/alerts.log"); out=$(a22)
expect "설정 전(push.conf 없음) — 조용 · 새 줄 0" "yes|0|0" "$(printf '%s' "$out" | grep -q '설정 전(push.conf 없음)' && echo yes || echo no)|$(printf '%s\n' "$out" | grep -c '★')|$(( $(wc -l < "$INST/log/alerts.log") - L0 ))"
ln -sfn "$WORK/push.conf" "$WORK/pushconf-cur"
touch "$STATE"
out=$(a22)
expect "설정 있음 · 상태 파일 방금 — stale 0" "yes|0" "$(printf '%s' "$out" | grep -q '상태 파일 — 있음 · 0분 전' && echo yes || echo no)|$(printf '%s\n' "$out" | grep -c 'AL-22 stale')"
touch -d '20 minutes ago' "$STATE"
L0=$(wc -l < "$INST/log/alerts.log"); out=$(a22)
contains "상태 파일 20분 그대로 → 발동" "★ AL-22 stale 발동 origin=system" "$out"
expect "  alerts.log 새 줄 1 · subject stale · state_file unchanged · 20분 이상 · 근거에 경로 0" "1|stale|unchanged|yes|0" \
  "$(( $(wc -l < "$INST/log/alerts.log") - L0 ))|$(tail -1 "$INST/log/alerts.log" | jq_py "d['evidence']['subject']")|$(tail -1 "$INST/log/alerts.log" | jq_py "d['evidence']['state_file']")|$(tail -1 "$INST/log/alerts.log" | jq_py "'yes' if d['evidence']['minutes'] >= 20 else 'no'")|$(tail -1 "$INST/log/alerts.log" | grep -c "$WORK")"
out=$(a22)
contains "  바로 다시 → 중복 억제(6시간)" "AL-22 stale — 6시간 안에 이미 발동해 건너뛴다" "$out"
mkdir -p "$WORK/state-none"; sed "s#^STATE_DIR=.*#STATE_DIR=$WORK/state-none#" "$WORK/push.conf" > "$WORK/push-nostate.conf"
touch -d '20 minutes ago' "$WORK/push-nostate.conf"; ln -sfn "$WORK/push-nostate.conf" "$WORK/pushconf-cur"
L0=$(wc -l < "$INST/log/alerts.log"); out=$( (cd "$INST/app" && php scripts/alerts_t22.php --rule AL-22 --state "$WORK/alerts-state3.json" 2>&1) )
expect "설정은 20분 전 · 상태 파일이 한 번도 없음 → 발동 · state_file missing" "yes|missing" \
  "$(printf '%s' "$out" | grep -q '★ AL-22 stale 발동' && echo yes || echo no)|$(tail -1 "$INST/log/alerts.log" | jq_py "d['evidence']['state_file']")"
ln -sfn "$WORK/push.conf" "$WORK/pushconf-cur"; touch "$STATE"

sub "AL-22 복구 — 막혔다가 풀리면 한 줄만(origin system)"
expect "앞 실행이 막힘 표시를 남겼다(상태 키 AL-22|down)" "yes" "$(jq_py "'yes' if 'AL-22|down' in d else 'no'" < "$AS2")"
L0=$(wc -l < "$INST/log/alerts.log"); out=$(a22)
contains "상태 파일이 다시 새로 쓰였다 → 복구 한 줄" "★ AL-22 recovered 발동 origin=system" "$out"
expect "  alerts.log 새 줄 1 · subject recovered · origin system · 막힘 표시 지움" "1|recovered|system|no" \
  "$(( $(wc -l < "$INST/log/alerts.log") - L0 ))|$(tail -1 "$INST/log/alerts.log" | jq_py "d['evidence']['subject']")|$(tail -1 "$INST/log/alerts.log" | jq_py "d['origin']")|$(jq_py "'yes' if 'AL-22|down' in d else 'no'" < "$AS2")"
L0=$(wc -l < "$INST/log/alerts.log"); out=$(a22)
expect "  다시 돌려도 복구 줄은 더 없다" "0|0" "$(( $(wc -l < "$INST/log/alerts.log") - L0 ))|$(printf '%s\n' "$out" | grep -c 'recovered')"
H2=$(sha "$AS2"); touch -d '20 minutes ago' "$STATE"
out=$( (cd "$INST/app" && php scripts/alerts_t22.php --rule AL-22 --state "$AS2" --dry-run 2>&1) )
expect "  dry-run 은 막힌 것을 봐도 상태 파일(막힘 표시·억제 시각)을 쓰지 않는다" "yes|$H2|no" \
  "$(printf '%s' "$out" | grep -q 'AL-22 stale' && echo yes || echo no)|$(sha "$AS2")|$(jq_py "'yes' if 'AL-22|down' in d else 'no'" < "$AS2")"
touch "$STATE"

sub "받기 해지 = 해지 + 감사 한 트랜잭션 — 감사가 실패하면 되돌리고 다음 실행에 다시 판정"
ID_X7=$(add_sub x7 "https://fcm.googleapis.com/f70/gone/x7-$TAG" fcm.googleapis.com)
[ -n "$ID_X7" ] || { echo "  ✖ x7 받기를 넣지 못했다"; FAIL=$((FAIL+1)); }
chmod 444 "$WORK/pushlog/audit.log"
alert_line AL-1 human "rv1-$TAG"
A0=$(mark)
B=$(batch 2026-09-30T09:00:30+09:00 --once)
expect "감사 사본을 못 쓰게 한 채 410 → 해지 되돌림 · 받기 살아 있음 · push_revoke 행 0 · 안내 한 줄 · 배치는 끝까지" "(살아 있음)|0|1|1" \
  "$(psqlq "SELECT CASE WHEN revoked_at IS NULL THEN '(살아 있음)' ELSE revoked_reason END FROM alert_push_subscriptions WHERE id = ${ID_X7:-0}")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_revoke'")|$(printf '%s\n' "$B" | grep -c '받기 해지를 되돌렸다')|$(printf '%s\n' "$B" | grep -c '휴대폰 경보 배치 끝')"
chmod 644 "$WORK/pushlog/audit.log"
alert_line AL-1 human "rv2-$TAG"
A0=$(mark)
B=$(batch 2026-09-30T09:01:30+09:00 --once)
expect "감사 사본을 되살린 다음 실행 — 해지 gone · push_revoke 행 1(대상 = 그 받기)" "gone|1" \
  "$(psqlq "SELECT coalesce(revoked_reason, '(살아 있음)') FROM alert_push_subscriptions WHERE id = ${ID_X7:-0}")|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_revoke' AND target_id = ${ID_X7:-0}")"

sub "상태 파일이 깨졌을 때 — 조용히 「처음 켬」으로 가지 않는다(안내 + 감사 한 줄 · 방향은 그대로: 그 사이 줄은 보내지 않음)"
printf 'not json{' > "$STATE"; chmod 600 "$STATE"
alert_line AL-1 human "corrupt-$TAG"
HC0=$(sha "$STATE"); A0=$(mark); S0=$(reqs)
B=$(batch 2026-09-30T09:02:30+09:00 --dry-run --once)
expect "dry-run — 안내 한 줄 · 상태 파일 그대로 · 감사 0 · 요청 0" "1|$HC0|$A0|$S0" "$(printf '%s\n' "$B" | grep -c '상태 파일 깨짐')|$(sha "$STATE")|$(mark)|$(reqs)"
B=$(batch 2026-09-30T09:02:30+09:00 --once)
expect "본 실행 — 안내 한 줄 · 감사 alert.push_send error state_corrupt 1(target_type push_state · detail kind state) · 요청 0" "1|1|0" \
  "$(printf '%s\n' "$B" | grep -c '상태 파일 깨짐')|$(psqlq "SELECT count(*) FROM audit_logs WHERE id > $A0 AND action = 'alert.push_send' AND result = 'error' AND reason = 'state_corrupt' AND target_type = 'push_state' AND detail->>'kind' = 'state'")|$(( $(reqs) - S0 ))"
expect "  상태 파일을 다시 씀 — JSON · 위치 = alerts.log 끝(그 사이 줄 corrupt-… 은 보내지 않았다)" "$(stat -c %s "$INST/log/alerts.log")" "$(jq_py "d['log']['offset']" < "$STATE")"

# ════════════════════════════════════════════════════════════════
hr "T7 — 비밀 격리 (시험으로 되는 만큼)"
ALLAUD=$(psqlq "SELECT coalesce(detail::text, '') || ' ' || summary || ' ' || coalesce(target_name, '') FROM audit_logs WHERE id > $M0 AND (action LIKE 'alert.%' OR action LIKE 'authz.%')")
PRIVBODY=$(sed -n '2p' "$WORK/etc/vapid-private.pem")
SCAN="$WORK/scan.txt"
{ printf '%s\n' "$ALLAUD"; cat "$WORK"/batch-*.out "$WORK/pushlog/audit.log" "$WORK/pushlog/error.log" "$INST/log/audit.log" "$INST/log/alerts.log" "$INST/server.log" "$STATE"; } > "$SCAN" 2>/dev/null
LEAK=0
for k in d1 d2 d3 m1 x1 x2 x3 x4 x5 x6; do
  grep -qF -f "$(kpfx "$k" 24)" "$SCAN" && { LEAK=$((LEAK+1)); note "p256dh $k 샘"; }
  grep -qF -f "$(kf "$k" auth)" "$SCAN" && { LEAK=$((LEAK+1)); note "auth $k 샘"; }
done
grep -qF "$PRIVBODY" "$SCAN" && { LEAK=$((LEAK+1)); note "VAPID 비밀 키 샘"; }
grep -qE "/f70/(ok|gone|err|toolarge|redir|rate)/" "$SCAN" && { LEAK=$((LEAK+1)); note "주소 경로 샘: $(grep -oE "/f70/[a-z]+/[^\" ]*" "$SCAN" | head -2 | tr '\n' ' ')"; }
expect "감사(DB·사본 둘)·배치 출력·경보 줄·웹 서버 로그·상태 파일에 p256dh·auth·VAPID 비밀 키·받기 주소 0" "0" "$LEAK"
expect "상태 파일 키 = v · log · pending · hour · digest (주소·키 칸 없음)" "digest,hour,log,pending,v" "$(jq_py "','.join(sorted(d.keys()))" < "$STATE")"
expect "비밀 키 파일 0600 · pgpass 0600 · 상태 파일 0600" "600|600|600" "$(stat -c %a "$WORK/etc/vapid-private.pem")|$(stat -c %a "$WORK/push.pgpass")|$(stat -c %a "$STATE")"
sub "tbox_app(웹 계정)은 받기를 지울 수 없다 — 인스턴스 사본의 cli_bootstrap 으로"
DELT=$(cd "$INST/app" && php -r 'require "scripts/lib/cli_bootstrap.php"; try { Db::instance()->run("DELETE FROM alert_push_subscriptions WHERE id = -1"); echo "deleted"; } catch (Throwable $e) { echo strpos($e->getMessage(), "permission denied") !== false ? "denied" : "other"; }' 2>/dev/null)
expect "tbox_app DELETE → permission denied" "denied" "$DELT"
UPDT=$(cd "$INST/app" && php -r 'require "scripts/lib/cli_bootstrap.php"; try { Db::instance()->run("UPDATE alert_push_subscriptions SET last_ok_at = now() WHERE id = -1"); echo "updated"; } catch (Throwable $e) { echo strpos($e->getMessage(), "permission denied") !== false ? "denied" : "other"; }' 2>/dev/null)
expect "tbox_app UPDATE last_ok_at → permission denied" "denied" "$UPDT"
skip "T7 OS 계정 경계(tbox-push 0600 · sudo -u tbox-php cat 실패 · id tbox-push · app.log 못 읽음 · alerts.log 읽음)" "계정이 없다 — 운영 반영 스크립트 apply-f70-host 의 확인 절(⑨ 에서 모양만 본다) · 미검증"

# ════════════════════════════════════════════════════════════════
hr "T8 — 앱 · 서비스 워커"
MF=$(curl -s -A "$UA" -H @"$HC" "$BASE/alerts-app.webmanifest")
expect "매니페스트 — start_url · scope · display · 아이콘 둘" "/tbox/alerts-app|/tbox/alerts-app|standalone|192x192,512x512" \
  "$(printf '%s' "$MF" | jq_py "d['start_url'] + '|' + d['scope'] + '|' + d['display'] + '|' + ','.join(i['sizes'] for i in d['icons'])")"
for sz in 192 512; do
  SRC=$(printf '%s' "$MF" | jq_py "[i['src'] for i in d['icons'] if i['sizes'] == '${sz}x${sz}'][0]")
  curl -s -A "$UA" -o "$WORK/icon$sz.png" "$SITE$SRC"
  expect "아이콘 $sz — ?v= 붙음 · PNG · ${sz}×${sz}" "yes|${sz}x${sz}" \
    "$(case "$SRC" in *'?v='[0-9]*) echo yes ;; *) echo no ;; esac)|$(python3 -c 'import struct,sys; h=open(sys.argv[1],"rb").read(24); print("%dx%d" % struct.unpack(">II", h[16:24]) if h[:8]==b"\x89PNG\r\n\x1a\n" else "notpng")' "$WORK/icon$sz.png")"
done
SW=$(curl -s -A "$UA" -H @"$HC" "$BASE/alerts-sw.js")
printf '%s' "$SW" > "$WORK/sw.js"
grep -vE '^\s*(/\*|\*|//)' "$WORK/sw.js" > "$WORK/sw-code.js"   # 주석 줄을 뺀 코드만(설명 글이 낱말에 걸리지 않게)
expect "서비스 워커 처리기 = push · notificationclick 둘뿐(fetch·캐시·불러오기 0 — 코드 줄)" "notificationclick,push|0" \
  "$(grep -oE "addEventListener\('[a-z]+'" "$WORK/sw-code.js" | sed "s/.*('//; s/'//" | sort | paste -sd, -)|$(grep -cE "addEventListener\('fetch'|onfetch|caches\.|importScripts|fetch\(|XMLHttpRequest|indexedDB" "$WORK/sw-code.js")"
expect "여는 주소 = 고정 /tbox/admin · 아이콘 버전 자리 채움" "1|0" "$(grep -c "var OPEN_PATH = '/tbox/admin';" "$WORK/sw.js")|$(grep -c '__ICON_VERSION__' "$WORK/sw.js")"
if python3 -c 'import esprima' 2>/dev/null; then
  for f in "$WORK/sw.js" "$ROOT/public/assets/alerts-app.js" "$ROOT/public/assets/app.js"; do
    expect "esprima 구문 — $(basename "$f")" "ok" "$(python3 -c 'import esprima,sys; esprima.parseScript(open(sys.argv[1],encoding="utf-8").read()); print("ok")' "$f" 2>&1 | tail -1)"
  done
else
  skip "esprima 구문" "esprima 없음"
fi
expect "alerts-app.js — innerHTML 계열 0 · eval 0" "0" "$(grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|eval\(|new Function' "$ROOT/public/assets/alerts-app.js")"
PG=$(curl -s -A "$UA" -H @"$HC" "$BASE/alerts-app")
# 먼저 이 응답이 휴대폰 경보 화면인지 — 셸이 아닌 이 화면에만 있는 표식으로(점검 봇 v3 R23 ②)
expect "화면 /alerts-app 이 맞다 — 이 화면만의 표식(id=push-app · id=push-devices)" "1|1" \
  "$(printf '%s' "$PG" | grep -c 'id="push-app"')|$(printf '%s' "$PG" | grep -c 'id="push-devices"')"
# qcheck-allow: R23 — 아래 셋은 셸 글자지만 $shellPwa === 'alerts' 일 때만 찍힌다 · 이 화면인지는 바로 위 표식 단언 · 다른 화면에 0 인지는 아래 /admin
expect "화면 — 매니페스트 링크(use-credentials) · 아이폰 메타 · alerts-app.js?v=" "1|1|1" \
  "$(printf '%s' "$PG" | grep -c 'rel="manifest" href="/tbox/alerts-app.webmanifest" crossorigin="use-credentials"')|$(printf '%s' "$PG" | grep -c 'apple-mobile-web-app-capable')|$(printf '%s' "$PG" | grep -cE 'src="/tbox/assets/alerts-app\.js\?v=[0-9]+"')"
expect "화면 — 인라인 <script> 0" "0" "$(printf '%s' "$PG" | grep -cE '<script>|<script type="text/javascript">')"
expect "화면 — style 속성 0" "0" "$(printf '%s' "$PG" | grep -c 'style="')"
H_ADMIN=$(curl -s -A "$UA" -H @"$HC" "$BASE/admin")
expect "다른 화면 /admin 이 맞다 — 이 화면만의 표식(id=admin-reauth) · 허브 카드 링크" "1|1" \
  "$(printf '%s' "$H_ADMIN" | grep -c 'id="admin-reauth"')|$(printf '%s' "$H_ADMIN" | grep -c 'href="/tbox/alerts-app"')"
expect "  /admin 에는 매니페스트 링크가 붙지 않는다" "0" "$(printf '%s' "$H_ADMIN" | grep -c 'rel="manifest"')"
expect "  /admin 에는 alerts-app.js 가 붙지 않는다" "0" "$(printf '%s' "$H_ADMIN" | grep -c 'alerts-app.js')"

sub "화면 — 헤드리스(라이트·다크·390px) · 워커 등록/해제 · 빈 문서에서 워커 처리기 흉내"
# 목록에 보일 기기를 되살린다(임시 DB) — 화면은 내 살아 있는 받기를 그린다
psqlq "UPDATE alert_push_subscriptions SET revoked_at = NULL, revoked_reason = NULL WHERE id IN ($ID_D1, $ID_D2)" >/dev/null
psqlq "UPDATE alert_push_outbox SET requested_at = requested_at - interval '11 minutes' WHERE user_id = $ADMIN_ID" >/dev/null
mkdir -p "$OUT"
cat > "$WORK/otp.sh" <<OTPSH
#!/bin/sh
sec=\$(sudo -n awk -v u="\$1" '\$1==u{print \$2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
[ -n "\$sec" ] || exit 1
printf '%s\n' "\$sec" | php "$ROOT/scripts/totp_code.php" -
OTPSH
chmod +x "$WORK/otp.sh"
cat > "$WORK/t8.py" <<'PY'
import json, re, subprocess, sys, time
base, ua, who, otpsh, chrome, shots, swfile = sys.argv[1:8]
pw = sys.stdin.readline().rstrip('\n')   # 비밀번호는 표준입력 첫 줄(R-62)
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)
def fresh_code():
    out = subprocess.run([otpsh, who], capture_output=True, text=True).stdout
    left = re.search(r'남은 시간: (\d+)', out)
    time.sleep(int(left.group(1)) + 1 if left else 31)
    return subprocess.run([otpsh, who], capture_output=True, text=True).stdout.splitlines()[0].strip()
errs = []; out = {'reauth_via': []}; outside = []
def step(name):
    sys.stderr.write('STEP ' + name + '\n'); sys.stderr.flush()
def on_console(m):
    if m.type == 'error' and not m.text.startswith('Failed to load resource'):
        errs.append(m.text)
def pass_reauth(page):
    for _ in range(3):
        if '/reauth' in page.url and page.locator('#reauth-password').count():
            page.fill('#reauth-password', pw); page.click('#reauth-submit'); page.wait_for_timeout(1500)
            out['reauth_via'].append('page'); continue
        dlg = page.locator('#reauth-dialog-form input[type=password]')
        if dlg.count() and dlg.first.is_visible():
            dlg.first.fill(pw); page.click('button[form=reauth-dialog-form]'); page.wait_for_timeout(1500)
            out['reauth_via'].append('modal'); continue
        break
def watch(page):
    page.on('console', on_console)
    page.on('pageerror', lambda e: errs.append('pageerror: ' + str(e)))
    page.on('request', lambda r: outside.append(r.url) if not r.url.startswith(base.split('/tbox')[0]) and not r.url.startswith('data:') and not r.url.startswith('about:') else None)
launch = {'args': ['--no-sandbox']}
if chrome:
    launch['executable_path'] = chrome
with sync_playwright() as p:
    b = p.chromium.launch(**launch)
    ctx = b.new_context(user_agent=ua, viewport={'width': 1440, 'height': 1000}, color_scheme='light')
    page = ctx.new_page(); watch(page)
    step('login')
    page.goto(base + '/login', wait_until='networkidle')
    page.fill('#username', who); page.fill('#password', pw); page.click('#login-submit'); page.wait_for_timeout(1200)
    if page.is_visible('#otp'):
        page.fill('#otp', fresh_code()); page.click('#login-submit'); page.wait_for_timeout(2000)
    step('alerts-app')
    page.goto(base + '/alerts-app', wait_until='networkidle')
    pass_reauth(page)
    if '/alerts-app' not in page.url:
        page.goto(base + '/alerts-app', wait_until='networkidle'); pass_reauth(page)
    page.wait_for_timeout(1500)
    out['url'] = page.url
    out['title'] = page.title()
    out['devices'] = page.eval_on_selector_all('#push-devices > li .list__head strong', 'els => els.map(e => e.textContent)')
    out['device_body'] = page.eval_on_selector_all('#push-devices > li .list__body', 'els => els.map(e => e.textContent)')
    out['state'] = page.text_content('#push-state')
    out['permission'] = page.text_content('#push-permission')
    out['enable_disabled'] = page.is_disabled('#push-enable')
    out['test_disabled'] = page.is_disabled('#push-test')
    out['inline_style'] = page.eval_on_selector_all('main [style]', 'els => els.length')
    out['manifest_link'] = page.eval_on_selector('link[rel=manifest]', 'e => e.href + "|" + e.crossOrigin')
    step('confirm')
    # 해지 확인 창 — 열고 「닫기」 (요청 0)
    page.click('#push-devices > li:first-child button.btn--danger'); page.wait_for_timeout(300)
    out['confirm_open'] = page.evaluate("() => document.getElementById('push-confirm').open")
    page.click('#push-confirm [data-push-confirm-close]:not(.btn--icon)'); page.wait_for_timeout(300)
    out['confirm_closed'] = page.evaluate("() => !document.getElementById('push-confirm').open")
    # 시험 알림 — 대기표 한 행(서버는 보내지 않는다)
    page.click('#push-test'); page.wait_for_timeout(1200)
    out['test_msg'] = page.text_content('#msg')
    step('sw-register')
    # 서비스 워커 등록 — 우리 서버에서 스크립트를 받을 뿐(구독은 하지 않는다 — FCM 요청 0)
    out['sw'] = page.evaluate("""async () => {
        const reg = await navigator.serviceWorker.register('/tbox/alerts-sw.js', {scope: '/tbox/alerts-app'});
        await navigator.serviceWorker.ready;
        const r = {scope: reg.scope, active: !!(reg.active || reg.installing || reg.waiting)};
        r.unregistered = await reg.unregister();
        return r;
    }""")
    step('shots')
    page.screenshot(path=shots + '/f70-light.png', full_page=True)
    page.emulate_media(color_scheme='dark'); page.reload(wait_until='networkidle'); pass_reauth(page); page.wait_for_timeout(1200)
    page.screenshot(path=shots + '/f70-dark.png', full_page=True)
    m = b.new_context(user_agent=ua, viewport={'width': 390, 'height': 844}, color_scheme='light')
    m.add_cookies(ctx.cookies())
    mp = m.new_page(); watch(mp)
    mp.goto(base + '/alerts-app', wait_until='networkidle'); pass_reauth(mp); mp.wait_for_timeout(1200)
    out['mobile_overflow'] = mp.evaluate('() => document.documentElement.scrollWidth > document.documentElement.clientWidth')
    mp.screenshot(path=shots + '/f70-390.png', full_page=True)
    step('sw-sim')
    # 빈 문서 — 워커 처리기에 흉내 self 를 주고 알림 제목·여는 주소를 본다(네트워크 0)
    blank = b.new_context().new_page()
    blank.set_content('<!doctype html><title>sw</title>')
    sw = open(swfile, encoding='utf-8').read()
    blank.add_script_tag(content="""
      var H = {}, shown = [], opened = [];
      var self = {addEventListener: function (t, f) { H[t] = f; },
                  registration: {showNotification: function (t, o) { shown.push([t, o.body, o.icon]); return Promise.resolve(); }},
                  clients: {openWindow: function (u) { opened.push(u); return Promise.resolve(); }}};
    """ + sw)
    out['sw_sim'] = blank.evaluate("""async () => {
        const fire = async (obj, raw) => { await H.push({data: raw === undefined ? {json: () => obj} : {json: () => { throw new Error('x'); }}, waitUntil: p => p}); };
        await fire({v: 1, kind: 'alert', rule: 'AL-1', name: '권한 우회 시도', origin: 'human', at: '2026-09-24T14:05:00+09:00', n: 1});
        await fire({v: 1, kind: 'alert', rule: 'AL-8', name: '백업 실패', origin: 'system', at: '2026-09-24T14:05:00+09:00', n: 1});
        await fire({v: 1, kind: 'digest', rule: null, name: null, origin: 'claude-session', at: '2026-09-24T18:00:00+09:00', n: 37});
        await fire({v: 1, kind: 'digest', rule: null, name: null, origin: 'human', at: '2026-09-24T18:00:00+09:00', n: 5});
        await fire({v: 1, kind: 'more', rule: null, name: null, origin: null, at: '2026-09-24T15:00:00+09:00', n: 4});
        await fire({v: 1, kind: 'test', rule: null, name: null, origin: null, at: '2026-09-24T15:00:00+09:00', n: 1});
        await fire({v: 1, kind: 'alert', rule: 'AL-1<img src=x>', name: '<img src=x onerror=1>' + 'x'.repeat(60), origin: 'robot', at: 'nope', n: 1});
        await fire(null, 'broken');
        await H.notificationclick({notification: {close: () => {}, data: {url: 'https://evil.example/'}}, waitUntil: p => p});
        return {titles: shown.map(s => s[0]), bodies: shown.map(s => s[1]), icon: shown[0][2], opened: opened, handlers: Object.keys(H).sort()};
    }""")
    out['errors'] = errs
    out['outside'] = outside
    b.close()
print(json.dumps(out, ensure_ascii=False))
PY
PW_CHROME=""
for cand in "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome; do
  [ -x "$cand" ] && { PW_CHROME="$cand"; break; }
done
ADMIN_PW=$(sudo -n awk -v u="$ADMIN" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
S0=$(reqs)
# 크로미움은 TMPDIR 에 임시 프로필을 만들고 그 안에 유닉스 소켓을 둔다 — 경로가 길면(108바이트 한도) 시작하자마자 죽는다(F-70 둘째 시험에서 실측).
# TMPDIR 이 길면 브라우저 과정만 시스템 기본(/tmp — playwright 가 만들고 지운다)을 쓴다. 이 시험의 다른 임시 파일은 그대로 $WORK 안이다.
T8_TMP="${TMPDIR:-/tmp}"; [ "${#T8_TMP}" -le 40 ] || T8_TMP=/tmp
( export TMPDIR="$T8_TMP"; py_secret ADMIN_PW -- python3 "$WORK/t8.py" "$BASE" "$UA" "$ADMIN" "$WORK/otp.sh" "$PW_CHROME" "$OUT" "$WORK/sw.js" ) > "$WORK/t8.json" 2> "$WORK/t8.err"
T8_RC=$?
unset ADMIN_PW
if grep -q 'PLAYWRIGHT_MISSING' "$WORK/t8.json" 2>/dev/null; then
  skip "T8 화면 전체" "playwright 가 없다 — 미검증"
elif [ "$T8_RC" -ne 0 ] || [ ! -s "$WORK/t8.json" ]; then
  printf '  ! T8 파이썬 실패 (rc=%s) — 단계 표시·오류 앞부분:\n' "$T8_RC"; grep -E '^STEP|Error|error:' "$WORK/t8.err" | head -12 | sed 's/^/      /'; head -8 "$WORK/t8.err" | sed 's/^/      /'; FAIL=$((FAIL+1))
else
  tq() { jq_py "$1" < "$WORK/t8.json"; }
  note "재인증 경로: $(tq "','.join(d['reauth_via']) or '없음'") · 주소 $(tq "d['url']")"
  expect "T8 화면 제목(t-box 셸 — 브랜드 t-box)" "휴대폰 경보 · t-box" "$(tq "d['title']")"
  expect "T8 받는 기기 목록 2줄(Android Chrome · iPhone Safari — 서버가 준 기기 요약)" "2" "$(tq "len(d['devices'])")"
  expect "T8 목록에 주소·키 글자 0" "0" "$(tq "sum(1 for t in d['device_body'] if '/f70/' in t or 'https' in t)")"
  contains "T8 목록 — 푸시 서버 이름표" "구글(안드로이드 Chrome)" "$(tq "' '.join(d['device_body'])")"
  expect "T8 인라인 style 0 · 매니페스트 링크 use-credentials" "0|$SITE/tbox/alerts-app.webmanifest|use-credentials" "$(tq "d['inline_style']")|$(tq "d['manifest_link']")"
  expect "T8 해지 확인 창 — 열림 · 「닫기」로 닫힘(요청 0)" "True|True" "$(tq "d['confirm_open']")|$(tq "d['confirm_closed']")"
  contains "T8 시험 알림 단추 → 서버 문구" "1분 안에" "$(tq "d['test_msg']")"
  expect "T8 서비스 워커 등록 — 범위 /tbox/alerts-app · 해제됨" "$SITE/tbox/alerts-app|True|True" "$(tq "d['sw']['scope']")|$(tq "d['sw']['active']")|$(tq "d['sw']['unregistered']")"
  expect "T8 390px 가로 넘침 없음" "False" "$(tq "d['mobile_overflow']")"
  expect "T8 콘솔 오류 0" "0" "$(tq "len(d['errors'])")"
  expect "T8 브라우저가 우리 서버 밖으로 보낸 요청 0" "0" "$(tq "len(d['outside'])")"
  expect "T8 가짜 푸시 서버 요청 0(화면은 보내지 않는다)" "$S0" "$(reqs)"
  sub "워커 처리기 흉내 — 제목 모양 · 고정 주소"
  expect "처리기 = notificationclick · push" "notificationclick,push" "$(tq "','.join(d['sw_sim']['handlers'])")"
  expect "제목 1 「[사람] AL-1 권한 우회 시도」" "[사람] AL-1 권한 우회 시도" "$(tq "d['sw_sim']['titles'][0]")"
  expect "제목 2 「[시스템] AL-8 백업 실패」" "[시스템] AL-8 백업 실패" "$(tq "d['sw_sim']['titles'][1]")"
  expect "제목 3 「[Claude] 오늘 경보 37건」" "[Claude] 오늘 경보 37건" "$(tq "d['sw_sim']['titles'][2]")"
  expect "제목 4 「[요약] 오늘 경보 5건」(사람 AL-9 섞임)" "[요약] 오늘 경보 5건" "$(tq "d['sw_sim']['titles'][3]")"
  expect "제목 5 「[경보] 지난 한 시간 외 4건」" "[경보] 지난 한 시간 외 4건" "$(tq "d['sw_sim']['titles'][4]")"
  contains "제목 6 시험" "[시험]" "$(tq "d['sw_sim']['titles'][5]")"
  expect "모양 밖 내용 — 규칙 번호는 「경보」, 출처는 사람, 이름은 40자에서 자름(글자 그대로)" "[사람] 경보 $(python3 -c 'print(("<img src=x onerror=1>" + "x" * 60)[:40])')" "$(tq "d['sw_sim']['titles'][6]")"
  expect "깨진 내용 → 고정 제목 하나(알림은 띄운다)" "[경보] Qaredi" "$(tq "d['sw_sim']['titles'][7]")"
  contains "본문 — 짧은 시각 · 로그인 안내" "09-24 14:05 · 눌러서 관리자 페이지에서 확인하세요" "$(tq "d['sw_sim']['bodies'][0]")"
  expect "누르면 고정 주소 /tbox/admin (data.url 무시)" "/tbox/admin" "$(tq "','.join(d['sw_sim']['opened'])")"
  note "스크린샷(직접 본다): $OUT/f70-light.png · $OUT/f70-dark.png · $OUT/f70-390.png"
fi
skip "T9 실제 기기(안드로이드 Chrome · 아이폰 Safari 홈 화면)" "관문 ③ — 사용자 · 대기"

# ════════════════════════════════════════════════════════════════
hr "T11 — 저장소 쪽만 반영, 서버 쪽은 아직 (키 파일 없음 · 표 없음 · push.conf 없음)"
note "마이그레이션 전 사본 $DBR · 두 번째 인스턴스 127.0.0.1:$RPORT · 시험 사본 두 줄(공개 키 자리 · push.conf 자리 — 둘 다 없는 경로)"
RAPP="$WORK/repoapp"; RLOG="$WORK/repolog"; RNONE="$WORK/repo-none"; mkdir -p "$RAPP" "$RLOG" "$RNONE" "$WORK/hdr-repo"; chmod 700 "$WORK/hdr-repo"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$RAPP" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(WORK 0700 · 사본 0600 로 만듦 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
( umask 077; sed -e "s#^DB_NAME=.*#DB_NAME=$DBR#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$RLOG/audit.log#" "$ENV_SRC" > "$RAPP/.env" )
touch "$RLOG/audit.log" "$RLOG/alerts.log"
sed -i "s#public const PUBLIC_KEY_FILE = '/etc/tbox-v2/vapid-public.txt';#public const PUBLIC_KEY_FILE = '$RNONE/vapid-public.txt';#" "$RAPP/src/WebPush.php"
sed -i "s#^const AL22_PUSH_CONF = '/etc/tbox-v2/push.conf';#const AL22_PUSH_CONF = '$RNONE/push.conf';#" "$RAPP/scripts/alerts.php"
cp "$INST/app/router.php" "$RAPP/router.php"
expect "T11 사본 — 바뀐 줄 두 곳뿐(공개 키 자리 · push.conf 자리) · 표 없음 · 두 파일 없음" "1|1|f|no|no" \
  "$(diff "$ROOT/src/WebPush.php" "$RAPP/src/WebPush.php" | grep -c '^>')|$(diff "$ROOT/scripts/alerts.php" "$RAPP/scripts/alerts.php" | grep -c '^>')|$(printf '%s\n' "SELECT (to_regclass('public.alert_push_subscriptions') IS NOT NULL OR to_regclass('public.alert_push_outbox') IS NOT NULL)" | sudo -n -u postgres psql -X -d "$DBR" -t -A -f - 2>/dev/null)|$([ -e "$RNONE/vapid-public.txt" ] && echo yes || echo no)|$([ -e "$RNONE/push.conf" ] && echo yes || echo no)"
( cd "$RAPP" && exec php -S "127.0.0.1:$RPORT" -t public router.php > "$RLOG/server.log" 2>&1 ) &
RSERVER_PID=$!
RSITE="http://127.0.0.1:$RPORT"; RBASE="$RSITE/tbox"; R_READY=""
for _ in 1 2 3 4 5 6 7 8 9 10; do sleep 1; R_READY=$(code_of "$RBASE/api/me"); [ "$R_READY" = "401" ] && break; done
expect "T11 인스턴스가 떴다(미로그인 /api/me 401)" "401" "$R_READY"
PW=$(sudo -n awk -v u="$ADMIN" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
SEC=$(sudo -n awk -v u="$ADMIN" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
expect "T11 $ADMIN 로그인" "LOGIN_OK" "$(py_secret PW SEC -- python3 "$WORK/login.py" "$RBASE" "$UA" "$ADMIN" "$WORK/hdr-repo")"
unset SEC
RHC="$WORK/hdr-repo/$ADMIN.cookie"; RHCS="$WORK/hdr-repo/$ADMIN.csrf"
RB=$(py_secret PW -- python3 -c 'import json,sys; print(json.dumps({"password": sys.stdin.readline().rstrip("\n")}))')
unset PW
expect "T11 재인증 → 200" "200" "$(curl_json_secret RB -s -o /dev/null -w '%{http_code}' -A "$UA" -H @"$RHC" -H @"$RHCS" -H 'Content-Type: application/json' -X POST "$RBASE/api/me/reauth")"
unset RB
rapi() {  # $1 메서드 $2 경로 [$3 본문] — 결과 "상태", 본문은 $WORK/rapi.body
  local extra=()
  [ "$1" != "GET" ] && extra+=(-H @"$RHCS")
  if [ $# -ge 3 ]; then
    curl -s -o "$WORK/rapi.body" -w '%{http_code}' -A "$UA" -H @"$RHC" "${extra[@]}" -H 'Content-Type: application/json' -X "$1" --data-binary @- "$RBASE$2" <<< "$3"
  else
    curl -s -o "$WORK/rapi.body" -w '%{http_code}' -A "$UA" -H @"$RHC" "${extra[@]}" -X "$1" "$RBASE$2"
  fi
}
rbody() { cat "$WORK/rapi.body"; }
NR_MSG=$(php -r 'require $argv[1]; echo WebPush::NOT_READY_MESSAGE;' "$RAPP/src/WebPush.php")
expect "T11 /tbox/admin → 200 · 관리자 페이지(id=admin-reauth) · 휴대폰 경보 카드" "200|1|1" \
  "$(rapi GET /admin)|$(rbody | grep -c 'id="admin-reauth"')|$(rbody | grep -c 'href="/tbox/alerts-app"')"
expect "T11 /tbox/alerts-app → 200 · 이 화면(id=push-app) · 「준비 안 됨」 안내 · data-push-ready=0" "200|1|1|1" \
  "$(rapi GET /alerts-app)|$(rbody | grep -c 'id="push-app"')|$(rbody | grep -c 'id="push-notready"')|$(rbody | grep -c 'data-push-ready="0"')"
expect "T11 GET /api/admin/push/key → 503 (서버의 알림 키 없음)" "503|yes" "$(rapi GET /api/admin/push/key)|$(rbody | jq_py "'yes' if '준비되지 않았습니다' in d.get('error', '') else 'no'")"
expect "T11 GET /api/admin/push/subscriptions → 503 · 준비 안 됨 문구" "503|$NR_MSG" "$(rapi GET /api/admin/push/subscriptions)|$(rbody | jq_py "d.get('error')")"
expect "T11 POST /api/admin/push/subscriptions(모양 맞는 받기) → 503 · 준비 안 됨 문구" "503|$NR_MSG" \
  "$(rapi POST /api/admin/push/subscriptions "$(sub_json "https://fcm.googleapis.com/f70/ok/repo-$TAG" d1 d1)")|$(rbody | jq_py "d.get('error')")"
expect "T11 POST /api/admin/push/test → 503" "503|$NR_MSG" "$(rapi POST /api/admin/push/test '{}')|$(rbody | jq_py "d.get('error')")"
expect "T11 DELETE /api/admin/push/subscriptions/1 → 503" "503|$NR_MSG" "$(rapi DELETE /api/admin/push/subscriptions/1)|$(rbody | jq_py "d.get('error')")"
expect "T11 서버 로그에 500 · 잡히지 않은 예외 0" "0|0" "$(grep -c '\[500\]' "$RLOG/server.log")|$(grep -ciE 'uncaught|fatal' "$RLOG/server.log")"
sub "T11 경보 판정 alerts.php — 표·설정이 없어도 오류 없이"
: > "$WORK/repo-empty-auth.log"
out=$( (cd "$RAPP" && php scripts/alerts.php --dry-run --state "$WORK/repo-alerts-state.json" --auth-log "$WORK/repo-empty-auth.log" 2>&1) ); RC=$?
expect "T11 alerts.php 전체 --dry-run → 0 · 잡히지 않은 실패 0" "0|0" "$RC|$(printf '%s\n' "$out" | grep -ciE '^\[실패\]|uncaught|fatal')"
expect "  AL-22 — 설정 전(조용) · 받기 표 없음(건너뜀) · AL-22 발동 0" "1|1|0" \
  "$(printf '%s\n' "$out" | grep -c 'AL-22 보내는 배치 — 설정 전')|$(printf '%s\n' "$out" | grep -c 'AL-22 건너뜀 (휴대폰 경보 받기 표가 없거나')|$(printf '%s\n' "$out" | grep -c 'AL-22 [a-z]* 발동\|\[dry-run\] AL-22')"
out=$( (cd "$RAPP" && php scripts/alerts.php --rule AL-22 --state "$WORK/repo-alerts-state.json" 2>&1) ); RC=$?
expect "T11 alerts.php --rule AL-22(본 실행) → 0 · 발동 0 · 경보 줄 0" "0|1|0" "$RC|$(printf '%s\n' "$out" | grep -c '이번에 발동 0건')|$(wc -l < "$RLOG/alerts.log" | tr -d ' ')"
sub "T11 화면 — 헤드리스(라이트·다크·390px) · 켜기·시험 단추 막힘 · 창구 호출 0"
cat > "$WORK/repo.py" <<'PY'
import json, sys
base, ua, cookiefile, chrome, shots = sys.argv[1:6]
try:
    from playwright.sync_api import sync_playwright
except Exception as e:
    print(json.dumps({'PLAYWRIGHT_MISSING': str(e)})); sys.exit(0)
ck = open(cookiefile).read().strip().split('=', 1)[1]   # 「Cookie: tbox_session=…」 한 줄(0600 파일) — 값은 인자에 싣지 않는다
site = base.split('/tbox')[0]
out = {'errors': [], 'push_api': [], 'overflow': {}}
launch = {'args': ['--no-sandbox']}
if chrome:
    launch['executable_path'] = chrome
with sync_playwright() as p:
    b = p.chromium.launch(**launch)
    for name, scheme, w, h in [('light', 'light', 1440, 1000), ('dark', 'dark', 1440, 1000), ('390', 'light', 390, 844)]:
        ctx = b.new_context(user_agent=ua, viewport={'width': w, 'height': h}, color_scheme=scheme)
        ctx.add_cookies([{'name': 'tbox_session', 'value': ck, 'url': site + '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
        page = ctx.new_page()
        page.on('console', lambda m: out['errors'].append(m.text) if m.type == 'error' and not m.text.startswith('Failed to load resource') else None)
        page.on('pageerror', lambda e: out['errors'].append('pageerror: ' + str(e)))
        page.on('request', lambda r: out['push_api'].append(r.method + ' ' + r.url.split('/tbox')[-1]) if '/api/admin/push/' in r.url else None)
        page.goto(base + '/alerts-app', wait_until='networkidle'); page.wait_for_timeout(1000)
        if name == 'light':
            out['url'] = page.url.replace(site, '')
            out['notready_visible'] = page.is_visible('#push-notready')
            out['notready_text'] = (page.text_content('#push-notready') or '').strip()
            out['enable_disabled'] = page.is_disabled('#push-enable')
            out['test_disabled'] = page.is_disabled('#push-test')
            out['state'] = (page.text_content('#push-state') or '').strip()
            out['msg'] = (page.text_content('#msg') or '').strip()
            page.click('#push-enable', force=True); page.wait_for_timeout(400)
        out['overflow'][name] = page.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        page.screenshot(path=shots + '/f70-notready-' + name + '.png', full_page=True)
        ctx.close()
    b.close()
print(json.dumps(out, ensure_ascii=False))
PY
( export TMPDIR="$T8_TMP"; python3 "$WORK/repo.py" "$RBASE" "$UA" "$RHC" "$PW_CHROME" "$OUT" ) > "$WORK/repo.json" 2> "$WORK/repo.err"; R_RC=$?
if grep -q 'PLAYWRIGHT_MISSING' "$WORK/repo.json" 2>/dev/null; then
  skip "T11 화면" "playwright 없음"
elif [ "$R_RC" -ne 0 ] || [ ! -s "$WORK/repo.json" ]; then
  printf '  ! T11 파이썬 실패 (rc=%s):\n' "$R_RC"; head -8 "$WORK/repo.err" | sed 's/^/      /'; FAIL=$((FAIL+1))
else
  rq() { jq_py "$1" < "$WORK/repo.json"; }
  expect "T11 화면 주소 = /tbox/alerts-app(로그인·재인증으로 튕기지 않음)" "/tbox/alerts-app" "$(rq "d['url']")"
  expect "T11 「준비 안 됨」 안내 보임 · 켜기 막힘 · 시험 막힘 · 받기 칸 「준비 안 됨」" "True|True|True|yes" \
    "$(rq "d['notready_visible']")|$(rq "d['enable_disabled']")|$(rq "d['test_disabled']")|$(rq "'yes' if d['state'].startswith('준비 안 됨') else 'no(' + d['state'] + ')'")"
  contains "T11 안내 문구" "서버 쪽 반영" "$(rq "d['notready_text']")"
  expect "T11 창구 호출 0(키·목록·등록 — 막힌 켜기를 눌러도) · 콘솔 오류 0 · 알림 칸 빈 글자" "0|0|(비어 있음)" "$(rq "len(d['push_api'])")|$(rq "len(d['errors'])")|$(rq "d['msg'] or '(비어 있음)'")"
  expect "T11 가로 넘침 없음(라이트 · 다크 · 390px)" "False|False|False" "$(rq "d['overflow']['light']")|$(rq "d['overflow']['dark']")|$(rq "d['overflow']['390']")"
  note "스크린샷(직접 본다): $OUT/f70-notready-light.png · $OUT/f70-notready-dark.png · $OUT/f70-notready-390.png"
fi
kill "$RSERVER_PID" 2>/dev/null; RSERVER_PID=""
sleep 0.5; sudo -n -u postgres dropdb --if-exists "$DBR" >/dev/null 2>&1

# ════════════════════════════════════════════════════════════════
hr "⑨ 반영 스크립트 둘 ($SH_DIR)"
if [ ! -f "$HOST_SH" ] || [ ! -f "$REPO_SH" ]; then
  skip "⑨ 전체" "반영 스크립트가 없다($SH_DIR)"
else
  expect "bash -n 호스트 · 저장소" "0|0" "$(bash -n "$HOST_SH" 2>/dev/null; echo $?)|$(bash -n "$REPO_SH" 2>/dev/null; echo $?)"
  expect "set -euo pipefail 둘" "2" "$(grep -l '^set -euo pipefail' "$HOST_SH" "$REPO_SH" | wc -l | tr -d ' ')"
  expect "해시 자리표시 남은 것 0" "0" "$(grep -c '__[A-Z_]*__' "$HOST_SH" "$REPO_SH" | awk -F: '{s+=$2} END {print s+0}')"
  hv() { sed -n "s/^$1=//p" "$2" | head -1; }
  expect "호스트 해시 — 마이그레이션 · cron · push.conf = 이 트리" "$(sha "$ROOT/db/migrations/2026-09-30_f70_push.sql")|$(sha "$ROOT/deploy/cron.d/tbox-v2")|$(sha "$ROOT/deploy/push.conf")" \
    "$(hv MIG_WANT "$HOST_SH")|$(hv CRON_WANT "$HOST_SH")|$(hv CONF_WANT "$HOST_SH")"
  CODE_OK=yes
  while IFS='=' read -r rel want; do
    [ -n "$rel" ] || continue
    [ "$(sha "$ROOT/$rel")" = "$want" ] || { CODE_OK="no($rel)"; }
  done < <(sed -n '/^CODE_WANT="/,/"$/p' "$HOST_SH" | tr -d '"' | sed 's/^CODE_WANT=//')
  expect "호스트 해시 — 배치 코드 여섯 = 이 트리" "yes" "$CODE_OK"
  expect "저장소 스크립트 해시 = 호스트 스크립트 해시" "$(hv MIG_WANT "$HOST_SH")|$(hv CRON_WANT "$HOST_SH")|$(sed -n '/^CODE_WANT="/,/"$/p' "$HOST_SH" | sha256sum | cut -c1-16)" \
    "$(hv MIG_WANT "$REPO_SH")|$(hv CRON_WANT "$REPO_SH")|$(sed -n '/^CODE_WANT="/,/"$/p' "$REPO_SH" | sha256sum | cut -c1-16)"
  expect "호스트 CRON_ETC_WANT = 지금 운영 /etc/cron.d/tbox-v2(반영 전) 또는 새 판" "yes" \
    "$(n=$(sha /etc/cron.d/tbox-v2); [ "$n" = "$(hv CRON_ETC_WANT "$HOST_SH")" ] || [ "$n" = "$(hv CRON_WANT "$HOST_SH")" ] && echo yes || echo "no(${n:0:16})")"
  expect "호스트 — 쓰이지 않는 도우미 hdr() 없음" "0" "$(grep -c '^hdr() {' "$HOST_SH")"
  ALLOW=$(sed -n "s/^ALLOW='\(.*\)'$/\1/p" "$REPO_SH")
  CHANGED=$(f70_changed . ':!review-scripts')
  if [ -z "$CHANGED" ]; then
    skip "허용 목록 대조" "$F70_SCOPE 이 비었다(커밋까지 끝난 반영 트리) — 패치 자체는 반영 스크립트가 diff --git 줄로 막는다"
  else
    NOTIN=$(printf '%s\n' "$CHANGED" | grep -vE "$ALLOW" || true)
    expect "저장소 스크립트 허용 목록이 이 판의 바뀐 파일(review-scripts 빼고)을 전부 덮는다($F70_SCOPE · $(printf '%s\n' "$CHANGED" | grep -c .)개)" "" "$NOTIN"
  fi
  expect "저장소 스크립트 — 실패 끝 줄 보이기(본보기와 같은 모양)" "2" "$(grep -c "printf '%s\\\\n' \"\$out\" | sed 's/^/     | /'" "$REPO_SH")"
  expect "저장소 스크립트 — 새 시험이 실패하면 되돌리면서 실패 항목·「환경 요인이면 다시」 안내" "1|1|1" \
    "$(grep -cF "{ grep -E '^[[:space:]]*(✖|!)' <<< \"\$full\" || true; } | sed -n '1,15s/^/     | /p'" "$REPO_SH")|$(grep -c '환경 요인' "$REPO_SH")|$(grep -cF 'apply -R "$PATCH"; exit 4' "$REPO_SH")"
  expect "반영 스크립트 둘 — pipefail 아래 SIGPIPE 에 흔들리는 파이프(| grep -q · | head) 0(주석 밖)" "0" \
    "$(grep -nE '\| *(grep -q|head)' "$HOST_SH" "$REPO_SH" | grep -vcE '^[^:]+:[0-9]+:[[:space:]]*#' || true)"
  expect "호스트 — 비밀번호는 표준입력으로만(printf | psql -f -) · 같은 입력 맨 앞에 SET 둘 · 명령 인자에 비밀 0" "1|1|0" \
    "$(grep -cF "printf \"SET log_min_error_statement = 'panic';\\nSET log_statement = 'none';\\nALTER ROLE %s LOGIN PASSWORD '%s';\\n\" \"\$ROLE\" \"\$NEWPW\" \\" "$HOST_SH")|$(grep -cF '    | "${PSQL_SU[@]}" -d postgres -f - >/dev/null || { unset NEWPW; rollback' "$HOST_SH")|$(grep -nE '(-c|--command)[ =]".*PASSWORD|PGPASSWORD=' "$HOST_SH" | grep -vcE '^[0-9]+:\s*#')"
  expect "호스트 — 확인 절의 경계 항목(tbox-php 비밀 키 실패 · 두 폴더 모든 항목 · 목록 못 봄 · adm·sudo 없음 · 배치 dry-run 대상 0 · getfacl)" "6" \
    "$(grep -cE 'cat vapid-private.pem → 실패|for f in "\$ETC"/\* "\$ETC"/\.\[!\.\]\* "\$LOG_DIR"/\* "\$LOG_DIR"/\.\[!\.\]\*; do|ls \$LOG_DIR → 실패|adm·sudo·\$APP_USER 없음|대상 받기 0개|getfacl 되읽기' "$HOST_SH")"
  expect "호스트 — 고정 목록으로 「못 읽음」을 적은 줄 0(폴더를 돈다)" "0" "$(grep -cE 'for f in owner\.pgpass|cat \$LOG_DIR/app\.log|cat \$ETC/seed-accounts' "$HOST_SH")"

  sub "저장소 스크립트 — 운영 트리에 닿기 **전에** 끝나는 거부 셋(패치 sha · 허용 목록 밖 · 이름 바꾸기/복사 머리)"
  if [ "$(id -un)" = "changkwon" ]; then
    printf 'diff --git a/API.md b/API.md\nindex 1..2 100644\n--- a/API.md\n+++ b/API.md\n' > "$WORK/p-ok.patch"
    printf 'diff --git a/src/evil.php b/src/evil.php\nnew file mode 100644\n' > "$WORK/p-out.patch"
    printf 'diff --git a/API.md b/API.md\nsimilarity index 90%%\nrename from API.md\nrename to public/evil.php\n' > "$WORK/p-ren.patch"
    : > "$WORK/repo-all.out"
    rrun() { local rc=0; bash "$REPO_SH" "$@" > "$WORK/repo.out" 2>&1 || rc=$?; cat "$WORK/repo.out" >> "$WORK/repo-all.out"; echo "$rc"; }
    expect "패치 sha 가 다르면 → 1" "1|yes" "$(rrun "$WORK/p-ok.patch" 0000)|$(grep -q 'sha256 이 알려 준 값과 다르다' "$WORK/repo.out" && echo yes || echo no)"
    expect "허용 목록 밖 파일(src/evil.php) → 1" "1|yes" "$(rrun "$WORK/p-out.patch" "$(sha "$WORK/p-out.patch")")|$(grep -q '허용 목록 밖' "$WORK/repo.out" && echo yes || echo no)"
    expect "rename 머리(허용 파일 → public/evil.php) → 1" "1|yes" "$(rrun "$WORK/p-ren.patch" "$(sha "$WORK/p-ren.patch")")|$(grep -q '이름 바꾸기·복사 헤더' "$WORK/repo.out" && echo yes || echo no)"
    expect "  세 갈래 모두 「사전 확인」(운영 트리 읽기) 전에 끝났다" "0" "$(grep -c '== 사전 확인' "$WORK/repo-all.out")"
  else
    skip "저장소 스크립트 거부 셋" "changkwon 이 아니다"
  fi

  sub "호스트 스크립트 사본 — 경로·명령만 임시로(검사 논리는 그대로) · 비root"
  # 상태 폴더($HS/lib)·로그 폴더($HS/plog)는 만들지 않는다 — 스크립트가 만들고, 되돌림이 「이번에 만든 것」으로 지우는지 본다
  HS="$WORK/hs"; mkdir -p "$HS/etc" "$HS/log" "$HS/cron" "$HS/bk" "$HS/bin"
  chmod 750 "$HS/etc" "$HS/log"
  cp /etc/cron.d/tbox-v2 "$HS/cron/tbox-v2" 2>/dev/null || cp "$ROOT/deploy/cron.d/tbox-v2" "$HS/cron/tbox-v2"
  printf '{}\n' > "$HS/log/alerts.log"; chmod 644 "$HS/log/alerts.log"
  : > "$HS/log/app.log"; : > "$HS/log/audit.log"; chmod 000 "$HS/log/app.log" "$HS/log/audit.log"
  mk_mig "$HROLE" "$DBH" > "$WORK/mig-h.sql"
  # 사본 앱 폴더 — 인스턴스 사본 그대로 + 마이그레이션·push.conf 만 시험용(해시 고정값은 사본 스크립트에서 그 파일로 바꾼다)
  HA="$WORK/hsapp"; mkdir -p "$HA"; tar -C "$ROOT" -cf - --exclude=.git --exclude=./.env . | tar -C "$HA" -xf -
  cp "$WORK/mig-h.sql" "$HA/db/migrations/2026-09-30_f70_push.sql"
  sed -e "s#^DB_NAME=.*#DB_NAME=$DBH#" -e "s#^DB_USER=.*#DB_USER=$HROLE#" -e "s#^PGPASSFILE=.*#PGPASSFILE=$HS/etc/push.pgpass#" \
      -e "s#^VAPID_PRIVATE_KEY_FILE=.*#VAPID_PRIVATE_KEY_FILE=$HS/etc/vapid-private.pem#" -e "s#^VAPID_PUBLIC_KEY_FILE=.*#VAPID_PUBLIC_KEY_FILE=$HS/etc/vapid-public.txt#" \
      -e "s#^STATE_DIR=.*#STATE_DIR=$HS/lib#" -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$HS/plog/audit.log#" -e "s#^ALERTS_LOG=.*#ALERTS_LOG=$HS/log/alerts.log#" \
      -e "s#^ERROR_LOG=.*#ERROR_LOG=$HS/plog/error.log#" \
      "$ROOT/deploy/push.conf" > "$HA/deploy/push.conf"
  ME=$(id -un); MYG=$(id -gn)
  mk_host() {  # $1 출력 [$2 AS_APP 명령]
    sed -e '/^\[ "\$(id -u)" -eq 0 \]/d' \
        -e "s#^APP=.*#APP=$HA#" -e "s#^ETC=.*#ETC=$HS/etc#" -e "s#^CRON=.*#CRON=$HS/cron/tbox-v2#" -e "s#^LOG_DIR=.*#LOG_DIR=$HS/log#" \
        -e "s#^PUSH_HOME=.*#PUSH_HOME=$HS/lib#" -e "s#^PUSH_LOG=.*#PUSH_LOG=$HS/plog#" -e "s#^BK_ROOT=.*#BK_ROOT=$HS/bk#" \
        -e "s#^PUSH_USER=.*#PUSH_USER=$ME#" -e "s#^APP_USER=.*#APP_USER=nobody#" -e "s#^DBNAME=.*#DBNAME=$DBH#" -e "s#^ROLE=.*#ROLE=$HROLE#" \
        -e "s#^PSQL_SU=(runuser -u postgres -- psql #PSQL_SU=($HS/bin/psql-rec #" \
        -e "s#^AS_PUSH=.*#AS_PUSH=(env)#" -e "s#^AS_APP=.*#AS_APP=(${2:-sudo -n -u nobody})#" -e "s#^USERADD=.*#USERADD=(true)#" \
        -e "s#^ROOT_USER=.*#ROOT_USER=$ME#" -e "s#^ROOT_GROUP=.*#ROOT_GROUP=$MYG#" \
        -e "s#^L8082=.*#L8082=$SITE#" -e "s#^L80=.*#L80=$SITE#" \
        -e "s#^MIG_WANT=.*#MIG_WANT=$(sha "$HA/db/migrations/2026-09-30_f70_push.sql")#" -e "s#^CONF_WANT=.*#CONF_WANT=$(sha "$HA/deploy/push.conf")#" \
        -e "s#s.setdatabase = 0#s.setdatabase = (SELECT oid FROM pg_database WHERE datname = '$DBH')#" \
        -e "s#^FORBID_GROUPS=.*#FORBID_GROUPS=f70-no-such-group#" -e "s#^NOLOGIN_SHELL=.*#NOLOGIN_SHELL=$(getent passwd "$ME" | cut -d: -f7)#" \
        -e "s#^CRON_ETC_WANT=.*#CRON_ETC_WANT=$(sha "$HS/cron/tbox-v2")#" "$HOST_SH" > "$1"
    ! grep -nE '(/etc/tbox-v2|/var/log/tbox-v2|/var/lib/tbox-push|/var/log/tbox-push|/etc/cron\.d|/var/backups|runuser|useradd --system)' "$1" | grep -vE '^[0-9]+:[[:space:]]*#|echo |CRON_APP=|say "|\.sql'
  }
  # 진짜 psql(postgres)을 부르되 표준입력(-f -)으로 온 문장은 **비밀번호 값만 가려** 적는다 — 같은 입력 안의 문장 순서를 보려고
  cat > "$HS/bin/psql-rec" <<'SH'
#!/bin/sh
log="$(dirname "$0")/../psql-stdin.log"
for a in "$@"; do
  if [ "$a" = "-" ]; then
    t=$(mktemp) || exit 90
    chmod 600 "$t"
    cat > "$t"
    sed -E "s/PASSWORD '[^']*'/PASSWORD '***'/g" "$t" >> "$log"
    printf '%s\n' '-- (입력 끝)' >> "$log"
    sudo -n -u postgres psql "$@" < "$t"; rc=$?
    rm -f "$t"
    exit $rc
  fi
done
exec sudo -n -u postgres psql "$@"
SH
  chmod +x "$HS/bin/psql-rec"
  runh() { local rc=0; ( cd "$HS" && TMPDIR="$WORK" PATH="$HPATH" bash "$@" ) > "$WORK/hs.out" 2>&1 || rc=$?; echo "$rc"; }
  mk_host "$WORK/h.sh" && expect "사본에 운영 경로·root 명령이 남지 않았다" "ok" "ok" || { expect "사본에 운영 경로·root 명령이 남지 않았다" "ok" "left"; }
  # (가) setfacl 이 없는 PATH — 지금 서버 그대로(acl 패키지 없음). 사본에서도 PATH 에 없음을 확인한다
  HPATH="$PATH"
  if command -v setfacl >/dev/null 2>&1; then
    mkdir -p "$WORK/nopath"; for t in bash sed grep awk cut sha256sum stat id cat date diff mktemp rm mkdir chmod php psql sudo curl openssl od head tail tr base64 install shred env sort wc cp dirname basename readlink printf test true; do
      p=$(command -v $t 2>/dev/null) && ln -sf "$p" "$WORK/nopath/$t"; done
    HPATH="$WORK/nopath"
  fi
  RC=$(runh "$WORK/h.sh" --dry-run)
  expect "(가) setfacl 없음 — dry-run → 1 · 안내 「apt install acl」 · 아무것도 바꾸지 않음" "1|yes|0" \
    "$RC|$(grep -q 'apt install acl' "$WORK/hs.out" && echo yes || echo no)|$(ls -A "$HS/etc" | wc -l | tr -d ' ')"
  grep -E '✖|✔' "$WORK/hs.out" | head -40 | sed 's/^/    | /'
  RC=$(runh "$WORK/h.sh")
  expect "(가) setfacl 없음 — 본 실행 → 1 · 바뀐 것 0(설정·키·cron)" "1|0|$(sha "$HS/cron/tbox-v2")" "$RC|$(ls -A "$HS/etc" | wc -l | tr -d ' ')|$(sha "$HS/cron/tbox-v2")"
  # (나) 가짜 setfacl·getfacl — 부른 인자를 적고, 되읽기는 적어 둔 대로 답한다(실제 ACL 은 비root 라 걸지 않는다 — 효과는 운영 확인 절)
  cat > "$HS/bin/setfacl" <<'SH'
#!/bin/sh
# 가짜 setfacl — 부른 인자를 적고(acl.log), 걸린 항목을 한 줄씩 기억한다(acl.state: "<폴더> <항목>")
st="$(dirname "$0")/../acl.state"
printf '%s\n' "$*" >> "$(dirname "$0")/../acl.log"
case "$1" in
  -m) printf '%s %s\n' "$3" "$2" >> "$st" ;;
  -x) grep -v "^$3 " "$st" > "$st.tmp" 2>/dev/null; mv "$st.tmp" "$st" ;;
esac
exit 0
SH
  cat > "$HS/bin/getfacl" <<'SH'
#!/bin/sh
d=$(eval echo "\${$#}")
printf '# file: %s\nuser::rwx\ngroup::r-x\nother::---\n' "$d"
grep "^$d u:" "$(dirname "$0")/../acl.state" 2>/dev/null | sed 's/^[^ ]* u:\([^:]*\):x$/user:\1:--x/'
exit 0
SH
  chmod +x "$HS/bin/setfacl" "$HS/bin/getfacl"; : > "$HS/acl.state"
  HPATH="$HS/bin:$PATH"
  RC=$(runh "$WORK/h.sh" --dry-run)
  expect "(나) 가짜 setfacl — dry-run → 0 · 바뀐 것 0" "0|0|$(sha "$HS/cron/tbox-v2")" "$RC|$(ls -A "$HS/etc" | wc -l | tr -d ' ')|$(sha "$HS/cron/tbox-v2")"
  [ "$RC" = 0 ] || sed 's/^/    | /' "$WORK/hs.out" | tail -15
  printf '# 누가 고침\n' >> "$HS/cron/tbox-v2"
  RC=$(runh "$WORK/h.sh" --dry-run)
  expect "(나) cron 이 알려진 판이 아님 → 1" "1|yes" "$RC|$(grep -q '알려진 판이 아니다' "$WORK/hs.out" && echo yes || echo no)"
  sed -i '$d' "$HS/cron/tbox-v2"
  printf 'x' >> "$HA/src/WebPush.php"
  RC=$(runh "$WORK/h.sh" --dry-run)
  expect "(나) 배치 코드가 검토 통과 판이 아님 → 1" "1|yes" "$RC|$(grep -q 'src/WebPush.php = 검토 통과 판 (기대' "$WORK/hs.out" && echo yes || echo no)"
  cp "$ROOT/src/WebPush.php" "$HA/src/WebPush.php"
  touch "$HS/etc/vapid-private.pem"
  RC=$(runh "$WORK/h.sh" --dry-run)
  expect "(나) VAPID 키가 반쪽 → 1" "1|yes" "$RC|$(grep -q '하나만 있다' "$WORK/hs.out" && echo yes || echo no)"
  rm -f "$HS/etc/vapid-private.pem"
  # (다) 본 실행 — 경계 확인(다른 계정으로 읽기)은 비root 로 흉내 낼 수 없다 → nobody 가 사본 폴더를 못 지나가 「공개 키 읽힘」 항목이 틀린다 → 되돌림
  RC=$(runh "$WORK/h.sh")
  expect "(다) 본 실행 · 경계 확인 틀림 → 되돌림 4" "4" "$RC"
  grep -E '✖' "$WORK/hs.out" | head -6 | sed 's/^/    | /'
  expect "(다) 되돌린 뒤 — 키·설정·pgpass 지움 · cron 그대로 · ACL 걷음 · 역할 NOLOGIN·비밀번호 NULL" "0|$(sha "$HS/cron/tbox-v2")|0|f|(없음)" \
    "$(ls -A "$HS/etc" | wc -l | tr -d ' ')|$(sha "$HS/cron/tbox-v2")|$(grep -c . "$HS/acl.state")|$(psqlpg "SELECT CASE WHEN rolcanlogin THEN 't' ELSE 'f' END || '|' || coalesce(rolpassword, '(없음)') FROM pg_authid WHERE rolname = '$HROLE'")"
  expect "(다) 가짜 setfacl 이 받은 인자 — 걸기 두 곳 x 하나씩 · 걷기 두 곳" "2|2" \
    "$(grep -cE "^-m u:$ME:x $HS/(log|etc)$" "$HS/acl.log")|$(grep -cE "^-x u:$ME $HS/(log|etc)$" "$HS/acl.log")"
  expect "(다) 표·역할은 남는다(되돌림은 「쓸 수 없게」까지)" "t|1" "$(psqlh "SELECT to_regclass('public.alert_push_subscriptions') IS NOT NULL")|$(psqlpg "SELECT count(*) FROM pg_roles WHERE rolname = '$HROLE'")"
  expect "(다) 이번에 새로 만든 폴더 둘(상태 · 로그) 지움" "no|no|1|1" \
    "$([ -e "$HS/lib" ] && echo yes || echo no)|$([ -e "$HS/plog" ] && echo yes || echo no)|$(grep -c "이번에 만든 $HS/lib 지움" "$WORK/hs.out")|$(grep -c "이번에 만든 $HS/plog 지움" "$WORK/hs.out")"
  expect "(다) 남는 것의 손 명령 — 표 DROP TABLE · 역할 DROP OWNED/ROLE(이번에 만듦) · 계정은 이번에 만들지 않음(userdel 줄 없음)" "1|1|1|0" \
    "$(grep -c '^== 남는 것' "$WORK/hs.out")|$(grep -c "DROP TABLE public.alert_push_outbox, public.alert_push_subscriptions" "$WORK/hs.out")|$(grep -c "DROP OWNED BY $HROLE' && sudo -u postgres psql -d postgres -c 'DROP ROLE $HROLE'" "$WORK/hs.out")|$(grep -c 'userdel' "$WORK/hs.out")"
  # (다′) 폴더를 도는 확인 — 웹 계정·목록 항목만 비운 사본 h3(다른 계정으로 흉내 낼 수 없는 것). 허용 셋 밖 파일이 읽히면 잡혀서 되돌림
  sed -E -e '/say "\[경계-(웹|목록)\]/s/^([[:space:]]*).*$/\1:/' "$WORK/h.sh" > "$WORK/h3.sh"
  expect "(다′) 사본 h3 — 웹·목록 항목 넷 비움 · 폴더 항목은 그대로 · 문법 그대로" "4|1|0" \
    "$(diff "$WORK/h.sh" "$WORK/h3.sh" | grep -c '^>')|$(grep -c 'say "\[경계-폴더\]' "$WORK/h3.sh")|$(bash -n "$WORK/h3.sh" 2>/dev/null; echo $?)"
  printf 'x\n' > "$HS/etc/other.secret"; chmod 644 "$HS/etc/other.secret"
  RC=$(runh "$WORK/h3.sh")
  expect "(다′) 허용 셋 밖 파일이 읽힘($HS/etc/other.secret) → 그 항목 ✖ · 되돌림 4" "4|1" \
    "$RC|$(grep -c "✖ \[경계-폴더\] sudo -u $ME 읽기 $HS/etc/other.secret" "$WORK/hs.out")"
  expect "  (다′) 앞 실행이 표·역할을 남겼으니 이번에는 DROP 줄 없이 RESET ALL 만" "0|1" \
    "$(grep -c 'DROP TABLE' "$WORK/hs.out")|$(grep -c "ALTER ROLE $HROLE RESET ALL" "$WORK/hs.out")"
  expect "  허용 셋(키 둘·설정·pgpass · alerts.log)은 read 로 통과했다" "5" "$(grep -cE "✔ \[경계-폴더\] .*($HS/etc/(push\.conf|push\.pgpass|vapid-private\.pem|vapid-public\.txt)|$HS/log/alerts\.log) " "$WORK/hs.out")"
  rm -f "$HS/etc/other.secret"
  # (라) 경계 항목을 뺀 사본 — 나머지 흐름(키·설정·마이그레이션·로그인·cron·확인·「이미 됨」)
  # 다른 계정으로 읽는 항목만 「:」 로 바꾼다(줄을 지우면 for 몸이 비어 문법이 깨진다)
  sed -E -e '/say "\[경계/s/^([[:space:]]*).*$/\1:/' "$WORK/h.sh" > "$WORK/h2.sh"
  expect "(라) 사본 h2 — 경계 항목 다섯(웹 셋 · 폴더 · 목록) 비움 · 문법 그대로" "5|0" "$(diff "$WORK/h.sh" "$WORK/h2.sh" | grep -c '^>')|$(bash -n "$WORK/h2.sh" 2>/dev/null; echo $?)"
  : > "$HS/acl.log"
  RC=$(runh "$WORK/h2.sh")
  expect "(라) 경계 항목 뺀 사본 — 본 실행 → 0" "0" "$RC"
  [ "$RC" = 0 ] || sed 's/^/    | /' "$WORK/hs.out" | grep -E '✖|==' | head -20
  grep -E '^   ✔' "$WORK/hs.out" | head -30 | sed 's/^/    | /'
  expect "(라) 키 0600 · 공개 키 0644 · 설정 0644 · pgpass 0600 · cron = 새 판 · 백업 있음" "600|644|644|600|$(sha "$HA/deploy/cron.d/tbox-v2")|1" \
    "$(stat -c %a "$HS/etc/vapid-private.pem")|$(stat -c %a "$HS/etc/vapid-public.txt")|$(stat -c %a "$HS/etc/push.conf")|$(stat -c %a "$HS/etc/push.pgpass")|$(sha "$HS/cron/tbox-v2")|$(ls "$HS"/bk/f70-push-*/tbox-v2.cron 2>/dev/null | wc -l | tr -d ' ')"
  # 비밀번호 값은 사본 pgpass 에서 꺼내 grep -F 의 **파일**(프로세스 치환)로만 넘긴다 — 명령 인자에 싣지 않는다
  expect "(라) 출력에 비밀 0(PEM · pgpass 줄 · 그 비밀번호 글자)" "0|0" "$(grep -cE 'BEGIN|PRIVATE KEY|:5432:' "$WORK/hs.out")|$(grep -cF -f <(cut -d: -f5 "$HS/etc/push.pgpass") "$WORK/hs.out")"
  expect "(라) 역할 로그인 · 시간 제한(IN DATABASE $DBH)" "t" "$(psqlpg "SELECT rolcanlogin FROM pg_roles WHERE rolname = '$HROLE'")"
  expect "(라) 비밀번호 문장이 간 입력 — SET log_min_error_statement=panic · SET log_statement=none 이 ALTER 보다 먼저 같은 입력에(실행마다)" "yes" \
    "$(python3 - "$HS/psql-stdin.log" <<'PY'
import sys
blocks = [b for b in open(sys.argv[1], encoding='utf-8').read().split('-- (입력 끝)') if "LOGIN PASSWORD '***'" in b]
ok = bool(blocks)
for b in blocks:
    i1, i2, i3 = b.find("SET log_min_error_statement = 'panic';"), b.find("SET log_statement = 'none';"), b.find('ALTER ROLE')
    ok = ok and 0 <= i1 < i3 and 0 <= i2 < i3
print('yes' if ok else 'no(%d)' % len(blocks))
PY
)"
  expect "(라) 기록에 비밀번호 값 0(가린 글자만)" "0" "$(grep -cF -f <(cut -d: -f5 "$HS/etc/push.pgpass") "$HS/psql-stdin.log")"
  PK1=$(sha "$HS/etc/vapid-private.pem"); PG1=$(sha "$HS/etc/push.pgpass")
  RC=$(runh "$WORK/h2.sh")
  expect "(라) 한 번 더 — 이미 됨 → 0 · 키·pgpass 바뀌지 않음 · 새 백업 없음" "0|$PK1|$PG1|1" \
    "$RC|$(sha "$HS/etc/vapid-private.pem")|$(sha "$HS/etc/push.pgpass")|$(ls -d "$HS"/bk/f70-push-* 2>/dev/null | wc -l | tr -d ' ')"
  [ "$RC" = 0 ] || grep -E '✖|==' "$WORK/hs.out" | head -12 | sed 's/^/    | /'
  grep -qE '그대로\(바꾸지 않는다\)' "$WORK/hs.out" && expect "(라) 「키 그대로(바꾸지 않는다)」" "yes" "yes" || expect "(라) 「키 그대로(바꾸지 않는다)」" "yes" "no"
  RC=$(runh "$WORK/h3.sh")
  expect "(라) 이미 된 상태에서 h3(폴더를 도는 확인 살림) → 0 · 폴더 항목 ✖ 0" "0|0" "$RC|$(grep -c '✖ \[경계-폴더\]' "$WORK/hs.out")"
  chmod 644 "$HS/etc/push.pgpass"
  RC=$(runh "$WORK/h2.sh")
  expect "(라) 이미 된 상태에서 확인이 틀림(pgpass 0644) → 5 · 되돌리지 않는다(pgpass·키 그대로 · cron 그대로)" "5|yes|$PG1|$PK1|$(sha "$HA/deploy/cron.d/tbox-v2")" \
    "$RC|$(grep -q '이번 실행이 바꾼 것이 없으므로 되돌리지 않는다' "$WORK/hs.out" && echo yes || echo no)|$(sha "$HS/etc/push.pgpass")|$(sha "$HS/etc/vapid-private.pem")|$(sha "$HS/cron/tbox-v2")"
  chmod 600 "$HS/etc/push.pgpass"
fi

# ════════════════════════════════════════════════════════════════
hr "운영 받기로 간 요청 0 — 사본에 딸려 온 운영 받기(휴대폰 주소)는 ① 에서 걷었고, 요청은 모두 시험 주소로만(디버깅 묶음 T)"
# 시험이 등록한 받기는 모두 https://<푸시 회사>/f70/<응답 모양>/<이름> 이다. 운영 받기 주소(…/fcm/send/… · 애플의 토큰 경로 등)는
#   /f70/ 으로 시작하지 않으므로, 가짜 푸시 서버(시험 사본의 이름 풀이가 fcm·apple 을 여기로 보낸다)가 받은 요청 중 /f70/ 밖이 0 이면
#   운영 받기로 보낸 요청이 없었다는 뜻이다(주소 값은 읽지도 찍지도 않는다 — 개수만).
PUSH_ALL=$(reqs)
PUSH_OUT=$(python3 -c 'import json,sys
n = 0
for l in open(sys.argv[1], encoding="utf-8"):
    if l.strip() and not str(json.loads(l).get("path", "")).startswith("/f70/"):
        n += 1
print(n)' "$WORK/push/log.jsonl" 2>/dev/null || echo "?")
expect "가짜 푸시 서버가 받은 요청 중 시험 주소(/f70/…) 밖 — 운영 받기로 간 요청" "0" "$PUSH_OUT"
expect "  (자기 확인) 대조가 헛돌지 않았다 — 가짜 푸시 서버가 받은 요청 30건 이상" "yes" "$([ "${PUSH_ALL:-0}" -ge 30 ] 2>/dev/null && echo yes || echo "no(${PUSH_ALL:-0})")"
expect "  임시 DB 받기 표의 줄이 모두 시험이 등록한 주소(/f70/…)" "0" \
  "$(psqlq "SELECT count(*) FROM alert_push_subscriptions WHERE endpoint NOT LIKE 'https://' || host || '/f70/%'")"

# ════════════════════════════════════════════════════════════════
hr "T10 — 정적"
bash "$ROOT/scripts/security_lint.sh" > "$WORK/lint.out" 2>&1
expect "security_lint.sh 종료 코드" "0" "$?"
expect "  ✖ 줄 수" "0" "$(grep -c '^✖' "$WORK/lint.out")"
tail -1 "$WORK/lint.out" | sed 's/^/    /'
PHPBAD=0
for f in src/WebPush.php src/Fetch.php src/Audit.php src/Origin.php public/index.php scripts/alert_push.php scripts/alerts.php \
         scripts/lib/log_tail.php scripts/lib/push_bootstrap.php scripts/vapid_keygen.php src/views/shell_top.php src/views/shell_bottom.php \
         src/help/screens.php src/handlers/admin_page.php src/handlers/push_app_page.php src/handlers/push_key.php src/handlers/push_manifest.php \
         src/handlers/push_subscription_delete.php src/handlers/push_subscriptions.php src/handlers/push_sw.php src/handlers/push_test.php \
         tests/f70_webpush_vectors.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "php -l (바뀐·새 PHP 스물둘)" "0" "$PHPBAD"
BASHBAD=0
for f in tests/f70.sh deploy/install.sh; do bash -n "$ROOT/$f" 2>/dev/null || { echo "  ✖ bash -n $f"; BASHBAD=1; }; done
expect "bash -n (시험 · install.sh)" "0" "$BASHBAD"
# 위키 검사는 저장소 바깥의 사이트 STAGES 두 개를 읽는다 — tests/wiki_check.sh 가 사이트 루트를 정한다(인자·QAREDI_SITE_ROOT·저장소 부모).
#   찾지 못하면 「환경 없음 — 건너뜀」(복제본) — 실패로 세지 않는다(디버깅 묶음 T)
bash "$ROOT/tests/wiki_check.sh" > "$WORK/wiki.out" 2>&1; WRC=$?
if grep -q '^RESULT pass=0 fail=0 skip=1$' "$WORK/wiki.out" && [ "$WRC" = 0 ]; then
  skip "wiki_check" "환경 없음 — 사이트 STAGES 를 찾지 못함(QAREDI_SITE_ROOT 로 줄 수 있다)"
else
  expect "wiki_check (tests/wiki_check.sh) 종료 코드" "0" "$WRC"
  grep -E '^(결과|사이트 루트)' "$WORK/wiki.out" | sed 's/^/    /'
fi
expect "지시서 본문 무변경(docs/f70-push-alerts-directive.md · $F70_SCOPE)" "0" "$(f70_changed docs/f70-push-alerts-directive.md | grep -c .)"
expect "저장소에 키·pgpass 파일 0(추적 파일 + 이 판의 새 파일 중 PEM·pgpass)" "0" "$( { git -C "$ROOT" ls-files; f70_changed .; } | sort -u | grep -cE '\.pem$|pgpass$|vapid-private')"
note "회귀 시험은 이 스크립트가 돌리지 않는다 — 따로: f69.sh · stage26.sh · help_button.sh · f68.sh · alerts_dryrun_offset.sh · tests/static R15·R16·R18"

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
printf 'RESULT pass=%d fail=%d skip=%d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB · $DBH · $DBR)·시험 역할($TROLE · $HROLE)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 스크린샷만 $OUT 에 남는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
