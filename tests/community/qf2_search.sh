#!/usr/bin/env bash
# tests/community/qf2_search.sh — Q:F-2 통합 검색 + 한글 색인 수락 시험 (지시서 docs/qf2-unified-search-directive.md 6장)
#
#   QF2-T1 결과 정확성  말뭉치(fixtures/search_ko.json — 문서 30 · 검색어 28 · 댓글 4)를 claude_employee01 이 앱 창구로 만든다.
#                       ① 재현율 ② 정밀도(말뭉치 정답표) ③ **기준선 대조** — 모든 검색어에서 창구 결과 = 판정 조건절 그대로 +
#                       낱말마다 NFC 원문 ILIKE(AND) 결과(색인을 쓰지 않는 SQL, 아래 도우미 `baseline`). 옛 5-B 검색(검색어 통째로
#                       원문 ILIKE)과 달라진 검색어는 목록으로 적는다(여러 낱말 AND · NFD). ⑤ 주입 문자열은 T3.
#   QF2-T2 권한         claude_ 다섯 계정 × 검색어 — 결과의 페이지·댓글을 **판정 함수**(CommunityAuthz::decidePageRead, 검색이 끄는
#                       admin_all·board_moderator 불허, 댓글은 comments_list 규칙)로 다시 본다(어긋남 0) + 시나리오 낱말은 판정 함수가
#                       보이는 페이지 전부가 나오는가(완전성). 숨긴·지운 댓글 0 · 게시판 등급으로만 보는 사람에게 게시 전 댓글 0 · Guest.
#   QF2-T3 주입         `' & | ! :* \ % _` 와 섞인 한글 — 두 창구 모두 200 · JSON · SQLSTATE·예외 0 · 서버 로그 새 오류 0.
#   QF2-T5 저장         100KB 본문 토큰화 시간·벡터 크기(도우미 tokbench) · 100KB 문서 PATCH 한 번의 시간.
#   QF2-T7 배치         qf2_backfill_search.php --dry-run 이 바꿀 행 수만(쓰기 0 — 벡터 md5 불변) · 그 수 = 독립 대조(도우미 vectors)의
#                       어긋난 행 수 · 실행 뒤 어긋남 0 · 다시 dry-run 0 · 앱 창구로 쓴 페이지·댓글·태그·판 되돌리기도 새 규칙.
#   QF2-T8 일부         stage5b_search.py(5-B 권한 시험 — 새 검색에서 그대로) · 보안 린트 · php -l · wiki_check.
#   REGRESS=1           같은 임시 인스턴스에서 커뮤니티 stage3~4c · stage26 · help_button · q_launcher 를 돈다(stage7d1.sh 의 회귀 틀).
#                       (커뮤니티 4d~6c 는 각자 임시 DB 를 만드는 시험 — 따로 돈다)
#   REGRESS_ONLY=1      T 절을 건너뛰고 회귀만(고치기 전 트리와 통과 수를 견줄 때 — 이 파일을 그 트리에 복사해 돌린다).
#
# ★ 운영 DB·운영 포트를 건드리지 않는다 — 임시 DB `tbox_qf2_<포트>_test`, 임시 인스턴스 `php -S 127.0.0.1:<포트>`(기본 8152).
#   운영 DB 는 pg_dump 로 **읽어** 임시 DB 에 붓기만 한다(파이프 — 파일로 남기지 않는다, 대기열 2d). 검토 계정은 claude_ 다섯(F-71).
#   비밀(비밀번호·TOTP·세션)은 표준입력으로만 넘긴다(tests/lib/secrets.sh — R-62).
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# 임시 .env 의 원본 — 기본은 트리의 .env. 복제본에는 두지 않는다: 시험하는 쪽이 mktemp 폴더(0700)에 사본을 만들어
# QF2_ENV_SRC 로 넘기고 끝에 지운다(운영 비밀 사본을 남기지 않는다 — 취합 규칙 2026-09-25). 값은 출력하지 않는다.
. "$ROOT/tests/lib/review_accounts.sh" || { echo "tests/lib/review_accounts.sh 를 읽지 못했다 — 시험을 시작하지 않는다"; exit 3; }
. "$ROOT/tests/lib/secrets.sh" || { echo "tests/lib/secrets.sh 를 읽지 못했다 — 시험을 시작하지 않는다 (R-62)"; exit 3; }
PORT="${QF2_PORT:-8152}"
case "$PORT" in 80|8082|8081) echo "운영 포트($PORT)는 쓰지 않는다"; exit 3 ;; esac
SITE="http://127.0.0.1:$PORT"
BASE="$SITE/tbox"
CBASE="$SITE/community"
UA="tbox-review/$(date +%F)"
SRC_DB="tbox_re"                  # 운영 — **읽기만** 한다(pg_dump)
DB="tbox_qf2_${PORT}_test"        # 임시 — 여기에만 쓴다
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
chmod 700 "$JAR_DIR" "$WORK"
INST=""
SERVER_PID=""
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
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
  [ -n "$INST" ] && rm -rf "$INST"
  rm -rf "$JAR_DIR" "$WORK"
}
trap cleanup EXIT

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }
jget() {
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
session_of() { awk '$6=="__Host-tbox_session"{print $7}' "$JAR_DIR/$1.jar" 2>/dev/null | tail -1; }

# ════════════════════════════════════════════════════════════════
hr "Q:F-2 — 임시 인스턴스 준비 (운영 DB 에는 쓰지 않는다)"
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
DUMP_PS=("${PIPESTATUS[@]}")
[ "${DUMP_PS[0]}" -eq 0 ] || { echo "운영 DB 를 읽지 못했다 (pg_dump)"; exit 3; }
n_pages=$(psqlq "SELECT count(*) FROM community.pages")
[ -n "$n_pages" ] || { echo "임시 DB 로 옮기지 못했다 — $(head -2 "$WORK/restore.err")"; exit 3; }
echo "  · 임시 DB $DB — 페이지 ${n_pages}장 · 댓글 $(psqlq "SELECT count(*) FROM community.comments")개"

sub "② 이번 마이그레이션(있으면) + 커뮤니티 권한 — 임시 DB 에만"
if [ -f "$ROOT/db/migrations/2026-10-01_qf2_search.sql" ]; then
  sudo -n -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/migrations/2026-10-01_qf2_search.sql" 2>&1 \
    | grep -v 'could not change directory' | sed 's/^/    /'
else
  note "db/migrations/2026-10-01_qf2_search.sql 없음 — 고치기 전 트리(회귀 대조용)"
fi
sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - < "$ROOT/db/community/grants.sql" 2>&1 \
  | grep -v 'could not change directory' | sed 's/^/    /'

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
PLAN_DIR="${PLAN_OVERRIDE:-$(cd "$ROOT/.." && pwd)/community}"
[ -f "$PLAN_DIR/doc-schema.json" ] || PLAN_DIR=/var/www/qaredi/community
ln -s "$PLAN_DIR" "$INST/community" 2>/dev/null
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
            $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css',
                      'json' => 'application/json', 'html' => 'text/html', 'svg' => 'image/svg+xml',
                      'png' => 'image/png', 'webmanifest' => 'application/manifest+json',
                      'map' => 'application/json', 'txt' => 'text/plain'];
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

