-- db/migrations/2026-10-07_community_palettes.sql — 커뮤니티 7-D2 관리자 팔레트 (C:F-33 · 결정 L-2)
--   (파일 이름의 날짜는 적용 **순서**용이다 — 앞 마이그레이션 2026-10-06_passkeys.sql 뒤. 작성 2026-09-27, claude)
--
-- 지시서: /var/www/qaredi/community/stage7d-palette-contrast-mention-directive.md 1장(1.1 P-1~P-17 · 1.2 DB 변경)
--
-- 실행 계정: postgres (표를 만든 뒤 소유자를 다른 커뮤니티 표와 같은 tbox_owner 로 넘긴다 — 2026-10-03_qf3a_… 와 같은 방식).
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-07_community_palettes.sql
--   ★ 운영 반영은 사용자 sudo 스크립트(review-scripts/apply-7d2-db-2026-09-27.sh — 검토 뒤 /var/www/qaredi/scripts/)로 한다.
--   ★ 이 파일 뒤에 db/community/grants.sql 을 다시 돌려도 같은 권한이 된다(그 파일 끝에 같은 줄이 있다).
--
-- 하는 일 (여러 번 돌려도 같다 — CREATE … IF NOT EXISTS · REVOKE 뒤 GRANT · 기존 행 변경 0 · 확장 0)
--   1. community.palettes — 관리자가 등록한 회사 색. 이름 하나에 라이트·다크 값 둘(배경값은 앱이 이 둘로 계산한다 — 저장하지 않는다).
--      7-C `embed_domains` 와 같은 모양: 줄은 남고 빼기는 `removed_at` · 살아 있는 이름은 하나(부분 UNIQUE) ·
--      뺐다가 같은 이름을 다시 넣으면 **새 줄**(누가 언제 넣고 고치고 뺐는지가 줄마다 남는다).
--      이름 `[a-z0-9-]{1,20}` · 값 `#rrggbb` 소문자 — CHECK 로 한 겹 더(앱이 먼저 400 으로 막는다).
--      예약 이름(기본 9색 · none)과 끝 `-bg` 금지는 **앱만** 막는다 — 목록이 두 곳에 있지 않게(지시서 1.2).
--   2. 권한 — tbox_app: SELECT · INSERT · UPDATE(★ DELETE 없음 — 빼기는 removed_at) + 시퀀스 USAGE·SELECT ·
--      tbox_backup: SELECT(백업 pg_dump 가 새 표에서 멈추지 않게) · tbox_perm · tbox_push: 0.
--
-- 잠금: 외래 키를 걸 때 public.users 에 짧은 SHARE ROW EXCLUSIVE 잠금을 쥔다. 긴 읽기 뒤에 줄을 서서 쓰기를 멈추지 않게
--   `lock_timeout 5s`(못 잡으면 오류 → ROLLBACK — 아무것도 바뀌지 않는다, 다시 돌리면 된다).
-- 새로 설치하는 DB 는 db/community/schema.sql 이 같은 모양을 만든다 — 그 뒤에 이 파일을 돌려도 아무 일도 하지 않는다.
--
-- 되돌리기 (코드를 먼저 되돌린 뒤 — 새 코드는 이 표를 읽는다. 표를 못 읽으면 새 코드도 500 이 아니라 색 없이 돈다(P-17)):
--   BEGIN; DROP TABLE IF EXISTS community.palettes; COMMIT;
--   (줄을 지우면 등록한 회사 색이 사라진다 — 글에는 이름만 남아 색 없이 그려진다)

\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS community.palettes (
    id         BIGSERIAL   PRIMARY KEY,
    name       TEXT        NOT NULL,
    light      TEXT        NOT NULL,
    dark       TEXT        NOT NULL,
    added_by   BIGINT      NOT NULL REFERENCES public.users(id),
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by BIGINT      REFERENCES public.users(id),
    updated_at TIMESTAMPTZ,
    removed_by BIGINT      REFERENCES public.users(id),
    removed_at TIMESTAMPTZ,
    CONSTRAINT palettes_name_shape  CHECK (name ~ '^[a-z0-9-]{1,20}$'),
    CONSTRAINT palettes_light_shape CHECK (light ~ '^#[0-9a-f]{6}$'),
    CONSTRAINT palettes_dark_shape  CHECK (dark ~ '^#[0-9a-f]{6}$'),
    CONSTRAINT palettes_removed_pair CHECK ((removed_at IS NULL) = (removed_by IS NULL)),
    CONSTRAINT palettes_updated_pair CHECK ((updated_at IS NULL) = (updated_by IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS palettes_live_name ON community.palettes (name) WHERE removed_at IS NULL;
ALTER TABLE community.palettes OWNER TO tbox_owner;
REVOKE ALL ON community.palettes FROM tbox_app, tbox_backup;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        EXECUTE 'REVOKE ALL ON community.palettes FROM tbox_perm';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_push') THEN
        EXECUTE 'REVOKE ALL ON community.palettes FROM tbox_push';
    END IF;
END
$$;
GRANT SELECT, INSERT, UPDATE ON community.palettes TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE community.palettes_id_seq TO tbox_app;
GRANT SELECT ON community.palettes TO tbox_backup;
GRANT SELECT ON SEQUENCE community.palettes_id_seq TO tbox_backup;
COMMIT;
