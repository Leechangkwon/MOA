-- 사이트 Q:F-15 S3 「과업 길잡이」 1차 (지시서 docs/qf15-s3-guide-directive.md A · community ARCHITECTURE 26.2 · DESIGN 2.37)
--
-- 무엇을: 새 표 하나 community.guide_journeys(사람 × 여정 한 줄 — 진행 상태 · 화면 · 단계 · 시작 · 완성 시각)와
--         「사람당 진행 중 하나」 부분 유니크 색인 하나 · 권한(tbox_app SELECT · INSERT · UPDATE / tbox_backup SELECT — DELETE 는 누구에게도 0).
--         행 0 · 시퀀스 0 · 함수 0 · 다른 표 0.
-- 왜: 길잡이(Q:F-15 ③)는 과업마다 시작 시각과 완성 이력이 있어야 「시작 뒤 결과가 생겼나」를 서버가 다시 잴 수 있다.
--     옛 사용 안내 표(community.usage_guides)는 사람당 한 줄(마지막 화면 · 단계)이라 그것을 담지 못한다 — 옛 표는 그대로 둔다.
--     표시 선호다: 권한 · 내용 · 다른 사람에게 영향 0(사용자 행이 지워지면 함께 지워진다 — CASCADE).
-- 적용 계정: ARCHITECTURE 10장 끝 「마이그레이션 적용 계정」 — postgres 로 붙어 이 파일을 돌리면 트랜잭션 첫 문장
--     `SET LOCAL ROLE tbox_owner;` 로 새 표의 소유자가 tbox_owner 가 된다(옛 안내 표가 postgres 소유로 만들어져 R-120 을 낳은 것의
--     재발 방지 · 선례 2026-10-11_product_spaces.sql). 적용 뒤 db/community/grants.sql 을 다시 돌린다(같은 권한 블록이 있다).
-- 되풀이 안전: CREATE … IF NOT EXISTS · GRANT 는 두 번 돌려도 같다.
-- 되돌리는 길: 코드를 되돌린 뒤, 사용자가 정하면 `DROP TABLE community.guide_journeys;` — 다른 표 영향 0 · 쌓인 진행 · 완성 기록은 사라진다.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL ROLE tbox_owner;
SET LOCAL lock_timeout = '5s';
CREATE TABLE IF NOT EXISTS community.guide_journeys (
    user_id           bigint      NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    journey           text        NOT NULL CHECK (journey ~ '^[a-z][a-z0-9-]{1,31}$'),
    status            text        NOT NULL CHECK (status IN ('active','paused','completed','dismissed')),
    page              smallint    NOT NULL DEFAULT 0 CHECK (page BETWEEN 0 AND 9),
    step              smallint    NOT NULL DEFAULT 0 CHECK (step BETWEEN 0 AND 30),
    started_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    completed_at      timestamptz,
    confirmed_by_user boolean     NOT NULL DEFAULT false,
    PRIMARY KEY (user_id, journey)
);
CREATE UNIQUE INDEX IF NOT EXISTS guide_journeys_one_active ON community.guide_journeys (user_id) WHERE status = 'active';
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_app') THEN
  GRANT SELECT,INSERT,UPDATE ON community.guide_journeys TO tbox_app;
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
  GRANT SELECT ON community.guide_journeys TO tbox_backup;
 END IF;
END $$;
COMMIT;
