-- ─────────────────────────────────────────────────────────────
-- 2026-09-13_folders.sql  (18단계 18-1 — PRD F-26 폴더(분류 + 권한), ARCHITECTURE 5.16 · 6.2-4)
--
-- 목적: 파일을 담는 **권한 있는 분류 트리**를 저장할 표를 만들고, files 에 그 참조를 더한다.
--
-- 설계 요점 (ARCH 6.2-4 컬럼 표 그대로)
--   · 폴더는 **DB 의 분류**다 — 저장 경로(ARCH 6.3, 무작위 이름 2단 디렉터리)는 한 글자도
--     바뀌지 않는다. 폴더 이름이 경로에 들어가는 자리가 어디에도 없으므로 경로 조작 표면이
--     늘지 않는다.
--   · 폴더도 **파일과 같은 등급 체계**를 갖는다(사용자 결정 2026-09-13 「폴더 자체도 권한을
--     두도록」): `access_level` 4종 + `team_id`(team 이면 필수). CHECK 문구는 files 와
--     **글자 그대로 같다** — 읽기 판정이 같은 함수(`Authz::decideRead`)이므로 DB 제약도 같아야
--     한쪽만 느슨해지는 일이 없다.
--   · depth — 부모 depth + 1, CHECK 로 1~5 를 DB 가 강제한다. 앱이 먼저 보지만, 한 경로라도
--     빠지면 무한히 깊은 트리가 생긴다 → 마지막 그물을 DB 에 둔다.
--   · 이름 중복 금지 — 같은 부모 안에서만이다. `UNIQUE(parent_id, name)` 는 PostgreSQL 에서
--     parent_id 가 NULL(루트) 인 행을 서로 다른 값으로 보아 막지 못한다. 그래서 **부분 UNIQUE
--     인덱스 둘**로 나눈다: 하위용(parent_id IS NOT NULL)과 루트용(parent_id IS NULL).
--   · files.folder_id → folders(id) **ON DELETE RESTRICT** — 파일이 참조하는 폴더는 DB 가
--     삭제를 막는다. 앱(folders_delete)이 "살아 있는 파일 0·하위 폴더 0" 을 먼저 보지만,
--     그 검사와 DELETE 사이에 다른 요청이 파일을 넣을 수 있다. FK 가 마지막 그물이다.
--   · parent_id → folders(id) 도 RESTRICT — 하위 폴더가 있는 폴더는 DB 가 지우지 못한다.
--   · **불변 규칙 ①(하위 등급 ≥ 부모 등급)은 DB 가 아니라 앱이 지킨다** (ARCH 5.16).
--     한 행만 보고 판정할 수 없는 규칙(부모 행을 함께 봐야 한다)이라 CHECK 로 쓸 수 없다.
--     대신 그 규칙을 지나는 쓰기 경로 7개가 전부 `Authz::fitsUnderFolder` 한 함수를 부른다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-13_folders.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): CREATE TABLE IF NOT EXISTS · ADD COLUMN IF NOT EXISTS ·
--   CREATE INDEX IF NOT EXISTS · 제약은 pg_constraint 를 먼저 확인하고 붙인다 · GRANT.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
--   ADD COLUMN 뒤의 SET NOT NULL 은 표가 비어 있을 때만 성립한다 — 이 표는 18단계에서 처음
--   만들어지므로 이행할 기존 행이 없다(있으면 SET NOT NULL 이 실패하며 멈춘다. 조용히
--   기본값을 채워 넣지 않는다 — 등급은 사람이 정해야 하는 값이다).
-- 권한: 새 표이므로 여기서 GRANT 를 함께 준다(기존 표 권한이 자동으로 따라붙지 않는다).
--   앱 계정은 SELECT·INSERT·UPDATE·**DELETE** 다 — 빈 폴더는 논리 삭제가 아니라 실제로
--   지운다(지워도 잃을 기록이 없고, 지운 사실은 감사 로그 folder.delete 에 남는다).
--   같은 내용이 db/grants.sql 에도 들어간다(두 곳을 맞춘다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS folders (
    id           BIGSERIAL   PRIMARY KEY,
    parent_id    BIGINT      REFERENCES folders(id) ON DELETE RESTRICT,   -- NULL = 루트 바로 아래
    name         TEXT        NOT NULL,
    depth        INT         NOT NULL,
    access_level TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    team_id      BIGINT      REFERENCES teams(id),
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT folders_name_len        CHECK (length(name) BETWEEN 1 AND 50),
    CONSTRAINT folders_depth_range     CHECK (depth BETWEEN 1 AND 5),
    CONSTRAINT folders_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    -- team 등급이면 team_id 필수 (files 의 team_requires_team_id 와 같은 규칙)
    CONSTRAINT folders_team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);

-- 이 마이그레이션의 **이전 판**(등급 없는 분류만 있던 판)으로 표가 만들어진 경우를 위한 보강.
-- 값을 채우지 않는다 — 표가 비어 있어야 SET NOT NULL 이 성립한다(위 머리말 참조).
ALTER TABLE folders ADD COLUMN IF NOT EXISTS access_level TEXT;
ALTER TABLE folders ADD COLUMN IF NOT EXISTS team_id      BIGINT;
ALTER TABLE folders ALTER COLUMN access_level SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'folders_access_level_check') THEN
        ALTER TABLE folders ADD CONSTRAINT folders_access_level_check
            CHECK (access_level IN ('public','internal','team','admin-only'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'folders_team_requires_team_id') THEN
        ALTER TABLE folders ADD CONSTRAINT folders_team_requires_team_id
            CHECK (access_level <> 'team' OR team_id IS NOT NULL);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'folders_team_id_fkey') THEN
        ALTER TABLE folders ADD CONSTRAINT folders_team_id_fkey
            FOREIGN KEY (team_id) REFERENCES teams(id);
    END IF;
