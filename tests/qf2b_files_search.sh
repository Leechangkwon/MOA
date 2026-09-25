#!/usr/bin/env bash
# tests/qf2b_files_search.sh — 개발 대기열 5b 파일 전역 검색 색인 수락 시험 (Q:F-2 J-2 후속 · 지시서 docs/qf2-unified-search-directive.md 2.4·4장)
#
#   B-T0 저장 길      claude_employee01 이 앱 창구로 파일을 올리고(이름·설명에 한글) 설명을 고친다 → 그 행의 search_tsv 가
#                     규칙(SearchText::indexTextFile([이름, 설명]) — 조합 토큰 ∪ 원문 토큰)으로 다시 만든 값과 같다 · 등급·폴더만 바꾸면 벡터 그대로 ·
#                     한글이 없는 파일은 빈 벡터(NULL 아님).
#   B-T1 틈 없음      합성 파일 3,000개를 **벡터 없이(NULL)** SQL 로 넣은 상태에서(반영 뒤 배치 전과 같은 모양) 모든 계정 ×
#                     모든 검색어의 창구 결과 = 기준선. 기준선 = 판정 조건절(Authz::visibleFilesWhere) + 이름·설명 원문 ILIKE
#                     (+ 폴더 조건) — 색인·SearchText 를 쓰지 않는 SQL(도우미 baseline). id 집합과 total 이 같아야 한다.
#   B-T2 배치         qf2b_backfill_files.php --dry-run 의 「바꿀」 = 독립 대조(도우미 vectors)의 어긋난 행 수 · dry-run 은 쓰기 0
#                     (벡터 md5 불변) · 실행 뒤 어긋남 0 · NULL 0 · 다시 dry-run 0.
#   B-T3 결과 정확성  배치 뒤 같은 대조(모든 계정 × 모든 검색어 · 폴더 안 검색 포함) — 창구 = 기준선.
#   B-T4 주입         `' & | ! :* \ % _ # ( )` 가 섞인 검색어 — 창구 200 · JSON · 서버 로그 새 PHP 오류·SQLSTATE 0(B-T1·T3 에 포함).
#   B-T5 정적         바뀐 PHP php -l · 보안 린트(scripts/security_lint.sh) 0.
#   (성능 — S1 파일 10만 건 p95 ≤ 300ms · EXPLAIN — 은 tests/perf/qf2_search_perf.sh 가 잰다)
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf2b_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8156).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프 — 파일로 남기지 않는다). 검토 계정은 claude_ 다섯(F-71).
#   비밀(비밀번호·TOTP·세션·CSRF)은 표준입력으로만 넘긴다(tests/lib/secrets.sh — R-62).
#   임시 .env 의 원본은 트리의 .env — 복제본에서는 시험하는 쪽이 mktemp 폴더(0700)의 사본을 QF2_ENV_SRC 로 넘기고 끝에 지운다.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF2B_PORT:-8156}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                  # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf2b_${PORT}_test"       # 임시 — 여기에만 쓴다
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
SERVER_PID=""
PASS=0
FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
review_password() { sudo -n awk -v u="$1" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null; }
review_secret()   { sudo -n awk -v u="$1" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null; }
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try body
  pw=$(review_password "$who")
  sec=$(review_secret "$who")
  [ -n "$pw" ] || return 2
  for try in 1 2 3; do
    if [ -n "$sec" ]; then
      raw=$(totp_now sec 2>/dev/null)
      code=${raw%%$'\n'*}
      printf -v body '{"username":"%s","password":"%s","otp":"%s"}' "$who" "$pw" "$code"
    else
      printf -v body '{"username":"%s","password":"%s"}' "$who" "$pw"
    fi
    out=$(curl_json_secret body -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' "$BASE/api/login")
    case "$out" in *'"ok":true'*) return 0 ;; esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 로그인 재시도 — 다음 코드 창까지 %s초\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}
