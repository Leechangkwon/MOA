#!/usr/bin/env bash
# review-scripts/apply-g0-db-2026-09-28.sh — G-0 t-box 「개인」 등급 · 사람 지정 권한 · 권한 요청(PRD F-73)의 **DB 쪽**
#   ★ 사용자(또는 허용 규칙 안의 메인) 승인 뒤 실행한다 — 운영 DB 의 CHECK 셋을 넓히고 CHECK 셋 · 권한 표 네 줄 · 표 둘 · 커뮤니티 알림 칸을 더한다. root(sudo)로.
#      검토를 통과하면 /var/www/qaredi/scripts/ 로 옮긴다(검토 동안은 복제본 review-scripts/ — 태그 review-base/g0 안).
#   근거: 지시서 ~/.local/share/qaredi-dev/prompt-g0-private-grants.txt 6장 · 마이그레이션 db/migrations/2026-10-10_access_grants.sql(여러 번 돌려도 같다) ·
#         구현 기록 docs/g0-implementation.md
#   본보기: scripts/apply-7d3-db-2026-09-27.sh(검토 통과 틀) — sha256 · 문장 목록 고정 · root 만 읽는 사본만 해시·실행 · lock_timeout ·
#     확인이 틀리면 되돌림 · 사용 중 확인 · **SQL 은 전부 표준입력**(`psql -c` 0 — 운영 경보 AL-9 가 한 모양으로 묶는다).
#   ★ 순서(반드시): ① 이 스크립트(--migrate) → ② apply-g0-repo-2026-09-28.sh(코드, changkwon).
#     **코드보다 먼저**다 — 새 코드는 목록 조건절부터 access_grants 를 읽어, 표 없이 나가면 파일 목록·트리·받기가 500 이다
#     (저장소 스크립트는 표가 없으면 종료 3 으로 멈춘다). 이 DB 만 먼저 들어간 동안 옛 코드는 거의 그대로 돈다(옛 판정은 can_read 참인 줄만
#     쓰고, 새 개인 줄은 전부 거짓) — **예외 하나**: 권한 매트릭스 편집(F-25)은 16행을 기대해 ① 과 ② 사이 몇 분 동안 편집이 500 이다(편집하지 않는다).
#   마이그레이션 파일은 운영 트리가 아니라 **인자로 받은 파일**(패치에서 뽑은 사본)을 읽는다. root 만 읽는 임시 폴더로 먼저 복사하고
#   **그 사본**을 해시·대조·실행한다(확인과 사용 사이에 원본이 바뀌어도 상관없게).
#   모드
#     --dry-run MIG   쓰지 않는다. 사전 확인 · 보낼 문장 · 지금 상태 · \dp 넷.
#     --migrate MIG   마이그레이션(검토 통과 판 sha256 · 문장 목록 고정)을 postgres 로. 확인: CHECK 셋 넓힘 + 새 CHECK 셋 · 개인 줄 4(읽기 0 · 올리기 3) ·
#                     권한 표 행 +4 · 표 둘(소유자 tbox_owner · 행 0) · 권한(tbox_app SIUD + 시퀀스 · tbox_backup SELECT · tbox_perm·tbox_push 0) ·
#                     알림 kind CHECK·칸(→ access_requests · ON DELETE SET NULL) · 파일·폴더·알림 행 수 그대로 · 기존 표 넷 \dp 그대로 · 사이트 200 둘.
#                     확인이 틀리면(이번 실행이 적용한 경우만 · 운영 코드가 아직 새 표를 읽지 않을 때만 · 개인 행 0 일 때만) 아래 되돌리기 문장으로 **반영 전**으로.
#   되돌리기(손으로 — **코드 ② 를 먼저** 되돌린 뒤. ★ 개인 등급 행이 하나라도 있으면 되돌리지 않는다 — 억지로 등급을 바꾸면 그 파일·폴더가 넓은 등급으로
#     새어 나간다. 먼저 `SELECT count(*) FROM files WHERE access_level='private'` · folders 같은 것 — 0·0 일 때만 이 스크립트 안 ROLLBACK_SQL 을 표준입력으로).
#   종료 코드: 0 성공(또는 이미 적용됨) · 1 사전 확인 실패(아무것도 바꾸지 않음) · 4 적용 뒤 확인 실패(되돌림)
#   확인 요청은 운영 감사에 행을 남기지 않는다 — HTTP 는 런처 `/` · 로그인 화면 `/tbox/login` 둘만(미로그인 `/api` 0).
#   연습 결과(2026-09-28 15:2x · claude — 이 고정 값 그대로(MIG_WANT 7c78c49e…) · 경로 둘(APP · L8082)만 바꾼 사본 · 모의 운영 트리 = g0w 를 c2b4d76 으로 clone ·
#     모의 DB tbox_g0_reh_test(운영 pg_dump 를 읽어 붓기만 · 소유자 tbox_owner) · 모의 인스턴스 php -S 127.0.0.1:8415 · 연습 틀 wk-g0/rehearse.sh — 운영 트리·8082·tbox_re 무접촉):
#     인자 없음 1 · 모르는 인자(--apply) 1 · 빈 인자 1 · 다른 마이그레이션(sha) 1 · --dry-run 0(반영 전 0/0|0|0|0/0/0/0|0 · 문장 98줄) ·
#     확인 실패(인스턴스 내림 — 사이트 200 둘 ✖) → 되돌림 4 · 되돌린 뒤 반영 전 모양 · \dp 같음 · 권한 표 16행 → --dry-run 0 「반영 전」 ·
#     --migrate 0(확인 ✔ 10 / ✖ 0 — 반영 뒤 2/4|0|3|0/3/3/1|1 · 권한 표 20 · 행 수 그대로 · 소유자 · 권한 · 알림 칸 · \dp 같음 · CHECK 정의 · 사이트 200 둘) ·
#     다시 --migrate 0 「이미 적용됨」 · 저장소 스크립트를 DB 전에 → 3 「DB 먼저」 · 옛 코드(c2b4d76) + 새 DB 로그인 화면·런처 200 ·
#     끝에 머리 주석대로 개인 행 0 확인 → ROLLBACK_SQL 표준입력 → --dry-run 「반영 전」. 로그 wk-g0/rehearse-1.log.
set -euo pipefail
# ★ pipefail 아래에서 `… | grep -q` · `… | head` · `… | awk '…exit'` 를 쓰지 않는다(읽는 쪽이 먼저 끝나면 SIGPIPE 로 파이프가 「실패」)
USAGE="사용: sudo bash $0 --dry-run <MIG> | --migrate <MIG>"
[ "$#" -eq 2 ] || { echo "$USAGE"; exit 1; }
# 빈 인자("")는 받지 않는다 — 빈 값이 기본값처럼 읽히지 않게
for a in "$@"; do [ -n "$a" ] || { echo "$USAGE — 빈 인자는 받지 않는다"; exit 1; }; done
case "$1" in --dry-run) MODE=dry ;; --migrate) MODE=migrate ;; *) echo "$USAGE — 모르는 인자: '${1}'"; exit 1 ;; esac
MIG_ARG="$2"
[ "$(id -u)" -eq 0 ] || { echo "root 로 실행하세요(sudo)"; exit 1; }
APP=/var/www/qaredi/tbox-v2
L8082=http://127.0.0.1:8082
UA="tbox-review/$(date +%F)"
# @@HASHES-BEGIN@@
MIG_WANT=7c78c49ebad9ac26643cb35b69140bc5c43cb2c213dccb1e12a9f6aa53481093
# @@HASHES-END@@
# 마이그레이션의 주석·빈 줄을 뺀 문장 — **글자 그대로 이 목록**이어야 한다(한 줄이라도 다르면 멈춘다)
STMTS_WANT=$(cat <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.permissions DROP CONSTRAINT IF EXISTS permissions_access_level_check;
ALTER TABLE public.permissions ADD CONSTRAINT permissions_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.permissions DROP CONSTRAINT IF EXISTS permissions_private_by_people;
ALTER TABLE public.permissions ADD CONSTRAINT permissions_private_by_people
    CHECK (access_level <> 'private' OR can_read = false);
ALTER TABLE public.files DROP CONSTRAINT IF EXISTS files_access_level_check;
ALTER TABLE public.files ADD CONSTRAINT files_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.files DROP CONSTRAINT IF EXISTS files_private_no_team;
ALTER TABLE public.files ADD CONSTRAINT files_private_no_team
    CHECK (access_level <> 'private' OR team_id IS NULL);
ALTER TABLE public.folders DROP CONSTRAINT IF EXISTS folders_access_level_check;
ALTER TABLE public.folders ADD CONSTRAINT folders_access_level_check
    CHECK (access_level IN ('public','internal','team','admin-only','private'));
ALTER TABLE public.folders DROP CONSTRAINT IF EXISTS folders_private_no_team;
ALTER TABLE public.folders ADD CONSTRAINT folders_private_no_team
    CHECK (access_level <> 'private' OR team_id IS NULL);
INSERT INTO public.permissions (role, access_level, can_read, can_upload) VALUES
    ('Admin',    'private', false, true),
    ('Manager',  'private', false, true),
    ('Employee', 'private', false, true),
    ('Guest',    'private', false, false)
ON CONFLICT (role, access_level) DO NOTHING;
DO $$
BEGIN
    IF (SELECT count(*) FROM public.permissions WHERE access_level = 'private') <> 4
       OR EXISTS (SELECT 1 FROM public.permissions WHERE access_level = 'private' AND can_read)
       OR EXISTS (SELECT 1 FROM public.permissions WHERE access_level = 'private' AND role = 'Guest' AND can_upload) THEN
        RAISE EXCEPTION 'permissions 의 private 줄이 기대(4줄 · 읽기 0 · 손님 올리기 0)와 다르다 — 멈춘다';
    END IF;
END
$$;
CREATE TABLE IF NOT EXISTS public.access_grants (
    id         BIGSERIAL   PRIMARY KEY,
    folder_id  BIGINT      REFERENCES public.folders(id) ON DELETE CASCADE,
    file_id    BIGINT      REFERENCES public.files(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    can_write  BOOLEAN     NOT NULL DEFAULT false,
    granted_by BIGINT      NOT NULL REFERENCES public.users(id),
    granted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_grants_one_target CHECK (num_nonnulls(folder_id, file_id) = 1)
);
CREATE UNIQUE INDEX IF NOT EXISTS access_grants_folder_user_uidx ON public.access_grants (folder_id, user_id) WHERE folder_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS access_grants_file_user_uidx   ON public.access_grants (file_id, user_id)   WHERE file_id IS NOT NULL;
CREATE INDEX        IF NOT EXISTS access_grants_user_idx         ON public.access_grants (user_id);
ALTER TABLE public.access_grants OWNER TO tbox_owner;
CREATE TABLE IF NOT EXISTS public.access_requests (
    id           BIGSERIAL   PRIMARY KEY,
    folder_id    BIGINT      REFERENCES public.folders(id) ON DELETE CASCADE,
    file_id      BIGINT      REFERENCES public.files(id),
    requester_id BIGINT      NOT NULL REFERENCES public.users(id),
    want_write   BOOLEAN     NOT NULL DEFAULT false,
    message      TEXT        NOT NULL DEFAULT '',
    status       TEXT        NOT NULL DEFAULT 'pending',
    decided_by   BIGINT      REFERENCES public.users(id),
    decided_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_requests_one_target  CHECK (num_nonnulls(folder_id, file_id) = 1),
    CONSTRAINT access_requests_message_len CHECK (char_length(message) <= 200),
    CONSTRAINT access_requests_status      CHECK (status IN ('pending','approved','rejected','cancelled')),
    CONSTRAINT access_requests_decided_pair CHECK ((status = 'pending') = (decided_at IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS access_requests_folder_pending_uidx ON public.access_requests (folder_id, requester_id)
    WHERE folder_id IS NOT NULL AND status = 'pending';
CREATE UNIQUE INDEX IF NOT EXISTS access_requests_file_pending_uidx   ON public.access_requests (file_id, requester_id)
    WHERE file_id IS NOT NULL AND status = 'pending';
CREATE INDEX IF NOT EXISTS access_requests_requester_idx ON public.access_requests (requester_id, created_at DESC);
CREATE INDEX IF NOT EXISTS access_requests_folder_idx    ON public.access_requests (folder_id) WHERE folder_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS access_requests_file_idx      ON public.access_requests (file_id)   WHERE file_id IS NOT NULL;
ALTER TABLE public.access_requests OWNER TO tbox_owner;
ALTER TABLE community.notifications DROP CONSTRAINT IF EXISTS notifications_kind_check;
ALTER TABLE community.notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('mention','reply','comment_on_my_page','handover','access_request','access_decided'));
ALTER TABLE community.notifications ADD COLUMN IF NOT EXISTS access_request_id BIGINT
    REFERENCES public.access_requests(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS notifications_access_request_idx ON community.notifications (access_request_id)
    WHERE access_request_id IS NOT NULL;
REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_app, tbox_backup;
REVOKE ALL ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq FROM tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON public.access_grants, public.access_requests FROM tbox_push';
    END IF;
END
$$;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.access_grants, public.access_requests TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_app;
GRANT SELECT ON public.access_grants, public.access_requests TO tbox_backup;
GRANT SELECT ON SEQUENCE public.access_grants_id_seq, public.access_requests_id_seq TO tbox_backup;
COMMIT;
SQL
)
# 되돌림 — 이번 실행이 적용한 것만(운영 코드가 아직 새 표를 읽지 않을 때 · 개인 행 0 일 때만). 마이그레이션 머리 주석의 되돌리기와 같은 문장
ROLLBACK_SQL=$(cat <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
DO $$
BEGIN
    IF (SELECT count(*) FROM public.files WHERE access_level = 'private') + (SELECT count(*) FROM public.folders WHERE access_level = 'private') > 0 THEN
        RAISE EXCEPTION '개인 등급 행이 있다 — 되돌리지 않는다(사람이 판단)';
    END IF;
END
$$;
ALTER TABLE community.notifications DROP COLUMN IF EXISTS access_request_id;
DELETE FROM community.notifications WHERE kind IN ('access_request','access_decided');
ALTER TABLE community.notifications DROP CONSTRAINT IF EXISTS notifications_kind_check;
ALTER TABLE community.notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('mention','reply','comment_on_my_page','handover'));
DROP TABLE IF EXISTS public.access_requests;
DROP TABLE IF EXISTS public.access_grants;
DELETE FROM public.permissions WHERE access_level = 'private';
ALTER TABLE public.permissions DROP CONSTRAINT IF EXISTS permissions_private_by_people;
ALTER TABLE public.files   DROP CONSTRAINT IF EXISTS files_private_no_team;
ALTER TABLE public.folders DROP CONSTRAINT IF EXISTS folders_private_no_team;
ALTER TABLE public.permissions DROP CONSTRAINT permissions_access_level_check;
ALTER TABLE public.permissions ADD CONSTRAINT permissions_access_level_check CHECK (access_level IN ('public','internal','team','admin-only'));
ALTER TABLE public.files DROP CONSTRAINT files_access_level_check;
ALTER TABLE public.files ADD CONSTRAINT files_access_level_check CHECK (access_level IN ('public','internal','team','admin-only'));
ALTER TABLE public.folders DROP CONSTRAINT folders_access_level_check;
ALTER TABLE public.folders ADD CONSTRAINT folders_access_level_check CHECK (access_level IN ('public','internal','team','admin-only'));
COMMIT;
SQL
)

# pipefail 아래에서 `… | head` 를 쓰지 않는다. 변수로 받아 첫 줄만 · 따옴표 한 겹은 bash 로.
DBRAW=$(sed -n 's/^DB_NAME=//p' "$APP/.env" || true)
DBNAME=${DBRAW%%$'\n'*}
case "$DBNAME" in \"*\") DBNAME=${DBNAME#\"}; DBNAME=${DBNAME%\"} ;; \'*\') DBNAME=${DBNAME#\'}; DBNAME=${DBNAME%\'} ;; esac
[[ "$DBNAME" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { echo "   ✖ .env 의 DB_NAME 을 읽지 못했거나 모양이 아니다"; exit 1; }
PSQL=(sudo -u postgres psql -X -q -t -A -v ON_ERROR_STOP=1 -d "$DBNAME")
# SQL 은 표준입력으로만(명령 인자 -c 0 — 운영 경보 AL-9 가 한 모양으로 묶는다)
q() { ( cd / && "${PSQL[@]}" <<< "$1" ) 2>/dev/null || echo "(query-failed)"; }
code() { curl -s -o /dev/null --max-time 10 -w '%{http_code}' -A "$UA" "$@" || true; }
# 상태 조각 — 하나의 글자로 모은다(반영 전 모양 · 반영 뒤 모양과 대조)
tables_n()  { q "SELECT (to_regclass('public.access_grants') IS NOT NULL)::int + (to_regclass('public.access_requests') IS NOT NULL)::int"; }
priv_rows() { q "SELECT count(*) || '|' || count(*) FILTER (WHERE can_read) || '|' || count(*) FILTER (WHERE can_upload) || '|' || count(*) FILTER (WHERE role = 'Guest' AND can_upload) FROM public.permissions WHERE access_level = 'private'"; }
chk_wide()  { q "SELECT count(*) FROM pg_constraint WHERE conname IN ('permissions_access_level_check','files_access_level_check','folders_access_level_check') AND pg_get_constraintdef(oid) LIKE '%''private''%'"; }
chk_new()   { q "SELECT count(*) FROM pg_constraint WHERE conname IN ('permissions_private_by_people','files_private_no_team','folders_private_no_team')"; }
notif()     { q "SELECT (pg_get_constraintdef(c.oid) LIKE '%access_decided%')::int || '|' || (SELECT count(*) FROM information_schema.columns WHERE table_schema = 'community' AND table_name = 'notifications' AND column_name = 'access_request_id') FROM pg_constraint c WHERE c.conname = 'notifications_kind_check' AND c.conrelid = 'community.notifications'::regclass"; }
state_str() { printf '%s' "$(tables_n)/$(priv_rows)/$(chk_wide)/$(chk_new)/$(notif)"; }
OLD_SHAPE="0/0|0|0|0/0/0/0|0"
NEW_SHAPE="2/4|0|3|0/3/3/1|1"
dp_sha() { local v; v=$(q "\\dp $1"); case "$v" in *query-failed*|'') printf '%s' "(query-failed)" ;; *) printf '%s' "$v" | sha256sum | cut -d' ' -f1 ;; esac; }
dp_all() { printf '%s' "$(dp_sha public.permissions)$(dp_sha public.files)$(dp_sha public.folders)$(dp_sha community.notifications)" | sha256sum | cut -d' ' -f1; }
counts() { q "SELECT (SELECT count(*) FROM public.files) || '|' || (SELECT count(*) FROM public.folders) || '|' || (SELECT count(*) FROM community.notifications)"; }
perm_n() { q "SELECT count(*) FROM public.permissions"; }
# 「tbox_app SIUD 둘 + 시퀀스 둘 | tbox_backup SELECT · INSERT | tbox_perm · tbox_push SELECT」 — 역할이 없으면 f
privs() { q "SELECT concat_ws('|',
    concat_ws(',', has_table_privilege('tbox_app','public.access_grants','SELECT,INSERT,UPDATE,DELETE')::text, has_table_privilege('tbox_app','public.access_requests','SELECT,INSERT,UPDATE,DELETE')::text,
                   has_sequence_privilege('tbox_app','public.access_grants_id_seq','USAGE')::text, has_sequence_privilege('tbox_app','public.access_requests_id_seq','USAGE')::text),
    concat_ws(',', has_table_privilege('tbox_backup','public.access_grants','SELECT')::text, has_table_privilege('tbox_backup','public.access_requests','SELECT')::text,
                   has_table_privilege('tbox_backup','public.access_grants','INSERT')::text),
    concat_ws(',', CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN has_table_privilege('tbox_perm','public.access_grants','SELECT')::text ELSE 'false' END,
                   CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN has_table_privilege('tbox_push','public.access_grants','SELECT')::text ELSE 'false' END))"; }
owners() { q "SELECT string_agg(DISTINCT tableowner, ',') || '|' || (SELECT count(*) FROM public.access_grants) + (SELECT count(*) FROM public.access_requests) FROM pg_tables WHERE schemaname = 'public' AND tablename IN ('access_grants','access_requests')"; }
fk_notif() { q "SELECT c.confdeltype FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conrelid = 'community.notifications'::regclass AND c.contype = 'f' AND a.attname = 'access_request_id' AND c.confrelid = 'public.access_requests'::regclass"; }
# 운영 코드가 이미 새 표를 읽나(파일 읽기만) — 읽으면 표를 지우지 않는다(지우면 500)
code_reads() { local n; n=$( (grep -c 'access_grants' "$APP/src/Authz.php" 2>/dev/null || true) ); printf '%s' "${n:-0}"; }
priv_items() { q "SELECT (SELECT count(*) FROM public.files WHERE access_level = 'private') + (SELECT count(*) FROM public.folders WHERE access_level = 'private')"; }
# 빈 값끼리·(query-failed)끼리 같다고 통과하지 않는다
same() { [ -n "$2" ] && [ "$1" = "$2" ] && [[ "$2" != *query-failed* ]]; }

echo "== 사전 확인 (DB $DBNAME · 모드 $MODE)"
[ -f "$MIG_ARG" ] && [ ! -L "$MIG_ARG" ] || { echo "   ✖ 마이그레이션 파일 없음(또는 링크): $MIG_ARG — 패치에서 뽑은 파일을 인자로 주세요"; exit 1; }
# root 만 읽는 임시 폴더에 사본 — 이 뒤로는 **사본만** 해시·대조·실행한다(확인-사용 틈 없음)
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
for t in public.permissions public.files public.folders public.users community.notifications; do
  h=$(q "SELECT (to_regclass('$t') IS NOT NULL)::int")
  same "1" "$h" || { echo "   ✖ $t 가 없다(또는 읽지 못했다 — $h)"; exit 1; }
done
for r in tbox_owner tbox_app tbox_backup; do
  h=$(q "SELECT count(*) FROM pg_roles WHERE rolname = '$r'")
  same "1" "$h" || { echo "   ✖ 역할 $r 이 없다($h)"; exit 1; }
done
S0=$(state_str)
STATE=unknown
if same "$OLD_SHAPE" "$S0"; then STATE=old
elif same "$NEW_SHAPE" "$S0"; then STATE=new
fi
[ "$STATE" != "unknown" ] || { echo "   ✖ 지금 상태($S0)가 반영 전($OLD_SHAPE)도 반영 뒤($NEW_SHAPE)도 아니다 — 누가 바꿨다. 멈춘다"; exit 1; }
DP0=$(dp_all); C0=$(counts); P0=$(perm_n)
same "$DP0" "$DP0" && [[ "$C0" =~ ^[0-9]+\|[0-9]+\|[0-9]+$ ]] && [[ "$P0" =~ ^[0-9]+$ ]] || { echo "   ✖ \\dp·행 수를 읽지 못했다 — 멈춘다"; exit 1; }
echo "   · 지금 = $( [ "$STATE" = old ] && echo '반영 전' || echo '반영 뒤 모양(이미 적용됨)') ($S0 — 표 둘/개인 줄 수|읽기|올리기|손님 올리기/넓힌 CHECK/새 CHECK/알림 kind|칸)"
echo "   · 권한 표 ${P0}행 · 파일|폴더|알림 행 $C0 · 기존 표 넷 \\dp sha ${DP0:0:16} · 운영 코드가 새 표를 읽는 줄 $(code_reads)(0 이어야 — 코드는 이 뒤에)"
echo "   사이트: 런처 $(code "$L8082/") · 로그인 $(code "$L8082/tbox/login")"

if [ "$MODE" = "dry" ]; then
  echo "   · 보낼 문장:"; printf '%s\n' "$STMTS" | sed 's/^/       /'
  echo "   · \\dp public.permissions(지금 — 반영 뒤에도 같아야 한다):"; q '\dp public.permissions' | sed 's/^/       /'
  echo "== [dry] 여기까지 — 바꾼 것 없음. 다음: --migrate <MIG> → apply-g0-repo-2026-09-28.sh(코드)"
  exit 0
fi
if [ "$STATE" = "new" ]; then echo "== 이미 적용됨(반영 뒤 모양) — 바꾸지 않는다"; exit 0; fi
BUSY=$(q "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'")
echo "   최근 10분 검토 계정이 아닌 사람의 감사 행: $BUSY (0 이어야 — 쓰는 사람 밑에서 files·folders 에 잠금을 잡지 않는다)"
same "0" "$BUSY" || { echo "   ✖ 누군가 사이트를 쓰는 중이거나 읽지 못했다 — 미룬다"; exit 1; }

echo "== 1. 마이그레이션 (postgres)"
if ! ( cd / && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DBNAME" -f - ) < "$MIG"; then
  # 한 트랜잭션이다 — 잠금을 5초 안에 못 잡았거나(lock_timeout) SQL 오류면 ROLLBACK, 바뀐 것 없음
  echo "   ✖ 마이그레이션 실패(잠금 대기 5초를 넘겼거나 SQL 오류) — 트랜잭션이라 바뀐 것 없음 · 지금 $(state_str). 잠시 뒤 다시 돌리면 된다"
  exit 1
fi

echo "== 2. 확인"
ok=1
chk() { if same "$2" "$3"; then echo "   ✔ $1 (${3:0:48})"; else echo "   ✖ $1 (기대 ${2:0:48} · 실제 ${3:0:48})"; ok=0; fi; }
chk "상태 = 반영 뒤 모양(표 둘/개인 줄 4|읽기 0|올리기 3|손님 0/넓힌 CHECK 3/새 CHECK 3/알림 kind·칸)" "$NEW_SHAPE" "$(state_str)"
chk "권한 표 행 +4" "$((P0 + 4))" "$(perm_n)"
chk "파일|폴더|알림 행 수 그대로" "$C0" "$(counts)"
chk "새 표 둘 소유자 tbox_owner · 행 0" "tbox_owner|0" "$(owners)"
chk "권한 — tbox_app SIUD 둘·시퀀스 둘 | tbox_backup SELECT 둘·INSERT 없음 | tbox_perm·tbox_push 0" "true,true,true,true|true,true,false|false,false" "$(privs)"
chk "알림 칸 access_request_id → access_requests · ON DELETE SET NULL" "n" "$(fk_notif)"
chk "기존 표 넷 \\dp — 권한 이전과 같음(sha)" "$DP0" "$(dp_all)"
chk "매트릭스 편집으로 개인 읽기를 켤 수 없다 — CHECK permissions_private_by_people 정의(읽기만)" "CHECK (((access_level <> 'private'::text) OR (can_read = false)))" \
  "$(q "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'permissions_private_by_people' AND conrelid = 'public.permissions'::regclass")"
chk "런처 200" "200" "$(code "$L8082/")"
chk "t-box 로그인 200" "200" "$(code "$L8082/tbox/login")"
if [ "$ok" -ne 1 ]; then
  echo "== ✖ 확인 실패 — 이번 실행이 적용한 것을 되돌려 반영 전으로"
  if same "0" "$(code_reads)" && same "0" "$(priv_items)"; then
    if ( cd / && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$DBNAME" -f - ) <<< "$ROLLBACK_SQL"; then echo "   · 되돌렸다"; else echo "   ✖ 되돌리지 못했다 — 사람이 봐야 한다"; fi
  else
    echo "   ✖ 운영 코드가 이미 새 표를 읽거나(줄 $(code_reads)) 개인 등급 행이 있다($(priv_items)) — 되돌리지 않는다. 사람이 봐야 한다"
  fi
  if same "$OLD_SHAPE" "$(state_str)" && same "$DP0" "$(dp_all)" && same "$P0" "$(perm_n)"; then echo "   ✔ 되돌린 뒤 = 반영 전 모양 · \\dp 이전과 같음 · 권한 표 ${P0}행"; else echo "   ✖ 되돌린 뒤에도 반영 전이 아니다($(state_str)) — 사람이 봐야 한다"; fi
  exit 4
fi
echo "== 끝 — 다음: bash /var/www/qaredi/scripts/apply-g0-repo-2026-09-28.sh <패치> <sha256> --dry-run → 본 실행(① 과 ② 사이에 권한 매트릭스 편집을 하지 않는다)"
