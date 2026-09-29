#!/usr/bin/env bash
# review-scripts/apply-qf10-db-2026-09-29.sh — Q:F-10 「토큰 하나에 범위 여럿」의 **DB 쪽**:
#   api_tokens.scope CHECK 다섯 값 → 원자 집합(`string_to_array(scope,'+') <@ ARRAY[원자 여섯]`) · bound_ip CHECK · 부분 유일 인덱스를
#   「session.open 원자가 든」 기준으로 · 옛 `community.write` 한 원자 행 → `community.read+community.write`. 열·표·권한 변경 0.
#   ★ 사용자 승인 뒤 실행한다 — 운영 DB 구조 변경(+ 행 몇 개 UPDATE)이다. root(sudo)로.
#      검토 동안은 복제본 review-scripts/(브랜치 wip/qf10), 반영 뒤 Qaredi scripts/ 로 옮긴다.
#   근거: 지시서 docs/qf10-multi-scope-directive.md 조각 S-2 · 「반영 순서」 · 마이그레이션 db/migrations/2026-10-13_api_tokens_multi_scope.sql
#         (한 트랜잭션 · lock_timeout 5s · 앞 검사(모르는 값이면 RAISE) · 멱등 · 되돌리기 절) · ARCHITECTURE 4.10 · SECURITY R-114 ·
#         검토 통과판 wip/qf10(지금 고정 값 = review-base/qf10 6b55bd7 — 검토 반영 커밋이 생기면 pins-qf10-repo.sh 로 다시 찍는다) ·
#         기준 운영 판 b2ee066(v2.38.0)
#   본보기: apply-qf9-db-2026-09-29.sh — sha256 · 문장 목록 고정 · 0700 사본만 해시·실행 · SQL 은 표준입력(`psql -c` 0) · 사용 중이면 미룸 ·
#     백업 · 확인이 틀리면 되돌림. 다른 점: ① **순서가 반대 — 코드 → 이 마이그레이션.** 시작할 때 운영 트리의 코드가 새 판인지 먼저 본다
#     (CODE_PIN 의 파일 sha + `Authz::TOKEN_SCOPE_ATOMS` 상수 있음 · 옛 `TOKEN_SCOPES` 상수 없음) — 아니면 「코드 먼저」로 3 종료.
#     ② 상태를 표지 셋(scope CHECK · bound_ip CHECK · 부분 유일 인덱스의 원문)으로 가른다 ③ 행을 바꾼다(community.write → 두 원자) —
#     반영 전 범위별 행 수로 반영 뒤 모양을 **미리 계산**해 두고 그대로인지 본다.
#   순서(반드시): ① apply-qf10-repo-2026-09-29.sh(코드 — DB 가 반영 전이어도 돈다) → 커밋·발행 → ② 이 스크립트 --migrate <MIG>(바로 이어서).
#     ★ 까닭: 새 코드는 옛 다섯 값을 그대로 읽는다(`community.write` 한 원자도 읽기로 인정). 옛 코드는 `community.read+community.write` 를
#       모르는 값으로 보아 **커뮤니티 글쓰기 토큰이 403** 이 된다 — 그러니 마이그레이션이 먼저 나가면 안 된다.
#     ① 과 ② 사이에 막히는 것: 묶음 발급 · `community.write` 발급(정규형이 옛 CHECK 에 걸려 500) — 그래서 ② 를 바로 잇는다.
#   모드
#     --dry-run MIG   쓰지 않는다. 사전 확인(코드 먼저 포함) · 지금 상태 · 반영 뒤 예상 행 모양 · 보낼 문장.
#     --migrate MIG   사용 중 확인 → 백업(api_tokens **구조만** pg_dump -s + 지금 제약·인덱스 정의·열 주석 + 반영 전 scope 별 행 수 · root 0600 —
#                     토큰 해시·행은 뜨지 않는다) → 마이그레이션(postgres) → 확인: scope CHECK 원자 집합 · bound_ip CHECK · 부분 유일 인덱스(원문) ·
#                     `community.write` 한 원자 행 0 · 행 수 그대로 · 범위별 수 = 예상 · 모르는 원자 0 · session.open ⇔ IP 어긋남 0 ·
#                     api_tokens 권한 그대로 · 사이트 200 둘. 확인이 틀리면 되돌림 SQL(옛 다섯 값 + 두 원자 밖의 행이 0 일 때만).
#   이미 반영된 상태(표지 셋 전부 새 판)면 「이미 적용」 으로 0 종료. 반영 전도 뒤도 아닌 섞인 상태면 1(누가 바꿨다 — 사람이 본다).
#   되돌리기(손으로): 아래 REVERT_SQL 을 표준입력으로(`sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d <DB> -f - <<< "$REVERT_SQL"` 꼴).
#     · ★ **새 원자가 든 행**(묶음 토큰 `read+upload+community.read+…` · `community.read` 한 원자 · `read+session.open` 등 — 옛 다섯 값 밖)이
#       하나라도 있으면 옛 CHECK 를 다시 거는 ADD CONSTRAINT(bound_ip · scope)가 그 행에 걸려 **실패한다**(한 트랜잭션이라 바뀐 것 없음). 회수된 행도 CHECK 를
#       받는다 — 그 행을 지울지는 **사용자 판단**이다. 이 스크립트는 행을 지우지 않는다.
#     · `community.read+community.write` 행은 되돌림 UPDATE 가 `community.write` 로 돌린다(마이그레이션 ④ 의 거꾸로).
#     · 순서: 반영의 거꾸로(**DB → 코드**)가 안전하다 — 새 코드는 옛 값을 읽지만 옛 코드는 새 값을 모른다. 코드만 되돌리고 DB 를 두면
#       `community.read+community.write` 토큰이 403 이다(연습 ㉑ 실측). 마이그레이션 머리 「되돌리기」 도 같은 순서(DB → 코드)로 맞췄다
#       (검토 RV-QF10 ② 반영 · 주석만). 둘 사이를 짧게 하면 어느 쪽도 데이터는 잃지 않는다.
#   종료 코드: 0 성공(또는 이미 적용됨 · dry-run) · 1 사전 확인 실패(아무것도 바꾸지 않음) · 3 순서 위반(코드가 새 판이 아니다 — 바꾼 것 없음) ·
#     4 적용 뒤 확인 실패(되돌림)
#   확인 요청은 운영 감사에 행을 남기지 않는다 — HTTP 는 런처 `/` · 로그인 화면 `/tbox/login` 둘만.
#   연습 결과: (저장소 · DB 함께 — 1 → 2 순서 · 2026-09-29 06:2x~06:4x · claude(구현자 Opus))
#     (이 고정 값 그대로(6b55bd7) · 경로·DB 이름·포트만 바꾼 사본(DB 스크립트 3줄: APP · L8082 · BKROOT /
#      저장소 스크립트 5줄: APP · L8082 · DB · APPLOG · RUNAS) · 모의 운영 트리 = `git worktree add --detach <임시> b2ee066`(+ 임시 .env —
#      운영 .env 를 읽어 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 만 임시로) · 모의 DB tbox_qf10_reh(postgres 가 tbox_re 를 pg_dump 로 **읽어** 부음 —
#      이 문맥의 tbox_re 는 scope 세 값(Q:F-8 DB 전)이라 운영 v2.38.0 과 맞추려고 모의 DB 에만 2026-10-11 · 2026-10-12 를 먼저 적용 ·
#      토큰 행이 0 이라 UPDATE 갈래를 보려고 모의 DB 에만 claude_ 계정 토큰 다섯 행(read · community.write ×2(하나는 회수) ·
#      read+upload+delete · session.open(IP 묶음))을 넣음) · 모의 인스턴스 php -S 127.0.0.1:8471(트리 밖 라우터) ·
#      재료 = 마이그레이션 `git archive review-base/qf10` sha 568d0a8d… / 패치 `git diff b2ee066 review-base/qf10 -- . ':!review-scripts'` sha 4d366845… —
#      운영 트리·8082·tbox_re 쓰기 0(tbox_re scope CHECK 세 값 그대로 · /var/www/qaredi/tbox-v2 status 0줄) · 끝에 worktree·모의 DB·임시 폴더 지움):
#     ① DB --dry-run(코드 옛 판) → 3 「코드 먼저」(CODE_PIN 다섯 파일 다름 · 원자 상수 0 · 옛 상수 2) · ② DB 인자 셋·모르는 모드·빈 인자 → 1·1·1 ·
#     ③ DB 마이그레이션 sha 다름 → 1 · ④ 저장소 sha 틀림·모르는 인자·빈 인자 → 1·1·1 ·
#     ⑤ 저장소 --dry-run(DB 반영 전) → 0(「정상 순서」 · 고칠 파일 32 = 기준 판 · 토큰 창구 표 3 · t-box 라우트 101줄 · 갈래 3 7 1 1 2 2 1 5 0 15 · 트리 변경 0줄) ·
#     ⑥ CODE_WANT 의 src/Authz.php 를 깨뜨린 사본 본 실행 → 확인 실패 → 스스로 되돌림 4(고친 파일 = 기준 판 · 새 파일 5개 없음 · 라우트 기준 판 · 트리 변경 0줄) ·
#     ⑦ 사용 중(모의 DB 에 10분 안 사람 감사 행 하나) 본 실행 → 3 「미룬다」(트리 변경 0줄) ·
#     ⑧ 저장소 본 실행 → 0(확인 19 ✔ · ✖ 0 · 37 파일 = 6b55bd7 sha · 토큰 창구 표 여섯 · HTTP 200·200·401·401·302) · ⑨ 다시 → 2(커밋 안 된 변경) ·
#     ⑩ 그 사이(새 코드 + 옛 DB): community.write 토큰 whoami 200 · 커뮤니티 검색 200 · read 토큰 검색 403 · 틀린 토큰 401 ·
#     ⑪ DB --dry-run → 0(코드 먼저 통과 · 반영 뒤 예상 행 모양 표시) ·
#     ⑫ NEW_INDEX 기대값을 깨뜨린 DB 사본 --migrate → 확인 실패 → 스스로 되돌림 4(「되돌린 뒤 반영 전 · 범위별 행 수 = 반영 전」 · 열 주석도 반영 전 글자) —
#        첫 시도에서 행 모양 함수가 「범위 가짓수」를 전체 수로 적는 잘못이 드러나 sum(n) 으로 고친 뒤 다시 돌려 기대대로(qf9 DB 스크립트의 rows() 도 같은 모양) ·
#     ⑬ 사용 중 --migrate → 1 「미룬다」 · ⑭ DB --migrate → 0(확인 11 ✔ · 백업 두 파일 0600 root · community.write 2 → community.read+community.write 2) ·
#     ⑮ 다시 → 「이미 적용」 0 · ⑯ 반영 뒤: 옛 community.write 토큰 whoami 200(token_scope community.read+community.write) · 검색 200 ·
#        묶음 토큰(read+upload+community.read+session.open · 127.0.0.1 묶음) 검색 200 · 같은 묶음을 10.0.0.9 에 묶은 토큰 검색·whoami 401 token_ip_mismatch · read 토큰 검색 403 ·
#     ⑰ 손 되돌리기(REVERT_SQL) — 묶음 행이 있을 때 → rc 3(`api_tokens_bound_ip_check … is violated` · 한 트랜잭션 — 행 모양·표지 반영 뒤 그대로) ·
#     ⑱ 모의 DB 에서만 묶음 두 행을 지운 뒤 손 되돌리기 → rc 0 · dry-run 「반영 전」 · community.write 2 로 돌아옴 ·
#     ⑲ 손으로 `git apply -R` → rc 0 · 트리 변경 0줄 · ⑳ 섞인 DB(scope CHECK 만 새 판) → 저장소 dry-run 3 · DB dry-run 1(코드는 잠깐 새 판으로 두고 봄) ·
#     ㉑ 거꾸로(DB 를 손으로 먼저) 옛 코드 + 새 DB → community.write 였던 토큰의 whoami·내 공간 **403**(순서 근거 실측) ·
#     ㉒ 그 상태에서 저장소 dry-run → 0(「DB 가 먼저 — 새 코드는 둘 다 읽는다 · 계속」) · ㉓ 본 실행 → 0 · 내 공간 200 · ㉔ 손 `git apply -R` → rc 0 · 트리 변경 0줄 ·
#     모의 서버 로그 PHP 오류·uncaught 0. 모두 기대대로.
#     연습하지 않은 것: 실제 nginx·php-fpm(opcache) 경로 · changkwon 계정으로의 실행(사본은 RUNAS=root) · 운영 app.log(사본은 모의 서버 로그).
set -euo pipefail
# ★ pipefail 아래에서 `… | grep -q` · `… | head` · `… | awk '…exit'` 를 쓰지 않는다(읽는 쪽이 먼저 끝나면 SIGPIPE 로 파이프가 「실패」)
USAGE="사용: sudo bash $0 --dry-run <MIG> | --migrate <MIG>"
[ "$#" -eq 2 ] || { echo "$USAGE"; exit 1; }
for a in "$@"; do [ -n "$a" ] || { echo "$USAGE — 빈 인자는 받지 않는다"; exit 1; }; done
case "$1" in --dry-run) MODE=dry ;; --migrate) MODE=migrate ;; *) echo "$USAGE — 모르는 인자: '${1}'"; exit 1 ;; esac
MIG_ARG="$2"
[ "$(id -u)" -eq 0 ] || { echo "root 로 실행하세요(sudo)"; exit 1; }
APP=/var/www/qaredi/tbox-v2
L8082=http://127.0.0.1:8082
BKROOT=/var/backups/tbox-v2
UA="tbox-review/$(date +%F)"
# @@CODEPIN-BEGIN@@
# 「코드 먼저」 표지 — 운영 트리의 이 파일들이 검토 통과판(6cd762b)이어야 마이그레이션을 보낸다(pins-qf10-repo.sh 가 찍음)
MIG_WANT=4c1212238dbb590aed5e98f95d913f1a17f08530d3b81b61b2449ed4951e08cf
CODE_PIN="src/Auth.php=23148907579ad89ec1004c68aed4246a5686db6624895b83df6433116822c6e6
src/Authz.php=faf6e1d9a6db5cddc08b7da7e09f9a97b0b0ee112b23367f3e34651111558981
src/community/routes.php=0a960e10cf5484dd868811db7d152eab5b8e7be35677f3884bff3092a4989825
src/handlers/me_tokens_issue.php=133529c27095c858f4329b02ff4d58ea40888c86922ef0904873ebc44f33b1af
db/migrations/2026-10-13_api_tokens_multi_scope.sql=4c1212238dbb590aed5e98f95d913f1a17f08530d3b81b61b2449ed4951e08cf"
# @@CODEPIN-END@@
ATOMS_CONST="public const TOKEN_SCOPE_ATOMS = ['read', 'upload', 'delete', 'community.read', 'community.write', 'session.open'];"
# 마이그레이션의 주석·빈 줄을 뺀 문장 — **글자 그대로 이 목록**이어야 한다
STMTS_WANT=$(cat <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
DO $$
DECLARE
    n_bad bigint;
BEGIN
    SELECT count(*) INTO n_bad
      FROM public.api_tokens
     WHERE scope NOT IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open')
       AND NOT (scope <> ''
                AND string_to_array(scope, '+')
                    <@ ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]);
    IF n_bad > 0 THEN
        RAISE EXCEPTION 'api_tokens.scope 에 모르는 값이 % 행 있다 — 마이그레이션을 멈춘다(바뀐 것 없음)', n_bad;
    END IF;
