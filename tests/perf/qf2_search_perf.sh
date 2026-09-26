#!/usr/bin/env bash
# tests/perf/qf2_search_perf.sh — Q:F-2 통합 검색 성능 시험 (지시서 docs/qf2-unified-search-directive.md 4장 · QF2-T4)
#
#   ① 지금 규모(운영 사본 그대로)에서 검색 창구 서버 시간 p95 ≤ 100ms
#   (대기열 5b) 트리에 db/migrations/2026-10-02_qf2b_files_search.sql 이 있으면 파일 검색 색인도 만들고 채우고(qf2b_backfill_files.php)
#      파일 검색어(휴가·계약서·1글자)와 files 문장 EXPLAIN 을 더 잰다. 파일만 보려면 QF2_S1_PAGES·QF2_S1_COMMENTS 를 줄인다.
#   ② 합성 규모 S1(임시 DB 에만 SQL 로): 살아 있는 페이지 +50,000(본문 평균 약 2,000자, 한글 약 80%) · 댓글 +100,000 · 파일 +100,000
#      — 계정 행은 만들지 않는다(시험 규칙 — 앱 창구 밖 계정 INSERT 금지). 사용자는 운영 사본의 활성 계정 그대로(보고서에 수).
#      벡터는 배치(scripts/qf2_backfill_search.php)로 채운다 — 운영 반영 때의 배치 시간도 함께 잰다.
#   ③ S1 측정: 한글 2글자 흔한 말(「회의」 등) p95 ≤ 300ms · 1글자 p95 ≤ 500ms · 파일 전역 p95 ≤ 300ms · 영문·숫자만(ILIKE 전수) 측정만
#      · EXPLAIN (ANALYZE, BUFFERS) 원문(페이지·댓글 — Seq Scan 없음, …search_tsv_idx 사용) · 판정 조건절만의 비용 따로
#      · 부하: 계정 5개가 워커 5개로 60초 동안 계속 검색 — 5xx 0 · p95
#      · 화면: 헤드리스로 Ctrl+K → 입력 → 입력 멈춤(250ms) 뒤 첫 결과까지(두 창구 병렬) ≤ 600ms
#   ④ QF2_BASE_REF=<커밋> 을 주면 같은 S1 DB 에 **고치기 전 코드**(git archive)를 두 번째 인스턴스로 띄워 같은 검색을 잰다(전후 표).
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf2_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8153 · 전 8154).
#   운영 DB 는 pg_dump 로 읽기만(파이프). 디스크: 시작 여유 2GB 이상일 때만(합성 데이터 약 1GB), 단계마다 500MB 밑이면 멈춘다.
#   비밀(비밀번호·TOTP·세션)은 표준입력으로만(tests/lib/secrets.sh — R-62).
# 나가는 값: 0 목표 전부 통과 · 1 목표 하나라도 못 넘음(측정표는 그래도 끝까지 찍는다) · 3·4 준비 실패
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# 임시 .env 의 원본 — 기본은 트리의 .env. 복제본에는 두지 않는다: 시험하는 쪽이 mktemp 폴더(0700)에 사본을 만들어
# QF2_ENV_SRC 로 넘기고 끝에 지운다(운영 비밀 사본을 남기지 않는다 — 취합 규칙 2026-09-25). 값은 출력하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 (R-62)"; exit 3; }
PORT="${QF2_PORT:-8153}"
BPORT="${QF2_BASE_PORT:-8154}"
for p in "$PORT" "$BPORT"; do case "$p" in 80|8081|8082) echo "운영 포트($p)는 쓰지 않는다"; exit 3 ;; esac; done
SITE="http://127.0.0.1:$PORT"
BSITE="http://127.0.0.1:$BPORT"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"
DB="tbox_qf2_${PORT}_test"
N_PAGES="${QF2_S1_PAGES:-50000}"; N_COMMENTS="${QF2_S1_COMMENTS:-100000}"; N_FILES="${QF2_S1_FILES:-100000}"
REPS="${QF2_REPS:-30}"
JAR_DIR="$(mktemp -d)"; WORK="$(mktemp -d)"; chmod 700 "$JAR_DIR" "$WORK"
INST=""; BINST=""; SERVER_PID=""; BSERVER_PID=""
PASS=0; FAIL=0
hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
note() { printf '  · %s\n' "$1"; }
goal() {  # $1 이름 $2 값(ms) $3 목표(ms 이하) — 값이 비었거나 숫자가 아니면 실패(재지 못한 것을 통과로 세지 않는다)
  if [[ "$2" =~ ^[0-9]+(\.[0-9]+)?$ ]] && awk -v x="$2" -v t="$3" 'BEGIN{exit !(x<=t)}'; then printf '  ✔ %s  (%sms ≤ %sms)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (%sms > %sms)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
expect() { if [ "$2" = "$3" ]; then printf '  ✔ %s  (%s)\n' "$1" "$3"; PASS=$((PASS+1)); else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi; }
cleanup() {
  # php -S 워커(PHP_CLI_SERVER_WORKERS)는 주 프로세스의 자식이다 — 자식부터 내린다(주만 죽이면 워커가 고아로 포트를 잡고 남는다)
  for p in "$SERVER_PID" "$BSERVER_PID"; do [ -n "$p" ] && { pkill -TERM -P "$p" 2>/dev/null; kill "$p" 2>/dev/null; wait "$p" 2>/dev/null; }; done
  # QF2_KEEP_DB=1 — 계획을 되풀이해 볼 때만 임시 DB 를 남긴다(이름을 찍는다 — 다 보면 `sudo -n -u postgres dropdb <이름>`). 기본은 지운다.
  if [ "${QF2_KEEP_DB:-0}" = "1" ]; then echo "  · 임시 DB $DB 를 남겼다(QF2_KEEP_DB=1)"; else sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1; fi
  [ -n "$INST" ] && rm -rf "$INST"; [ -n "$BINST" ] && rm -rf "$BINST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT
psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
disk_ok() { local kb; kb=$(df -Pk / | awk 'NR==2{print $4}'); [ "$kb" -ge "${1:-512000}" ] || { echo "  ✖ 디스크 여유 $(df -Ph / | awk 'NR==2{print $4}') — 멈춘다"; exit 3; }; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
login() {   # $1 계정 $2 사이트
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who"); sec=$(review_secret "$who"); [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then raw=$(totp_now sec 2>/dev/null); code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"; fi
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$2/tbox/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p'); [ -n "$left" ] || left=31
    sleep $((left + 1))
  done
  return 1
}
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }
mkinst() {   # $1 원본 트리(폴더) $2 인스턴스 폴더 $3 포트 → 서버 pid
  local src="$1" dst="$2" port="$3"
  mkdir -p "$dst/app" "$dst/storage" "$dst/log"
  tar -C "$src" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$dst/app" -xf -
  # qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
  sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$dst/storage#" \
      -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$dst/log/audit.log#" "${QF2_ENV_SRC:-$ROOT/.env}" > "$dst/app/.env"
  chmod 600 "$dst/app/.env"
  grep -q "^DB_NAME=$DB\$" "$dst/app/.env" || { echo "임시 .env 실패"; exit 4; }
  local plan; plan="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"; [ -f "$plan/doc-schema.json" ] || plan=/var/www/qaredi/community
  ln -s "$plan" "$dst/community" 2>/dev/null
  cp "$WORK/router.php" "$dst/app/router.php"
  ( cd "$dst/app" && PHP_CLI_SERVER_WORKERS="${QF2_WORKERS:-4}" exec php -S "127.0.0.1:$port" -t public router.php > "$dst/server.log" 2>&1 ) &
  echo $!
}
cat > "$WORK/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    $rel = substr($path, strlen('/tbox/assets/'));
    if (strpos($rel, '..') === false) {
        $file = $root . '/assets/' . $rel;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json',
                      'svg' => 'image/svg+xml', 'png' => 'image/png'];
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

# ─── 측정기(파이썬) — 세션은 표준입력 한 줄씩. 서버 시간 ≈ 이 호스트 안 요청의 총시간(같은 기계 · 네트워크 0) ──────
cat > "$WORK/bench.py" <<'PY'
import json, random, sys, threading, time, urllib.parse, urllib.request, urllib.error
site, ua, mode = sys.argv[1], sys.argv[2], sys.argv[3]
names = sys.argv[4].split(',')
S = {n: sys.stdin.readline().rstrip('\n') for n in names}
def get(who, path):
    r = urllib.request.Request(site + path, headers={'User-Agent': ua, 'Cookie': '__Host-tbox_session=' + S[who]})
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(r, timeout=90) as x: x.read(); c = x.status
    except urllib.error.HTTPError as e:
        e.read(); c = e.code
    except Exception:
        c = 0
    return c, (time.perf_counter() - t0) * 1000
def p95(v):
    v = sorted(v); return v[min(len(v) - 1, int(round(0.95 * len(v) + 0.5)) - 1)] if v else float('nan')
if mode == 'series':
    # 줄마다: 이름|창구(c=커뮤니티 · f=파일)|검색어 — 첫 계정으로 reps 번(첫 한 번은 데우기로 버린다)
    reps = int(sys.argv[5]); who = names[0]
    lines = [l for l in sys.stdin]
    # php -S 워커(PHP_CLI_SERVER_WORKERS)는 저마다 처음 몇 번 PHP 를 새로 읽는다 — 그 몇 번이 첫 줄의 p95 를 부풀렸다.
    # 워커 수의 두 배만큼 먼저 불러 데운다(재는 값에 넣지 않는다)
    if lines:
        n0, a0, q0 = lines[0].rstrip('\n').split('|', 2)
        for _ in range(8):
            get(who, ('/community/api/search?q=' if a0 == 'c' else '/tbox/api/files?q=') + urllib.parse.quote(q0))
    for line in lines:
        line = line.rstrip('\n')
        if not line: continue
        name, api, q = line.split('|', 2)
        path = ('/community/api/search?q=' if api == 'c' else '/tbox/api/files?q=') + urllib.parse.quote(q)
        c0, warm = get(who, path)
        n = reps if warm < 1000 else max(5, reps // 6)   # 1초 넘게 걸리는 검색(옛 코드·영문 전수)은 횟수를 줄인다 — 표에 횟수를 함께 찍는다
        ts = []; codes = set()
        for _ in range(n):
            c, dt = get(who, path); ts.append(dt); codes.add(c)
        print('%s|%.0f|%.0f|%.0f|%s|%d' % (name, sorted(ts)[len(ts)//2], p95(ts), max(ts), ','.join(str(c) for c in sorted(codes)), n))
elif mode == 'load':
    secs = int(sys.argv[5]); qs = [l.rstrip('\n').split('|', 2) for l in sys.stdin if l.strip()]
    res = []; res2 = []; lock = threading.Lock(); stop = time.time() + secs
    def worker(who, seed):
        rnd = random.Random(seed)
        while time.time() < stop:
            name, api, q = rnd.choice(qs)
            path = ('/community/api/search?q=' if api == 'c' else '/tbox/api/files?q=') + urllib.parse.quote(q)
            c, dt = get(who, path)
            with lock: res.append((c, dt, api)); res2.append((c, dt, api, name))
            time.sleep(rnd.uniform(0.05, 0.25))   # 사람이 치는 사이(입력 멈춤 250ms 전후)
    th = [threading.Thread(target=worker, args=(n, i)) for i, n in enumerate(names)]
    [t.start() for t in th]; [t.join() for t in th]
    n5 = sum(1 for c, _, _ in res if c >= 500 or c == 0)
    def cat(name, api):
        if api == 'f': return 'F'
        if name.startswith('1글자'): return 'K1'
        if name.startswith('영문') or name.startswith('숫자'): return 'EN'
        return 'K2'
    by = {}
    for c, dt, a, nm in res2:
        by.setdefault(cat(nm, a), []).append(dt)
    print('LOAD_REQ=%d LOAD_5XX=%d ' % (len(res), n5) + ' '.join('LOAD_P95_%s=%.0f LOAD_N_%s=%d' % (k, p95(v), k, len(v)) for k, v in sorted(by.items())))
PY

# ─── EXPLAIN 도우미(PHP) — 앱과 **같은 문장**(community_search_sql)을 임시 인스턴스 폴더에서 EXPLAIN (ANALYZE, BUFFERS) ────
cat > "$WORK/explain.php" <<'PHP'
<?php
declare(strict_types=1);
$app = getcwd();
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$db = Db::instance();
$r = $db->run('SELECT id, username, role, team_id FROM public.users WHERE username = :u', [':u' => (string) $argv[1]])->fetch();
$u = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'], 'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']];
$mode = (string) $argv[2];
$q = rtrim((string) fgets(STDIN), "\n");
if ($mode === 'pages' || $mode === 'comments') {
    [$sql, $bind] = community_search_sql($u, SearchText::queryTerms($q), $mode);
} elseif ($mode === 'where_only') {
    [$w, $bind] = community_search_pages_where($u);
    $sql = "SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
              LEFT JOIN public.users owner ON owner.id = s.owner_user_id WHERE p.deleted_at IS NULL AND " . $w;
} elseif ($mode === 'index_only') {
    $bind = [':tq' => (string) SearchText::tsquery(SearchText::queryTerms($q))];
    $sql = "SELECT count(*) FROM community.pages p WHERE p.search_tsv @@ to_tsquery('simple', CAST(:tq AS text))";
} elseif ($mode === 'files') {
    // 대기열 5b — src/handlers/files_list.php 의 목록 문장(전역 검색 · 한글 검색어)을 **옮겨 적은 것**이다.
    // 그 파일의 조각(권한 조건절 · ILIKE · 색인 갈래 · 정렬 created_at desc · LIMIT 20)이 바뀌면 여기도 바꾼다.
    [$w, $bind] = Authz::visibleFilesWhere($u);
    $bind[':q_name'] = SearchText::likePattern($q);
    $bind[':q_desc'] = SearchText::likePattern($q);
    $bind[':q_tq']   = (string) SearchText::tsquery(SearchText::queryTerms($q));
    $sql = 'SELECT f.public_id FROM files f JOIN users u ON u.id = f.uploaded_by LEFT JOIN teams t ON t.id = f.team_id WHERE ' . $w
         . " AND (f.original_name ILIKE :q_name ESCAPE '#' OR f.description ILIKE :q_desc ESCAPE '#')"
         . " AND (f.search_tsv @@ to_tsquery('simple', :q_tq) OR f.search_tsv IS NULL)"
         . ' ORDER BY f.created_at DESC, f.id DESC LIMIT 20';
} elseif ($mode === 'files_count') {
    [$w, $bind] = Authz::visibleFilesWhere($u);
    $bind[':q_name'] = SearchText::likePattern($q);
    $bind[':q_desc'] = SearchText::likePattern($q);
    $bind[':q_tq']   = (string) SearchText::tsquery(SearchText::queryTerms($q));
    $sql = 'SELECT count(*) FROM files f WHERE ' . $w
         . " AND (f.original_name ILIKE :q_name ESCAPE '#' OR f.description ILIKE :q_desc ESCAPE '#')"
         . " AND (f.search_tsv @@ to_tsquery('simple', :q_tq) OR f.search_tsv IS NULL)";
} else {
    exit(3);
}
// 앱(search.php)과 같이 — 페이지 문장은 트랜잭션 안 계획 설정(COMMUNITY_SEARCH_PAGES_PLAN_ON)을 건 채로 잰다
$db->begin();
if ($mode === 'pages') {
    $db->run(COMMUNITY_SEARCH_PAGES_PLAN_ON);
}
foreach ($db->run('EXPLAIN (ANALYZE, BUFFERS) ' . $sql, $bind)->fetchAll() as $row) {
    echo reset($row), "\n";
}
$db->rollback();
PHP

# ════════════════════════════════════════════════════════════════
hr "준비 — 임시 DB · 인스턴스 (운영에는 쓰지 않는다)"
# 시작 여유 기본 2GB(합성 약 1GB + WAL). QF2_MIN_FREE_KB 로만 낮출 수 있다 — 낮춰 돌렸으면 보고서에 적는다. 도중에는 500MB 밑이면 멈춘다.
disk_ok "${QF2_MIN_FREE_KB:-2097152}"
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
for p in "$PORT" "$BPORT"; do if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$p "; then echo "포트 $p 사용 중 — 중단"; exit 3; fi; done
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
[ -n "$(psqlq "SELECT count(*) FROM community.pages")" ] || { echo "임시 DB 로 옮기지 못했다"; exit 3; }
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/2026-10-01_qf2_search.sql" > /dev/null 2>&1 || { echo "마이그레이션 실패"; exit 3; }
# 대기열 5b — 파일 검색 색인(있는 트리에서만 — 고치기 전 트리와 견줄 때도 같은 DB 를 쓴다: 옛 코드는 이 칸을 모른다)
if [ -f "$ROOT/db/migrations/2026-10-02_qf2b_files_search.sql" ]; then
  sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/2026-10-02_qf2b_files_search.sql" > /dev/null 2>&1 || { echo "마이그레이션 실패(5b)"; exit 3; }
fi
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" > /dev/null 2>&1
INST="$(mktemp -d)"; SERVER_PID=$(mkinst "$ROOT" "$INST" "$PORT")
( cd "$INST/app" && php scripts/qf2_backfill_search.php ) | sed 's/^/    /'
[ -f "$INST/app/scripts/qf2b_backfill_files.php" ] && ( cd "$INST/app" && php scripts/qf2b_backfill_files.php ) | sed 's/^/    /'
for _ in $(seq 1 10); do sleep 1; [ "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/community/api/pages")" = "401" ] && break; done
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" "$SITE" || { echo "  ✖ $who 로그인 실패"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01); S_G1=$(session_of claude_guest01)
for s in S_E1 S_E2 S_M1 S_A1; do
  printf 'Cookie: __Host-tbox_session=%s\n' "${!s}" | curl -s -o /dev/null -H @- -A "$UA" "$SITE/community/api/me/space"
done
note "임시 DB $DB — 살아 있는 페이지 $(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL") · 파일 $(psqlq "SELECT count(*) FROM files WHERE deleted_at IS NULL") · 댓글 $(psqlq "SELECT count(*) FROM community.comments") · 활성 계정 $(psqlq "SELECT count(*) FROM users WHERE is_active")"

QUERIES_NOW=$'2글자 휴가|c|휴가\n2글자 회의|c|회의\n1글자 휴|c|휴\n3글자 계약서|c|계약서\n여러 낱말|c|휴가 신청\n영문 report|c|report\n숫자 2026|c|2026\n파일 계약|f|계약\n파일 report|f|report'
run_series() {  # $1 사이트 $2 검색어 목록 $3 결과 파일 [$4 세션 변수 이름 — 기본 S_E1(직원1)]
  local sv="${4:-S_E1}"
  { printf '%s\n' "${!sv}"; printf '%s\n' "$2"; } | python3 "$WORK/bench.py" "$1" "$UA" series e1 "$REPS" > "$3"
}
# (위 run_series 는 세션을 표준입력 첫 줄로 준다 — bench.py 가 계정 이름 수만큼 먼저 읽고 나머지 줄을 검색어로 읽는다)

hr "① 지금 규모 — 검색 창구 서버 시간 (직원1 · ${REPS}회 · 중앙값/p95/최대 ms)"
run_series "$SITE" "$QUERIES_NOW" "$WORK/now.txt"
printf '    %-16s %8s %8s %8s  %s\n' "검색" "중앙" "p95" "최대" "상태·횟수"
while IFS='|' read -r n m p x c r; do printf '    %-16s %8s %8s %8s  %s·%s\n' "$n" "$m" "$p" "$x" "$c" "$r"; done < "$WORK/now.txt"
worst=$(awk -F'|' '{print $3}' "$WORK/now.txt" | sort -n | tail -1)
goal "지금 규모 — 모든 검색 창구 p95" "${worst:-9999}" 100
if [ "${QF2_ONLY_NOW:-0}" = "1" ]; then hr "결과(QF2_ONLY_NOW=1 — 지금 규모만)"; printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"; [ "$FAIL" -eq 0 ] || exit 1; exit 0; fi

# ════════════════════════════════════════════════════════════════
hr "② 합성 규모 S1 — 임시 DB 에만(페이지 +$N_PAGES · 댓글 +$N_COMMENTS · 파일 +$N_FILES)"
# 글은 파이썬이 짓고(씨앗 고정) COPY 로 붓는다 — SQL 안에서 큰 낱말 배열을 줄마다 꺼내면 배열을 매번 풀어(detoast) 몇십 분이 걸렸다(첫 회차 실측).
#   흐름은 파이프 하나(파일로 남기지 않는다): 파이썬 → psql(postgres, 임시 DB).
cat > "$WORK/s1gen.py" <<'PY'
import random, sys
NP, NC, NF = (int(x) for x in sys.argv[1:4])
rnd = random.Random(20260925)
real = ('자료 일정 보고 검토 공유 확인 요청 처리 관리 업무 사항 진행 완료 예정 담당 부서 팀장 문서 결재 예산 인사 출장 교육 안내 변경 '
        '회의 휴가 정산 계약 신청 제출 승인 반려 계획 결과 분석 개선 품질 고객 영업 구매 재무 회계 법무 총무 개발 운영 서버 배포 장애 '
        '점검 백업 보안 계정 권한 비밀번호 로그인 파일 폴더 게시판 공지 질문 답변 댓글 첨부 양식 신청서 계약서 보고서 회의록 정산서 견적서 '
        '연차 반차 병가 출근 퇴근 야근 주간 월간 분기 연간 목표 성과 평가 면담 채용 입사 퇴사 교육비 출장비 식대 교통비 비품 사무실').split()
syl = list('가나다라마바사아자차카타파하거너더러머버서어저처커터퍼허고노도로모보소오조초코토포호구누두루무부수우주추쿠투푸후그느드르므브스으즈츠크트프흐기니디리미비시이지치키티피히개내대래매배새애재채캐태패해게네데레메베세에제체케테페헤강공동방상정중청통행원전선신진민인한현연성명영경')
words, seen = [], set(real)
while len(words) < 2000:
    w = ''.join(rnd.choice(syl) for _ in range(rnd.choice([2, 2, 2, 3, 3, 4])))
    if w not in seen:
        seen.add(w); words.append(w)
vocab = words[:]
for i, w in enumerate(real):     # 업무 말을 순위 5~1,500 사이에 흩어 넣는다(「회의」는 200쯤 — 문서의 약 1/5 에 나오게)
    vocab.insert(200 if w == '회의' else 5 + (i * 37) % 1500, w)
parts = ['', '', '', '을', '를', '은', '는', '이', '가', '에', '의', '로', '와', '과', '도', '만', '에서', '까지', '부터', '입니다', '합니다', '했습니다', '하고', '하는']
eng = 'report meeting budget review plan draft final update release server deploy backup account login file folder note memo agenda v1 v2 v2.14 2025 2026 Q1 Q2 Q3 Q4 A-7 B-2 ok todo done'.split()
NV = len(vocab)
def word():
    if rnd.random() < 0.8:
        return vocab[int((rnd.random() ** 3) * NV)] + rnd.choice(parts)
    return rnd.choice(eng)
def text(n, sep=' '):
    return sep.join(word() for _ in range(n))
out = sys.stdout
out.write('\\set ON_ERROR_STOP on\n')
out.write('CREATE TEMP TABLE stg_p (g int, title text, body text);\nCOPY stg_p FROM STDIN;\n')
for g in range(1, NP + 1):
    out.write('%d\t%s\t%s\n' % (g, text(2 + g % 4), text(200 + (g * 31) % 500)))
out.write('\\.\n')
out.write('CREATE TEMP TABLE stg_c (g int, body text);\nCOPY stg_c FROM STDIN;\n')
for g in range(1, NC + 1):
    out.write('%d\t%s\n' % (g, text(4 + g % 30)[:2000]))
out.write('\\.\n')
out.write('CREATE TEMP TABLE stg_f (g int, name text, descr text);\nCOPY stg_f FROM STDIN;\n')
for g in range(1, NF + 1):
    out.write('%d\t%s\t%s\n' % (g, text(2 + g % 3, '_'), text(g % 12)[:500]))
out.write('\\.\n')
PY
cat > "$WORK/s1ins.sql" <<'SQL'
SELECT setseed(0.42);
CREATE TEMP TABLE sp AS SELECT row_number() OVER (ORDER BY id) AS k, id, kind, owner_user_id FROM community.spaces WHERE kind IN ('personal','team','board');
CREATE TEMP TABLE us AS SELECT row_number() OVER (ORDER BY id) AS k, id, team_id FROM public.users WHERE is_active;
SELECT max(id) AS pmax FROM community.pages \gset
INSERT INTO community.pages (space_id, title, doc, doc_version, plain_text, block_count, author_id, last_edited_by, visibility, position, created_at, updated_at)
SELECT s.id, t.title, '{"schema":1,"blocks":[]}'::jsonb, 1, t.body, 1,
       coalesce(s.owner_user_id, u.id), coalesce(s.owner_user_id, u.id),
       CASE WHEN s.kind = 'personal' THEN (ARRAY['private','private','team','internal','internal'])[1 + (t.g % 5)] ELSE 'private' END,
       t.g, now() - (t.g % 400) * interval '1 day', now() - random() * interval '365 days'
  FROM stg_p t
  JOIN sp s ON s.k = 1 + (t.g * 7919) % (SELECT count(*) FROM sp)
  JOIN us u ON u.k = 1 + (t.g::bigint * 104729) % (SELECT count(*) FROM us)
 ORDER BY t.g;
-- 하위 페이지 — 다섯에 하나는 같은 공간의 앞 페이지 아래로(경로 줄·공유 재귀가 일을 하게), 범위는 부모와 같게
UPDATE community.pages c SET parent_page_id = par.id, visibility = par.visibility
  FROM community.pages par
 WHERE c.id > :pmax AND c.id % 5 = 0 AND par.id = c.id - 15 AND par.space_id = c.space_id AND par.parent_page_id IS NULL;
INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
SELECT p.id, u.id, (ARRAY['view','edit'])[1 + (p.id % 2)::int], p.author_id
  FROM community.pages p JOIN community.spaces s ON s.id = p.space_id AND s.kind = 'personal'
  JOIN us u ON u.k = 1 + (p.id * 13) % (SELECT count(*) FROM us)
 WHERE p.id > :pmax AND p.id % 17 = 0 AND u.id <> p.author_id
ON CONFLICT DO NOTHING;
INSERT INTO community.page_tags (page_id, tag, tag_norm, created_by)
SELECT p.id, split_part(p.title, ' ', 1), lower(split_part(p.title, ' ', 1)), p.author_id
  FROM community.pages p WHERE p.id > :pmax AND p.id % 7 = 0 AND split_part(p.title, ' ', 1) <> ''
ON CONFLICT DO NOTHING;
INSERT INTO community.comments (page_id, body, author_id, created_at, updated_at)
SELECT :pmax + 1 + (c.g * 2654435761::bigint) % (SELECT count(*) FROM stg_p), c.body, u.id,
       now() - random() * interval '300 days', now() - random() * interval '300 days'
  FROM stg_c c JOIN us u ON u.k = 1 + (c.g * 7) % (SELECT count(*) FROM us)
 WHERE c.body <> '';
INSERT INTO public.files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, team_id, uploaded_by, folder_id, created_at)
SELECT f.name || '.' || (ARRAY['pdf','docx','xlsx','hwp','txt','png','zip'])[1 + (f.g % 7)], 'qf2-s1-' || f.g::text || '-' || md5(f.g::text),
       (ARRAY['pdf','docx','xlsx','hwp','txt','png','zip'])[1 + (f.g % 7)], 'application/octet-stream', 1000 + f.g, repeat('0', 64),
       f.descr, lv.lvl, CASE WHEN lv.lvl = 'team' THEN (SELECT min(id) FROM teams) END, u.id, NULL, now() - random() * interval '365 days'
  FROM stg_f f
  JOIN us u ON u.k = 1 + (f.g * 11) % (SELECT count(*) FROM us)
  CROSS JOIN LATERAL (SELECT (ARRAY['public','internal','internal','team','admin-only'])[1 + (f.g % 5)] AS lvl) lv;
SQL
disk_ok "$(( ${QF2_MIN_FREE_KB:-2097152} * 3 / 4 ))"
t0=$(date +%s)
{ python3 "$WORK/s1gen.py" "$N_PAGES" "$N_COMMENTS" "$N_FILES"; cat "$WORK/s1ins.sql"; } \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - > "$WORK/s1.out" 2>&1 || { echo "  ✖ 합성 SQL 실패"; grep -v 'could not change directory' "$WORK/s1.out" | tail -5; exit 4; }
note "합성 SQL $(( $(date +%s) - t0 ))초 — 살아 있는 페이지 $(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL") · 댓글 $(psqlq "SELECT count(*) FROM community.comments") · 파일 $(psqlq "SELECT count(*) FROM files WHERE deleted_at IS NULL") · 공유 $(psqlq "SELECT count(*) FROM community.page_shares") · 태그 $(psqlq "SELECT count(*) FROM community.page_tags")"
note "합성 본문 평균 $(psqlq "SELECT round(avg(char_length(plain_text))) FROM (SELECT plain_text FROM community.pages ORDER BY id DESC LIMIT $N_PAGES) z")자 · 한글 음절 비율 $(psqlq "SELECT round(100.0 * sum(char_length(regexp_replace(plain_text, '[^가-힣]', '', 'g'))) / nullif(sum(char_length(regexp_replace(plain_text, '\s', '', 'g'))), 0)) FROM (SELECT plain_text FROM community.pages ORDER BY id DESC LIMIT 2000) z")%(공백 뺀 글자 중, 최근 2,000장 표본)"
note "「회의」가 든 페이지 $(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL AND plain_text LIKE '%회의%'") · 「휴」 $(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL AND plain_text LIKE '%휴%'") · 「의」 $(psqlq "SELECT count(*) FROM community.pages WHERE deleted_at IS NULL AND plain_text LIKE '%의%'")"
disk_ok
sub "벡터 채우기 — scripts/qf2_backfill_search.php(운영 반영 때와 같은 배치)"
t0=$(date +%s)
( cd "$INST/app" && php scripts/qf2_backfill_search.php 2>&1 | grep -v '토큰 상한' ) | sed 's/^/    /'
BF_SECS=$(( $(date +%s) - t0 ))
if [ -f "$INST/app/scripts/qf2b_backfill_files.php" ]; then
  t0=$(date +%s)
  ( cd "$INST/app" && php scripts/qf2b_backfill_files.php 2>&1 ) | sed 's/^/    /'
  note "파일 배치(5b) $(( $(date +%s) - t0 ))초 (파일 $(psqlq "SELECT count(*) FROM files")) · NULL 벡터 $(psqlq "SELECT count(*) FROM files WHERE search_tsv IS NULL")"
fi
note "배치 ${BF_SECS}초 (페이지 $(psqlq "SELECT count(*) FROM community.pages") · 댓글 $(psqlq "SELECT count(*) FROM community.comments"))"
sudo -n -u postgres psql -q -d "$DB" -c "VACUUM ANALYZE community.pages" -c "VACUUM ANALYZE community.comments" -c "VACUUM ANALYZE public.files" -c "VACUUM ANALYZE community.page_shares" -c "VACUUM ANALYZE community.page_tags" >/dev/null 2>&1
note "크기 — pages $(psqlq "SELECT pg_size_pretty(pg_total_relation_size('community.pages'))") · pages_search_tsv_idx $(psqlq "SELECT pg_size_pretty(pg_relation_size('community.pages_search_tsv_idx'))") · comments $(psqlq "SELECT pg_size_pretty(pg_total_relation_size('community.comments'))") · comments_search_tsv_idx $(psqlq "SELECT pg_size_pretty(pg_relation_size('community.comments_search_tsv_idx'))") · files $(psqlq "SELECT pg_size_pretty(pg_total_relation_size('public.files'))") · 임시 DB $(psqlq "SELECT pg_size_pretty(pg_database_size(current_database()))")"
note "벡터 크기 — 페이지 평균 $(psqlq "SELECT round(avg(pg_column_size(search_tsv))) FROM community.pages WHERE search_tsv IS NOT NULL")B · 최대 $(psqlq "SELECT max(pg_column_size(search_tsv)) FROM community.pages")B"
disk_ok

QUERIES_S1=$'영문 없는 말|c|zqxjvk\n2글자 회의|c|회의\n2글자 휴가|c|휴가\n2글자 정산|c|정산\n2글자 계약|c|계약\n1글자 회|c|회\n1글자 휴|c|휴\n1글자 의(최악)|c|의\n3글자 계약서|c|계약서\n3글자 회의록|c|회의록\n여러 낱말|c|휴가 신청\n섞임 report 휴가|c|report 휴가\n드문 말|c|정산서\n없는 말|c|없는말조각\n영문 report|c|report\n영문 meet|c|meet\n숫자 2026|c|2026\n파일 계약|f|계약\n파일 회의|f|회의\n파일 휴가|f|휴가\n파일 계약서|f|계약서\n파일 1글자 회|f|회\n파일 report|f|report\n파일 2026|f|2026'
hr "③ S1 측정 — 새 코드 (직원1 · ${REPS}회 · 중앙값/p95/최대 ms)"
run_series "$SITE" "$QUERIES_S1" "$WORK/s1new.txt"
printf '    %-18s %8s %8s %8s  %s\n' "검색" "중앙" "p95" "최대" "상태·횟수"
while IFS='|' read -r n m p x c r; do printf '    %-18s %8s %8s %8s  %s·%s\n' "$n" "$m" "$p" "$x" "$c" "$r"; done < "$WORK/s1new.txt"
p95of() { awk -F'|' -v n="$1" '$1==n{print $3}' "$WORK/s1new.txt"; }
for n in "2글자 회의" "2글자 휴가" "2글자 정산" "2글자 계약" "3글자 계약서" "3글자 회의록" "여러 낱말" "섞임 report 휴가" "드문 말" "없는 말"; do goal "S1 커뮤니티 검색 「$n」 p95" "$(p95of "$n")" 300; done
for n in "1글자 회" "1글자 휴" "1글자 의(최악)"; do goal "S1 커뮤니티 1글자 「$n」 p95" "$(p95of "$n")" 500; done
for n in "파일 계약" "파일 회의" "파일 휴가" "파일 계약서" "파일 report" "파일 2026"; do goal "S1 파일 전역 「$n」 p95" "$(p95of "$n")" 300; done
# 파일 한 글자 — 지시서 4장은 파일에 따로 적지 않았다. 커뮤니티 1글자와 같은 500ms 로 본다(대기열 5b)
goal "S1 파일 전역 「파일 1글자 회」 p95" "$(p95of "파일 1글자 회")" 500
note "영문·숫자만(ILIKE 전수 — J-10 ⓑ, 목표 없음·측정만): report $(p95of '영문 report')ms · meet $(p95of '영문 meet')ms · 2026 $(p95of '숫자 2026')ms · 없는 영문 말 $(p95of '영문 없는 말')ms(후보를 끝까지 매긴다 — 최악) (1초 넘으면 갈래 B 를 새 항목으로)"
codes=$(awk -F'|' '{print $5}' "$WORK/s1new.txt" | tr ',' '\n' | sort -u | tr '\n' ' ')
expect "S1 측정 요청 상태 코드는 200 뿐" "200 " "$codes"

sub "계정별 — 볼 수 있는 것이 적은 계정일수록 판정을 매길 줄이 는다(정렬 먼저 · 판정은 필요한 만큼)"
QUERIES_WHO=$'2글자 회의|c|회의\n1글자 회|c|회\n3글자 계약서|c|계약서\n영문 report|c|report'
for who in S_A1 S_M1 S_G1; do
  run_series "$SITE" "$QUERIES_WHO" "$WORK/who_$who.txt" "$who"
  while IFS='|' read -r n m p x c r; do printf '    %-6s %-14s 중앙 %6s  p95 %6s  최대 %6s  %s·%s\n' "${who#S_}" "$n" "$m" "$p" "$x" "$c" "$r"; done < "$WORK/who_$who.txt"
  for n in "2글자 회의" "3글자 계약서"; do goal "S1 ${who#S_} 「$n」 p95" "$(awk -F'|' -v n="$n" '$1==n{print $3}' "$WORK/who_$who.txt")" 300; done
  goal "S1 ${who#S_} 「1글자 회」 p95" "$(awk -F'|' '$1=="1글자 회"{print $3}' "$WORK/who_$who.txt")" 500
done

sub "EXPLAIN (ANALYZE, BUFFERS) — 앱과 같은 문장(community_search_sql, 직원1)"
explain() { ( cd "$INST/app" && printf '%s\n' "$3" | php "$WORK/explain.php" "$1" "$2" ) 2>&1; }
for q in 회의 계약서; do
  explain claude_employee01 pages "$q" > "$WORK/ex_p_$q.txt"
  echo "    ▸ pages 「$q」"; sed 's/^/      /' "$WORK/ex_p_$q.txt"
  expect "pages 「$q」 — Seq Scan on pages 없음" "0" "$(grep -c 'Seq Scan on pages' "$WORK/ex_p_$q.txt")"
  expect "pages 「$q」 — Bitmap Index Scan on pages_search_tsv_idx" "yes" "$(grep -q 'Bitmap Index Scan on pages_search_tsv_idx' "$WORK/ex_p_$q.txt" && echo yes || echo no)"
done
explain claude_employee01 comments 회의 > "$WORK/ex_c.txt"
echo "    ▸ comments 「회의」"; sed 's/^/      /' "$WORK/ex_c.txt"
expect "comments 「회의」 — Seq Scan on comments 없음" "0" "$(grep -cE 'Seq Scan on comments( |$)' "$WORK/ex_c.txt")"
expect "comments 「회의」 — Bitmap Index Scan on comments_search_tsv_idx" "yes" "$(grep -q 'Bitmap Index Scan on comments_search_tsv_idx' "$WORK/ex_c.txt" && echo yes || echo no)"
if [ -f "$ROOT/db/migrations/2026-10-02_qf2b_files_search.sql" ]; then
  for q in 회의 계약서; do
    explain claude_employee01 files "$q" > "$WORK/ex_f_$q.txt"; explain claude_employee01 files_count "$q" > "$WORK/ex_fc_$q.txt"
    echo "    ▸ files 「$q」 목록(대기열 5b)"; sed 's/^/      /' "$WORK/ex_f_$q.txt"
    echo "    ▸ files 「$q」 개수"; sed 's/^/      /' "$WORK/ex_fc_$q.txt" | tail -8
    expect "files 「$q」 목록·개수 — Seq Scan on files 없음" "0" "$(cat "$WORK/ex_f_$q.txt" "$WORK/ex_fc_$q.txt" | grep -c 'Seq Scan on files')"
    expect "files 「$q」 목록·개수 — Bitmap Index Scan on files_search_tsv_idx" "yes|yes" "$(grep -q 'Bitmap Index Scan on files_search_tsv_idx' "$WORK/ex_f_$q.txt" && echo yes || echo no)|$(grep -q 'Bitmap Index Scan on files_search_tsv_idx' "$WORK/ex_fc_$q.txt" && echo yes || echo no)"
  done
fi
sub "판정 조건절만의 비용(리서치 P-11 — 조건절이 병목이면 이 지시서가 아니라 P-11 로)"
explain claude_employee01 where_only "" > "$WORK/ex_w.txt"; echo "    ▸ 조건절만 — 살아 있는 페이지 전부에 community_search_pages_where"; sed 's/^/      /' "$WORK/ex_w.txt" | tail -4
explain claude_employee01 index_only 회의 > "$WORK/ex_i.txt"; echo "    ▸ 색인만 — search_tsv @@ 「회의」 count"; sed 's/^/      /' "$WORK/ex_i.txt" | tail -4
note "조건절만(전 페이지) $(sed -n 's/^Execution Time: //p' "$WORK/ex_w.txt") · 색인만(회의) $(sed -n 's/^Execution Time: //p' "$WORK/ex_i.txt") · 검색 전체(회의) $(sed -n 's/^Execution Time: //p' "$WORK/ex_p_회의.txt")"

hr "부하 — 계정 5개 · 워커 5 · 60초 (입력 사이 50~250ms)"
LOADQ=$(printf '%s\n' "$QUERIES_S1" | grep -v '의(최악)\|영문 없는 말')
{ printf '%s\n%s\n%s\n%s\n%s\n' "$S_E1" "$S_E2" "$S_M1" "$S_A1" "$S_G1"; printf '%s\n' "$LOADQ"; } \
  | python3 "$WORK/bench.py" "$SITE" "$UA" load e1,e2,m1,a1,g1 "${QF2_LOAD_SECS:-60}" > "$WORK/load.txt"
sed 's/^/    /' "$WORK/load.txt"
lv() { sed -n "s/.*$1=\([0-9]*\).*/\1/p" "$WORK/load.txt"; }
expect "부하 중 5xx·연결 실패 0" "0" "$(lv LOAD_5XX)"
goal "부하 중 한글 2글자 이상 검색 p95($(lv LOAD_N_K2)건)" "$(lv LOAD_P95_K2)" 300
goal "부하 중 한글 1글자 검색 p95($(lv LOAD_N_K1)건)" "$(lv LOAD_P95_K1)" 500
goal "부하 중 파일 검색 p95($(lv LOAD_N_F)건)" "$(lv LOAD_P95_F)" 300
note "부하 중 영문·숫자만(ILIKE 전수 — 측정만) p95 $(lv LOAD_P95_EN)ms ($(lv LOAD_N_EN)건)"
note "임시 인스턴스는 php -S 워커 ${QF2_WORKERS:-4}개(PHP_CLI_SERVER_WORKERS) — 운영 FPM 과 같지 않다(워커 수·opcache). 2코어 기계라 동시 요청은 CPU 를 나눈다"

hr "화면 — Ctrl+K · 입력 멈춤 뒤 첫 결과까지(두 창구 병렬) — 헤드리스"
cat > "$WORK/ui.py" <<'PY'
import sys, time
from playwright.sync_api import sync_playwright
site, sess = sys.argv[1], sys.stdin.readline().rstrip('\n')
host = site.split('//')[1].split(':')[0]
out = []; alls = []
with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(user_agent=sys.argv[2])
    ctx.add_cookies([{'name': '__Host-tbox_session', 'value': sess, 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])   # S2 — `__Host-` 는 url 로 넣으면 http 에서 거부
    pg = ctx.new_page()
    # 재는 자리는 t-box 홈(두 앱 공통 창 — 같은 search.js). 커뮤니티 홈은 S1 에서 화면 자체가 몇 분 걸려(검색과 별개 —
    # 아래 「참고」) 워커를 붙잡으므로 **재기를 다 마친 뒤** 따로 연다.
    pg.goto(site + '/tbox/', wait_until='load', timeout=120000)
    pg.wait_for_selector('#nav-search')
    for q in ['회의', '휴가', '계약서', '정산', '회']:
        pg.keyboard.press('Control+k')
        pg.wait_for_selector('#search-dialog[open]')
        pg.fill('#search-input', '')
        time.sleep(0.4)
        pg.type('#search-input', q, delay=40)
        t_last = time.perf_counter()
        # 앞 검색어의 결과 글이 남아 있다 — 표시를 지워 두고(입력 멈춤 250ms 안), 새 답이 그 자리를 채울 때까지 잰다
        pg.evaluate("document.getElementById('search-note').textContent = '__wait__'")
        # wait_for_function 은 문자열을 eval 해서 CSP(script-src 'self')에 막힌다 — 파이썬에서 10ms 마다 글자를 읽는다
        note = pg.locator('#search-note')
        deadline = time.perf_counter() + 15
        first = None
        while time.perf_counter() < deadline:
            n = note.text_content() or ''
            if first is None and n != '__wait__' and n != '찾는 중…':
                first = (time.perf_counter() - t_last) * 1000 - 250    # 먼저 온 묶음이 그려진 때(search.js 가 먼저 온 쪽부터 그린다)
            if n != '__wait__' and '찾는 중' not in n:
                break
            time.sleep(0.01)
        dt = (time.perf_counter() - t_last) * 1000 - 250                 # 두 묶음이 다 온 때
        out.append(first if first is not None else dt)
        alls.append(dt)
        print('UI %s 첫 결과 %.0f · 다 옴 %.0f' % (q, first if first is not None else -1, dt))
        pg.keyboard.press('Escape')
    # (참고) 커뮤니티 홈이 S1 에서 얼마나 걸려 열리나 — 검색과 별개로 찍기만 한다(60초까지)
    t0 = time.perf_counter()
    try:
        pg.goto(site + '/community/', wait_until='load', timeout=60000)
        print('HOME_MS=%.0f' % ((time.perf_counter() - t0) * 1000))
    except Exception:
        print('HOME_MS=60초 넘음')
    b.close()
print('UI_MAX=%.0f' % max(out))
print('UI_ALL_MAX=%.0f' % max(alls))
PY
printf '%s\n' "$S_E1" | PATH="$HOME/.local/bin:$PATH" python3 "$WORK/ui.py" "$SITE" "$UA" > "$WORK/ui.txt" 2>&1
sed 's/^/    /' "$WORK/ui.txt" | tail -8
goal "입력 멈춤(250ms) 뒤 첫 결과까지 최대(먼저 온 묶음)" "$(sed -n 's/^UI_MAX=//p' "$WORK/ui.txt")" 600
note "두 묶음이 다 올 때까지 최대 $(sed -n 's/^UI_ALL_MAX=//p' "$WORK/ui.txt")ms (파일 전역 검색이 늦으면 이쪽이 길다 — J-2)"
note "(참고) 커뮤니티 홈 열기(load) $(sed -n 's/^HOME_MS=//p' "$WORK/ui.txt") — 검색과 별개. 서버 기록의 커뮤니티 요청(끝난 것, 마지막 12줄):"
grep -a '/community/' "$INST/server.log" | grep -a '\]: ' | tail -12 | sed 's/^/      /'
home_time() { printf 'Cookie: __Host-tbox_session=%s\n' "$S_E1" | curl -s -o /dev/null -w '%{time_total}' -H @- -A "$UA" "$1/community/"; }

if [ -n "${QF2_BASE_REF:-}" ]; then
  hr "④ 같은 S1 DB 에 고치기 전 코드($QF2_BASE_REF) — 전후 표"
  mkdir -p "$WORK/base"; git -C "$ROOT" archive "$QF2_BASE_REF" | tar -x -C "$WORK/base"
  BINST="$(mktemp -d)"; BSERVER_PID=$(mkinst "$WORK/base" "$BINST" "$BPORT")
  for _ in $(seq 1 10); do sleep 1; [ "$(curl -s -o /dev/null -w '%{http_code}' "$BSITE/community/api/pages")" = "401" ] && break; done
  # 같은 DB 라 세션 표가 같다 — 같은 세션으로 부른다(로그인 다시 하지 않는다)
  run_series "$BSITE" "$QUERIES_S1" "$WORK/s1old.txt"
  note "(참고) 커뮤니티 홈 HTML 한 번 — 전 $(home_time "$BSITE")초 · 후 $(home_time "$SITE")초(검색과 별개 — 홈 화면 자체)"
  printf '    %-18s %10s %10s   %s\n' "검색" "전 p95" "후 p95" "(ms)"
  while IFS='|' read -r n m p x c r; do
    printf '    %-18s %10s %10s   %s\n' "$n" "$p" "$(p95of "$n")" "(전 ${r}회)$( [ "$c" = "200" ] || echo " 전 상태 $c")"
  done < "$WORK/s1old.txt"
fi

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