# ─── 대조 도우미(PHP) — 임시 인스턴스 폴더에서 부른다(그 .env = 임시 DB). 읽기만 한다. ─────────────
#   vectors · baseline <계정> [comments] · oldline <계정> · perm <계정> · visible_with <계정> · tokbench
#   (판정 조건절은 앱의 것을 그대로 쓰고, 「무엇이 맞나」는 색인 없이 NFC 원문 ILIKE 로 따로 적는다 — 시험 대상 SearchText 에 기대지 않는다.
#    단 vectors 는 「저장된 값 = 규칙으로 다시 만든 값」을 보는 것이라 규칙 함수 community_search_text 를 부른다.)
cat > "$WORK/qf2_check.php" <<'PHP'
<?php
declare(strict_types=1);
$app = getcwd();
require $app . '/scripts/lib/cli_bootstrap.php';
require_once $app . '/src/community/routes.php';
$mode = (string) ($argv[1] ?? '');
$db   = Db::instance();
function qf2c_user(string $username): array {
    $r = Db::instance()->run('SELECT id, username, role, team_id, is_active FROM public.users WHERE username = :u', [':u' => $username])->fetch();
    if (!is_array($r)) { fwrite(STDERR, "계정 없음\n"); exit(3); }
    return ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => (string) $r['role'],
            'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'is_active' => $r['is_active'] === true];
}
function qf2c_terms(string $q): array {
    $q = trim($q);
    if ($q === '' || preg_match('/[\p{L}\p{N}]/u', $q) !== 1) { return []; }
    $n = (string) Db::instance()->run('SELECT normalize(CAST(:q AS text), NFC)', [':q' => $q])->fetchColumn();
    return array_values(array_unique(preg_split('/\s+/u', trim($n), -1, PREG_SPLIT_NO_EMPTY) ?: []));
}
function qf2c_like(string $t): string { return '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $t) . '%'; }
switch ($mode) {
case 'vectors':
    $bad = 0; $n = 0; $samples = [];
    foreach ($db->run('SELECT p.id, p.title, p.plain_text, p.search_tsv::text AS v FROM community.pages p ORDER BY p.id')->fetchAll() as $r) {
        $n++;
        $tags = array_map(static fn (array $t): string => (string) $t['tag'],
            $db->run('SELECT tag FROM community.page_tags WHERE page_id = :p ORDER BY tag_norm', [':p' => (int) $r['id']])->fetchAll());
        $want = (string) $db->run("SELECT strip(to_tsvector('simple', CAST(:t AS text)))::text",
            [':t' => community_search_text((string) $r['title'], (string) $r['plain_text'], $tags)])->fetchColumn();
        if ((string) $r['v'] !== $want) { $bad++; if (count($samples) < 3) { $samples[] = 'page ' . $r['id']; } }
    }
    echo "PAGES=$n\nPAGES_BAD=$bad\n";
    $cbad = 0; $cn = 0;
    foreach ($db->run('SELECT c.id, c.body, c.search_tsv::text AS v FROM community.comments c ORDER BY c.id')->fetchAll() as $r) {
        $cn++;
        $want = (string) $db->run("SELECT strip(to_tsvector('simple', CAST(:t AS text)))::text",
            [':t' => SearchText::indexText([(string) $r['body']])])->fetchColumn();
        if ((string) $r['v'] !== $want) { $cbad++; if (count($samples) < 6) { $samples[] = 'comment ' . $r['id']; } }
    }
    echo "COMMENTS=$cn\nCOMMENTS_BAD=$cbad\n";
    foreach ($samples as $s) { echo "BAD_SAMPLE=$s\n"; }
    break;
case 'baseline':
case 'oldline':
    $u = qf2c_user((string) ($argv[2] ?? ''));
    $q = rtrim((string) fgets(STDIN), "\n");
    [$visible, $bind] = community_search_pages_where($u);
    if ($mode === 'oldline') {
        $q = trim($q);
        if ($q === '') { break; }
        $bind[':o1'] = qf2c_like($q); $bind[':o2'] = qf2c_like($q); $bind[':o3'] = qf2c_like($q);
        $sql = "SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
                  LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                 WHERE p.deleted_at IS NULL AND {$visible} AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
                   AND (p.title ILIKE :o1 ESCAPE '#' OR p.plain_text ILIKE :o2 ESCAPE '#')
                 ORDER BY (p.title ILIKE :o3 ESCAPE '#') DESC, p.updated_at DESC, p.id DESC LIMIT 20";
        foreach ($db->run($sql, $bind)->fetchAll() as $r) { echo $r['public_id'], "\n"; }
        break;
    }
    $terms = qf2c_terms($q);
    if ($terms === []) { break; }
    $comments = ($argv[3] ?? '') === 'comments';
    $conds = []; $inTitle = [];
    foreach ($terms as $i => $t) {
        $l = qf2c_like($t);
        if ($comments) {
            $bind[":b{$i}"] = $l;
            $conds[] = "normalize(c.body, NFC) ILIKE :b{$i} ESCAPE '#'";
        } else {
            $bind[":b{$i}"] = $l; $bind[":c{$i}"] = $l; $bind[":d{$i}"] = $l; $bind[":e{$i}"] = $l;
            $conds[] = "(normalize(p.title, NFC) ILIKE :b{$i} ESCAPE '#' OR normalize(p.plain_text, NFC) ILIKE :c{$i} ESCAPE '#'"
                     . " OR EXISTS (SELECT 1 FROM community.page_tags t WHERE t.page_id = p.id AND normalize(t.tag, NFC) ILIKE :d{$i} ESCAPE '#'))";
            $inTitle[] = "normalize(p.title, NFC) ILIKE :e{$i} ESCAPE '#'";
        }
    }
    if ($comments) {
        $bind[':me'] = (int) $u['id'];
        $sql = "SELECT c.public_id FROM community.comments c JOIN community.pages p ON p.id = c.page_id
                  JOIN community.spaces s ON s.id = p.space_id LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                  LEFT JOIN community.comments par ON par.id = c.parent_comment_id
                 WHERE c.deleted_at IS NULL AND c.hidden_at IS NULL AND p.deleted_at IS NULL AND {$visible}
                   AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
                   AND NOT (s.kind = 'personal' AND p.visibility = 'published' AND s.owner_user_id IS DISTINCT FROM CAST(:me AS bigint)
                            AND (c.created_at < p.published_at OR coalesce(par.created_at < p.published_at, false)))
                   AND " . implode(' AND ', $conds) . "
                 ORDER BY c.created_at DESC, c.id DESC LIMIT 20";
    } else {
        $sql = "SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
                  LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                 WHERE p.deleted_at IS NULL AND {$visible} AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
                   AND " . implode(' AND ', $conds) . "
                 ORDER BY (" . implode(' AND ', $inTitle) . ") DESC, p.updated_at DESC, p.id DESC LIMIT 20";
    }
    foreach ($db->run($sql, $bind)->fetchAll() as $r) { echo $r['public_id'], "\n"; }
    break;
case 'perm':
    $u = qf2c_user((string) ($argv[2] ?? ''));
    $in = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($in)) { fwrite(STDERR, "JSON 아님\n"); exit(3); }
    $checked = 0; $bad = 0; $samples = [];
    $pageOk = static function (string $pid) use ($u): array {
        $row = community_page_row($pid, (int) $u['id']);
        if ($row === null) { return [false, 'no_row', null]; }
        [$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
        if (!$ok) { return [false, 'deny:' . $code, $row]; }
        if ($code === CommunityAuthz::CODE_ADMIN_ALL || $code === CommunityAuthz::CODE_BOARD_MODERATOR) { return [false, 'priv:' . $code, $row]; }
        if ((string) $row['space_kind'] === 'board' && $row['hidden_at'] !== null) { return [false, 'hidden_board', $row]; }
        return [true, $code, $row];
    };
    foreach (($in['pages'] ?? []) as $pid) {
        $checked++;
        [$ok, $why] = $pageOk((string) $pid);
        if (!$ok) { $bad++; $samples[] = "page $pid $why"; }
    }
    foreach (($in['comments'] ?? []) as $cid) {
        $checked++;
        $c = $db->run('SELECT c.id, c.deleted_at, c.hidden_at, c.created_at, p.public_id AS page_pid, par.created_at AS par_created
                         FROM community.comments c JOIN community.pages p ON p.id = c.page_id
                         LEFT JOIN community.comments par ON par.id = c.parent_comment_id
                        WHERE c.public_id = CAST(:c AS uuid)', [':c' => (string) $cid])->fetch();
        if (!is_array($c)) { $bad++; $samples[] = "comment $cid no_row"; continue; }
        if ($c['deleted_at'] !== null || $c['hidden_at'] !== null) { $bad++; $samples[] = "comment $cid deleted_or_hidden"; continue; }
        [$ok, $code, $row] = $pageOk((string) $c['page_pid']);
        if (!$ok) { $bad++; $samples[] = "comment $cid page:$code"; continue; }
        if ($code === CommunityAuthz::CODE_BOARD && (string) $row['space_kind'] === 'personal'
            && (string) $row['visibility'] === 'published' && $row['published_at'] !== null) {
            $since = strtotime((string) $row['published_at']);
            if (strtotime((string) $c['created_at']) < $since || ($c['par_created'] !== null && strtotime((string) $c['par_created']) < $since)) {
                $bad++; $samples[] = "comment $cid before_publish"; continue;
            }
        }
    }
    echo "CHECKED=$checked\nBAD=$bad\n";
    foreach (array_slice($samples, 0, 8) as $s) { echo "BAD_SAMPLE=$s\n"; }
    break;
case 'visible_with':
    $u = qf2c_user((string) ($argv[2] ?? ''));
    $w = trim((string) fgets(STDIN));
    if ($w === '') { break; }
    $rows = $db->run("SELECT p.public_id FROM community.pages p WHERE p.deleted_at IS NULL
                        AND (p.title ILIKE :a ESCAPE '#' OR p.plain_text ILIKE :b ESCAPE '#') ORDER BY p.id",
                     [':a' => qf2c_like($w), ':b' => qf2c_like($w)])->fetchAll();
    foreach ($rows as $r) {
        $row = community_page_row((string) $r['public_id'], (int) $u['id']);
        if ($row === null) { continue; }
        [$ok, $code] = CommunityAuthz::decidePageRead($u, $row);
        if (!$ok || $code === CommunityAuthz::CODE_ADMIN_ALL || $code === CommunityAuthz::CODE_BOARD_MODERATOR) { continue; }
        if ((string) $row['space_kind'] === 'board' && $row['hidden_at'] !== null) { continue; }
        echo $r['public_id'], "\n";
    }
    break;
case 'tokbench':
    mt_srand(20260925);
    $words = ['휴가', '회의', '계약', '정산', '신청', '보고', '일정', '검토', '자료', '공유', '문서', '결재', '예산', '인사', '출장',
              '교육', '안내', '변경', '확인', '요청', '처리', '관리', '업무', '사항', '진행', '완료', '예정', '담당', '부서', '팀장'];
    $particles = ['을', '를', '은', '는', '이', '가', '에', '의', '로', '와', '과', '도', '만', '에서', '까지', '부터', '합니다', '했습니다'];
    $text = '';
    while (strlen($text) < 100 * 1024) {
        if (mt_rand(1, 100) <= 80) {
            $text .= $words[mt_rand(0, count($words) - 1)] . (mt_rand(0, 1) ? $particles[mt_rand(0, count($particles) - 1)] : '') . ' ';
        } else {
            $text .= ['report', 'v2.14', '2026', 'Q3', 'meeting', 'budget', 'ok', '15', 'A-7'][mt_rand(0, 8)] . ' ';
        }
    }
    $times = []; $t = '';
    for ($i = 0; $i < 20; $i++) { $s = hrtime(true); $t = community_search_text('제목', $text, []); $times[] = (hrtime(true) - $s) / 1e6; }
    sort($times);
    $size = (int) $db->run("SELECT pg_column_size(strip(to_tsvector('simple', CAST(:t AS text))))", [':t' => $t])->fetchColumn();
    $wide = '';
    while (strlen($wide) < 100 * 1024) {
        $n = mt_rand(2, 6);
        for ($k = 0; $k < $n; $k++) {
            $cp = mt_rand(0xAC00, 0xD7A3);
            $wide .= chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        $wide .= ' ';
    }
    $wt = []; $w = '';
    for ($i = 0; $i < 5; $i++) { $s = hrtime(true); $w = community_search_text('제목', $wide, []); $wt[] = (hrtime(true) - $s) / 1e6; }
    sort($wt);
    $wsize = (int) $db->run("SELECT pg_column_size(strip(to_tsvector('simple', CAST(:t AS text))))", [':t' => $w])->fetchColumn();
    printf("TEXT_BYTES=%d\nTOK_MS_MEDIAN=%.2f\nTOK_MS_MAX=%.2f\nVEC_BYTES=%d\nTOKENS=%d\n", strlen($text), $times[10], $times[19], $size, count(explode(' ', $t)));
    printf("WIDE_BYTES=%d\nWIDE_TOK_MS_MEDIAN=%.2f\nWIDE_VEC_BYTES=%d\nWIDE_TOKENS=%d\n", strlen($wide), $wt[2], $wsize, count(explode(' ', $w)));
    // 상한에서 잘릴 때 제목·태그가 남는가(검토 L-2) — 서로 다른 음절 100KB 본문 + 태그 하나 · 제목 하나
    $capped = explode(' ', community_search_text('맨앞제목', $wide, ['끝자리꼬리표']));
    printf("CAP_TOKENS=%d\nCAP_TITLE_KEPT=%s\nCAP_TAG_KEPT=%s\n", count($capped),
        in_array('맨앞', $capped, true) && in_array('제목', $capped, true) ? 'yes' : 'no',
        in_array('끝자', $capped, true) && in_array('꼬리', $capped, true) && in_array('리표', $capped, true) ? 'yes' : 'no');
    break;
case 'locksql':
    // 저장 경로가 태그를 읽기 전에 거는 잠금 문장(앱 상수 그대로) — 겹침 시험이 psql 세션에서 같은 잠금을 흉내 낸다
    echo trim(COMMUNITY_PAGE_LOCK_SQL), "\n";
    break;
default:
    fwrite(STDERR, "모드?\n"); exit(3);
}
PHP
chk() { ( cd "$INST/app" && php "$WORK/qf2_check.php" "$@" ); }

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS_ONLY:-0}" != "1" ]; then
hr "QF2-T7 배치 — qf2_backfill_search.php (인스턴스를 띄우기 전, 임시 DB 에만)"
vec_md5() {
  psqlq "SELECT md5(coalesce(string_agg(id::text || ':' || coalesce(search_tsv::text, '-'), ',' ORDER BY id), '')) FROM community.pages" | tr -d '\n'
  printf '/'
  psqlq "SELECT md5(coalesce(string_agg(id::text || ':' || coalesce(search_tsv::text, '-'), ',' ORDER BY id), '')) FROM community.comments"
}
before=$(vec_md5)
upd_md5() { psqlq "SELECT md5(coalesce(string_agg(id::text || ':' || updated_at::text, ',' ORDER BY id), '')) FROM community.pages"; }
upd_before=$(upd_md5)
chk vectors > "$WORK/vec0.txt" 2>&1
sed 's/^/    /' "$WORK/vec0.txt"
want_pages=$(sed -n 's/^PAGES_BAD=//p' "$WORK/vec0.txt")
want_comments=$(sed -n 's/^COMMENTS_BAD=//p' "$WORK/vec0.txt")
( cd "$INST/app" && php scripts/qf2_backfill_search.php --dry-run ) > "$WORK/dry1.txt" 2>&1; rc=$?
sed 's/^/    /' "$WORK/dry1.txt"
expect "dry-run 종료 코드" "0" "$rc"
expect "dry-run 이 벡터를 바꾸지 않았다(md5 전후 같음)" "$before" "$(vec_md5)"
expect "dry-run 「바꿀」 페이지 수 = 독립 대조의 어긋난 페이지 수" "${want_pages:-?}" "$(sed -n 's/^\[dry\] pages: 본 [0-9]* · 바꿀 \([0-9]*\).*/\1/p' "$WORK/dry1.txt")"
expect "dry-run 「바꿀」 댓글 수 = 독립 대조의 어긋난 댓글 수" "${want_comments:-?}" "$(sed -n 's/^\[dry\] comments: 본 [0-9]* · 바꿀 \([0-9]*\).*/\1/p' "$WORK/dry1.txt")"
( cd "$INST/app" && php scripts/qf2_backfill_search.php --only bogus ) > /dev/null 2>&1; rc=$?
expect "--only 가 틀리면 사용법 오류(3)" "3" "$rc"
( cd "$INST/app" && php scripts/qf2_backfill_search.php ) > "$WORK/run1.txt" 2>&1; rc=$?
sed 's/^/    /' "$WORK/run1.txt"
expect "실행 종료 코드" "0" "$rc"
expect "실행 「바꾼」 페이지 수 = dry-run 의 수" "${want_pages:-?}" "$(sed -n 's/^pages: 본 [0-9]* · 바꾼 \([0-9]*\).*/\1/p' "$WORK/run1.txt")"
chk vectors > "$WORK/vec1.txt" 2>&1
expect "실행 뒤 — 새 규칙으로 다시 만든 것과 다른 페이지 0" "0" "$(sed -n 's/^PAGES_BAD=//p' "$WORK/vec1.txt")"
expect "실행 뒤 — 다른 댓글 0" "0" "$(sed -n 's/^COMMENTS_BAD=//p' "$WORK/vec1.txt")"
( cd "$INST/app" && php scripts/qf2_backfill_search.php --dry-run ) > "$WORK/dry2.txt" 2>&1
expect "다시 dry-run — 바꿀 페이지 0" "0" "$(sed -n 's/^\[dry\] pages: 본 [0-9]* · 바꿀 \([0-9]*\).*/\1/p' "$WORK/dry2.txt")"
expect "  updated_at 은 그대로(배치가 「최근 고친 순」을 흔들지 않는다 — md5 전후 같음)" "$upd_before" "$(upd_md5)"
fi

sub "④ 두 번째 인스턴스를 띄운다"
( cd "$INST/app" && exec php -S "127.0.0.1:$PORT" -t public router.php > "$INST/server.log" 2>&1 ) &
SERVER_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 1
  READY=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$CBASE/api/pages")
  [ "$READY" = "401" ] && break
done
expect "임시 인스턴스가 떴다 (미로그인 커뮤니티 창구 401)" "401" "$READY"
[ "$READY" = "401" ] || { echo "인스턴스를 띄우지 못했다 — 중단"; tail -5 "$INST/server.log"; exit 4; }

sub "로그인 (claude_ 검토 계정 다섯 — 순차, F-71)"
for who in claude_employee01 claude_employee02 claude_manager01 claude_admin01 claude_guest01; do
  if login "$who"; then echo "  ✔ $who"; else echo "  ✖ $who 로그인 실패 — 중단"; exit 4; fi
done
S_E1=$(session_of claude_employee01); S_E2=$(session_of claude_employee02); S_M1=$(session_of claude_manager01)
S_A1=$(session_of claude_admin01);   S_G1=$(session_of claude_guest01)

if [ "${REGRESS_ONLY:-0}" != "1" ]; then
# ─── 시험 몸통(파이썬) — 세션 다섯은 표준입력 한 줄씩(R-62). 대조는 위 PHP 도우미를 부른다. ─────────────
cat > "$WORK/qf2_driver.py" <<'PY'
import json, os, re, subprocess, sys, time, unicodedata, uuid, urllib.request, urllib.error, urllib.parse
SITE, UA, FIX, CHECK, APPDIR, SECTION = sys.argv[1:7]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'm1', 'a1', 'g1')}
NAME = {'e1': 'claude_employee01', 'e2': 'claude_employee02', 'm1': 'claude_manager01', 'a1': 'claude_admin01', 'g1': 'claude_guest01'}
CSRF = {}
P = F = 0
def ok(name, cond, detail=''):
    global P, F
    if cond: P += 1; print('  ✔ ' + name)
    else: F += 1; print('  ✖ ' + name + ('  — ' + str(detail)[:300] if detail != '' else ''))
