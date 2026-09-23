-- db/migrations/2026-09-28_community_embeds.sql
--   커뮤니티 7-C — 임베드 허용 목록 (C:F-32 · T-33, ARCH 21.21)
--
-- 요구사항: ../../../community/stage7-finish-directive.md 「7-C 확정」 · 결정 J-4 · K-1~K-6
--
-- 실행 계정: tbox_owner (표 소유자).
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정):
--     embed_domains  SELECT·INSERT·UPDATE  (빼기는 removed_at — DELETE 없음)
-- ★ 7-B(`2026-09-27_community_templates.sql`) 뒤에 돌린다.
--
-- 하는 일 (여러 번 돌려도 같다, 기존 행 변경 0)
--   1. embed_domains  Admin 이 등록한 임베드 호스트(https 만 — 호스트 이름 **정확히**). 살아 있는 것은 앱이 20개까지 둔다.
--                     모양(소문자 ASCII 라벨 · 점 하나 이상 · IP·포트 없음)은 앱 `Doc::isEmbedHost` 가 먼저 보고, 여기서 한 번 더 본다.
--
-- 되돌리기 (아무도 쓰기 전): DROP TABLE community.embed_domains;

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS community.embed_domains (
    id         BIGSERIAL   PRIMARY KEY,
    host       TEXT        NOT NULL,
    added_by   BIGINT      NOT NULL REFERENCES public.users(id),
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at TIMESTAMPTZ,
    removed_by BIGINT      REFERENCES public.users(id),
    CONSTRAINT embed_domains_host_shape CHECK (
        char_length(host) BETWEEN 4 AND 253
        AND host ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$'),
    CONSTRAINT embed_domains_removed_pair CHECK ((removed_at IS NULL) = (removed_by IS NULL))
);
-- 살아 있는 줄에서 호스트는 하나 — 뺐다가 다시 넣으면 새 줄(누가 언제 넣고 뺐는지가 줄마다 남는다)
CREATE UNIQUE INDEX IF NOT EXISTS embed_domains_live_host ON community.embed_domains (host) WHERE removed_at IS NULL;

COMMIT;
