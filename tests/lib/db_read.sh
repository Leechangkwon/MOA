# tests/lib/db_read.sh — 시험이 운영 DB 를 「읽기만」 할 때 sudo 대신 읽기 전용 역할로 (점검 봇 1-E · F-69 K-3)
#
# 왜 있나
#   경보 AL-9(위험한 sudo)의 소음 대부분은 시험의 R-73 사전 검사·행 수 대조가 `sudo -u postgres psql` 로
#   운영 DB 를 **읽는** 데서 난다. F-69 는 AL-9 판정을 넓히지 않는다(K-1). 대신 읽기만 하는 줄은 로그인 계정용
#   읽기 전용 역할 `tbox_testread`(열 단위 SELECT 만 · default_transaction_read_only · statement_timeout 10s —
#   /var/www/qaredi/scripts/apply-test-readonly-role-2026-09-24.sh 가 만든다)로 읽는다.
#
# 쓰는 법 — 각 시험의 ROOT 계산 바로 뒤(review_accounts.sh 다음)
#     . "$ROOT/tests/lib/db_read.sh" || { echo "…"; exit 3; }
#   db_read <db> <sql>             결과를 표준출력으로(`X=$(db_read …)`). 실패하면 빈 출력 + rc 3
#   db_read_var <변수> <db> <sql>  결과를 <변수>에 넣고 DB_READ_VIA=ro|sudo 를 **이 셸에** 남긴다.
#                                  R-73 사전 검사처럼 「읽지 못하면 시작하지 않는다」 자리에 쓴다:
#                                      db_read_var BUSY "$DB" "SELECT …" || { echo "…"; exit 3; }
#                                  (`X=$(db_read …)` 는 서브셸이라 rc 3 이 부른 쪽을 끝내지 못하고, 빈 값이
#                                   `${X:-0}` 으로 0 이 돼 관문이 열린다 — 관문 자리에는 반드시 db_read_var)
#
# 순서(1-E 지시서)
#   1. ro 경로: PGPASSFILE(기본 ~/.config/qaredi/pgpass-testread) 이 **내 소유 0600 일반 파일**이면
#      `psql -h 127.0.0.1 -p 5432 -U tbox_testread -w -X -A -t -v ON_ERROR_STOP=1` 로 읽는다.
#      접속 옵션: application_name=tbox-testread · default_transaction_read_only=on(PGOPTIONS) · 접속 5초 제한.
#      비밀번호는 .pgpass 에만 있다 — 인자·환경 변수·출력 어디에도 없다(PGPASSFILE 는 경로일 뿐).
#   2. ro 경로가 없거나(파일 없음·권한 모양 다름) 실패하면(접속 실패·권한 오류 42501·시간 초과) 한 줄 경고를
#      표준오류에 찍고 지금 방식 `sudo -n -u postgres psql -d <db> -X -A -t -v ON_ERROR_STOP=1` 로 떨어진다(READ ONLY 세션으로).
#      **실패를 0 으로 읽지 않는다** — ro 경로 출력은 psql 이 0 으로 끝났을 때만 쓴다.
#   3. 둘 다 실패하면 rc 3(시작 거부), 출력 없음.
#   SQL 은 `-c` 인자로 준다(표준입력이 아니다) — `-c` 에는 psql 메타 명령(`\!` 등)이 SQL 과 섞일 수 없고, sudo 경로의
#   auth.log `COMMAND=` 에 「무엇을 읽었나」가 그대로 남아 AL-9 경보를 본 사람이 읽기였음을 가를 수 있다(1-B-1 독립 검토 정보 6).
#   이 SQL 들은 판정 조각·시각뿐이고 비밀이 없다(비밀 열·비밀 값을 이 함수로 보내지 않는다).
#   두 경로 모두 그 앞에 `-c 'SET standard_conforming_strings = on'`·`-c 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'`
#   (sudo 경로는 그보다 먼저 `-c 'SET ROLE pg_read_all_data'`)를 돌린다 — sudo 경로의 postgres 는
#   슈퍼유저라, 이것이 없으면 「ro 경로가 쓰기를 거부 → 실패로 보고 sudo 로 폴백 → 쓰기가 실행」되는 길이 있었다(독립 검토 ②-2,
#   임시 클러스터에서 재현).
#
# 금지(1-E) — 쓰기 쿼리(INSERT·UPDATE·DELETE·DDL)를 이 함수로 보내지 않는다. 쓰기는 지금 방식 그대로다.
#   막는 것은 네 겹이다: ① 허용 목록 판정기(_db_read_sql_ok — 아래) ② 두 경로 모두 세션을 READ ONLY 로 먼저 묶는다
#   ③ ro 경로는 역할 권한(열 SELECT 만 · public 스키마 CREATE 없음 — 09-24 확인) ④ sudo 폴백은 `SET ROLE pg_read_all_data`
#   로 슈퍼유저 권한을 내려놓는다(읽기만 되는 PG14 내장 역할 — 쓰기·복제 슬롯·슈퍼유저 함수 불가). 독립 재검토(1-B-1)가
#   임시 클러스터에서 막는 목록 방식의 우회 5가지(주석 속 따옴표 · E'…' · $tag$ · BEGIN READ WRITE · 부수효과 함수)를 재현한 뒤의 판이다.
#   이 함수는 판정(무엇을 「사용자 활동」으로 보나)을 바꾸지 않는다 — 판정 조각은 review_accounts.sh 한 곳이다.
#
# 이 파일은 실행하지 않고 source 만 한다(실행 비트·shebang 없음).

