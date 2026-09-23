-- db/migrations/2026-09-23_community_owner_share.sql
--   커뮤니티 5-A — 소유자·인계 · 사람 지정 공유 (C:F-18 · C:F-28 · C:S-14, PRD NG-8 해제)
--
-- 요구사항: ../../../community/PRD.md 4.1 「인계(F-28)」 · NG-8(해제),
--           ../../../community/stage5a-owner-share-directive.md 「데이터 — 마이그레이션」
--
-- 실행 계정: tbox_owner (표 소유자).
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-23_community_owner_share.sql
--
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정).
--   새 표 둘에는 아직 앱 계정 권한이 하나도 없다 — 그 파일이
--     `page_shares` SIUD(공유는 바뀌는 값이다 — 바뀐 일은 감사 `page.share` 가 남긴다)
--     `handovers`   **SELECT·INSERT 만**(C:S-14 — 인계 기록은 지울 수 없다)
--   을 준다. 이 마이그레이션은 GRANT 를 쓰지 않는다(권한의 진실은 `grants.sql` 한 파일 — 4-C·4-D 와 같다).
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. `page_shares` — 페이지 하나를 **한 사람**에게 보기(`view`) 또는 편집(`edit`)으로 준 줄.
--                      하위 페이지에도 미친다(판정이 조상을 따라 오른다 — 줄을 하위마다 복사하지 않는다).
--                      같은 페이지·같은 사람은 한 줄뿐이다(UNIQUE) — 등급을 바꾸면 그 줄이 바뀐다.
--   2. `handovers`    — 인계 기록. 누가(`by_user_id`)·언제(`at`)·무엇을(`root_page_id`)·
--                      누구에서(`from_user_id`) 누구에게(`to_user_id`)·몇 쪽(`page_count`)·
--                      옛 주인에게 남긴 권한(`kept_level`, 없음이면 NULL). append-only.
--   3. 인덱스 — 「공유받은 페이지」(`page_shares_user`) · 페이지별 인계 이력(`handovers_root_at`).
--
-- ★ `pages.assignee_id`(4-D)는 **지우지 않는다**(열 삭제 0). 5-A 코드는 그 칸에 새 값을 쓰지
--   않고, 이미 든 값과 `page_property_events` 의 담당자 줄은 「처리 이력」에 그대로 남는다.
-- ★ **앱은 이 파일이 적용되기 전의 DB 에서 5-A 코드를 돌리지 못한다** — 페이지 한 건을 읽는
--   질의(`community_page_row`)가 `page_shares` 를 읽는다. 그래서 순서는
--   「이 마이그레이션 → grants.sql → 코드 배포」다(4-D 교훈).
--
-- 되돌리기 (아무도 새 표를 쓰기 전):
--   DROP TABLE community.handovers; DROP TABLE community.page_shares;

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1. 사람 지정 공유 ─────────────────────────────────────────
--   `user_id`    받은 사람. `ON DELETE` 절이 없다 — t-box 는 계정을 지우지 않는다(비활성만).
--                비활성 계정의 줄은 남지만 **판정에서 효력 0** 이다(`users.is_active` 를 함께 본다).
--   `granted_by` 준 사람(소유자 또는 팀 공간 팀원, 인계 때 남긴 줄은 인계한 사람).
CREATE TABLE IF NOT EXISTS community.page_shares (
    id         BIGSERIAL   PRIMARY KEY,
    page_id    BIGINT      NOT NULL REFERENCES community.pages(id),
    user_id    BIGINT      NOT NULL REFERENCES public.users(id),
    level      TEXT        NOT NULL CHECK (level IN ('view','edit')),
    granted_by BIGINT      NOT NULL REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (page_id, user_id)
);
CREATE INDEX IF NOT EXISTS page_shares_user ON community.page_shares (user_id);

-- ─── 2. 인계 기록 — append-only (C:S-14) ──────────────────────
CREATE TABLE IF NOT EXISTS community.handovers (
    id           BIGSERIAL   PRIMARY KEY,
    root_page_id BIGINT      NOT NULL REFERENCES community.pages(id),
    from_user_id BIGINT      NOT NULL REFERENCES public.users(id),
    to_user_id   BIGINT      NOT NULL REFERENCES public.users(id),
    by_user_id   BIGINT      NOT NULL REFERENCES public.users(id),
    page_count   INT         NOT NULL,
    kept_level   TEXT        CHECK (kept_level IN ('view','edit')),
    at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS handovers_root_at ON community.handovers (root_page_id, at DESC);

COMMIT;

-- ─── 확인 (적용한 사람이 눈으로 본다) ───
--   \d community.page_shares
--   \d community.handovers
--   SELECT count(*) FROM community.page_shares;   -- 0 이어야 한다
--   -- grants.sql 을 돌린 뒤: handovers 는 r=SELECT · a=INSERT 둘만 보여야 한다
--   SELECT relname, relacl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
--    WHERE n.nspname='community' AND relname IN ('page_shares','handovers');