def req(who, m, path, body=None):
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + S[who]}
    if m != 'GET':
        h['X-CSRF-Token'] = CSRF.get(who, ''); h['Content-Type'] = 'application/json'
    data = None if body is None else json.dumps(body, ensure_ascii=False).encode('utf-8')
    r = urllib.request.Request(SITE + path, data=data, headers=h, method=m)
    t0 = time.time()
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            raw = x.read(); code = x.status
    except urllib.error.HTTPError as e:
        raw = e.read(); code = e.code
    dt = (time.time() - t0) * 1000
    try: d = json.loads(raw or b'{}')
    except Exception: d = None
    return code, d, raw, dt
def php(*args, inp=''):
    r = subprocess.run(['php', CHECK] + list(args), input=inp, capture_output=True, text=True, cwd=APPDIR)
    return r.stdout
def search(who, q):
    c, d, raw, dt = req(who, 'GET', '/community/api/search?q=' + urllib.parse.quote(q))
    d = d or {}
    return c, [x['id'] for x in d.get('results', [])], [x['id'] for x in d.get('comments', [])], d, raw
for w in S:
    c, d, _, _ = req(w, 'GET', '/tbox/api/me'); CSRF[w] = (d or {}).get('csrf_token', '')
C = '/community/api/pages'
def make_page(who, title, body, tags=None, space=None):
    b = {'title': title}
    if space: b['space_id'] = space
    c, d, _, _ = req(who, 'POST', C, b)
    pid = (d or {}).get('page', {}).get('id', '')
    if not pid: return '', c
    c, g, _, _ = req(who, 'GET', f'{C}/{pid}')
    blocks = [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': ([{'t': body, 'm': {}}] if body else [])}]
    c2, d2, _, _ = req(who, 'PATCH', f'{C}/{pid}', {'doc_version': g['page']['doc_version'], 'doc': {'schema': 1, 'blocks': blocks}, 'title': title})
    if tags:
        req(who, 'PUT', f'{C}/{pid}/tags', {'tags': tags})
    return pid, c2
def comment(who, pid, body):
    c, d, _, _ = req(who, 'POST', f'{C}/{pid}/comments', {'body': body})
    return (d or {}).get('comment', {}).get('id', ''), c

fx = json.load(open(FIX, encoding='utf-8'))
if SECTION in ('all', 't1'):
    print('\n── QF2-T1 결과 정확성 — 말뭉치를 claude_employee01 이 앱 창구로 만든다')
    ids = {}
    for d in fx['docs']:
        pid, c = make_page('e1', d['title'], d['body'], d['tags'])
        ids[d['key']] = pid
    ok('말뭉치 문서 %d개를 만들었다' % len(fx['docs']), all(ids.values()) and len(ids) == len(fx['docs']), [k for k, v in ids.items() if not v])
    rev = {v: k for k, v in ids.items()}
    cids = {}
    for cm in fx['comments']:
        cid, c = comment('e1', ids[cm['doc']], cm['body'])
        cids[cm['key']] = cid
    ok('말뭉치 댓글 %d개' % len(cids), all(cids.values()), cids)
    crev = {v: k for k, v in cids.items()}
    diff_old = []
    for q in fx['queries']:
        c, pages, comments, d, raw = search('e1', q['q'])
        mine = sorted(rev[p] for p in pages if p in rev)
        base = [x for x in php('baseline', NAME['e1'], inp=q['q'] + '\n').split('\n') if x]
        trunc = len(pages) >= 20
        label = '%s 「%s」' % (q['key'], q['q'].encode('unicode_escape').decode() if unicodedata.normalize('NFC', q['q']) != q['q'] else q['q'])
        ok(label + ' 200', c == 200, c)
        if trunc:
            print('    · 결과 20건(잘림) — 정답표는 기준선 대조로만 본다')
        else:
            ok(label + ' 재현율·정밀도 = 정답표 %s' % q['expect'], mine == sorted(q['expect']), mine)
        ok(label + ' 기준선(판정 조건절 + NFC 원문 ILIKE AND)과 같은 집합 (%d건)' % len(base), sorted(pages) == sorted(base),
           {'api_only': [rev.get(x, x) for x in set(pages) - set(base)], 'base_only': [rev.get(x, x) for x in set(base) - set(pages)]})
        old = [x for x in php('oldline', NAME['e1'], inp=q['q'] + '\n').split('\n') if x]
        if sorted(old) != sorted(pages):
            diff_old.append('%s(%s: 옛 %d → 새 %d)' % (q['key'], q['q'].encode('unicode_escape').decode() if unicodedata.normalize('NFC', q['q']) != q['q'] else q['q'], len(old), len(pages)))
    print('    · 옛 5-B 검색(검색어 통째로 원문 ILIKE)과 결과가 다른 검색어: ' + (', '.join(diff_old) if diff_old else '없음'))
    for cq in fx['comment_queries']:
        c, pages, comments, d, raw = search('e1', cq['q'])
        mine = sorted(crev[x] for x in comments if x in crev)
        ok('댓글 「%s」 = 정답 %s' % (cq['q'], cq['expect']), mine == sorted(cq['expect']), mine)
        base = [x for x in php('baseline', NAME['e1'], 'comments', inp=cq['q'] + '\n').split('\n') if x]
        ok('댓글 「%s」 기준선과 같은 집합' % cq['q'], sorted(base) == sorted(comments), (len(base), len(comments)))
    # 응답 모양 — 전체 개수 없음 · 조각 길이
    c, pages, comments, d, raw = search('e1', '휴가')
    ok('응답에 전체 개수(total) 칸이 없다', 'total' not in (d or {}) and b'"total"' not in raw, list((d or {}).keys()))
    ok('조각은 200자 이하', all(len(x.get('snippet', '')) <= 200 for x in d.get('results', []) + d.get('comments', [])))
    hit = [x for x in d.get('results', []) if x['id'] == ids['d14']]
    ok('NFD 문서의 조각은 조합한 글자로(「휴가」가 조각 안에서 찾힌다 — 강조가 맞는다)', bool(hit) and '휴가' in hit[0].get('snippet', '') + hit[0].get('title', ''), hit)
    c, pages, comments, d, raw = search('e1', '#')
    ok('J-11 — 글자·숫자 없는 검색어(#)는 빈 결과 200', c == 200 and pages == [] and comments == [], (c, len(pages)))
    json.dump({'ids': ids, 'cids': cids}, open(os.environ.get('QF2_IDS', '/dev/null'), 'w'))

    print('\n── QF2-T7(후반) 앱 창구로 쓴 값도 새 규칙인가 — 태그 · 댓글 고치기 · 판 되돌리기')
    out = php('vectors')
    ok('창구로 만든 페이지·댓글 벡터 = 규칙으로 다시 만든 값 (' + out.replace('\n', ' ').strip() + ')', 'PAGES_BAD=0' in out and 'COMMENTS_BAD=0' in out, out)
    d19 = ids['d19']
    req('e1', 'PUT', f'{C}/{d19}/tags', {'tags': []})
    c, pages, _, _, _ = search('e1', '계약서')
    ok('태그를 빼면 태그로만 맞던 페이지가 검색에서 빠진다', d19 not in pages, [rev.get(x, x) for x in pages])
    req('e1', 'PUT', f'{C}/{d19}/tags', {'tags': ['계약서']})
    c, pages, _, _, _ = search('e1', '계약서')
    ok('태그를 다시 달면 나온다(색인이 태그와 같은 트랜잭션에서 바뀐다)', d19 in pages)
    c02 = cids['c02']
    c, d, _, _ = req('e1', 'PATCH', f'/community/api/comments/{c02}', {'body': '회의록 다시 확인했어요'})
    ok('댓글 고치기 200', c == 200, c)
    c, _, comments, _, _ = search('e1', '다시')
    ok('고친 댓글 본문의 새 낱말(「다시」)로 찾힌다', c02 in comments)
    d24 = ids['d24']
    c, g, _, _ = req('e1', 'GET', f'{C}/{d24}')
    blocks = [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': '새내용만 남김', 'm': {}}]}]
    req('e1', 'PATCH', f'{C}/{d24}', {'doc_version': g['page']['doc_version'], 'doc': {'schema': 1, 'blocks': blocks}, 'title': '정산표'})
    c, pages, _, _, _ = search('e1', '새내용만')
    ok('고친 본문의 낱말로 찾힌다', d24 in pages)
    c, v, _, _ = req('e1', 'GET', f'{C}/{d24}/versions')
    olds = [x['version'] for x in (v or {}).get('versions', []) if not x.get('current')]
    olds = [x for x in olds if x != g['page']['doc_version'] + 1]
    if olds:
        c, d, _, _ = req('e1', 'POST', f'{C}/{d24}/versions/{min(olds)}/restore', {})
        ok('판 되돌리기 200 (판 %s)' % min(olds), c == 200, (c, d))
        c, pages, _, _, _ = search('e1', '새내용만')
        ok('되돌린 뒤 — 되돌리기 전 본문의 낱말로는 더 안 찾힌다(색인도 되돌아감)', d24 not in pages)
        out = php('vectors')
        ok('되돌린 뒤 벡터 = 규칙 (' + out.replace('\n', ' ').strip() + ')', 'PAGES_BAD=0' in out and 'COMMENTS_BAD=0' in out, out)
    else:
        ok('되돌릴 옛 판이 있다', False, v)

if SECTION in ('all', 't3'):
    print('\n── QF2-T3 주입 — 두 창구 모두 200 · JSON · SQLSTATE·예외 0')
    inj = ["'", '"', '&', '|', '!', ':*', '\\', '%', '_', '휴가&계약', '휴가 | 계약', '!휴가', '휴가:*', "휴'가", '\\휴가', '(휴가)', '휴가)',
           '<b>휴가</b>', "' OR 1=1 --", '휴가%', '%휴가%', '_휴', '휴가\\', "휴가''", '&|' * 50, '휴가 & | ! ( ) : * \\ \'', "'; DROP TABLE x; --",
           '휴가' * 60, 'ㅎㄱ', 'ᄒ', '가\u0000나', '\x7f휴가']
    bad = 0
    for s in inj:
        for path in ('/community/api/search?q=', '/tbox/api/files?q='):
            c, d, raw, dt = req('e1', 'GET', path + urllib.parse.quote(s))
            body = (raw or b'').decode('utf-8', 'replace')
            good = c == 200 and d is not None and 'SQLSTATE' not in body and 'Exception' not in body and 'Stack trace' not in body
            if not good:
                bad += 1; print('    ✖ %s %r → %s %s' % (path, s[:40], c, body[:120]))
    ok('주입 문자열 %d개 × 창구 2 — 전부 200·JSON·오류 글자 0' % len(inj), bad == 0, bad)

if SECTION in ('all', 't2'):
    print('\n── QF2-T2 권한 — 시나리오(비공개·팀·사내·공유·공유 뺌·게시·숨긴 게시판 글·숨긴/지운 댓글)')
    W = '권한조각'
    A, _ = make_page('e1', W + ' 비공개 qfperm', '비공개 본문 ' + W)
    B, _ = make_page('e1', W + ' 팀범위 qfperm', '팀 본문 ' + W); req('e1', 'PATCH', f'{C}/{B}/visibility', {'visibility': 'team'})
    Cp, _ = make_page('e1', W + ' 사내 qfperm', '사내 본문 ' + W); req('e1', 'PATCH', f'{C}/{Cp}/visibility', {'visibility': 'internal'})
    D, _ = make_page('e1', W + ' 공유 qfperm', '공유 본문 ' + W); req('e1', 'PUT', f'{C}/{D}/shares/claude_employee02', {'level': 'view'})
    E, _ = make_page('e1', W + ' 공유뺌 qfperm', '공유뺌 본문 ' + W)
    req('e1', 'PUT', f'{C}/{E}/shares/claude_employee02', {'level': 'view'}); req('e1', 'DELETE', f'{C}/{E}/shares/claude_employee02', {})
    Fp, _ = make_page('e1', W + ' 게시 qfperm', '게시 본문 ' + W)
    c_pre, _ = comment('e1', Fp, '게시전댓글 권한댓글')
    boards = req('e1', 'GET', '/community/api/boards')[1] or {}
    free = [b['id'] for b in boards.get('boards', []) if b.get('name') == '자유게시판']
    c, d, _, _ = req('e1', 'POST', f'{C}/{Fp}/publish', {'board_id': free[0] if free else 0})
    ok('게시 200 (자유게시판)', c == 200, (c, d))
    time.sleep(1.1)
    c_post, cc = comment('e2', Fp, '게시후댓글 권한댓글')
    ok('게시판 독자(직원2)가 게시 뒤 댓글을 남겼다', bool(c_post), cc)
    c, d, _, _ = req('e2', 'POST', '/community/api/boards/%s/pages' % (free[0] if free else 0), {'title': W + ' 숨긴글 qfperm'})
    G = (d or {}).get('page', {}).get('id', '')
    c, d, _, _ = req('a1', 'POST', f'{C}/{G}/hide', {})
    ok('관리자가 게시판 글을 숨겼다', c == 200, (c, d))
    c_vis, _ = comment('e2', Cp, '권한댓글 보임')
    c_hid, _ = comment('e2', Cp, '권한댓글 숨김')
    c, d, _, _ = req('a1', 'POST', f'/community/api/comments/{c_hid}/hide', {})
    ok('관리자가 댓글을 숨겼다', c == 200, (c, d))
    c_del, _ = comment('e2', Cp, '권한댓글 지움')
    c, d, _, _ = req('e2', 'DELETE', f'/community/api/comments/{c_del}', {})
    ok('쓴 사람이 댓글을 지웠다', c == 200, (c, d))
    c_priv, _ = comment('e1', A, '권한댓글 비공개')
    names = {A: 'A비공개', B: 'B팀', Cp: 'C사내', D: 'D공유', E: 'E공유뺌', Fp: 'F게시', G: 'G숨긴글'}
    cnames = {c_pre: '게시전', c_post: '게시후', c_vis: '보임', c_hid: '숨김', c_del: '지움', c_priv: '비공개댓글'}
    queries = [W, '권한댓글', '게시전댓글', '게시후댓글', 'qfperm', '권한', '조각', '휴가', '회의', '의', '가', '이', 'a', 'e', '1', 'claude']
    total_checked = 0; total_bad = 0
    got = {}
    for w in ('e1', 'e2', 'm1', 'a1', 'g1'):
        for q in queries:
            c, pages, comments, d, raw = search(w, q)
            if c != 200:
                ok('%s 「%s」 200' % (NAME[w], q), False, c); continue
            got[(w, q)] = (pages, comments)
            out = php('perm', NAME[w], inp=json.dumps({'pages': pages, 'comments': comments}))
            m = re.search(r'CHECKED=(\d+)\s+BAD=(\d+)', out)
            total_checked += int(m.group(1)) if m else 0
            b = int(m.group(2)) if m else 1
            total_bad += b
            if b: print('    ✖ %s 「%s」: %s' % (NAME[w], q, out.strip().replace('\n', ' | ')))
    ok('전수 대조 — 계정 5 × 검색어 %d 의 결과 %d건 모두 판정 함수가 허락(어긋남 %d)' % (len(queries), total_checked, total_bad), total_bad == 0 and total_checked > 0, total_bad)
    for w in ('e1', 'e2', 'm1', 'a1', 'g1'):
        pages, comments = got.get((w, W), ([], []))
        exp = [x for x in php('visible_with', NAME[w], inp=W + '\n').split('\n') if x]
        ok('%s 완전성 — 판정 함수가 보이는 「%s」 페이지 전부 = 검색 결과 %s' % (NAME[w], W, sorted(names.get(x, x) for x in pages)),
           sorted(exp) == sorted(pages), {'missing': [names.get(x, x) for x in set(exp) - set(pages)], 'extra': [names.get(x, x) for x in set(pages) - set(exp)]})
        pc = got.get((w, '권한댓글'), ([], []))[1]
        print('    · %s 「권한댓글」 댓글: %s' % (NAME[w], sorted(cnames.get(x, x) for x in pc)))
    e2p, e2c = got.get(('e2', W), ([], []))
    ok('직원2 — 남의 비공개(A)·팀(B, 다른 팀)·공유 뺀(E)·숨긴 게시판 글(G) 0', not ({A, B, E, G} & set(e2p)), [names.get(x) for x in e2p])
    ok('직원2 — 공유받은(D)·사내(C)·게시판에 게시된(F) 은 나온다', {D, Cp, Fp} <= set(e2p), [names.get(x) for x in e2p])
    a1p = got.get(('a1', W), ([], []))[0]
    ok('관리자 — 남의 비공개(A)는 검색으로 안 나온다(특권 갈래 끔)', A not in a1p and G not in a1p, [names.get(x) for x in a1p])
    for w in ('e1', 'e2', 'm1', 'a1', 'g1'):
        allc = set(got.get((w, '권한댓글'), ([], []))[1])
        ok('%s — 숨긴·지운 댓글 0 (쓴 사람·관리자 포함)' % NAME[w], not ({c_hid, c_del} & allc), [cnames.get(x) for x in allc])
    e2c = set(got.get(('e2', '권한댓글'), ([], []))[1])
    ok('직원2(게시판 등급으로만 봄) — 게시 전 댓글 0 · 게시 뒤 댓글은 나온다', c_pre not in e2c and c_post in e2c, [cnames.get(x) for x in e2c])
    e1c = set(got.get(('e1', '권한댓글'), ([], []))[1])
    ok('직원1(주인) — 게시 전·뒤 댓글과 자기 비공개 페이지 댓글이 다 나온다', {c_pre, c_post, c_priv, c_vis} <= e1c, [cnames.get(x) for x in e1c])
    g1p, g1c = got.get(('g1', W), ([], []))
    print('    · Guest 「%s」 페이지: %s' % (W, sorted(names.get(x, x) for x in g1p)))
    ok('Guest — 비공개·팀·공유·숨긴 글 0', not ({A, B, D, E, G} & set(g1p)), [names.get(x) for x in g1p])
    # 조각에 못 보는 페이지의 글이 섞이지 않는다 — 직원2의 모든 결과 조각에 비공개 본문 글자가 없다
    leak = 0
    for w in ('e2', 'm1', 'a1', 'g1'):
        c, d, raw, dt = req(w, 'GET', '/community/api/search?q=' + urllib.parse.quote('비공개 본문'))
        # 응답의 `q`(보낸 검색어 되돌림)는 빼고 결과·조각만 본다
        body = json.dumps({'results': (d or {}).get('results', []), 'comments': (d or {}).get('comments', [])}, ensure_ascii=False)
        if '비공개 본문' in body:
            leak += 1
    ok('남의 비공개 본문 글자가 다른 계정의 결과·조각에 0', leak == 0, leak)

if SECTION in ('all', 't5'):
    print('\n── QF2-T5 저장 — 100KB 본문 한 번 저장(PATCH)의 시간')
    unit = '휴가를 신청합니다 회의록 정산 계약서 결재 올립니다 report 2026 '
    text = ''
    while len(text.encode('utf-8')) < 100 * 1024:
        text += unit
    pid, c = make_page('e1', 'QF2 큰 문서', '처음')
    c, g, _, _ = req('e1', 'GET', f'{C}/{pid}')
    # doc-schema 한도 — 글 조각 하나 2,000자(segment_chars) · 블록 3,000개 · 문서 1MB
    blocks = [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': text[i:i + 2000], 'm': {}}]} for i in range(0, len(text), 2000)]
    c, d, raw, dt = req('e1', 'PATCH', f'{C}/{pid}', {'doc_version': g['page']['doc_version'], 'doc': {'schema': 1, 'blocks': blocks}, 'title': 'QF2 큰 문서'})
    print('    · 본문 %d바이트 PATCH → %s · %.0fms (임시 인스턴스 php -S, 스냅샷·파생 표·색인 포함)' % (len(text.encode()), c, dt))
    ok('100KB 문서 저장 200', c == 200, (c, str(d)[:200]))
    c, pages, _, _, _ = search('e1', '계약서')
    ok('큰 문서가 색인으로 찾힌다', pid in pages)

print('PY_PASS=%d PY_FAIL=%d' % (P, F))
PY

run_driver() {   # $1 절 이름
  QF2_IDS="$WORK/ids.json" py_secret S_E1 S_E2 S_M1 S_A1 S_G1 -- \
    python3 "$WORK/qf2_driver.py" "$SITE" "$UA" "$ROOT/tests/community/fixtures/search_ko.json" "$WORK/qf2_check.php" "$INST/app" "$1" \
    > "$WORK/py_$1.txt" 2>&1
  grep -v '^PY_PASS=' "$WORK/py_$1.txt"
  local line p f
  line=$(grep '^PY_PASS=' "$WORK/py_$1.txt" | tail -1)
  p=$(printf '%s' "$line" | sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\1/p')
  f=$(printf '%s' "$line" | sed -n 's/^PY_PASS=\([0-9]*\) PY_FAIL=\([0-9]*\)$/\2/p')
  if [ -z "$p" ]; then echo "  ✖ 파이썬 몸통($1)이 끝 줄을 내지 못했다"; tail -5 "$WORK/py_$1.txt"; FAIL=$((FAIL+1)); return; fi
  PASS=$((PASS+p)); FAIL=$((FAIL+f))
}
errs_before=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")

hr "QF2-T1 · T7(창구) — 결과 정확성 · 기준선 대조"
run_driver t1
hr "QF2-T7(겹침) — 태그 고치기가 커밋되기 전에 저장이 들어와도 색인이 새 태그를 담는다(검토 L-1)"
# 임시 DB 에서 「태그를 바꾸고 페이지 행을 잠근 채 3초 쥔」 트랜잭션을 흉내 내고(pages_tags_put 과 같은 순서 — 행 잠금 → 태그),
# 그 사이 앱 창구로 같은 페이지를 저장한다. 저장은 태그를 읽기 **전에** 행을 잠그므로 기다렸다가 커밋된 새 태그로 색인을 쓴다.
P13=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["ids"]["d13"])' "$WORK/ids.json" 2>/dev/null)
PID13=$(psqlq "SELECT id FROM community.pages WHERE public_id = '$P13'")
UID1=$(psqlq "SELECT id FROM users WHERE username = 'claude_employee01'")
if [ -n "$PID13" ] && [ -n "$UID1" ]; then
  LOCKSQL=$(chk locksql | sed "s/CAST(:pid AS bigint)/$PID13/")
  expect "  잠금 문장(앱 상수)이 FOR NO KEY UPDATE 다(검토 N-1)" "yes" "$(case "$LOCKSQL" in *'FOR NO KEY UPDATE'*) echo yes ;; *) echo no ;; esac)"
  sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - > "$WORK/l1.txt" 2>&1 <<SQL &
BEGIN;
$LOCKSQL;
INSERT INTO community.page_tags (page_id, tag, tag_norm, created_by) VALUES ($PID13, '겹침꼬리표', '겹침꼬리표', $UID1);
SELECT pg_sleep(3);
COMMIT;
SQL
  L1_PID=$!
  sleep 1
  CSRF1=$(curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
  V13=$(psqlq "SELECT doc_version FROM community.pages WHERE id = $PID13")
  t0=$(date +%s%N)
  st=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" -H "X-CSRF-Token: $CSRF1" \
       -H 'Content-Type: application/json' -X PATCH --data-binary "{\"title\":\"휴 겹침\",\"doc_version\":$V13}" "$CBASE/api/pages/$P13")
  waited=$(( ($(date +%s%N) - t0) / 1000000 ))
  wait "$L1_PID"
  expect "  저장 200 (잠금을 기다렸다 들어감 — ${waited}ms)" "200" "$st"
  expect "  저장이 행 잠금을 기다렸다(1.5초 넘게)" "yes" "$([ "$waited" -ge 1500 ] && echo yes || echo no)"
  hits=$(curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$CBASE/api/search?q=%EA%B2%B9%EC%B9%A8%EA%BC%AC%EB%A6%AC%ED%91%9C" | jget "len([r for r in d['results'] if r['id']=='$P13'])")
  expect "  겹친 뒤 새 태그(겹침꼬리표)로 찾힌다 — 색인이 새 태그를 담았다" "1" "$hits"
  chk vectors > "$WORK/vec_l1.txt" 2>&1
  expect "  겹친 뒤 벡터 = 규칙으로 다시 만든 값(어긋난 페이지 0)" "0" "$(sed -n 's/^PAGES_BAD=//p' "$WORK/vec_l1.txt")"
  # N-1 — 자동 저장이 잠금을 쥔 동안(같은 잠금 문장 · 3초) 같은 페이지에 댓글 쓰기는 기다리지 않는다(외래 키 KEY SHARE 와 안 부딪힘)
  sudo -n -u postgres psql -q -d "$DB" -v ON_ERROR_STOP=1 -f - > "$WORK/n1.txt" 2>&1 <<SQL &
BEGIN;
$LOCKSQL;
SELECT pg_sleep(3);
COMMIT;
SQL
  N1_PID=$!
  sleep 1
  t0=$(date +%s%N)
  st=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" -H "X-CSRF-Token: $CSRF1" \
       -H 'Content-Type: application/json' --data-binary '{"body":"잠금 중 댓글"}' "$CBASE/api/pages/$P13/comments")
  cwait=$(( ($(date +%s%N) - t0) / 1000000 ))
  wait "$N1_PID"
  expect "  (N-1) 잠금 중 같은 페이지 댓글 쓰기 201" "201" "$st"
  expect "  (N-1) 댓글 쓰기가 잠금을 기다리지 않았다(${cwait}ms < 1,000ms)" "yes" "$([ "$cwait" -lt 1000 ] && echo yes || echo no)"
else
  echo "  ✖ 겹침 시험 준비 실패(말뭉치 d13 없음)"; FAIL=$((FAIL+1))
fi

hr "QF2-T3 — 주입"
run_driver t3
errs_after=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄 (T1·T3 동안)" "0" "$((errs_after - errs_before))"
hr "QF2-T2 — 권한 전수 대조"
run_driver t2
hr "QF2-T6 — 화면(헤드리스 · 두 앱) — tests/browser/qf2_search_modal.py"
SHOTS="${QF2_SHOTS:-$WORK/shots}"; mkdir -p "$SHOTS"
FILE_NAME="휴가 계획표 QF2.txt"
printf '휴가 계획표 — 화면 시험용 파일\n' > "$WORK/upload.txt"
CSRF_E1=$(curl -s -b "$JAR_DIR/claude_employee01.jar" -A "$UA" "$BASE/api/me" | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p')
st=$(curl -s -o "$WORK/up.json" -w '%{http_code}' -b "$JAR_DIR/claude_employee01.jar" -A "$UA" -H "X-CSRF-Token: $CSRF_E1" \
     -F "file=@$WORK/upload.txt;filename=$FILE_NAME" -F "access_level=internal" -F "description=QF2 화면 시험" "$BASE/api/files")
expect "직원1이 이름에 「휴가」가 든 파일을 올렸다(파일 묶음 재료)" "201" "$st"
py_secret S_E1 S_G1 -- env PATH="$HOME/.local/bin:$PATH" python3 "$ROOT/tests/browser/qf2_search_modal.py" \
  "$SITE" "$UA" "$WORK/ids.json" "$SHOTS" "$FILE_NAME" > "$WORK/t6.txt" 2>&1; rc=$?
grep -v '^SMOKE_FAIL=' "$WORK/t6.txt" | sed 's/^/  /'
PASS=$((PASS + $(grep -c '^  ✔' "$WORK/t6.txt")))
expect "QF2-T6 화면 시험 종료 코드 · 실패 0" "0|SMOKE_FAIL=0" "$rc|$(grep '^SMOKE_FAIL=' "$WORK/t6.txt")"
note "스크린샷: $SHOTS ($(ls "$SHOTS" | wc -l)장 — 사람이 직접 열어 본다)"

hr "QF2-T5 — 저장(토큰화 시간 · 벡터 크기 · 100KB 저장)"
chk tokbench > "$WORK/tok.txt" 2>&1
sed 's/^/    /' "$WORK/tok.txt"
tokms=$(sed -n 's/^TOK_MS_MEDIAN=//p' "$WORK/tok.txt"); vecb=$(sed -n 's/^VEC_BYTES=//p' "$WORK/tok.txt")
wtokms=$(sed -n 's/^WIDE_TOK_MS_MEDIAN=//p' "$WORK/tok.txt"); wvecb=$(sed -n 's/^WIDE_VEC_BYTES=//p' "$WORK/tok.txt")
expect "100KB 토큰화 중앙값 ≤ 20ms (${tokms}ms)" "yes" "$(awk -v x="${tokms:-999}" 'BEGIN{print (x<=20)?"yes":"no"}')"
expect "100KB 벡터 ≤ 200KB (${vecb}B)" "yes" "$(awk -v x="${vecb:-999999999}" 'BEGIN{print (x<=204800)?"yes":"no"}')"
expect "최악 쪽(서로 다른 음절 100KB) 토큰화 ≤ 20ms (${wtokms}ms)" "yes" "$(awk -v x="${wtokms:-999}" 'BEGIN{print (x<=20)?"yes":"no"}')"
expect "최악 쪽 벡터 ≤ 200KB (${wvecb}B)" "yes" "$(awk -v x="${wvecb:-999999999}" 'BEGIN{print (x<=204800)?"yes":"no"}')"
expect "상한(20,000)에서 잘려도 제목·태그 토큰은 남는다(검토 L-2 — 순서 제목 → 태그 → 본문)" "20000|yes|yes" \
  "$(sed -n 's/^CAP_TOKENS=//p' "$WORK/tok.txt")|$(sed -n 's/^CAP_TITLE_KEPT=//p' "$WORK/tok.txt")|$(sed -n 's/^CAP_TAG_KEPT=//p' "$WORK/tok.txt")"
run_driver t5
errs_end=$(grep -ciE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log")
expect "서버 로그의 새 PHP 오류·SQLSTATE 줄 (전체)" "0" "$((errs_end - errs_before))"
if [ "$((errs_end - errs_before))" != "0" ]; then grep -iE 'PHP (Fatal|Warning|Notice|Deprecated)|SQLSTATE|Uncaught' "$INST/server.log" | tail -5 | sed 's/^/      /'; fi

hr "QF2-T8 — stage5b_search.py(5-B 권한 시험 그대로) · 정적 검사"
py_secret S_E1 S_E2 S_A1 -- python3 "$ROOT/tests/community/stage5b_search.py" "$SITE" "$UA" > "$WORK/s5b.txt" 2>&1; rc=$?
grep -E '✖|SEARCH_FAIL' "$WORK/s5b.txt" | sed 's/^/    /'
expect "stage5b_search.py 종료 코드 · 실패 0 ($(grep -c '✔' "$WORK/s5b.txt") 통과)" "0|SEARCH_FAIL=0" "$rc|$(grep '^SEARCH_FAIL=' "$WORK/s5b.txt")"
bash "$ROOT/scripts/security_lint.sh" "$ROOT" > "$WORK/lint.txt" 2>&1; rc=$?
expect "보안 린트(저장소 전체) 0건" "0" "$rc"
[ "$rc" = "0" ] || grep -A4 '^✖' "$WORK/lint.txt" | head -20 | sed 's/^/    /'
nbad=0
for f in src/SearchText.php src/community/routes.php src/community/handlers/search.php src/community/handlers/pages_update.php \
         src/community/handlers/pages_create.php src/community/handlers/board_pages_create.php src/community/handlers/space_me.php \
         src/community/handlers/version_restore.php src/community/handlers/pages_tags_put.php src/community/handlers/comments_create.php \
         src/community/handlers/comment_update.php src/views/shell_top.php scripts/qf2_backfill_search.php; do
  [ -f "$ROOT/$f" ] || continue
  php -l "$ROOT/$f" > /dev/null 2>&1 || { nbad=$((nbad+1)); echo "    ✖ php -l $f"; }
done
expect "php -l (바뀐 PHP) 실패 0" "0" "$nbad"
# 5-A″ 규칙(커뮤니티 시험들과 같은 검사) — 창구(JSON)는 「이름 (아이디)」를 잇지 않는다(tbox_person_label 은 서버 화면 두 파일만)
expect "창구 코드가 「이름 (아이디)」를 잇지 않는다 — tbox_person_label 은 셸·런처 두 파일만 — 7-D4 멘션 이름 지도(routes.php — 지시서 M-4 · 두 렌더러가 같은 글자)까지 셋뿐" "src/community/routes.php src/views/launcher.php src/views/shell_top.php" \
  "$(cd "$ROOT" && grep -rln 'tbox_person_label(' src | grep -vx 'src/bootstrap.php' | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//')"
# wiki_check 는 트리 형제 자리(../STAGES.md · ../community/STAGES.md — 사이트 문서)를 읽는다. 복제본에서는 그 자리가 비어 있으므로
# 임시 인스턴스 폴더($INST — app 의 형제)에 사이트 문서 자리를 **읽기 전용 링크**로 흉내 낸다(QF2_SITE — 없으면 트리 형제, 그다음 운영 사이트 폴더).
SITE_DOCS="${QF2_SITE:-$(cd "$ROOT/.." && pwd)}"
[ -f "$SITE_DOCS/STAGES.md" ] || SITE_DOCS=/var/www/qaredi
ln -sfn "$SITE_DOCS/STAGES.md" "$INST/STAGES.md"
rm -f "$INST/community"; ln -s "$SITE_DOCS/community" "$INST/community"
note "wiki_check 의 사이트 문서 자리: $SITE_DOCS"
# 옛 커뮤니티 검색 부품(public/assets/community/search.js)을 지웠으므로 커뮤니티 부품 묶음의 버전이 바뀐다 — import 표시가 맞아야 한다
( cd "$ROOT" && php scripts/community_asset_version.php --check ) > "$WORK/assetv.txt" 2>&1; rc=$?
expect "커뮤니티 부품 import 버전 표시가 지금 내용과 맞다(community_asset_version.php --check)" "0" "$rc"
[ "$rc" = "0" ] || sed 's/^/    /' "$WORK/assetv.txt"
( cd "$INST/app" && php scripts/wiki_check.php ) > "$WORK/wiki.txt" 2>&1; rc=$?
expect "wiki_check 0건" "0" "$rc"
[ "$rc" = "0" ] || tail -5 "$WORK/wiki.txt" | sed 's/^/    /'
fi   # REGRESS_ONLY

# ════════════════════════════════════════════════════════════════
if [ "${REGRESS:-0}" = "1" ] || [ "${REGRESS_ONLY:-0}" = "1" ]; then
  hr "회귀 — 같은 임시 인스턴스에서(stage7d1.sh 의 회귀 틀 그대로)"
  note "각 스크립트를 임시 인스턴스 폴더로 복사해 SITE·DB 만 바꿔 돈다(운영 8082·tbox_re 에 닿지 않는다)."
  sed -i "s#http://127.0.0.1:8082#$SITE#g" "$INST/app/scripts/ui_snapshot.sh"
  if grep -v '^\s*#' "$INST/app/scripts/ui_snapshot.sh" | grep -q '8082'; then
    echo "  ✖ ui_snapshot.sh 에 운영 주소가 남았다 — 회귀를 돌리지 않는다"; FAIL=$((FAIL+1)); REGRESS_LIST=" "
  fi
  # 커뮤니티 4d~6c 는 스스로 임시 DB·임시 인스턴스를 만드는 시험이라(운영 DB 를 SRC_DB 로 **읽는** 줄이 있다) 이 틀의
  # 「운영 주소·DB 가 남아 있다」 거르개에 걸린다 — 그 시험들은 따로 돌린다(구현 기록 6장 — 고치기 전 트리와 같은 사본으로 견줌).
  for s in ${REGRESS_LIST:-community/stage3 community/stage4a community/stage4b community/stage4c stage26 help_button q_launcher}; do
    src="$INST/app/tests/$s.sh"
    dst="$INST/app/tests/$s.temp.sh"
    [ -f "$src" ] || { echo "  ✖ $s — 스크립트가 없다"; FAIL=$((FAIL+1)); continue; }
    sed -e "s#http://127.0.0.1:8082#$SITE#g" -e "s#^DB=\"tbox_re\"#DB=\"$DB\"#" -e "s#-d tbox_re #-d $DB #g" "$src" > "$dst"
    if grep -v '^\s*#' "$dst" | grep -q '8082\|DB="tbox_re"\|-d tbox_re'; then
      echo "  ✖ $s — 운영 주소·DB 가 남아 있어 돌리지 않는다"; FAIL=$((FAIL+1)); continue
    fi
    echo "  ▸ $s (시작 $(date +%T))"
    bash "$dst" > "$WORK/regress_$(basename "$s").txt" 2>&1
    rc=$?
    line=$(grep -E '통과 [0-9]+|PASS|통과:|결과' "$WORK/regress_$(basename "$s").txt" | tail -1)
    echo "    종료 코드 $rc · ${line:-결과 줄 없음}"
    grep -E '^\s*(✖|FAIL)' "$WORK/regress_$(basename "$s").txt" | head -12 | sed 's/^/      /'
    [ -n "${REGRESS_KEEP:-}" ] && cp "$WORK/regress_$(basename "$s").txt" "$REGRESS_KEEP/"
    [ "$rc" = "0" ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
  done
fi

hr "결과"
printf '통과 %d · 실패 %d · 건너뜀 %d\n' "$PASS" "$FAIL" "$SKIP"
note "임시 DB($DB)·임시 폴더·임시 서버는 이 스크립트가 끝나며 지워진다. 운영에는 아무것도 남지 않는다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