END
$$;
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope <> '' AND string_to_array(scope, '+')
           <@ ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]);
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
ALTER TABLE public.api_tokens
    ADD CONSTRAINT api_tokens_bound_ip_check
    CHECK (('session.open' = ANY (string_to_array(scope, '+'))) = (bound_ip IS NOT NULL));
DROP INDEX IF EXISTS public.api_tokens_one_session_open;
CREATE UNIQUE INDEX api_tokens_one_session_open
    ON public.api_tokens (user_id)
    WHERE 'session.open' = ANY (string_to_array(scope, '+')) AND revoked_at IS NULL;
UPDATE public.api_tokens SET scope = 'community.read+community.write' WHERE scope = 'community.write';
COMMENT ON COLUMN public.api_tokens.scope IS
  'Q:F-10: 토큰의 범위 — 원자 집합을 정규화 순서(read · upload · delete · community.read · community.write · session.open)로 + 로 이은 문자열(Authz::normalizeScope). CHECK 는 원자 포함 여부만 — 순서·의존(upload 는 read · delete 는 read+upload)은 앱이 본다. 창구를 좁힐 뿐이고 행 단위 판정은 요청마다 Authz 가 한다 (ARCH 4.7 · 4.10)';
COMMENT ON COLUMN public.api_tokens.bound_ip IS
  'Q:F-9 · Q:F-10: session.open 원자가 든 토큰이 묶인 공인 IP — 발급 때 서버가 본 Http::clientIp() 값(요청 값이 아니다). 그 토큰의 모든 창구가 이 IP 에서만 통한다. 다른 토큰은 NULL (ARCH 4.9 · 4.10)';