END
$$;

-- 같은 부모 안 이름 중복 금지. 루트(parent_id IS NULL)는 따로 본다 — NULL 은 서로 같지 않아
-- 하나의 UNIQUE(parent_id, name) 로는 루트의 중복을 막지 못한다.
CREATE UNIQUE INDEX IF NOT EXISTS folders_parent_name_uidx
    ON folders (parent_id, name) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS folders_root_name_uidx
    ON folders (name) WHERE parent_id IS NULL;

-- 트리를 한 층씩 받는 조회(`GET /api/folders?parent=`)가 훑는 인덱스
CREATE INDEX IF NOT EXISTS folders_parent_idx  ON folders (parent_id);
-- 트리 조회는 언제나 권한 조건(access_level·team_id)과 함께 훑는다 (files_visible_idx 와 같은 결)
CREATE INDEX IF NOT EXISTS folders_visible_idx ON folders (access_level, team_id);

-- ─── files.folder_id (NULL = 루트) ───
ALTER TABLE files ADD COLUMN IF NOT EXISTS folder_id BIGINT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'files_folder_id_fkey') THEN
        ALTER TABLE files
            ADD CONSTRAINT files_folder_id_fkey
            FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE RESTRICT;
    END IF;
END
$$;

-- 폴더 안 목록(`GET /api/files?folder=`)과 "이 폴더에 파일이 있나"(folders_delete) 조회용.
CREATE INDEX IF NOT EXISTS files_folder_idx ON files (folder_id) WHERE deleted_at IS NULL;

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───
REVOKE ALL ON folders FROM tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON folders TO tbox_app;   -- ★ 빈 폴더 삭제에 DELETE 필요
GRANT USAGE, SELECT ON SEQUENCE folders_id_seq TO tbox_app;

-- 백업 계정은 다른 표와 같게 읽기만 (PRD S-08)
GRANT SELECT ON folders TO tbox_backup;
GRANT SELECT ON SEQUENCE folders_id_seq TO tbox_backup;

COMMIT;
