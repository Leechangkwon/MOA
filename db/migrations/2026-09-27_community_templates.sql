-- db/migrations/2026-09-27_community_templates.sql
--   커뮤니티 7-B — 템플릿 (C:F-31, ARCH 21.20)
--
-- 요구사항: ../../../community/stage7-finish-directive.md 「7-B」 · 결정 J-3(내 것 · 팀 · 사이트 기본, 남의 개인 템플릿은 안 보임)
--
-- 실행 계정: tbox_owner (표 소유자).
-- ★ 이 파일 뒤에 `db/community/grants.sql` 을 **반드시 한 번 더** 돌린다(postgres 계정):
--     templates  SELECT·INSERT·UPDATE  (지우기는 deleted_at — DELETE 없음)
-- ★ 7-A(`2026-09-26_community_purge.sql`) 뒤에 돌린다.
--
-- 하는 일 (표·열 삭제 0, 여러 번 돌려도 같다)
--   1. templates   템플릿 한 개 = 제목·아이콘·본문 뼈대(+ DB 행 템플릿이면 속성 값). 첨부·댓글·하위 페이지는 담지 않는다
--                  (본문을 거르는 일은 앱 `Doc::templateBody` — 여기서는 모양만 본다)
--      scope      personal(만든 사람만) · team(그 팀원) · site(모두 — 시드, Admin) · database(그 DB 에 행을 더할 수 있는 사람)
--   2. 사이트 기본 셋 시드 — 회의록 · 인수인계서 · 업무 체크리스트 (seed_key 로 한 번만. 관리자가 지운 것은 다시 살리지 않는다)
--
-- 되돌리기 (아무도 쓰기 전): DROP TABLE community.templates;

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS community.templates (
    id            BIGSERIAL   PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    scope         TEXT        NOT NULL,
    owner_user_id BIGINT      REFERENCES public.users(id),
    team_id       BIGINT      REFERENCES public.teams(id),
    database_id   BIGINT      REFERENCES community.databases(id),
    name          TEXT        NOT NULL,
    title         TEXT        NOT NULL,
    icon          TEXT,
    doc           JSONB       NOT NULL,
    properties    JSONB       NOT NULL DEFAULT '{}'::jsonb,
    seed_key      TEXT        UNIQUE,
    created_by    BIGINT      REFERENCES public.users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ,
    deleted_by    BIGINT      REFERENCES public.users(id),
    CONSTRAINT templates_scope_check CHECK (scope IN ('personal', 'team', 'site', 'database')),
    -- 범위마다 채우는 칸이 하나다 — 둘 이상 채워진 줄(범위가 모호한 줄)은 들어오지 못한다
    CONSTRAINT templates_scope_shape CHECK (
           (scope = 'personal' AND owner_user_id IS NOT NULL AND team_id IS NULL AND database_id IS NULL)
        OR (scope = 'team'     AND team_id IS NOT NULL AND owner_user_id IS NULL AND database_id IS NULL)
        OR (scope = 'site'     AND owner_user_id IS NULL AND team_id IS NULL AND database_id IS NULL)
        OR (scope = 'database' AND database_id IS NOT NULL AND owner_user_id IS NULL AND team_id IS NULL)),
    CONSTRAINT templates_name_len  CHECK (char_length(name) BETWEEN 1 AND 60),
    CONSTRAINT templates_title_len CHECK (char_length(title) BETWEEN 1 AND 200),
    CONSTRAINT templates_icon_len  CHECK (icon IS NULL OR char_length(icon) BETWEEN 1 AND 8),
    CONSTRAINT templates_doc_shape CHECK (jsonb_typeof(doc) = 'object' AND jsonb_typeof(doc -> 'blocks') = 'array'),
    -- jsonb 의 글자 모양은 `": "`·`", "` 로 공백이 붙어 앱의 1MB(빈칸 없는 JSON)보다 크다 — 앱이 먼저 1MB 로 400 을 주고, 여기는 두 배 여유
    CONSTRAINT templates_doc_size  CHECK (octet_length(doc::text) <= 2097152),
    CONSTRAINT templates_props_shape CHECK (jsonb_typeof(properties) = 'object'
                                            AND (scope = 'database' OR properties = '{}'::jsonb)),
    -- 시드만 만든 사람이 없다(사이트 기본). 사람이 만든 것은 누가 만들었는지 늘 남는다
    CONSTRAINT templates_creator CHECK (created_by IS NOT NULL OR (scope = 'site' AND seed_key IS NOT NULL)),
    CONSTRAINT templates_deleted_pair CHECK ((deleted_at IS NULL) = (deleted_by IS NULL))
);