session_of() { awk '$6=="tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

# ════════════════════════════════════════════════════════════════
hr "5b — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}')"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }
sudo -n -u postgres psql -d "$SRC_DB" -tAc "SELECT 1" >/dev/null 2>&1 \
  || { echo "psql(postgres) 을 쓸 수 없다 — 이 시험은 임시 DB 가 없으면 성립하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$PORT "; then echo "포트 $PORT 이 이미 쓰이고 있다 — 중단"; exit 3; fi

sub "① 운영 DB 를 **읽어** 임시 DB 를 만든다(파이프)"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb -O tbox_owner "$DB" >/dev/null 2>&1 || { echo "임시 DB 를 만들 수 없다"; exit 3; }
sudo -n -u postgres pg_dump -d "$SRC_DB" 2>/dev/null \
  | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=0 >/dev/null 2>"$WORK/restore.err"
[ -n "$(psqlq "SELECT count(*) FROM files")" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
note "임시 DB $DB — 파일 $(psqlq "SELECT count(*) FROM files") · 폴더 $(psqlq "SELECT count(*) FROM folders")"

sub "② 마이그레이션 — Q:F-2(댓글 칸 · 이미 있으면 아무 일 없음) · 5b(파일 칸·색인)"
for m in 2026-10-01_qf2_search.sql 2026-10-02_qf2b_files_search.sql; do
  sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/$m" 2>&1 | grep -v 'could not change directory\|already exists, skipping' | sed 's/^/    /'
done
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 | grep -v 'could not change directory' | sed 's/^/    /'
expect "files.search_tsv 칸" "1" "$(psqlq "SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='files' AND column_name='search_tsv'")"
expect "files 검색 색인 둘(GIN · NULL 부분 색인)" "files_search_tsv_idx:gin,files_search_tsv_null_idx:btree" \
  "$(psqlq "SELECT string_agg(c.relname || ':' || am.amname, ',' ORDER BY c.relname) FROM pg_class c JOIN pg_am am ON am.oid = c.relam WHERE c.relname IN ('files_search_tsv_idx','files_search_tsv_null_idx')")"
expect "앱 계정이 새 칸을 읽고 쓸 수 있다(표 단위 권한)" "t|t|t" \
  "$(psqlq "SELECT has_column_privilege('tbox_app','public.files','search_tsv','SELECT')::text || '|' || has_column_privilege('tbox_app','public.files','search_tsv','INSERT')::text || '|' || has_column_privilege('tbox_app','public.files','search_tsv','UPDATE')::text" | sed 's/true/t/g')"
expect "마이그레이션을 한 번 더 돌려도 오류 없음(IF NOT EXISTS)" "0" \
  "$(sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/2026-10-02_qf2b_files_search.sql" >/dev/null 2>&1; echo $?)"

sub "③ 저장소를 임시 폴더로 복사(임시 .env — DB 이름·저장·감사 경로만 바꾼다, 값은 출력하지 않는다)"
INST="$(mktemp -d)"
mkdir -p "$INST/app" "$INST/storage" "$INST/log"
tar -C "$ROOT" -cf - --exclude=.git --exclude=jar --exclude=api-test.txt --exclude=./.env . | tar -C "$INST/app" -xf -
# qcheck-allow: R20 — 임시 인스턴스용 사본(0700·0600 · trap 정리) · kill -9 뒤 남는 창은 받아들인 위험(HO-B1-004)
sed -e "s#^DB_NAME=.*#DB_NAME=$DB#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$INST/storage#" \
    -e "s#^AUDIT_LOG_FILE=.*#AUDIT_LOG_FILE=$INST/log/audit.log#" \
    "${QF2_ENV_SRC:-$ROOT/.env}" > "$INST/app/.env"
chmod 600 "$INST/app/.env"
grep -q "^DB_NAME=$DB\$" "$INST/app/.env" || { echo "임시 .env 에 임시 DB 이름이 들어가지 않았다 — 중단"; exit 4; }
ln -s /var/www/qaredi/community "$INST/community" 2>/dev/null
cat > "$INST/app/router.php" <<'PHP'
<?php
$root = __DIR__ . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && strpos($path, '/tbox/assets/') === 0) {
    http_response_code(404);
    return true;
}
require $root . '/index.php';
PHP

