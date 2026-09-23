-- db/migrations/2026-09-23_users_display_name.sql
--   Qaredi 5-A″ — 계정의 **이름** 칸 (사이트 공통 — t-box 계정 표)
--
-- 요구사항: ../../../community/stage5a2-names-teamshare-directive.md 「1. 이름」
--           (사용자 2026-09-23 「아이디, 이름 같이 보이게 해. 아이디만으로는 누가 누군지 알아보기 쉽지 않아」)
--
-- 실행 계정: tbox_owner (표 소유자) 또는 postgres.
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-23_users_display_name.sql
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. `users.display_name TEXT NULL` — 사람이 알아보는 이름. **없음(NULL)이 기본**이다 —
--      지금 있는 계정은 전부 이름 없이 남고, 화면은 이름이 없으면 아이디만 보인다.
--      유일하지 않아도 된다(동명이인) — 그래서 화면은 **언제나 아이디를 함께** 보인다.
--   2. CHECK `users_display_name_shape` — 앱 검증(1~40자 · 앞뒤 공백 없음 · 제어 문자 없음)의
--      **뒷받침**이다. 앱이 한 군데라도 검사를 빠뜨려도 이 모양 밖의 값은 들어갈 수 없다
--      (`guest_requires_expires_at` 과 같은 태도). 앱은 이보다 **더 좁게** 막는다
--      (서식 문자 — 방향 바꾸기 U+202E·폭 없는 공백 같은 것 — 도 거부한다).
--
-- 권한: GRANT/REVOKE 를 하지 않는다. 열 추가는 **표 단위 권한에 자동으로 포함**된다
--   (`db/grants.sql` — `users` 는 tbox_app 에 SELECT·INSERT·UPDATE, tbox_backup 에 SELECT.
--   열 단위 GRANT 는 이 표에 없다 — `\dp users` 로 확인). 커뮤니티 코드도 같은 `tbox_app`
--   계정으로 돈다(db/community/grants.sql 머리글 「한 앱·한 로그인」) — 따로 줄 권한이 없다.
--   쓰기는 **Admin 창구 하나**(`PATCH /api/users/{uid}`·`POST /api/users`, 정책 `user.manage`)뿐이다.
--
-- ★ 코드보다 **먼저** 적용한다. 5-A″ 코드는 세션을 읽는 질의(`Auth::SQL_LOOKUP`)부터 이 칸을 읽는다 —
--   적용 전의 DB 에서 5-A″ 코드를 돌리면 모든 요청이 500 이다(4-D 교훈).
--
-- 되돌리기 (아무도 이름을 넣기 전):
--   ALTER TABLE users DROP CONSTRAINT users_display_name_shape; ALTER TABLE users DROP COLUMN display_name;

\set ON_ERROR_STOP on

BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name TEXT;

-- CHECK 은 `IF NOT EXISTS` 가 없다 — 이름으로 있는지 보고 없을 때만 더한다(여러 번 돌려도 같다).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'users_display_name_shape'
           AND conrelid = 'public.users'::regclass
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_display_name_shape CHECK (
            display_name IS NULL
            OR (    char_length(display_name) BETWEEN 1 AND 40
                AND display_name = btrim(display_name)
                AND display_name !~ '[[:cntrl:]]')
        );
    END IF;
END
$$;

COMMIT;