COMMIT;
SQL
)
# 반영 전·뒤 표지(pg_get_constraintdef · pg_indexes 원문 — 임시 DB 에 2026-10-11 · 2026-10-12 · 2026-10-13 을 실제로 적용해 뽑은 글자)
OLD_SCOPE="CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text, 'session.open'::text])))"
OLD_BOUND="CHECK (((scope = 'session.open'::text) = (bound_ip IS NOT NULL)))"
OLD_INDEX="CREATE UNIQUE INDEX api_tokens_one_session_open ON public.api_tokens USING btree (user_id) WHERE ((scope = 'session.open'::text) AND (revoked_at IS NULL))"
NEW_SCOPE="CHECK (((scope <> ''::text) AND (string_to_array(scope, '+'::text) <@ ARRAY['read'::text, 'upload'::text, 'delete'::text, 'community.read'::text, 'community.write'::text, 'session.open'::text])))"
NEW_BOUND="CHECK ((('session.open'::text = ANY (string_to_array(scope, '+'::text))) = (bound_ip IS NOT NULL)))"
NEW_INDEX="CREATE UNIQUE INDEX api_tokens_one_session_open ON public.api_tokens USING btree (user_id) WHERE (('session.open'::text = ANY (string_to_array(scope, '+'::text))) AND (revoked_at IS NULL))"
# 되돌림 — 마이그레이션의 「되돌리기」 절 + 열 주석을 Q:F-9 글자로. 옛 다섯 값 밖의 행(새 원자)이 있으면 옛 CHECK ADD 가 실패(트랜잭션 — 바뀐 것 없음)
REVERT_SQL=$(cat <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
UPDATE public.api_tokens SET scope = 'community.write' WHERE scope = 'community.read+community.write';
DROP INDEX IF EXISTS public.api_tokens_one_session_open;
CREATE UNIQUE INDEX api_tokens_one_session_open ON public.api_tokens (user_id) WHERE scope = 'session.open' AND revoked_at IS NULL;
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_bound_ip_check;
ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_bound_ip_check CHECK ((scope = 'session.open') = (bound_ip IS NOT NULL));
ALTER TABLE public.api_tokens DROP CONSTRAINT IF EXISTS api_tokens_scope_check;
ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open'));
COMMENT ON COLUMN public.api_tokens.scope IS
  'F-66 · Q:F-8 · Q:F-9: 토큰의 범위 — read · read+upload · read+upload+delete(파일 창구 사다리) · community.write(커뮤니티 창구 셋 — 독립 값) · session.open(이 PC 로그인 전용 — 링크 창구 하나 · 독립 값). 창구를 좁힐 뿐이고 행 단위 판정은 요청마다 Authz 가 한다 (ARCH 4.7 · 4.8 · 4.9)';
COMMENT ON COLUMN public.api_tokens.bound_ip IS
  'Q:F-9: 범위 session.open 토큰이 묶인 공인 IP — 발급 때 서버가 본 Http::clientIp() 값(요청 값이 아니다). 다른 범위는 NULL (ARCH 4.9)';
COMMIT;
SQL
)
ATOMS_SQL="ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]"