# ─── 대조 도우미(PHP) — 임시 인스턴스 폴더에서 부른다(그 .env = 임시 DB). 읽기만 한다. ─────────────
#   baseline : 표준입력 JSON [{key, who, q, folder|null}] → {key: {total, ids}} — 판정 조건절 + 원문 ILIKE(+ 폴더). SearchText 를 쓰지 않는다
#   vectors  : 저장된 벡터가 규칙(SearchText::indexTextFile([이름, 설명]))으로 다시 만든 값과 다른 행 수 · NULL 행 수 · 벡터 md5 합
#   vec <public_id> : 그 행 하나가 규칙과 같은가(same|diff|null) — 한글이 없으면 빈 벡터인지도
cat > "$WORK/chk.php" <<'PHP'
<?php
declare(strict_types=1);
$app = getcwd();
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/SearchText.php';
$mode = (string) ($argv[1] ?? '');
$db   = Db::instance();
function chk_user(string $username): array {
    $r = Db::instance()->run('SELECT id, username, role, team_id, is_active FROM users WHERE username = :u', [':u' => $username])->fetch();
    if (!is_array($r)) { fwrite(STDERR, "계정 없음\n"); exit(3); }
    return ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
            'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'is_active' => $r['is_active'] === true];
}
switch ($mode) {
case 'baseline':
    $jobs = json_decode((string) stream_get_contents(STDIN), true);
    $out = [];
    foreach ($jobs as $j) {
        [$w, $b] = Authz::visibleFilesWhere(chk_user((string) $j['who']));
        $sql = 'SELECT f.public_id FROM files f WHERE ' . $w;
        $q = (string) $j['q'];
        if ($q !== '') {
            $sql .= " AND (f.original_name ILIKE :p1 ESCAPE '#' OR f.description ILIKE :p2 ESCAPE '#')";
            $b[':p1'] = $b[':p2'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
        }
        if ($j['folder'] !== null) {
            // 폴더 안 검색은 창구가 먼저 그 폴더를 읽을 수 있는지 본다(못 읽으면 403 — files_list.php [권한 ✔ 폴더]).
            // 폴더 관문은 이번 변경 밖이라 앱의 판정 함수를 그대로 기대값으로 쓴다(검색 결과 쪽은 여전히 색인 없는 SQL).
            if ((int) $j['folder'] !== 0) {
                $fr = $db->run('SELECT id, parent_id, name, depth, access_level, team_id FROM folders WHERE id = CAST(:id AS bigint)', [':id' => (int) $j['folder']])->fetch();
                if (!is_array($fr) || !Authz::decideRead(chk_user((string) $j['who']), $fr)[0]) {
                    $out[(string) $j['key']] = ['forbidden' => true, 'total' => null, 'ids' => []];
                    continue;
                }
            }
            $sql .= ' AND f.folder_id IS NOT DISTINCT FROM CAST(:fo AS bigint)';
            $b[':fo'] = (int) $j['folder'] === 0 ? null : (int) $j['folder'];
        }
        $ids = array_map('strval', $db->run($sql, $b)->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        $out[(string) $j['key']] = ['forbidden' => false, 'total' => count($ids), 'ids' => $ids];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
    break;
case 'vectors':
    $bad = 0; $null = 0; $n = 0; $h = hash_init('md5');
    foreach ($db->run('SELECT id, original_name, description, search_tsv::text AS v FROM files ORDER BY id')->fetchAll() as $r) {
        $n++;
        hash_update($h, (string) $r['id'] . ':' . ($r['v'] === null ? '(null)' : (string) $r['v']) . "\n");
        if ($r['v'] === null) { $null++; $bad++; continue; }
        $want = (string) $db->run("SELECT strip(to_tsvector('simple', :t))::text", [':t' => SearchText::indexTextFile([(string) $r['original_name'], (string) $r['description']])])->fetchColumn();
        if ($want !== (string) $r['v']) { $bad++; }
    }
    echo "rows=$n bad=$bad null=$null md5=" . hash_final($h) . "\n";
    break;
case 'vec':
    $r = $db->run('SELECT original_name, description, search_tsv::text AS v FROM files WHERE public_id = CAST(:p AS uuid)', [':p' => (string) ($argv[2] ?? '')])->fetch();
    if (!is_array($r)) { echo "none\n"; break; }
    if ($r['v'] === null) { echo "null\n"; break; }
    $want = (string) $db->run("SELECT strip(to_tsvector('simple', :t))::text", [':t' => SearchText::indexTextFile([(string) $r['original_name'], (string) $r['description']])])->fetchColumn();
    echo ($want === (string) $r['v'] ? 'same' : 'diff'), '|', ($r['v'] === '' ? 'empty' : 'tokens'), "\n";
    break;
default:
    fwrite(STDERR, "모드?\n"); exit(3);
}
PHP
chk() { ( cd "$INST/app" && php "$WORK/chk.php" "$@" ); }

( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
READY=""
for _ in $(seq 1 20); do
  sleep 0.5
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$BASE/api/files?q=x")
  [ "$READY" = "401" ] && break
done
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯 — 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  login "$who" || { echo "  ✖ $who 로그인 실패 — 중단"; exit 4; }
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)
note "세션 다섯 받음(값은 출력하지 않는다)"

# ─── 창구 도우미(파이썬) — 세션 다섯을 표준입력 첫 다섯 줄로 받는다(R-62). ─────────────
cat > "$WORK/drv.py" <<'PY'
import json, sys, urllib.request, urllib.parse, uuid
SITE, UA, MODE = sys.argv[1], sys.argv[2], sys.argv[3]
WHO = ['claude_employee01', 'claude_employee02', 'claude_manager01', 'claude_admin01', 'claude_guest01']
SESS = {w: sys.stdin.readline().rstrip('\n') for w in WHO}
def req(who, method, path, body=None, ctype=None, csrf=None):
    h = {'User-Agent': UA, 'Cookie': 'tbox_session=' + SESS[who]}
    if csrf: h['X-CSRF-Token'] = csrf
    if ctype: h['Content-Type'] = ctype
    r = urllib.request.Request(SITE + path, data=body, method=method, headers=h)
    try:
        with urllib.request.urlopen(r, timeout=30) as resp:
            raw = resp.read(); code = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read(); code = e.code
    try: d = json.loads(raw.decode('utf-8'))
    except Exception: d = None
    return code, d, raw
def csrf(who):
    return (req(who, 'GET', '/tbox/api/me')[1] or {}).get('csrf_token', '')
if MODE == 'writes':
    tok = csrf('claude_employee01'); out = {}
    def upload(name, desc, text):
        b = uuid.uuid4().hex; parts = []
        for k, v in (('access_level', 'internal'), ('description', desc)):
            parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
        parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + text.encode() + b'\r\n')
        parts.append(('--%s--\r\n' % b).encode())
        c, d, _ = req('claude_employee01', 'POST', '/tbox/api/files', b''.join(parts), 'multipart/form-data; boundary=' + b, tok)
        return c, ((d or {}).get('file') or d or {}).get('id', '')
    out['up_ko'] = upload('연차휴가 신청서 QF2B.txt', '다음 주 휴가를 신청합니다', '휴가 신청 본문\n')
    out['up_en'] = upload('report_2026 QF2B.txt', '', 'plain\n')
    fid = out['up_ko'][1]
    c, _, _ = req('claude_employee01', 'PATCH', '/tbox/api/files/' + fid, json.dumps({'description': '회의록 첨부 — 정산서 포함'}).encode(), 'application/json', tok)
    out['patch_desc'] = c
    def hit(q):
        c, d, _ = req('claude_employee01', 'GET', '/tbox/api/files?q=' + urllib.parse.quote(q))
        return c, fid in [f['id'] for f in (d or {}).get('files', [])]
    out['hit_new_desc'] = hit('정산서'); out['hit_old_desc'] = hit('신청합니다'); out['hit_name'] = hit('연차휴가')
    print(json.dumps(out, ensure_ascii=False))
elif MODE == 'level':
    # 관리자가 앱 창구로 등급만 바꾼다(설명·이름은 그대로 — 벡터가 그대로여야 한다)
    fid = sys.argv[4]; tok = csrf('claude_admin01')
    c, d, raw = req('claude_admin01', 'PATCH', '/tbox/api/files/' + fid, json.dumps({'access_level': 'public'}).encode(), 'application/json', tok)
    print(c)
elif MODE == 'list':
    jobs = json.load(open(sys.argv[4], encoding='utf-8')); res = {}
    for j in jobs:
        ids = []; total = None; codes = set(); page = 1; bad = ''
        while True:
            qs = {'q': j['q'], 'page': str(page)}
            if j['folder'] is not None: qs['folder'] = str(j['folder'])
            c, d, raw = req(j['who'], 'GET', '/tbox/api/files?' + urllib.parse.urlencode(qs))
            codes.add(c)
            if c != 200 or not isinstance(d, dict):
                bad = raw[:120].decode('utf-8', 'replace'); break
            if b'SQLSTATE' in raw or b'Exception' in raw: bad = 'SQLSTATE/Exception'
            if total is None: total = d.get('total')
            elif total != d.get('total'): bad = 'total 흔들림'
            got = [f['id'] for f in d.get('files', [])]
            ids += got
            if not got or len(ids) >= (total or 0) or page >= 400: break
            page += 1
        res[j['key']] = {'codes': sorted(codes), 'total': total, 'ids': sorted(ids), 'bad': bad}
    print(json.dumps(res, ensure_ascii=False))
PY

# ════════════════════════════════════════════════════════════════
hr "B-T0 — 저장 길: 올리기 · 설명 고치기가 색인을 같은 규칙으로 쓴다"
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
W0=$(py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" writes)
note "창구 결과: $(printf '%s' "$W0" | python3 -c 'import json,sys; d=json.load(sys.stdin); print({k:(v if k.startswith("hit") or k=="patch_desc" else v[0]) for k,v in d.items()})')"
KO_ID=$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.load(sys.stdin)["up_ko"][1])')
EN_ID=$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.load(sys.stdin)["up_en"][1])')
expect "한글 이름·설명 파일 올리기 201" "201" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.load(sys.stdin)["up_ko"][0])')"
expect "한글 없는 파일 올리기 201" "201" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.load(sys.stdin)["up_en"][0])')"
expect "설명 고치기(PATCH) 200" "200" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.load(sys.stdin)["patch_desc"])')"
expect "고친 뒤 벡터 = 규칙(이름 + 새 설명)" "same|tokens" "$(chk vec "$KO_ID")"
expect "한글 없는 파일은 빈 벡터(NULL 아님)" "same|empty" "$(chk vec "$EN_ID")"
expect "새 설명의 말(정산서)로 찾힌다" "[200, true]" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["hit_new_desc"]))')"
expect "옛 설명의 말(신청합니다)로는 안 찾힌다" "[200, false]" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["hit_old_desc"]))')"
expect "이름의 말(연차휴가)로 찾힌다" "[200, true]" "$(printf '%s' "$W0" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["hit_name"]))')"
V_BEFORE=$(psqlq "SELECT search_tsv::text FROM files WHERE public_id = '$KO_ID'")
expect "등급 바꾸기 전 벡터가 읽혔다(비지 않음)" "yes" "$([ -n "$V_BEFORE" ] && echo yes || echo no)"
expect "관리자가 앱 창구로 등급만 바꿨다(PATCH access_level)" "200" "$(py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" level "$KO_ID")"
expect "등급 바뀐 파일 등급 = public" "public" "$(psqlq "SELECT access_level FROM files WHERE public_id = '$KO_ID'")"
expect "등급만 바뀐 행의 벡터는 그대로(설명·이름이 그대로)" "$V_BEFORE" "$(psqlq "SELECT search_tsv::text FROM files WHERE public_id = '$KO_ID'")"