CREATE INDEX IF NOT EXISTS templates_personal_idx ON community.templates (owner_user_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'personal';
CREATE INDEX IF NOT EXISTS templates_team_idx ON community.templates (team_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'team';
CREATE INDEX IF NOT EXISTS templates_database_idx ON community.templates (database_id, created_at)
    WHERE deleted_at IS NULL AND scope = 'database';

-- ─── 사이트 기본 셋 (결정 J-3 · PRD F-31 「부서 기본 양식」 · N-23 회의록) ─────────
--   블록 id 는 여기 적힌 고정값이지만, 템플릿에서 페이지를 만들 때마다 앱이 **새 id** 를 매긴다(`Doc::freshIds`).
INSERT INTO community.templates (seed_key, scope, name, title, icon, doc)
SELECT v.seed_key, 'site', v.name, v.title, v.icon, v.doc
  FROM (VALUES
    ('meeting', '회의록', '회의록', '📝', '{"schema": 1, "blocks": [{"id": "c1364351-c76b-46b9-8726-e1d1e87029cc", "type": "heading", "level": 2, "text": [{"t": "회의 정보", "m": {}}]}, {"id": "806e4144-12fa-4536-882c-6e3a7e2119a4", "type": "bulleted", "text": [{"t": "일시: ", "m": {}}]}, {"id": "1ef3c976-23c4-420f-a91a-25504299e496", "type": "bulleted", "text": [{"t": "장소: ", "m": {}}]}, {"id": "88157ee4-b4ac-40c4-8817-f659fccc0bbc", "type": "bulleted", "text": [{"t": "참석: ", "m": {}}]}, {"id": "b8bf69a8-9045-4081-8f54-48534569a6f4", "type": "bulleted", "text": [{"t": "기록: ", "m": {}}]}, {"id": "edcab900-1b81-4cc2-90a8-0f84f2e6c1f3", "type": "heading", "level": 2, "text": [{"t": "안건", "m": {}}]}, {"id": "84ffb04b-b5b6-4779-b7ab-bcde8e6255fe", "type": "numbered", "text": [{"t": "첫 번째 안건", "m": {}}]}, {"id": "8344434b-515f-4c0b-a414-9ccd8dc79ead", "type": "numbered", "text": [{"t": "두 번째 안건", "m": {}}]}, {"id": "1cd59d6a-cb17-47b8-a91a-fabe053ff8aa", "type": "heading", "level": 2, "text": [{"t": "논의 내용", "m": {}}]}, {"id": "6cf38075-2361-4304-ab27-01832728ec65", "type": "paragraph", "text": []}, {"id": "eeb91e44-8489-44d4-bb17-2fd4708fb6e0", "type": "heading", "level": 2, "text": [{"t": "결정 사항", "m": {}}]}, {"id": "c9d3da4b-2de8-453f-b8bb-51d042a4d8b6", "type": "bulleted", "text": []}, {"id": "71871d46-b05c-4c48-8e12-a94db0bad74f", "type": "heading", "level": 2, "text": [{"t": "할 일", "m": {}}]}, {"id": "73d9da1e-b482-4b7b-b43a-b6e63812fe41", "type": "to_do", "checked": false, "text": [{"t": "누가 · 무엇을 · 언제까지", "m": {}}]}, {"id": "bceeb90b-6fb4-45b2-90d9-cb8a09674ae0", "type": "heading", "level": 2, "text": [{"t": "다음 회의", "m": {}}]}, {"id": "15422f19-62ba-4ca7-8758-6f33d3a1e33e", "type": "paragraph", "text": [{"t": "일시와 안건을 적습니다.", "m": {}}]}]}'::jsonb),
    ('handover', '인수인계서', '인수인계서', '🤝', '{"schema": 1, "blocks": [{"id": "cc77e884-12ce-4bab-8008-d796dbff52ab", "type": "callout", "icon": "📋", "text": [{"t": "인계하는 사람과 받는 사람이 함께 읽고, 받는 사람이 맨 아래 확인란을 체크합니다. 비밀번호는 여기에 적지 않습니다.", "m": {}}]}, {"id": "c20bb03b-91c9-4758-b7e6-a7f8fd8e94e1", "type": "heading", "level": 2, "text": [{"t": "기본 정보", "m": {}}]}, {"id": "5da57cfd-16a3-4026-a0d6-581ab3b2f835", "type": "table", "header_row": true, "header_col": false, "rows": [{"color": null, "cells": [{"text": [{"t": "항목", "m": {}}], "color": null}, {"text": [{"t": "내용", "m": {}}], "color": null}]}, {"color": null, "cells": [{"text": [{"t": "인계자", "m": {}}], "color": null}, {"text": [], "color": null}]}, {"color": null, "cells": [{"text": [{"t": "인수자", "m": {}}], "color": null}, {"text": [], "color": null}]}, {"color": null, "cells": [{"text": [{"t": "인계일", "m": {}}], "color": null}, {"text": [], "color": null}]}, {"color": null, "cells": [{"text": [{"t": "업무 범위", "m": {}}], "color": null}, {"text": [], "color": null}]}]}, {"id": "758056ad-1642-4208-972a-c8b97e1a0a8e", "type": "heading", "level": 2, "text": [{"t": "진행 중인 업무", "m": {}}]}, {"id": "b7c06b09-a05e-468e-bbe3-783818414a52", "type": "to_do", "checked": false, "text": [{"t": "업무 이름 — 지금 상태와 다음 할 일", "m": {}}]}, {"id": "70d350ad-5067-452b-8b39-c57b07be8d04", "type": "heading", "level": 2, "text": [{"t": "정기 업무", "m": {}}]}, {"id": "1384070b-8a20-4d31-bda1-a50de61f4648", "type": "bulleted", "text": [{"t": "주기 · 업무 · 참고 문서", "m": {}}]}, {"id": "d3844e84-bb8b-4d3e-8dbe-38cc84fc6b0c", "type": "heading", "level": 2, "text": [{"t": "관계자 · 연락처", "m": {}}]}, {"id": "c87e8785-cb78-4f3d-a5bb-36fa819c390e", "type": "bulleted", "text": []}, {"id": "b5606364-b1de-4412-8517-e033249d58fb", "type": "heading", "level": 2, "text": [{"t": "계정 · 권한 넘기기", "m": {}}]}, {"id": "c8292c8b-1693-4055-831d-542289ab3389", "type": "to_do", "checked": false, "text": [{"t": "시스템 권한 넘기기", "m": {}}]}, {"id": "3e9d7f19-3605-4e81-b73f-d50fae70fb0a", "type": "heading", "level": 2, "text": [{"t": "주의할 점", "m": {}}]}, {"id": "cf8c2a30-6d3e-44e9-a321-41217f9527be", "type": "quote", "text": []}, {"id": "4e3b9c4b-e69e-49c6-a746-76f864c8ace1", "type": "heading", "level": 2, "text": [{"t": "인수 확인", "m": {}}]}, {"id": "bba6b1f2-41e1-469f-ae26-c0331e436e8f", "type": "to_do", "checked": false, "text": [{"t": "인수자가 내용을 읽고 확인함", "m": {}}]}]}'::jsonb),
    ('checklist', '업무 체크리스트', '업무 체크리스트', '✅', '{"schema": 1, "blocks": [{"id": "d0ceaa27-944d-4286-8946-ed75bd269e76", "type": "paragraph", "text": [{"t": "주기: 매일 / 매주 / 매월 중 하나만 남기고 지웁니다.", "m": {}}]}, {"id": "68510a23-1a25-44a0-a16e-f98d734ff466", "type": "heading", "level": 2, "text": [{"t": "시작 전", "m": {}}]}, {"id": "25998c48-6735-4bae-80be-c579ecd3b2e0", "type": "to_do", "checked": false, "text": [{"t": "준비물 · 자료 확인", "m": {}}]}, {"id": "cb5d4d3e-c68e-4880-b162-332555812f33", "type": "to_do", "checked": false, "text": [{"t": "관련 공지 읽기", "m": {}}]}, {"id": "79de6e63-4898-4dcd-a2fa-2dd106b86ed3", "type": "heading", "level": 2, "text": [{"t": "진행", "m": {}}]}, {"id": "718536dc-ef75-4f9b-a8a0-f35c4ed7bbb5", "type": "to_do", "checked": false, "text": [{"t": "첫 번째 할 일", "m": {}}]}, {"id": "2848f67c-abd4-452a-9050-baf00cb7034c", "type": "to_do", "checked": false, "text": [{"t": "두 번째 할 일", "m": {}}]}, {"id": "eff04509-3ec2-4776-94f7-57d460182027", "type": "to_do", "checked": false, "text": [{"t": "세 번째 할 일", "m": {}}]}, {"id": "c5c328c6-f3e7-467e-b962-1337ffcd5712", "type": "heading", "level": 2, "text": [{"t": "마무리", "m": {}}]}, {"id": "eb466e89-e526-484a-9e32-954cc03c7794", "type": "to_do", "checked": false, "text": [{"t": "결과 기록", "m": {}}]}, {"id": "a448f29f-cfd8-4300-a543-3baafd2f02a0", "type": "to_do", "checked": false, "text": [{"t": "다음 담당자에게 알리기", "m": {}}]}, {"id": "1d154f43-121a-448d-9068-c90f20f7d0c2", "type": "toggle", "text": [{"t": "참고", "m": {}}], "children": [{"id": "c991fe36-89b0-4840-9f7c-eef0d5c3dbbc", "type": "paragraph", "text": [{"t": "관련 문서 링크나 설명을 적습니다.", "m": {}}]}]}]}'::jsonb)

       ) AS v(seed_key, name, title, icon, doc)
ON CONFLICT (seed_key) DO NOTHING;

COMMIT;