DBRAW=$(sed -n 's/^DB_NAME=//p' "$APP/.env" || true)
DBNAME=${DBRAW%%$'\n'*}
case "$DBNAME" in \"*\") DBNAME=${DBNAME#\"}; DBNAME=${DBNAME%\"} ;; \'*\') DBNAME=${DBNAME#\'}; DBNAME=${DBNAME%\'} ;; esac
[[ "$DBNAME" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { echo "   ✖ .env 의 DB_NAME 을 읽지 못했거나 모양이 아니다"; exit 1; }
PSQL=(sudo -u postgres psql -X -q -t -A -v ON_ERROR_STOP=1 -d "$DBNAME")
q() { ( cd / && "${PSQL[@]}" <<< "$1" ) 2>/dev/null || echo "(query-failed)"; }
code() { curl -s -o /dev/null --max-time 10 -w '%{http_code}' -A "$UA" "$@" || true; }
same() { [ -n "$2" ] && [ "$1" = "$2" ] && [[ "$2" != *query-failed* ]] && [ "$2" != "(none)" ]; }
fsha() { local g; g=$(sha256sum "$APP/$1" 2>/dev/null | cut -d' ' -f1 || true); printf '%s' "${g:-(none)}"; }
cdef() { q "SELECT coalesce(string_agg(pg_get_constraintdef(oid), ' | ' ORDER BY conname), '(none)') FROM pg_constraint WHERE conrelid = 'public.api_tokens'::regclass AND conname = '$1'"; }
idxdef() { q "SELECT coalesce(string_agg(indexdef, ' | '), '(none)') FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'api_tokens_one_session_open'"; }
# 행 모양 = 전체 수 + 범위별 수(글자 순)
rows() { q "SELECT coalesce(sum(n), 0) || ' ' || coalesce(string_agg(scope || '=' || n, ',' ORDER BY scope), '') FROM (SELECT scope, count(*) AS n FROM api_tokens GROUP BY scope) s"; }
# 반영 뒤 예상 = 지금 행에 마이그레이션 ④ 를 적용한 모양(글자 순은 같은 ORDER BY)
rows_after() { q "SELECT coalesce(sum(n), 0) || ' ' || coalesce(string_agg(scope || '=' || n, ',' ORDER BY scope), '') FROM (SELECT CASE WHEN scope = 'community.write' THEN 'community.read+community.write' ELSE scope END AS scope, count(*) AS n FROM api_tokens GROUP BY 1) s"; }
priv() { q "SELECT coalesce(string_agg(grantee || ':' || privilege_type, ',' ORDER BY grantee, privilege_type), '(none)') FROM information_schema.role_table_grants WHERE table_schema = 'public' AND table_name = 'api_tokens'"; }
colcomment() { q "SELECT coalesce(col_description('public.api_tokens'::regclass, attnum), '(null)') FROM pg_attribute WHERE attrelid = 'public.api_tokens'::regclass AND attname = '$1'"; }
state_of() {   # old · new · unknown
  local s b i; s=$(cdef api_tokens_scope_check); b=$(cdef api_tokens_bound_ip_check); i=$(idxdef)
  if same "$OLD_SCOPE" "$s" && same "$OLD_BOUND" "$b" && same "$OLD_INDEX" "$i"; then echo old
  elif same "$NEW_SCOPE" "$s" && same "$NEW_BOUND" "$b" && same "$NEW_INDEX" "$i"; then echo new
  else echo unknown; fi
}

echo "== 사전 확인 (DB $DBNAME · 모드 $MODE)"
[ -f "$MIG_ARG" ] && [ ! -L "$MIG_ARG" ] || { echo "   ✖ 파일 없음(또는 링크): $MIG_ARG — git archive 로 뽑은 마이그레이션을 인자로 주세요"; exit 1; }
WORKD=$(mktemp -d)
trap 'rm -rf "$WORKD"' EXIT
chmod 700 "$WORKD"
MIG="$WORKD/migration.sql"
cp -- "$MIG_ARG" "$MIG"
chmod 400 "$MIG"
GOT=$(sha256sum "$MIG" | cut -d' ' -f1)
same "$MIG_WANT" "$GOT" || { echo "   ✖ 마이그레이션이 검토 통과 판과 다르다(${GOT:0:16} ≠ ${MIG_WANT:0:16})"; exit 1; }
STMTS=$(grep -vE '^[[:space:]]*(--|$)' "$MIG" || true)
same "$STMTS_WANT" "$STMTS" || { echo "   ✖ 마이그레이션 문장이 고정 목록과 다르다 — 멈춘다"; diff <(printf '%s\n' "$STMTS_WANT") <(printf '%s\n' "$STMTS") || true; exit 1; }
echo "   · 마이그레이션 사본 = 검토 통과 판(${GOT:0:16}) · 문장 $(printf '%s\n' "$STMTS" | wc -l | tr -d ' ')줄이 고정 목록과 같다"
# ① 코드 먼저 — apply-qf10-repo-2026-09-29.sh 가 끝났나(파일 읽기만)
CBAD=0
while IFS='=' read -r rel want; do
  [ -n "$rel" ] || continue
  got=$(fsha "$rel")
  same "$want" "$got" || { echo "   ✖ 운영 트리 $rel 이 검토 통과판이 아니다(기대 ${want:0:16} · 실제 ${got:0:16})"; CBAD=1; }
done <<< "$CODE_PIN"
NATOM=$( (grep -cF -- "$ATOMS_CONST" "$APP/src/Authz.php" 2>/dev/null || true) | tr -d ' ')
NOLD=$( (grep -cE 'public const (FILE_)?TOKEN_SCOPES = ' "$APP/src/Authz.php" 2>/dev/null || true) | tr -d ' ')
[ "${NATOM:-0}" = "1" ] && [ "${NOLD:-0}" = "0" ] || { echo "   ✖ 운영 트리 Authz 에 원자 표 상수가 없거나(${NATOM:-0}) 옛 범위 상수가 남았다(${NOLD:-0})"; CBAD=1; }
if [ "$CBAD" != "0" ]; then
  echo "   ✖ 코드 먼저 — 운영 트리가 Q:F-10 새 판이 아니다. apply-qf10-repo-2026-09-29.sh 를 먼저(옛 코드는 community.read+community.write 를 몰라 커뮤니티 글쓰기 토큰이 403). 바꾼 것 없음"
  exit 3
fi
echo "   · 코드 먼저: 운영 트리 $(printf '%s\n' "$CODE_PIN" | grep -c '=') 파일 = 검토 통과판 · TOKEN_SCOPE_ATOMS 있음 · 옛 범위 상수 없음"
h=$(q "SELECT (to_regclass('public.api_tokens') IS NOT NULL)::int")
same "1" "$h" || { echo "   ✖ public.api_tokens 가 없다(또는 읽지 못했다 — $h)"; exit 1; }
STATE=$(state_of)
S0=$(cdef api_tokens_scope_check); R0=$(rows); RP=$(rows_after); P0=$(priv)
[ "$STATE" != "unknown" ] || { echo "   ✖ 반영 전도 반영 뒤도 아니다(scope ${S0:0:90} · 인덱스 $(idxdef | cut -c1-60)) — 누가 바꿨다. 멈춘다"; exit 1; }
NCW=$(q "SELECT count(*) FROM api_tokens WHERE scope = 'community.write'")
echo "   · 지금 = $( [ "$STATE" = old ] && echo '반영 전(scope 다섯 값 · 한 값 비교 bound_ip·인덱스)' || echo '반영 뒤(이미 적용됨)') · 토큰 행 $R0 · community.write 한 원자 $NCW"
[ "$STATE" = old ] && echo "   · 반영 뒤 예상 행 모양: $RP"
echo "   사이트: 런처 $(code "$L8082/") · 로그인 $(code "$L8082/tbox/login")"

if [ "$MODE" = "dry" ]; then
  echo "   · 보낼 문장(마이그레이션 — 한 트랜잭션 · lock_timeout 5s):"; printf '%s\n' "$STMTS" | sed 's/^/       /'
  echo "== [dry] 여기까지 — 바꾼 것 없음. 다음: --migrate <MIG>"
  exit 0
fi
if [ "$STATE" = "new" ]; then echo "== 이미 적용됨 — 바꾸지 않는다"; exit 0; fi
BUSY=$(q "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'")
echo "   최근 10분 검토 계정이 아닌 사람의 감사 행: $BUSY (0 이어야 — 토큰을 쓰는 사람 밑에서 api_tokens 에 잠금을 잡지 않는다)"
same "0" "$BUSY" || { echo "   ✖ 누군가 사이트를 쓰는 중이거나 읽지 못했다 — 미룬다"; exit 1; }

echo "== 1. 백업 (api_tokens **구조만** + 지금 제약·인덱스·열 주석 + 범위별 행 수 — 토큰 해시·행은 뜨지 않는다 · root 0600)"
BK="$BKROOT/qf10-$(date +%Y%m%d-%H%M%S)"
( umask 077; mkdir -p "$BK" )
if ! ( cd / && sudo -u postgres pg_dump -s -t public.api_tokens "$DBNAME" ) > "$BK/schema-before-qf10.sql"; then
  echo "   ✖ 백업 실패 — 바뀐 것 없음. 멈춘다"; exit 1
fi
{ echo "api_tokens_scope_check = $S0"
  echo "api_tokens_bound_ip_check = $(cdef api_tokens_bound_ip_check)"
  echo "api_tokens_one_session_open = $(idxdef)"
  echo "constraints(api_tokens) ="
  q "SELECT conname || ' = ' || pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.api_tokens'::regclass ORDER BY 1"
  echo "indexes(api_tokens) ="
  q "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'api_tokens' ORDER BY 1"
  echo "comment(scope) = $(colcomment scope)"
  echo "comment(bound_ip) = $(colcomment bound_ip)"
  echo "grants(api_tokens) = $P0"
  echo "api_tokens rows(반영 전 · 범위별) = $R0"
  echo "api_tokens rows(반영 뒤 예상) = $RP"; } > "$BK/state-before-qf10.txt"
chmod 600 "$BK/schema-before-qf10.sql" "$BK/state-before-qf10.txt"
BKN=$(wc -c < "$BK/schema-before-qf10.sql" | tr -d ' ')
[ "${BKN:-0}" -gt 400 ] || { echo "   ✖ 백업이 너무 작다(${BKN}바이트) — 멈춘다"; exit 1; }
BKC=$( (grep -c "api_tokens_scope_check" "$BK/schema-before-qf10.sql" || true) | tr -d ' ')
BKT=$( (grep -c "CREATE TABLE public.api_tokens" "$BK/schema-before-qf10.sql" || true) | tr -d ' ')
BKI=$( (grep -c "api_tokens_one_session_open" "$BK/schema-before-qf10.sql" || true) | tr -d ' ')
[ "${BKC:-0}" -ge 1 ] && [ "${BKT:-0}" -ge 1 ] && [ "${BKI:-0}" -ge 1 ] || { echo "   ✖ 백업에 api_tokens 표·제약·인덱스 정의가 없다 — 멈춘다"; exit 1; }
echo "   · $BKN 바이트 → $BK/schema-before-qf10.sql · 제약·인덱스·주석·범위별 행 수 → $BK/state-before-qf10.txt"

echo "== 2. 마이그레이션 (postgres · 한 트랜잭션 · lock_timeout 5s 는 파일 안)"
if ! ( cd / && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DBNAME" -f - ) < "$MIG"; then
  echo "   ✖ 마이그레이션 실패(잠금 대기 5초를 넘겼거나 앞 검사(모르는 값) · SQL 오류) — 한 트랜잭션이라 바뀐 것 없음 · 상태 $(state_of). 잠시 뒤 다시 돌리면 된다"
  exit 1
fi

echo "== 3. 확인"
ok=1
chk() { if same "$2" "$3"; then echo "   ✔ $1 (${3:0:60})"; else echo "   ✖ $1 (기대 ${2:0:60} · 실제 ${3:0:60})"; ok=0; fi; }
chk "scope CHECK = 원자 집합(string_to_array … <@ ARRAY[원자 여섯])" "$NEW_SCOPE" "$(cdef api_tokens_scope_check)"
chk "bound_ip CHECK(session.open 원자 ⇔ IP 있음)" "$NEW_BOUND" "$(cdef api_tokens_bound_ip_check)"
chk "부분 유일 인덱스(session.open 원자 · 한 사람 1대)" "$NEW_INDEX" "$(idxdef)"
chk "community.write 한 원자 행 0" "0" "$(q "SELECT count(*) FROM api_tokens WHERE scope = 'community.write'")"
chk "행 수 그대로 · 범위별 수 = 예상(community.write → community.read+community.write)" "$RP" "$(rows)"
chk "행 수 그대로(반영 전 ${R0%% *})" "${R0%% *}" "$(q "SELECT count(*) FROM api_tokens")"
chk "모르는 원자 행 0" "0" "$(q "SELECT count(*) FROM api_tokens WHERE NOT (scope <> '' AND string_to_array(scope, '+') <@ $ATOMS_SQL)")"
chk "session.open 원자 ⇔ bound_ip 어긋남 0" "0" "$(q "SELECT count(*) FROM api_tokens WHERE ('session.open' = ANY (string_to_array(scope, '+'))) <> (bound_ip IS NOT NULL)")"
chk "api_tokens 권한 그대로" "$P0" "$(priv)"
chk "런처 200" "200" "$(code "$L8082/")"
chk "t-box 로그인 200" "200" "$(code "$L8082/tbox/login")"
if [ "$ok" -ne 1 ]; then
  echo "== ✖ 확인 실패 — 반영 전으로 되돌린다"
  NEWATOM=$(q "SELECT count(*) FROM api_tokens WHERE scope NOT IN ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open', 'community.read+community.write')")
  if same "0" "$NEWATOM"; then
    if ( cd / && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DBNAME" -f - ) <<< "$REVERT_SQL"; then echo "   · 되돌림 문장을 돌렸다"; else echo "   ✖ 되돌리지 못했다 — 사람이 봐야 한다(백업 $BK)"; fi
  else
    echo "   ✖ 옛 다섯 값 밖의 행($NEWATOM)이 있다 — 되돌리지 않는다. 사람이 봐야 한다(백업 $BK)"
  fi
  if same "old" "$(state_of)" && same "$R0" "$(rows)"; then echo "   ✔ 되돌린 뒤 반영 전(표지 셋 옛 원문 · 범위별 행 수 = 반영 전)"; else echo "   ✖ 되돌린 뒤에도 반영 전이 아니다 — 사람이 봐야 한다"; fi
  exit 4
fi
cat <<'NEXT'
== 끝 — 서버 반영 완료. 이제 사용자가 할 것(PC 쪽)
   ① 비서(aa): git pull origin wip/qf10 → 비서를 다시 켠다(평소 갱신 방법 그대로)
   ② t-box → 내 계정 → 프로그램 토큰: 토큰 **하나**를 새로 발급(파일 · 커뮤니티 읽기·쓰기 · 이 PC 로그인 — 쓸 범위를 체크 · 묶인 IP 가 사무실 공인 IP 인지)
      → 비서 트레이 「API 토큰 변경」 에 넣고 Ctrl+B · Ctrl+F(커뮤니티 검색) · 파일 올리기를 한 번씩
   ③ 옛 토큰(파일용 · 커뮤니티 쓰기용 · 이 PC 로그인 전용)을 같은 화면에서 **회수**
   ④ MCP(Claude 데스크톱)를 쓰면 설정의 토큰을 새 토큰으로 바꾸고 qaredi_search_pages 한 번
NEXT