# ─── 합성 파일 3,000 — 벡터 없이(NULL) 넣는다(반영 뒤 배치 전 모양). 이름·설명은 파이썬이 짓는다(씨앗 고정) ─────
cat > "$WORK/gen.py" <<'PY'
import random, sys, unicodedata
N = int(sys.argv[1]); rnd = random.Random(20260925)
ko = ('휴가 연차휴가 휴가신청 신청서 회의 회의록 계약 계약서 게약서 정산 정산서 휴게 휴게실 가휴 가신 보고서 결재 예산 인사 출장 '
      '교육 안내 변경 서버 배포 장애 점검 백업 보안 계정 권한 양식 견적서 연간 분기 월간 주간 목표 성과 평가 채용 입사 퇴사 식대 교통비').split()
parts = ['', '', '을', '를', '은', '는', '의', '로', '와', '에서', '합니다', '했습니다']
en = 'report meeting budget v2.14 2025 2026 Q1 Q4 a동 b동 draft final memo 100% 50_50 #tag (1)'.split()
def name(g):
    w = [rnd.choice(ko) + rnd.choice(parts) for _ in range(1 + g % 3)]
    if g % 4 == 0: w.append(rnd.choice(en))
    s = rnd.choice(['_', ' ', '-', '']).join(w) + '.' + rnd.choice(['pdf', 'docx', 'hwp', 'txt', 'xlsx'])
    if g % 29 == 0: s = rnd.choice(en) + '_' + rnd.choice(en) + '.txt'          # 한글 없는 이름
    return s