DB_READ_ROLE="tbox_testread"
_DBR_RO_SET="SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY"
_DBR_SCS="SET standard_conforming_strings = on"      # '…' 안의 \ 는 글자 그대로 — 판정기의 따옴표 해석과 서버를 맞춘다
_DBR_ROLE="SET ROLE pg_read_all_data"                 # sudo 폴백은 슈퍼유저가 아니라 PG14 내장 「모든 데이터 읽기」 역할로
DB_READ_VIA=""

_db_read_pgpass() {
  local f="${PGPASSFILE:-$HOME/.config/qaredi/pgpass-testread}"
  [ -e "$f" ] || return 1                        # 역할을 아직 안 만들었다 — 조용히 sudo 경로
  if [ ! -f "$f" ] || [ -L "$f" ] || [ ! -O "$f" ] || [ "$(stat -c %a "$f" 2>/dev/null)" != "600" ]; then
    echo "db_read: $f 가 내 소유 0600 일반 파일이 아니다 — 쓰지 않고 sudo 경로로 떨어진다" >&2
    return 1
  fi
  printf '%s' "$f"
}

# 허용 목록 판정기 — 시험이 이 함수로 보내는 것은 `SELECT count(*)/max(id) … WHERE …` 뿐이다(35곳 전수, 1-B-1).
# 그 모양 **밖은 전부** 보내지 않는다(rc 2). 막는 목록 방식은 재검토에서 주석·E'…'·$tag$·BEGIN READ WRITE 로 뚫렸다.
#   · 첫 낱말 SELECT(앞 괄호 허용) · 따옴표는 표준 문자열 '…'('' 이스케이프)만 — 앞에 글자가 붙은 E'·U&'·B'·X' 는 거부
#   · 따옴표 밖에 ;(맨 끝 하나 말고)·$·\·"·--·/*·*/ 가 있으면 거부
#   · 따옴표 밖 낱말에 트랜잭션 제어·쓰기·DDL·세션 설정 낱말이 있으면 거부
#   · 따옴표 밖 「이름(」 은 count·max·min·sum·coalesce·now·in·exists 만 · 스키마로 한정한 호출(x.f()) 거부
#   · ASCII 밖 글자·제어 문자(줄바꿈·탭 말고) 거부 — 판정기와 서버의 공백·이름 해석이 갈리는 틈(2차 재검토)
#   파이썬이 없으면 보내지 않는다(닫힌 쪽으로 실패).
_db_read_sql_ok() {
  command -v python3 >/dev/null 2>&1 || { echo "db_read: python3 가 없어 문장을 검사하지 못한다 — 보내지 않는다" >&2; return 1; }
  local why
  why=$(python3 -c '
import re, sys
s = sys.argv[1]
def no(m):
    print(m); sys.exit(0)
if re.search(r"[^\x20-\x7e\n\t]", s):
    no("ASCII 밖 글자·제어 문자")
out, i, n = [], 0, len(s)
while i < n:
    c = s[i]
    if c == "\x27":
        if i and re.match(r"[A-Za-z0-9_&]", s[i - 1]):
            no("접두가 붙은 따옴표(E\x27·U&\x27 등)")
        j = i + 1
        while True:
            k = s.find("\x27", j)
            if k < 0:
                no("따옴표가 닫히지 않음")
            if k + 1 < n and s[k + 1] == "\x27":
                j = k + 2
                continue
            break
        out.append(" \x27\x27 ")
        i = k + 1
        continue
    out.append(c)
    i += 1
o = re.sub(r";\s*$", "", "".join(out))
for bad in (";", "$", "\\", "\"", "--", "/*", "*/"):
    if bad in o:
        no("따옴표 밖 " + bad)
words = re.findall(r"[A-Za-z_][A-Za-z0-9_]*", o)
if not words or words[0].upper() != "SELECT" or not re.match(r"^[ \t\n(]*select\b", o, re.I):
    no("첫 낱말이 SELECT 가 아님")
BAD = set("""begin commit rollback start end abort savepoint release prepare execute deallocate do call copy
 insert update delete merge upsert create drop alter grant revoke truncate vacuum analyze cluster reindex lock
 notify listen unlisten set reset discard load security comment refresh import checkpoint into returning
 transaction session""".split())
for w in words:
    if w.lower() in BAD:
        no("쓰기·제어 낱말 " + w)
OK = {"count", "max", "min", "sum", "coalesce", "now", "in", "exists"}
if re.search(r"[A-Za-z0-9_]\s*\.\s*[A-Za-z_][A-Za-z0-9_]*\s*\(", o):
    no("스키마로 한정한 함수 호출")
for f in re.findall(r"([A-Za-z_][A-Za-z0-9_]*)\s*\(", o):
    if f.lower() not in OK:
        no("허용 목록 밖 함수 " + f)
print("ok")
' "$1" 2>/dev/null)
  if [ "$why" != "ok" ]; then
    echo "db_read: 허용 모양(SELECT count·max … WHERE …)이 아니다(${why:-검사 실패}) — 이 문장은 보내지 않는다" >&2
    return 1
  fi
  return 0
}

# $1 db  $2 sql  $3 받을 변수 이름 — 결과를 printf -v 로 넣고 _DBR_VIA 에 경로를 남긴다. rc 0 · 2 · 3
_db_read_run() {
  local _dr_db="$1" _dr_sql="$2" _dr_to="$3" _dr_out _dr_err _dr_pf
  case "$_dr_db" in ''|*[!A-Za-z0-9_]*) echo "db_read: DB 이름 모양이 아니다" >&2; return 2 ;; esac
  _db_read_sql_ok "$_dr_sql" || return 2
  if _dr_pf=$(_db_read_pgpass); then
    # 부른 쪽의 PGHOST·PGPORT·PGHOSTADDR·PGSERVICE 를 따르지 않는다 — 다른 클러스터를 읽고 「ro 로 읽었다」고 하는 길을
    # 막는다(독립 검토 ②-3, PGPORT 로 재현). 주소는 .pgpass 줄과 같은 127.0.0.1:5432 로 못 박는다.
    if _dr_out=$(env -u PGHOST -u PGHOSTADDR -u PGPORT -u PGSERVICE -u PGSERVICEFILE -u PGDATABASE -u PGUSER \
                 PGPASSFILE="$_dr_pf" PGAPPNAME=tbox-testread PGCONNECT_TIMEOUT=5 \
                 PGOPTIONS="-c default_transaction_read_only=on" \
                 psql -h 127.0.0.1 -p 5432 -U "$DB_READ_ROLE" -d "$_dr_db" -w -X -A -t -q -v ON_ERROR_STOP=1 \
                      -c "$_DBR_SCS" -c "$_DBR_RO_SET" -c "$_dr_sql" 2>/dev/null); then
      printf -v "$_dr_to" '%s' "$_dr_out"; _DBR_VIA=ro; return 0
    fi
    echo "db_read: 읽기 전용 경로 실패 — sudo 경로로 떨어진다" >&2
  fi
  if _dr_out=$(sudo -n -u postgres psql -d "$_dr_db" -X -A -t -q -v ON_ERROR_STOP=1 \
               -c "$_DBR_ROLE" -c "$_DBR_SCS" -c "$_DBR_RO_SET" -c "$_dr_sql" 2>/dev/null); then
    printf -v "$_dr_to" '%s' "$_dr_out"; _DBR_VIA=sudo; return 0
  fi
  echo "db_read: 읽기 전용 경로·sudo 경로 모두 실패 — 읽지 못함(rc 3, 0 으로 읽지 않는다)" >&2
  _DBR_VIA=none
  return 3
}

db_read() {
  local _dr_res _DBR_VIA=""
  _db_read_run "${1:-}" "${2:-}" _dr_res || return $?
  printf '%s\n' "$_dr_res"
}

db_read_var() {
  local _dv_to="${1:-}" _dv_res _DBR_VIA="" _dv_rc
  case "$_dv_to" in
    ''|[0-9]*|_d[rv]_*|_DBR_VIA|*[!A-Za-z0-9_]*) echo "db_read_var: 변수 이름 모양이 아니다" >&2; return 2 ;;
  esac
  _db_read_run "${2:-}" "${3:-}" _dv_res; _dv_rc=$?
  DB_READ_VIA="$_DBR_VIA"
  if [ "$_dv_rc" -ne 0 ]; then printf -v "$_dv_to" '%s' ""; return "$_dv_rc"; fi
  printf -v "$_dv_to" '%s' "$_dv_res"
}