def desc(g):
    if g % 3 == 0: return ''
    return ' '.join(rnd.choice(ko) + rnd.choice(parts) if rnd.random() < 0.8 else rnd.choice(en) for _ in range(1 + g % 9))[:500]
print('\\set ON_ERROR_STOP on')
print('CREATE TEMP TABLE stg (g int, name text, descr text);')
print('COPY stg FROM STDIN;')
for g in range(1, N + 1):
    n, d = name(g), desc(g)
    if g % 13 == 0: n = unicodedata.normalize('NFD', n)                       # 맥에서 온 이름(분리 자모)
    if g % 17 == 0: d = unicodedata.normalize('NFD', d)
    # 「조합 음절 + 받침 자모」(LV+T) — 원문에는 「가」가 있고 조합하면 「각」(독립 검토 M-1 재현 모양)
    if g % 31 == 0: n = n.replace('가', '가\u11a8')
    if g % 37 == 0: d = d.replace('가', '가\u11af')
    print('%d\t%s\t%s' % (g, n, d))
print('\\.')
PY
cat > "$WORK/ins.sql" <<'SQL'
CREATE TEMP TABLE us AS SELECT row_number() OVER (ORDER BY id) AS k, id, team_id FROM users WHERE is_active;
CREATE TEMP TABLE fo AS SELECT row_number() OVER (ORDER BY id) AS k, id, access_level, team_id FROM folders;
INSERT INTO files (original_name, stored_name, ext, mime, size_bytes, sha256, description, access_level, team_id, uploaded_by, folder_id, created_at, deleted_at, deleted_by)
SELECT s.name, 'qf2b-' || s.g || '-' || md5(s.g::text), 'txt', 'text/plain', 100 + s.g, repeat('0', 64), s.descr,
       CASE WHEN fo.id IS NOT NULL THEN fo.access_level ELSE lv.lvl END,
       CASE WHEN fo.id IS NOT NULL THEN fo.team_id WHEN lv.lvl = 'team' THEN (SELECT min(id) FROM teams) + (s.g % 2) END,
       u.id, fo.id, now() - s.g * interval '1 minute',
       CASE WHEN s.g % 23 = 0 THEN now() END, CASE WHEN s.g % 23 = 0 THEN u.id END
  FROM stg s
  JOIN us u ON u.k = 1 + (s.g * 11) % (SELECT count(*) FROM us)
  CROSS JOIN LATERAL (SELECT (ARRAY['public','internal','internal','team','admin-only'])[1 + (s.g % 5)] AS lvl) lv
  LEFT JOIN fo ON s.g % 7 = 0 AND fo.k = 1 + (s.g / 7) % greatest((SELECT count(*) FROM fo), 1);
SQL
N_SYN="${QF2B_SYN:-3000}"
{ python3 "$WORK/gen.py" "$N_SYN"; cat "$WORK/ins.sql"; } | sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - > "$WORK/ins.out" 2>&1 \
  || { echo "  ✖ 합성 SQL 실패"; grep -v 'could not change directory' "$WORK/ins.out" | tail -5; exit 4; }
sudo -n -u postgres psql -q -d "$DB" -c "ANALYZE files" >/dev/null 2>&1
note "합성 파일 $N_SYN 넣음 — 벡터 NULL $(psqlq "SELECT count(*) FROM files WHERE search_tsv IS NULL") · 폴더 안 $(psqlq "SELECT count(*) FROM files WHERE stored_name LIKE 'qf2b-%' AND folder_id IS NOT NULL") · 휴지통 $(psqlq "SELECT count(*) FROM files WHERE stored_name LIKE 'qf2b-%' AND deleted_at IS NOT NULL")"

# ─── 대조할 검색어 · 계정 · 폴더 ─────────────────────────────────
FOLDERS=$(psqlq "SELECT string_agg(folder_id::text, ',') FROM (SELECT folder_id FROM files WHERE stored_name LIKE 'qf2b-%' AND folder_id IS NOT NULL GROUP BY folder_id ORDER BY count(*) DESC LIMIT 2) z")
python3 - "$WORK/jobs.json" "$FOLDERS" <<'PY'
import json, sys, unicodedata
out, folders = sys.argv[1], [int(x) for x in sys.argv[2].split(',') if x]
nfd = lambda s: unicodedata.normalize('NFD', s)
Q = ['휴가', '휴', '가', '의', '가신', '휴가신청', '휴가 신청', '신청서', '연차휴가', '회의록', '휴게', '가휴', '정산', '게약서',
     '휴가를', '신청합니다', '보고서 결재', 'report', '2026', 'report 휴가', '휴가_2026', 'v2.14', 'a동', 'Q4', '없는말조각', 'zqxjvk',
     nfd('휴가'), nfd('신청서'), nfd('휴가')[:-1], nfd('연차휴가')[:-1], '휴가' + nfd('신'),
     "휴'가", '휴&가', '휴|가', '!휴', '휴:*', '\\휴', '휴%', '휴_가', '#휴', '휴가 & 신청', '(휴가)', '100%', '50_50', '#tag', '%', '_']
WHO = ['claude_employee01', 'claude_employee02', 'claude_manager01', 'claude_admin01', 'claude_guest01']
jobs = []
for w in WHO:
    for i, q in enumerate(Q):
        jobs.append({'key': '%s|%d|g' % (w, i), 'who': w, 'q': q, 'folder': None})
    for fo in folders:
        for i, q in enumerate(['휴가', '의', '신청서', 'report', nfd('휴가')]):
            jobs.append({'key': '%s|f%d|%d' % (w, fo, i), 'who': w, 'q': q, 'folder': fo})
json.dump(jobs, open(out, 'w', encoding='utf-8'), ensure_ascii=False)
print('    · 대조 %d건(계정 %d × 검색어 %d + 폴더 %s × 5)' % (len(jobs), len(WHO), len(Q), folders))
PY
compare() {   # $1 이름표 — 창구(drv list) 와 기준선(chk baseline) 을 견준다
  py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- python3 "$WORK/drv.py" "$SITE" "$UA" list "$WORK/jobs.json" > "$WORK/api_$1.json"
  chk baseline < "$WORK/jobs.json" > "$WORK/base_$1.json"
  python3 - "$WORK/api_$1.json" "$WORK/base_$1.json" "$WORK/jobs.json" <<'PY'
import json, sys
a = json.load(open(sys.argv[1], encoding='utf-8')); b = json.load(open(sys.argv[2], encoding='utf-8'))
jobs = {j['key']: j for j in json.load(open(sys.argv[3], encoding='utf-8'))}
diff = []; non200 = []; bad = []; hits = 0; empty = 0; forb = 0; folder_ok = 0
for k, j in jobs.items():
    x, y = a[k], b[k]
    if y['forbidden']:
        # 못 읽는 폴더 — 창구는 403 한 가지 · 결과 0 이어야 한다
        forb += 1
        if x['codes'] != [403] or x['ids']: diff.append((j['who'], repr(j['q']), j['folder'], 'api', x['codes'], len(x['ids']), 'base', '403'))
        continue
    if j['folder'] is not None: folder_ok += 1
    if x['codes'] != [200]: non200.append((k, x['codes'], x['bad']))
    if x['bad']: bad.append((k, x['bad']))
    if x['ids'] != y['ids'] or x['total'] != y['total']:
        diff.append((j['who'], repr(j['q']), j['folder'], 'api', x['total'], len(x['ids']), 'base', y['total']))
    hits += y['total']; empty += (y['total'] == 0)
print('DIFF=%d NON200=%d BAD=%d HITS=%d EMPTY=%d N=%d FORB=%d FOLDER_OK=%d' % (len(diff), len(non200), len(bad), hits, empty, len(jobs), forb, folder_ok))
for d in diff[:8]: print('    ✖ 어긋남', d)
for d in non200[:5]: print('    ✖ 상태', d)
for d in bad[:5]: print('    ✖ 응답', d)
PY
}
res_of() { sed -n "s/.*$2=\([0-9]*\).*/\1/p" <<<"$1"; }

hr "B-T1 — 틈 없음: 벡터를 채우기 전(NULL) 모든 계정 × 검색어 — 창구 = 기준선(색인 없는 ILIKE)"
R1=$(compare pre)
printf '%s\n' "$R1" | sed 's/^/  /'
expect "B-T1 어긋난 대조(id 집합 · total)" "0" "$(res_of "$R1" DIFF)"
expect "B-T1 200 이 아닌 응답" "0" "$(res_of "$R1" NON200)"
expect "B-T1 SQLSTATE·예외가 든 응답" "0" "$(res_of "$R1" BAD)"
[ "$(res_of "$R1" HITS)" -gt 500 ] && expect "B-T1 대조가 헛돌지 않는다(기준선 결과 합 > 500)" "yes" "yes" || expect "B-T1 대조가 헛돌지 않는다(기준선 결과 합 > 500)" "yes" "no($(res_of "$R1" HITS))"

hr "B-T2 — 배치: dry-run(쓰기 0) → 실행 → 다시 dry-run 0 · 독립 대조"
V0=$(chk vectors); note "배치 전: $V0"
BAD0=$(sed -n 's/.*bad=\([0-9]*\).*/\1/p' <<<"$V0"); MD50=$(sed -n 's/.*md5=\([0-9a-f]*\).*/\1/p' <<<"$V0")
expect "배치 전 대조 값이 읽혔다(어긋난 수 · md5 가 비지 않음)" "yes" "$([[ "$BAD0" =~ ^[0-9]+$ ]] && [[ "$MD50" =~ ^[0-9a-f]{32}$ ]] && echo yes || echo no)"
D1=$( cd "$INST/app" && php scripts/qf2b_backfill_files.php --dry-run 2>&1 ); note "$D1"
expect "dry-run 의 「바꿀」 = 독립 대조의 어긋난 행 수" "$BAD0" "$(sed -n 's/.*바꿀 \([0-9]*\).*/\1/p' <<<"$D1")"
expect "dry-run 은 쓰지 않는다(벡터 md5 그대로)" "$MD50" "$(chk vectors | sed -n 's/.*md5=\([0-9a-f]*\).*/\1/p')"
R2=$( cd "$INST/app" && php scripts/qf2b_backfill_files.php 2>&1 ); note "$R2"
expect "실행 — 바꾼 행 = 어긋난 행" "$BAD0" "$(sed -n 's/.*바꾼 \([0-9]*\).*/\1/p' <<<"$R2")"
V1=$(chk vectors); note "배치 뒤: $V1"
expect "배치 뒤 규칙과 다른 벡터" "0" "$(sed -n 's/.*bad=\([0-9]*\).*/\1/p' <<<"$V1")"
expect "배치 뒤 NULL 벡터" "0" "$(sed -n 's/.*null=\([0-9]*\).*/\1/p' <<<"$V1")"
D2=$( cd "$INST/app" && php scripts/qf2b_backfill_files.php --dry-run 2>&1 )
expect "다시 dry-run — 바꿀 행" "0" "$(sed -n 's/.*바꿀 \([0-9]*\).*/\1/p' <<<"$D2")"
expect "잘못된 인자는 사용법 오류(3)" "3" "$( cd "$INST/app" && php scripts/qf2b_backfill_files.php --only pages >/dev/null 2>&1; echo $? )"
expect "NULL 부분 색인이 비었다" "0" "$(psqlq "SELECT count(*) FROM files WHERE search_tsv IS NULL")"
sudo -n -u postgres psql -q -d "$DB" -c "ANALYZE files" >/dev/null 2>&1

hr "B-T3 — 결과 정확성: 배치 뒤 모든 계정 × 검색어(폴더 안 포함) — 창구 = 기준선"
R3=$(compare post)
printf '%s\n' "$R3" | sed 's/^/  /'
expect "B-T3 어긋난 대조(id 집합 · total)" "0" "$(res_of "$R3" DIFF)"
expect "B-T3 200 이 아닌 응답" "0" "$(res_of "$R3" NON200)"
expect "B-T3 SQLSTATE·예외가 든 응답" "0" "$(res_of "$R3" BAD)"
note "폴더 안 검색 — 읽을 수 있어 결과를 대조한 것 $(res_of "$R3" FOLDER_OK)건 · 못 읽어 403 을 확인한 것 $(res_of "$R3" FORB)건"
[ "$(res_of "$R3" FOLDER_OK)" -gt 0 ] && expect "B-T3 폴더 안 검색 대조가 실제로 돌았다(200 대조 > 0)" "yes" "yes" || expect "B-T3 폴더 안 검색 대조가 실제로 돌았다(200 대조 > 0)" "yes" "no"

hr "B-T6 — 음성 대조: 색인 갈래가 실제로 붙는다(벡터를 비운 행은 한글 검색에서 빠지고 영문 검색에는 남는다)"
# 결과가 기준선과 같다는 것만으로는 색인 조건이 붙었는지 모른다(독립 검토 L-1) — 일부러 한 행의 벡터를 빈 값으로 만들어 본다.
cat > "$WORK/neg.py" <<'PY'
import json, sys, urllib.request, urllib.parse
SITE, UA, FID = sys.argv[1], sys.argv[2], sys.argv[3]
S = sys.stdin.readline().rstrip('\n')
def hit(q):
    r = urllib.request.Request(SITE + '/tbox/api/files?q=' + urllib.parse.quote(q), headers={'User-Agent': UA, 'Cookie': 'tbox_session=' + S})
    with urllib.request.urlopen(r, timeout=30) as resp:
        d = json.loads(resp.read().decode('utf-8'))
    return 'in' if FID in [f['id'] for f in d.get('files', [])] else 'out'
print(hit('연차휴가') + '|' + hit('QF2B'))
PY
expect "벡터가 규칙대로일 때 — 한글·영문 둘 다 찾힘" "in|in" "$(py_secret S_E1 -- python3 "$WORK/neg.py" "$SITE" "$UA" "$KO_ID")"
sudo -n -u postgres psql -q -d "$DB" -c "UPDATE files SET search_tsv = strip(to_tsvector('simple', '')) WHERE public_id = '$KO_ID'" >/dev/null 2>&1
expect "벡터를 빈 값으로 — 한글 검색에서 빠지고(색인 갈래) 영문 검색엔 남음(ILIKE 만)" "out|in" "$(py_secret S_E1 -- python3 "$WORK/neg.py" "$SITE" "$UA" "$KO_ID")"
N1=$( cd "$INST/app" && php scripts/qf2b_backfill_files.php --dry-run 2>&1 ); note "$N1"
expect "배치 dry-run 이 그 한 행을 찾는다" "1" "$(sed -n 's/.*바꿀 \([0-9]*\).*/\1/p' <<<"$N1")"
( cd "$INST/app" && php scripts/qf2b_backfill_files.php >/dev/null 2>&1 )
expect "배치 뒤 다시 한글·영문 둘 다 찾힘" "in|in" "$(py_secret S_E1 -- python3 "$WORK/neg.py" "$SITE" "$UA" "$KO_ID")"

hr "B-T4 — 주입·서버 로그"
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄 (B-T0~T3 동안)" "0" "$((errs_after - errs_before))"

hr "B-T5 — 정적"
nbad=0
for f in src/handlers/files_list.php src/handlers/files_update.php src/handlers/files_fetch.php src/handlers/_upload_common.php src/bootstrap.php scripts/qf2b_backfill_files.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l $f"; nbad=$((nbad+1)); }
done
expect "바뀐 PHP php -l 실패" "0" "$nbad"
if [ -f "$ROOT/scripts/security_lint.sh" ]; then
  ( cd "$ROOT" && bash scripts/security_lint.sh > "$WORK/lint.txt" 2>&1 ); rc=$?
  expect "보안 린트 종료 코드" "0" "$rc"
  [ "$rc" -eq 0 ] || tail -8 "$WORK/lint.txt" | sed 's/^/    /'
fi

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
