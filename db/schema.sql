-- db/schema.sql — t-box 재구축 스키마 (PRD 9장, ARCHITECTURE 6.2 · 6.2-1 · 6.2-2 · 7.2)
--
-- 실행 계정: tbox_owner (테이블 소유자). 앱 계정 tbox_app 은 db/grants.sql 로 최소 권한만 받는다.
-- 실행 예:  PGPASSWORD=... psql -h 127.0.0.1 -U tbox_owner -d tbox_re -v ON_ERROR_STOP=1 -f db/schema.sql
--
-- 멱등하지 않다(초기 구축 전용). deploy/install.sh 는 DB 를 새로 만들었을 때만 이 파일을 적용한다.
-- gen_random_uuid() 는 PostgreSQL 13+ 내장 함수다 — pgcrypto 확장을 설치하지 않는다(공급망 최소화, PRD S-10-C).

\set ON_ERROR_STOP on

-- ─────────────────────────────────────────────────────────────
-- teams — Manager·Employee 의 소속. 'team' 레벨 판정에 필요 (PRD 9장)
--
--   12-3 (F-19, ARCH 5.9 · 6.2-1) — 팀은 **지우지 않는다**. files.team_id·users.team_id 와
--   감사 기록이 팀을 참조하므로 행을 지우면 추적이 끊어진다. 대신 is_active=false 로 내리고,
--   비활성 팀에는 새 배정·새 업로드가 막힌다(이미 그 팀에 있는 것은 그대로).
--   이름 길이는 앱(org_team_create·org_team_update)이 먼저 보지만 DB 도 마지막 그물로 막는다.
--   (기존 DB 는 db/migrations/2026-09-12_teams_active.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE teams (
    id         BIGSERIAL   PRIMARY KEY,
    name       TEXT        NOT NULL UNIQUE,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT teams_name_len CHECK (length(name) BETWEEN 1 AND 50)
);

-- ─────────────────────────────────────────────────────────────
-- users — 역할은 4종으로 DB 가 강제한다 (PRD 9장 제약 조건)
--   password_hash 는 Argon2id 해시만 저장(2단계, PRD S-09-4). 평문 비밀번호는 어디에도 저장하지 않는다.
--   failed_count / locked_until — 실패 5회 10분 잠금 (PRD S-09-4)
--   expires_at / mfa_* / updated_at — 10단계 (F-10, SECURITY R-12 · R-05).
--     · expires_at  : 계정 만료 시각. **Guest 는 필수**(아래 CHECK). 만료 뒤 로그인은 401 + user.expired
--     · mfa_secret  : TOTP 공유 비밀(base32). 켜져 있을 때만 값이 있고 끄면 NULL 로 되돌린다.
--                     응답(enroll 1회)·감사 로그 어디에도 나가지 않는다
--     · mfa_enabled : 2단계 인증을 켰나. 켠 계정은 코드 없이 로그인할 수 없다
--     · updated_at  : 역할·활성·비밀번호·MFA 가 마지막으로 바뀐 시각
-- ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id            BIGSERIAL PRIMARY KEY,
    username      TEXT        NOT NULL UNIQUE
                  CONSTRAINT users_username_len   CHECK (length(username) BETWEEN 3 AND 64)
                  CONSTRAINT users_username_chars CHECK (username ~ '^[a-z0-9_.-]+$'),
    password_hash TEXT        NOT NULL,
    role          TEXT        NOT NULL CHECK (role IN ('Admin','Manager','Employee','Guest')),
    team_id       BIGINT      REFERENCES teams(id),
    is_active     BOOLEAN     NOT NULL DEFAULT true,
    failed_count  INT         NOT NULL DEFAULT 0,
    locked_until  TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at    TIMESTAMPTZ,
    mfa_secret    TEXT,
    mfa_enabled   BOOLEAN     NOT NULL DEFAULT false,
    -- 2단계 인증 코드 1회성 (13단계, SECURITY R-45, RFC 6238 §5.2).
    --   마지막으로 성공시킨 TOTP 스텝 번호(= 유닉스 시각 ÷ 30). 한 번도 안 썼으면 NULL.
    --   이 값 **이하**의 스텝은 코드가 맞아도 "이미 쓴 코드" 로 거부한다 — ±1 창(90초) 안에서
    --   같은 6자리로 여러 번 로그인하던 것을 막는다. 코드 자체는 저장하지 않는다(PRD 7.7).
    mfa_last_step BIGINT,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- 최초 로그인 강제 절차 (11단계, PRD F-16, ARCH 4.0).
    --   must_change_password — 임시 비밀번호 상태. true 인 동안 라우터 관문이 비밀번호 변경 외 모든 요청을 막는다
    --   mfa_setup_required   — 2단계 인증을 아직 등록하지 않았다. 비밀번호를 바꾼 뒤 이 관문이 이어진다
    --   password_changed_at  — 본인이 비밀번호를 마지막으로 바꾼 시각(NULL = 한 번도 바꾸지 않음).
    --                          마이그레이션의 이행 조건이자 R-33("시드 비밀번호 파일 수명") 판단 근거다
    must_change_password BOOLEAN NOT NULL DEFAULT false,
    mfa_setup_required   BOOLEAN NOT NULL DEFAULT false,
    password_changed_at  TIMESTAMPTZ,
    -- 19단계 구매·재고 모듈의 **권한 두 축** (PRD F-28, ARCH 5.18 「권한 두 축」).
    --   branch_id     — 지점 격리의 기준. NULL 허용(구매팀·경영진은 지점이 없다).
    --                   FK 는 p_branches 를 만든 **뒤**에 붙인다(아래 ALTER) — 두 표가 서로를 참조한다.
    --   purchase_role — 모듈 역할 requester·buyer·stock·viewer. NULL 이면 /purchase/* 전부 403
    --                   (Admin 제외). 값 목록은 Authz::purchaseAuthorize 의 판정과 같다 (ARCH 4.1).
    -- (기존 DB 는 db/migrations/2026-09-13_purchase_masters.sql 로 같은 모양이 된다.)
    branch_id     BIGINT,
    purchase_role TEXT CHECK (purchase_role IS NULL
                              OR purchase_role IN ('requester','buyer','stock','viewer')),
    -- Guest 는 만료일 필수 (PRD 3장 Guest 생명주기, SECURITY R-12).
    -- 앱 코드가 한 군데라도 검사를 빠뜨려도 만료 없는 Guest 행은 존재할 수 없다.
    CONSTRAINT guest_requires_expires_at
        CHECK (role <> 'Guest' OR expires_at IS NOT NULL)
);

-- ─────────────────────────────────────────────────────────────
-- permissions — 권한 매트릭스를 "데이터"로 (ARCH 6.2-1)
--   앱 계정은 이 표에 SELECT 만 가진다 → 화면·API 로는 권한 표를 바꿀 수 없다(권한 상승 경로 차단)
--   16행 시드는 db/seed.sql
-- ─────────────────────────────────────────────────────────────
CREATE TABLE permissions (
    role         TEXT NOT NULL CHECK (role IN ('Admin','Manager','Employee','Guest')),
    access_level TEXT NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    can_read     BOOLEAN NOT NULL DEFAULT false,   -- 목록·다운로드 (team 은 "같은 팀이면" 의 전제)
    can_upload   BOOLEAN NOT NULL DEFAULT false,   -- 이 레벨로 올릴 수 있나
    PRIMARY KEY (role, access_level)
);

-- ─────────────────────────────────────────────────────────────
-- folders — 파일을 담는 **권한 있는** 분류 트리 (18-1, PRD F-26, ARCH 5.16 · 6.2-4)
--   폴더는 DB 의 분류다 — 저장 경로(6.3)는 한 글자도 바뀌지 않는다.
--   폴더도 **파일과 같은 등급 체계**(access_level 4종 + team_id)를 갖는다
--   (사용자 결정 2026-09-13 「폴더 자체도 권한을 두도록」). 읽기 판정은 파일과 같은 함수
--   Authz::decideRead 이고, 못 읽는 폴더는 트리 응답에 아예 없다 (PRD S-02).
--   불변 규칙 ①(하위 등급 ≥ 부모 등급)은 한 행만 보고 판정할 수 없어 CHECK 로 쓸 수 없다 →
--   쓰기 경로 7개가 전부 Authz::fitsUnderFolder 를 지나는 것으로 앱이 지킨다 (ARCH 5.16).
--   (기존 DB 는 db/migrations/2026-09-13_folders.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE folders (
    id           BIGSERIAL   PRIMARY KEY,
    parent_id    BIGINT      REFERENCES folders(id) ON DELETE RESTRICT,   -- NULL = 루트 바로 아래
    name         TEXT        NOT NULL,
    depth        INT         NOT NULL,                  -- 부모 depth + 1
    access_level TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    team_id      BIGINT      REFERENCES teams(id),
    created_by   BIGINT      NOT NULL REFERENCES users(id),   -- 관리 범위 판정 (decideFolderChange)
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT folders_name_len        CHECK (length(name) BETWEEN 1 AND 50),
    CONSTRAINT folders_depth_range     CHECK (depth BETWEEN 1 AND 5),
    CONSTRAINT folders_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    -- team 등급이면 team_id 필수 (files 의 team_requires_team_id 와 같은 규칙)
    CONSTRAINT folders_team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
-- 같은 부모 안 이름 중복 금지. 루트는 parent_id 가 NULL 이라 하나의 UNIQUE(parent_id, name)
-- 로는 막지 못한다(NULL 은 서로 같지 않다) → 부분 UNIQUE 인덱스 둘로 나눈다.
CREATE UNIQUE INDEX folders_parent_name_uidx ON folders (parent_id, name) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX folders_root_name_uidx   ON folders (name)            WHERE parent_id IS NULL;
CREATE INDEX        folders_parent_idx       ON folders (parent_id);
CREATE INDEX        folders_visible_idx      ON folders (access_level, team_id);

-- ─────────────────────────────────────────────────────────────
-- files — 파일 "정보"만. 실제 바이트는 /var/lib/tbox/storage (ARCH 6.1 · 6.3)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE files (
    id            BIGSERIAL PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),  -- URL 에 노출되는 ID (순차 열거 방지, PRD S-09-5)
    original_name TEXT        NOT NULL,                 -- 사용자가 올린 이름 (화면 표시용, 출력 시 이스케이프)
    stored_name   TEXT        NOT NULL UNIQUE,          -- 서버가 만든 이름: UUID.ext  (경로 없음)
    ext           TEXT        NOT NULL,                 -- 화이트리스트 통과한 확장자
    mime          TEXT        NOT NULL,                 -- finfo 로 판별한 실제 MIME
    size_bytes    BIGINT      NOT NULL CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256        CHAR(64)    NOT NULL,                 -- 무결성 검사용
    description   TEXT        NOT NULL DEFAULT '' CHECK (length(description) <= 500),
    access_level  TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    team_id       BIGINT      REFERENCES teams(id),
    uploaded_by   BIGINT      NOT NULL REFERENCES users(id),
    scan_status   TEXT        NOT NULL DEFAULT 'clean' CHECK (scan_status IN ('pending','clean','infected')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ,                          -- NULL 이면 살아있는 파일
    deleted_by    BIGINT      REFERENCES users(id),
    -- 30일 지난 휴지통 파일의 **실제 바이트**를 배치가 지운 시각 (9단계, PRD S-08, ARCH 11.1).
    -- 행은 남긴다 — 감사 로그가 target_id 로 이 행을 가리키고 있고, stored_name 이 있어야
    -- 저장소에 남은 고아 파일을 나중에 대조할 수 있다 (scripts/consistency_check.php).
    purged_at     TIMESTAMPTZ,
    -- 15-3 (F-22, ARCH 5.13) — 업로드·URL 가져오기 때 검출된 **패턴 이름만** (`rrn`·`card`·`keyword`).
    -- 검출된 숫자·문자열 원문은 어떤 형태로도 들어가지 않는다 (PRD S-07, SECURITY R-58).
    -- 비어 있지 않으면 `public`·`internal` 로 올리거나 낮출 수 없다.
    -- (기존 DB 는 db/migrations/2026-09-12_files_sensitive_flags.sql 로 같은 모양이 된다.)
    sensitive_flags TEXT[]    NOT NULL DEFAULT '{}',
    -- 18-1 (F-26, ARCH 5.16 · 6.2-4) — 이 파일이 든 폴더. NULL 이면 루트.
    -- ON DELETE RESTRICT: 파일이 참조하는 폴더는 DB 가 삭제를 막는다(앱 검사의 마지막 그물).
    -- (기존 DB 는 db/migrations/2026-09-13_folders.sql 로 같은 모양이 된다.)
    folder_id     BIGINT      REFERENCES folders(id) ON DELETE RESTRICT,
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;
-- 휴지통 정리 배치(scripts/purge_trash.php)가 훑는 행만 담는 부분 인덱스 (9단계)
CREATE INDEX files_purge_idx   ON files (deleted_at) WHERE deleted_at IS NOT NULL AND purged_at IS NULL;
-- 18-1 (F-26) — 폴더 안 목록(`GET /api/files?folder=`)과 폴더 삭제 전 "파일이 있나" 조회용
CREATE INDEX files_folder_idx  ON files (folder_id) WHERE deleted_at IS NULL;

-- ─────────────────────────────────────────────────────────────
-- audit_logs — Append-only (ARCH 7.2, PRD S-07)
--   앱 계정에는 SELECT·INSERT 만 준다 → 코드 버그·침입자도 지울 수 없다
-- ─────────────────────────────────────────────────────────────
CREATE TABLE audit_logs (
    id           BIGSERIAL PRIMARY KEY,
    happened_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    request_id   UUID        NOT NULL,
    actor_id     BIGINT,                 -- NULL = 시스템(배치) 또는 미인증
    actor_name   TEXT,
    actor_role   TEXT,                   -- 행동 당시 역할 (나중에 바뀌어도 남김)
    action       TEXT        NOT NULL,   -- auth.login, file.download, file.delete, user.role_change ...
    target_type  TEXT,                   -- file / user / system
    target_id    BIGINT,
    target_name  TEXT,
    access_level TEXT,
    result       TEXT        NOT NULL CHECK (result IN ('allow','deny','error')),
    reason       TEXT,                   -- role_not_permitted / not_owner / mime_mismatch ...
    ip           INET,
    user_agent   TEXT,
    detail       JSONB,                  -- 이전값→새값 등. 비밀번호·토큰·파일내용 금지
    summary      TEXT        NOT NULL    -- 초보자용 한국어 요약 한 문장. 기록 시점에 ARCH 7.4 문장 표로 생성 (2026-09-10)
);
CREATE INDEX audit_time_idx  ON audit_logs (happened_at DESC);
CREATE INDEX audit_actor_idx ON audit_logs (actor_id, happened_at DESC);

-- ─────────────────────────────────────────────────────────────
-- sessions — DB 세션 (ARCH 6.2-2, PRD S-09-2, SECURITY R-10)
--   쿠키에는 random_bytes(32) 의 hex 토큰만, DB 에는 그 SHA-256 만 둔다.
--   ON DELETE CASCADE: 사용자 삭제 시 세션도 사라진다.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE sessions (
    id           CHAR(64)    PRIMARY KEY,           -- 쿠키 토큰(32바이트 난수)의 SHA-256. 토큰 원문은 저장하지 않음
    user_id      BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at   TIMESTAMPTZ NOT NULL,              -- 유휴 30분 / 절대 8시간 (PRD S-09-2)
    ip           INET,
    user_agent   TEXT,
    -- 16-1 (PRD F-23, ARCH 4.0 「관리 기능 재인증 — sudo 모드」): 이 **세션**이 마지막으로
    -- 비밀번호를 다시 확인한 시각. NULL 이면 아직 확인하지 않았다는 뜻이고, 관리 영역
    -- (user.manage·org.manage·audit.read·role:Admin)은 최근 15분 안의 값일 때만 열린다.
    -- 로그인은 이 값을 놓지 않는다 — 관리 영역 첫 진입에 반드시 한 번 묻는다.
    -- (기존 DB 는 db/migrations/2026-09-12_sessions_reauth.sql 로 같은 모양이 된다.)
    reauth_at    TIMESTAMPTZ
);
CREATE INDEX sessions_user_idx ON sessions (user_id);

-- ─────────────────────────────────────────────────────────────
-- share_links — 만료되는 공유 링크 (15-2, PRD F-13, ARCH 5.12 · 6.2-3)
--   토큰(32바이트 난수의 base64url 43자)의 **sha256 만** 저장한다 — 원문은 만들 때
--   응답에 한 번 나가고 서버 어디에도 남지 않는다(sessions 와 같은 방식).
--   회수·횟수는 UPDATE 로만 바뀌고 행은 지우지 않는다 → 앱 계정에 DELETE 권한이 없다.
--   만료 7일·횟수 10회 상한은 앱이 먼저 보지만 DB 도 CHECK 로 마지막 그물을 둔다.
--   (기존 DB 는 db/migrations/2026-09-12_share_links.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE share_links (
    id             BIGSERIAL   PRIMARY KEY,
    token_hash     CHAR(64)    NOT NULL UNIQUE,           -- 토큰의 sha256. 원문은 저장하지 않음
    file_id        BIGINT      NOT NULL REFERENCES files(id),
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    expires_at     TIMESTAMPTZ NOT NULL,
    max_downloads  INT         NOT NULL CHECK (max_downloads BETWEEN 1 AND 10),
    download_count INT         NOT NULL DEFAULT 0 CHECK (download_count >= 0),
    revoked_at     TIMESTAMPTZ,
    revoked_by     BIGINT      REFERENCES users(id),
    reason_text    TEXT        CHECK (reason_text IS NULL OR length(reason_text) <= 500),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT share_links_expiry_max CHECK (expires_at <= created_at + interval '7 days'),
    CONSTRAINT share_links_expiry_min CHECK (expires_at > created_at),
    CONSTRAINT share_links_count_max  CHECK (download_count <= max_downloads)
);
-- token_hash 의 UNIQUE 제약이 조회용 인덱스를 겸한다 (`WHERE token_hash = …` 가 유일한 조회 경로)
CREATE INDEX share_links_created_by_idx ON share_links (created_by);
CREATE INDEX share_links_file_idx       ON share_links (file_id);

-- ═════════════════════════════════════════════════════════════
-- 구매·재고 모듈 기초정보 (19단계 — PRD F-28 · F-29, ARCH 5.18,
--   docs/purchase/03-architecture-draft.md 3장)
--
-- 표 접두는 전부 `p_` 다 — 본체 표와 이름이 섞이지 않는다 (ARCH 5.18 「경계」).
-- **삭제가 없다**: 그만 쓰는 것은 `is_active = false` 이고, 앱 계정에는 이 표들에
-- DELETE 권한 자체가 없다 (db/grants.sql).
-- 전표(p_docs · p_doc_lines · p_doc_seq)와 내 발주 리스트(p_lists · p_list_items)는 20단계,
-- 재고 원장(p_stock_moves)과 뷰 둘(p_stock_balance · p_backorder)은 21단계에 이 파일 끝에 붙었다.
-- (기존 DB 는 db/migrations/2026-09-13_purchase_masters.sql · 2026-09-14_purchase_docs.sql ·
--  2026-09-14_purchase_stock.sql 로 같은 모양이 된다.)
-- ═════════════════════════════════════════════════════════════

CREATE TABLE p_brands (
    id         BIGSERIAL   PRIMARY KEY,
    name       TEXT        NOT NULL,
    -- 회사 정보 (사용자 Q-18 확정 2026-09-13, ARCH 5.18) — **발주서 PDF 머리 정보의 출처**다.
    -- 20단계의 발주서 PDF 가 이 다섯 칸을 읽는다. 19단계에서는 저장·표시만 한다.
    --   logo_file_id  files 의 이미지 한 건(회사 로고). NULL 이면 로고 없이 낸다
    --   biz_no · ceo_name · address · tel  — **회사** 정보이며 개인정보가 아니다.
    --   그래서 변경 이력(감사 detail.before/after)에 값이 그대로 남아도 된다.
    -- 엑셀 가져오기 대상이 아니다(화면에서만 고친다) — 회사 정보는 대량 갱신할 값이 아니다.
    logo_file_id BIGINT     REFERENCES files(id),
    biz_no     TEXT,
    ceo_name   TEXT,
    address    TEXT,
    tel        TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_brands_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE UNIQUE INDEX p_brands_name_uidx ON p_brands (lower(name));

CREATE TABLE p_branches (
    id                    BIGSERIAL   PRIMARY KEY,
    brand_id              BIGINT      NOT NULL REFERENCES p_brands(id),
    name                  TEXT        NOT NULL,
    -- 이카운트 창고/사업장 코드 (선택값, 유일 제약 없음 — 지점은 수가 적고 사람이 만든다).
    -- 19단계 2차 보정 2026-09-13: 창고 파일에서 지점을 파생 등록할 때 함께 담는다.
    code                  TEXT,
    warehouse_location_id BIGINT,     -- FK 는 p_locations 뒤에 붙인다 (서로 참조)
    central_location_id   BIGINT,
    is_active             BOOLEAN     NOT NULL DEFAULT true,
    created_by            BIGINT      NOT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_branches_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE UNIQUE INDEX p_branches_brand_name_uidx ON p_branches (brand_id, lower(name));
CREATE INDEX p_branches_brand_idx ON p_branches (brand_id);

-- 장소 = 지점 × 용도. legacy_name 에 이카운트 원본 이름을 보존한다 (시안 ⑭).
CREATE TABLE p_locations (
    id          BIGSERIAL   PRIMARY KEY,
    branch_id   BIGINT      NOT NULL REFERENCES p_branches(id),
    kind        TEXT        NOT NULL CHECK (kind IN ('warehouse','central','or','other')),
    name        TEXT        NOT NULL,
    code        TEXT,
    legacy_name TEXT,
    is_active   BOOLEAN     NOT NULL DEFAULT true,
    created_by  BIGINT      NOT NULL REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_locations_name_len CHECK (length(name) BETWEEN 1 AND 80)
);
-- ★ 지점 격리(SECURITY R-65)가 훑는 인덱스 — Authz::purchaseScopeWhere 의 조건이 여기 닿는다
CREATE INDEX p_locations_branch_idx ON p_locations (branch_id);
CREATE UNIQUE INDEX p_locations_branch_name_uidx ON p_locations (branch_id, lower(name));

ALTER TABLE p_branches ADD CONSTRAINT p_branches_warehouse_fkey
    FOREIGN KEY (warehouse_location_id) REFERENCES p_locations(id);
ALTER TABLE p_branches ADD CONSTRAINT p_branches_central_fkey
    FOREIGN KEY (central_location_id) REFERENCES p_locations(id);

-- users.branch_id 의 FK (users 를 먼저 만들었으므로 여기서 붙인다)
ALTER TABLE users ADD CONSTRAINT users_branch_id_fkey
    FOREIGN KEY (branch_id) REFERENCES p_branches(id);
CREATE INDEX users_purchase_role_idx ON users (purchase_role) WHERE purchase_role IS NOT NULL;

-- 거래처 — 지점 무관 마스터다(전 지점이 같은 거래처를 쓴다)
--
-- ★ 거래처의 **정체는 이름이 아니라 코드**다 (19단계 2차 보정 2026-09-13).
--   실제 이카운트 거래처 파일에는 이름이 같고 코드가 다른 거래처가 있다(같은 상호로 사업자를
--   나눈 경우). 이름에 유일 제약을 걸면 그런 행 때문에 184행짜리 가져오기가 통째로 롤백된다.
--   그래서 `code` 만 유일하고, 같은 이름은 **앱이 「확인 필요」로 걸러** 사람이 판단한다
--   (Importer::judgeVendor · 화면 수동 등록의 409 + 「그래도 등록」).
--   (기존 DB 는 db/migrations/2026-09-13_purchase_vendor_identity.sql 로 같은 모양이 된다.)
--
--   ceo_name · tel — 이카운트 「대표자명」·「전화」. **회사 정보**이며 담당자 개인 연락처가
--   아니다(SECURITY R-69 저장 최소화).
CREATE TABLE p_vendors (
    id         BIGSERIAL   PRIMARY KEY,
    code       TEXT,
    name       TEXT        NOT NULL,
    biz_no     TEXT,
    ceo_name   TEXT,
    tel        TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_vendors_name_len CHECK (length(name) BETWEEN 1 AND 120)
);
-- 이름은 **유일하지 않다**(위 ★). 조회 경로(가져오기의 이름 대조)를 위해 인덱스만 둔다.
CREATE INDEX        p_vendors_name_idx  ON p_vendors (lower(name));
CREATE UNIQUE INDEX p_vendors_code_uidx ON p_vendors (code) WHERE code IS NOT NULL;

-- 분류 대 → 중 → 소 (3단). 지점 무관 마스터.
CREATE TABLE p_categories (
    id         BIGSERIAL   PRIMARY KEY,
    parent_id  BIGINT      REFERENCES p_categories(id),
    name       TEXT        NOT NULL,
    level      INT         NOT NULL CHECK (level BETWEEN 1 AND 3),
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_categories_name_len        CHECK (length(name) BETWEEN 1 AND 80),
    CONSTRAINT p_categories_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    CONSTRAINT p_categories_level_parent
        CHECK ((level = 1 AND parent_id IS NULL) OR (level > 1 AND parent_id IS NOT NULL))
);
CREATE UNIQUE INDEX p_categories_parent_name_uidx
    ON p_categories (parent_id, lower(name)) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX p_categories_root_name_uidx
    ON p_categories (lower(name)) WHERE parent_id IS NULL;

-- 품목 — code 는 거래처 카탈로그 번호 그대로, 거래처 안에서만 유일하다.
-- price_in 은 VAT **별도** 공급가액 기준 (docs/purchase/02-prd-draft.md 가정 A5).
CREATE TABLE p_items (
    id            BIGSERIAL   PRIMARY KEY,
    brand_id      BIGINT      REFERENCES p_brands(id),
    vendor_id     BIGINT      NOT NULL REFERENCES p_vendors(id),
    code          TEXT        NOT NULL,
    name          TEXT        NOT NULL,
    spec          TEXT,
    unit          TEXT,
    pack_qty      INT         NOT NULL DEFAULT 1 CHECK (pack_qty >= 1),
    cat1_id       BIGINT      REFERENCES p_categories(id),
    cat2_id       BIGINT      REFERENCES p_categories(id),
    cat3_id       BIGINT      REFERENCES p_categories(id),
    stock_managed BOOLEAN     NOT NULL DEFAULT true,
    safety_qty    INT         NOT NULL DEFAULT 0 CHECK (safety_qty >= 0),
    lead_days     INT         NOT NULL DEFAULT 0 CHECK (lead_days >= 0),
    min_order_qty INT         NOT NULL DEFAULT 1 CHECK (min_order_qty >= 1),
    price_in      NUMERIC(14,2) CHECK (price_in IS NULL OR price_in >= 0),
    barcode       TEXT,
    is_active     BOOLEAN     NOT NULL DEFAULT true,
    created_by    BIGINT      NOT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_items_code_len CHECK (length(code) BETWEEN 1 AND 60),
    CONSTRAINT p_items_name_len CHECK (length(name) BETWEEN 1 AND 200)
);
CREATE UNIQUE INDEX p_items_vendor_code_uidx ON p_items (vendor_id, code);
-- 품목 찾기 API(PRD T-43, 1.35만 행에서 200ms)가 훑는 인덱스들
CREATE INDEX p_items_code_idx    ON p_items (code);
CREATE INDEX p_items_name_idx    ON p_items (lower(name));
CREATE INDEX p_items_cat_idx     ON p_items (cat1_id, cat2_id, cat3_id);
CREATE INDEX p_items_vendor_idx  ON p_items (vendor_id);
CREATE INDEX p_items_barcode_idx ON p_items (barcode) WHERE barcode IS NOT NULL;

-- 품목 × 사용 지점 (비우면 브랜드 전체). ★ 품목 검색의 지점 격리가 지나는 표.
CREATE TABLE p_item_branches (
    item_id   BIGINT NOT NULL REFERENCES p_items(id),
    branch_id BIGINT NOT NULL REFERENCES p_branches(id),
    -- 20단계: 사용 지점 「빼기」. 이 표에도 DELETE 가 없으므로 빼기는 is_active = false 다.
    is_active BOOLEAN NOT NULL DEFAULT true,
    PRIMARY KEY (item_id, branch_id)
);
CREATE INDEX p_item_branches_branch_idx ON p_item_branches (branch_id);

-- 장소별 인가량. effective_from 이 있어 "언제부터의 인가량인가" 가 남는다.
CREATE TABLE p_par_levels (
    id             BIGSERIAL   PRIMARY KEY,
    location_id    BIGINT      NOT NULL REFERENCES p_locations(id),
    item_id        BIGINT      NOT NULL REFERENCES p_items(id),
    qty            INT         NOT NULL CHECK (qty >= 0),
    effective_from DATE        NOT NULL DEFAULT CURRENT_DATE,
    is_active      BOOLEAN     NOT NULL DEFAULT true,
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX p_par_levels_uidx ON p_par_levels (location_id, item_id, effective_from);
CREATE INDEX p_par_levels_item_idx ON p_par_levels (item_id);

-- 엑셀 가져오기 열 매핑 (F-29). mapping_json 은 {엑셀 열 → 우리 칸} 한 겹 객체이며
-- 파일 내용은 들어가지 않는다.
CREATE TABLE p_import_mappings (
    id           BIGSERIAL   PRIMARY KEY,
    kind         TEXT        NOT NULL CHECK (kind IN ('items','vendors','locations')),
    name         TEXT        NOT NULL,
    mapping_json JSONB       NOT NULL,
    -- 머리행이 몇 번째 행인가 (0기준). 이카운트 내보내기처럼 0행이 제목 한 칸이고 1행이
    -- 진짜 머리행인 파일이 있다 — 매핑만 저장하고 이 값을 빼면 같은 양식을 다시 올릴 때마다
    -- 머리행을 또 골라야 한다 (19단계 1차 보정 2026-09-13, db/migrations/…_import_header.sql).
    -- 범위는 앱의 Importer::HEAD_ROWS(앞 20행을 훑는다)와 같다.
    header_row   SMALLINT    NOT NULL DEFAULT 0 CHECK (header_row BETWEEN 0 AND 19),
    is_active    BOOLEAN     NOT NULL DEFAULT true,
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_import_mappings_name_len CHECK (length(name) BETWEEN 1 AND 80)
);
CREATE UNIQUE INDEX p_import_mappings_kind_name_uidx ON p_import_mappings (kind, lower(name));

-- 창고 대기표 (24단계 — PRD F-37, ARCH 5.18 「창고 대기표」,
--   db/migrations/2026-09-15_purchase_location_inbox.sql)
--
-- ★ **지점이 정해지기 전의 창고**가 머무는 자리다. `p_locations.branch_id` 는 NOT NULL 이고
--   지점 격리·전표·재고가 전부 그 열로 훑기 때문에, 지점 없는 창고를 본 표에 넣으려면 그 열을
--   NULL 허용으로 풀어야 한다 — 그 대신 연결되기 전의 창고를 **본 표 밖**에 두고, 사람이
--   지점을 고르는 순간에만 `p_locations` 행을 만든다(전표·재고 코드는 한 줄도 바뀌지 않는다).
--   이름은 이카운트 원문 그대로이고(trim 만), **행을 지우지 않는다** — 제외도 상태값이다.
CREATE TABLE p_location_inbox (
    id             BIGSERIAL   PRIMARY KEY,
    ecount_code    TEXT        NOT NULL,               -- 창고코드 (원문 trim) — 이 행의 정체
    name           TEXT        NOT NULL,               -- 창고명 원문 그대로
    kind_text      TEXT,                               -- 「구분」 원문 (창고·기타 …)
    site_name      TEXT,                               -- 「추가사업장명」 원문 (표시용)
    ecount_active  BOOLEAN     NOT NULL DEFAULT true,  -- 「사용」 Yes/No
    status         TEXT        NOT NULL DEFAULT 'unlinked'
                   CHECK (status IN ('unlinked','linked','excluded')),
    location_id    BIGINT      REFERENCES p_locations(id),   -- linked 일 때만
    source_file_id BIGINT      REFERENCES files(id),         -- 마지막 업로드 원본
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    linked_by      BIGINT      REFERENCES users(id),
    linked_at      TIMESTAMPTZ,
    CONSTRAINT p_location_inbox_name_len CHECK (length(name) BETWEEN 1 AND 80),
    CONSTRAINT p_location_inbox_code_len CHECK (length(ecount_code) BETWEEN 1 AND 40)
);
-- 같은 창고코드는 한 행뿐 — 같은 파일을 다시 올리면 「변동 없음」이 되는 근거(멱등)
CREATE UNIQUE INDEX p_location_inbox_code_uidx   ON p_location_inbox (ecount_code);
CREATE INDEX        p_location_inbox_status_idx  ON p_location_inbox (status);

-- ═════════════════════════════════════════════════════════════
-- 구매 전표 (20단계 — PRD F-30 발주요청 · F-31 추천/내 리스트 · F-32 불러오기→발주서,
--   ARCH 5.18 「연결」·「채번」·「요청 수정 창」·「발주서 PDF」·「송부 전 검토」)
--
-- **삭제가 없다**: 전표 취소는 `status = 'void'` 이고, 앱 계정에는 DELETE 권한이 없다.
-- 연결은 `p_doc_lines.src_line_id` 하나이며 미발주 잔량은 컬럼이 아니라 계산값이다.
-- (기존 DB 는 db/migrations/2026-09-14_purchase_docs.sql 로 같은 모양이 된다.)
-- ═════════════════════════════════════════════════════════════

CREATE TABLE p_docs (
    id             BIGSERIAL   PRIMARY KEY,
    doc_type       TEXT        NOT NULL CHECK (doc_type IN ('pr','po','gr','mv','is','adj')),
    doc_no         TEXT        NOT NULL,
    doc_date       DATE        NOT NULL DEFAULT CURRENT_DATE,
    branch_id      BIGINT      NOT NULL REFERENCES p_branches(id),
    vendor_id      BIGINT      REFERENCES p_vendors(id),
    from_location_id BIGINT    REFERENCES p_locations(id),  -- 21단계 이동·사용·실사의 보내는 장소
    to_location_id BIGINT      REFERENCES p_locations(id),
    manager_id     BIGINT      NOT NULL REFERENCES users(id),
    status         TEXT        NOT NULL DEFAULT 'unconfirmed'
                   CHECK (status IN ('unconfirmed','in_progress','partial','done','void')),
    memo           TEXT,
    header_json    JSONB,                      -- 발주서 머리 (Q-18②) — 서버가 채운다
    split_from_id  BIGINT      REFERENCES p_docs(id),   -- 요청 분리 출처 (Q-18①)
    sent_at        TIMESTAMPTZ,                -- 「보냄」 (Q-18④) — 한 번만
    sent_by        BIGINT      REFERENCES users(id),
    count_cycle    TEXT,                       -- 21단계 실사 주기 daily|weekly|monthly (Q-18③)
    closed_month   TEXT,                       -- 월 마감 (22단계)
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_docs_memo_len  CHECK (memo IS NULL OR length(memo) <= 500),
    CONSTRAINT p_docs_month_fmt CHECK (closed_month IS NULL OR closed_month ~ '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT p_docs_count_cycle_check
        CHECK (count_cycle IS NULL OR count_cycle IN ('daily','weekly','monthly'))
);
CREATE UNIQUE INDEX p_docs_doc_no_uidx ON p_docs (doc_no);
CREATE INDEX p_docs_branch_type_status_idx ON p_docs (branch_id, doc_type, status);
CREATE INDEX p_docs_manager_idx ON p_docs (manager_id);
CREATE INDEX p_docs_vendor_idx  ON p_docs (vendor_id);
CREATE INDEX p_docs_date_idx    ON p_docs (doc_date);
CREATE INDEX p_docs_from_loc_idx ON p_docs (from_location_id);   -- 21단계
CREATE INDEX p_docs_to_loc_idx   ON p_docs (to_location_id);     -- 21단계

CREATE TABLE p_doc_lines (
    id          BIGSERIAL   PRIMARY KEY,
    doc_id      BIGINT      NOT NULL REFERENCES p_docs(id),
    line_no     INT         NOT NULL CHECK (line_no >= 1),
    item_id     BIGINT      NOT NULL REFERENCES p_items(id),
    qty         NUMERIC(14,3) NOT NULL,     -- 21단계: 실사 줄의 「장부 스냅샷」은 0 일 수 있다
    unit_price  NUMERIC(14,2) CHECK (unit_price IS NULL OR unit_price >= 0),  -- 서버가 p_items 에서
    amount      NUMERIC(16,2) CHECK (amount IS NULL OR amount >= 0),          -- 서버 계산 (R-66)
    remark      TEXT,
    src_line_id BIGINT      REFERENCES p_doc_lines(id),   -- 발주서 줄 → 요청 줄
    locked_at   TIMESTAMPTZ,                              -- 담긴 시각 (Q-18① 수정 창)
    status      TEXT        NOT NULL DEFAULT 'unconfirmed'
                CHECK (status IN ('unconfirmed','ordered','done','void')),
    lot_no      TEXT,                                     -- 21단계 입고 줄의 로트
    expiry_date DATE,                                     -- 21단계 입고 줄의 유효기간
    counted_qty NUMERIC(14,3),                            -- 21단계 실사 줄의 실제 센 수량
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_doc_lines_remark_len CHECK (remark IS NULL OR length(remark) <= 200),
    CONSTRAINT p_doc_lines_doc_line_uniq UNIQUE (doc_id, line_no),
    -- 21단계: 수량은 0 이상이고, **0 은 실사 줄(counted_qty 가 있는 줄)에만** 허용된다.
    CONSTRAINT p_doc_lines_qty_nonneg   CHECK (qty >= 0),
    CONSTRAINT p_doc_lines_qty_positive CHECK (qty > 0 OR counted_qty IS NOT NULL),
    CONSTRAINT p_doc_lines_lot_len      CHECK (lot_no IS NULL OR length(lot_no) BETWEEN 1 AND 60),
    CONSTRAINT p_doc_lines_counted_check CHECK (counted_qty IS NULL OR counted_qty >= 0)
);
CREATE INDEX p_doc_lines_doc_idx  ON p_doc_lines (doc_id);
CREATE INDEX p_doc_lines_src_idx  ON p_doc_lines (src_line_id);
CREATE INDEX p_doc_lines_item_idx ON p_doc_lines (item_id);

-- 채번 — 하루·종류마다 한 행. Doc::nextDocNo() 가 이 행을 잠그고 1 올린다.
CREATE TABLE p_doc_seq (
    doc_type TEXT NOT NULL CHECK (doc_type IN ('pr','po','gr','mv','is','adj')),
    seq_date DATE NOT NULL,
    last_no  INT  NOT NULL DEFAULT 0 CHECK (last_no >= 0),
    PRIMARY KEY (doc_type, seq_date)
);

-- 내 발주 리스트 (F-31, 시안 ③). 남의 리스트는 공유된 것만 보인다 (SECURITY R-68).
CREATE TABLE p_lists (
    id         BIGSERIAL   PRIMARY KEY,
    owner_id   BIGINT      NOT NULL REFERENCES users(id),
    branch_id  BIGINT      REFERENCES p_branches(id),
    name       TEXT        NOT NULL,
    is_shared  BOOLEAN     NOT NULL DEFAULT false,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_lists_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE INDEX p_lists_owner_idx  ON p_lists (owner_id);
CREATE INDEX p_lists_branch_idx ON p_lists (branch_id);

CREATE TABLE p_list_items (
    list_id     BIGINT NOT NULL REFERENCES p_lists(id),
    item_id     BIGINT NOT NULL REFERENCES p_items(id),
    default_qty NUMERIC(14,3) NOT NULL DEFAULT 1 CHECK (default_qty > 0),
    sort_no     INT    NOT NULL DEFAULT 1 CHECK (sort_no >= 1),
    is_active   BOOLEAN NOT NULL DEFAULT true,   -- 「빼기」는 삭제가 아니다
    PRIMARY KEY (list_id, item_id)
);
CREATE INDEX p_list_items_item_idx ON p_list_items (item_id);

-- 줄 연결 사슬 — src_line_id 를 따라 내려가는 재귀 CTE (ARCH 5.18 「연결」).
-- 권한 조건은 없다: 이 뷰를 쓰는 SQL 이 p_docs 를 조인해 purchaseScopeWhere 로 건다.
CREATE OR REPLACE VIEW p_line_chain AS
WITH RECURSIVE chain AS (
    SELECT l.id AS root_id, l.id, l.src_line_id, l.doc_id, 0 AS depth
      FROM p_doc_lines l
     WHERE l.src_line_id IS NULL
    UNION ALL
    SELECT c.root_id, l.id, l.src_line_id, l.doc_id, c.depth + 1
      FROM p_doc_lines l
      JOIN chain c ON l.src_line_id = c.id
)
SELECT root_id, id AS line_id, src_line_id, doc_id, depth FROM chain;

-- ═════════════════════════════════════════════════════════════
-- 재고 원장 (21단계 — PRD F-33 입고·이동·사용·실사 · F-34 현황,
--   ARCH 5.18 「원장」·「음수 재고」, SECURITY R-66)
--
-- ★ **추가만 하는 표**다. 앱 계정에는 INSERT·SELECT 뿐이고 UPDATE·DELETE 가 없다
--   (db/grants.sql). 취소·정정은 반대 부호 행 + reverses_move_id 다.
-- ★ 현재고는 컬럼이 아니라 **합**이다 — 뷰 p_stock_balance.
-- ★ 원장에 INSERT 하는 코드는 src/purchase/Stock.php 하나뿐이고,
--   scripts/security_lint.sh 규칙 (e) 가 그것을 강제한다.
-- (기존 DB 는 db/migrations/2026-09-14_purchase_stock.sql 로 같은 모양이 된다.)
-- ═════════════════════════════════════════════════════════════

CREATE TABLE p_stock_moves (
    id               BIGSERIAL   PRIMARY KEY,
    doc_line_id      BIGINT      NOT NULL REFERENCES p_doc_lines(id),   -- 근거 없는 변화는 없다
    location_id      BIGINT      NOT NULL REFERENCES p_locations(id),
    item_id          BIGINT      NOT NULL REFERENCES p_items(id),
    qty_delta        NUMERIC(14,3) NOT NULL CHECK (qty_delta <> 0),     -- + 들어옴 / − 나감
    moved_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    reverses_move_id BIGINT      REFERENCES p_stock_moves(id),          -- 되돌린 원본 행
    created_by       BIGINT      NOT NULL REFERENCES users(id)
);
CREATE INDEX p_stock_moves_loc_item_idx  ON p_stock_moves (location_id, item_id);
CREATE INDEX p_stock_moves_line_idx      ON p_stock_moves (doc_line_id);
CREATE INDEX p_stock_moves_item_time_idx ON p_stock_moves (item_id, moved_at);
-- 같은 행을 두 번 되돌리지 못하게 (앱도 막지만 DB 가 마지막으로 막는다)
CREATE UNIQUE INDEX p_stock_moves_reverse_uidx
    ON p_stock_moves (reverses_move_id) WHERE reverses_move_id IS NOT NULL;

-- 현재고 = 원장의 합 (ARCH 5.18 불변 규칙 2)
CREATE OR REPLACE VIEW p_stock_balance AS
SELECT location_id, item_id, sum(qty_delta) AS qty
  FROM p_stock_moves
 GROUP BY location_id, item_id;

-- 미입고 = 「보낸」 발주서 줄 − 그 줄을 출처로 하는 입고 줄 (PRD F-34 R6).
-- 권한 조건은 없다: 이 뷰를 쓰는 SQL 이 p_docs 를 조인해 purchaseScopeWhere 로 건다.
CREATE OR REPLACE VIEW p_backorder AS
SELECT l.id           AS po_line_id,
       l.doc_id       AS po_doc_id,
       d.doc_no       AS po_doc_no,
       d.doc_date     AS po_date,
       d.sent_at      AS sent_at,
       d.branch_id    AS branch_id,
       d.vendor_id    AS vendor_id,
       d.to_location_id AS to_location_id,
       d.manager_id   AS manager_id,
       l.item_id      AS item_id,
       l.qty          AS ordered_qty,
       COALESCE(g.received, 0)         AS received_qty,
       l.qty - COALESCE(g.received, 0) AS remaining_qty,
       l.unit_price   AS unit_price
  FROM p_doc_lines l
  JOIN p_docs      d ON d.id = l.doc_id
  LEFT JOIN LATERAL (
        SELECT sum(c.qty) AS received
          FROM p_doc_lines c
          JOIN p_docs      cd ON cd.id = c.doc_id
         WHERE c.src_line_id = l.id
           AND cd.doc_type = 'gr'
           AND c.status  <> 'void'
           AND cd.status <> 'void'
       ) g ON true
 WHERE d.doc_type = 'po'
   AND d.status  <> 'void'
   AND l.status  <> 'void'
   AND d.sent_at IS NOT NULL
   AND l.qty - COALESCE(g.received, 0) > 0;

-- ═════════════════════════════════════════════════════════════
-- 22단계 — 거래처 월 대사 · 월 마감 (PRD F-35, ARCH 5.18 「마감」, SECURITY R-69)
--
-- ★ R-69 — 명세서에서 가져오는 값은 아래 p_recon_lines 의 **품목·수량·단가·금액**이 전부다.
--   담당자 연락처·계좌번호를 담을 컬럼이 아예 없다. 원본 파일은 files 의 admin-only 폴더
--   「구매/명세서/거래처/월」에만 남는다.
-- ★ 앱 계정에 **DELETE 가 없다**(db/grants.sql). 대사를 다시 하려면 명세서를 다시 올리고,
--   마감을 되돌리는 길은 코드에도 권한에도 없다.
-- (기존 DB 는 db/migrations/2026-09-14_purchase_recon.sql 로 같은 모양이 된다.)
-- ═════════════════════════════════════════════════════════════

-- 거래처가 보내온 명세서 한 장 (엑셀·CSV·PDF 텍스트)
CREATE TABLE p_recon_statements (
    id           BIGSERIAL   PRIMARY KEY,
    vendor_id    BIGINT      NOT NULL REFERENCES p_vendors(id),
    month        CHAR(7)     NOT NULL,                  -- 'YYYY-MM'
    file_id      BIGINT      NOT NULL REFERENCES files(id),
    mapping_json JSONB,                                 -- 확정에 쓴 열 매핑 (다음 달 재사용)
    total_amount NUMERIC(16,2),                         -- **명세서 쪽** 합계 (파일에 적힌 값)
    status       TEXT        NOT NULL DEFAULT 'open',
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_recon_statements_month_fmt    CHECK (month ~ '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT p_recon_statements_status_check CHECK (status IN ('open','closed')),
    CONSTRAINT p_recon_statements_total_check  CHECK (total_amount IS NULL OR total_amount >= 0),
    CONSTRAINT p_recon_statements_uniq         UNIQUE (vendor_id, month, file_id)
);
CREATE INDEX p_recon_statements_month_idx ON p_recon_statements (month, vendor_id);

-- 명세서 줄 ↔ 우리 입고 줄. diff_type 이 판정이다 (none·qty·price·missing_ours·missing_theirs)
CREATE TABLE p_recon_lines (
    id              BIGSERIAL   PRIMARY KEY,
    statement_id    BIGINT      NOT NULL REFERENCES p_recon_statements(id),
    row_no          INT         NOT NULL CHECK (row_no >= 1),
    item_code       TEXT,
    item_name       TEXT        NOT NULL,
    qty             NUMERIC(14,3) NOT NULL,
    unit_price      NUMERIC(14,2),
    amount          NUMERIC(16,2),
    matched_line_id BIGINT      REFERENCES p_doc_lines(id),
    diff_type       TEXT,
    note            TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_recon_lines_code_len CHECK (item_code IS NULL OR length(item_code) BETWEEN 1 AND 60),
    CONSTRAINT p_recon_lines_name_len CHECK (length(item_name) BETWEEN 1 AND 200),
    CONSTRAINT p_recon_lines_note_len CHECK (note IS NULL OR length(note) <= 200),
    CONSTRAINT p_recon_lines_diff_check
        CHECK (diff_type IS NULL
               OR diff_type IN ('none','qty','price','missing_ours','missing_theirs')),
    CONSTRAINT p_recon_lines_row_uniq UNIQUE (statement_id, row_no)
);
CREATE INDEX p_recon_lines_stmt_idx    ON p_recon_lines (statement_id);
CREATE INDEX p_recon_lines_matched_idx ON p_recon_lines (matched_line_id);

-- 월 마감. branch_id IS NULL = 전사 마감(있으면 지점 것도 잠긴다 — 판정은 Doc::closeGuard)
CREATE TABLE p_month_close (
    id          BIGSERIAL   PRIMARY KEY,
    branch_id   BIGINT      REFERENCES p_branches(id),
    month       CHAR(7)     NOT NULL,
    closed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_by   BIGINT      NOT NULL REFERENCES users(id),
    reason_text TEXT,
    CONSTRAINT p_month_close_month_fmt  CHECK (month ~ '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT p_month_close_reason_len CHECK (reason_text IS NULL OR length(reason_text) <= 300),
    CONSTRAINT p_month_close_uniq       UNIQUE (branch_id, month)
);
-- 전사 마감은 UNIQUE 가 NULL 을 같은 값으로 보지 않으므로 부분 인덱스로 한 달에 하나만.
CREATE UNIQUE INDEX p_month_close_all_uidx ON p_month_close (month) WHERE branch_id IS NULL;
CREATE INDEX p_month_close_month_idx ON p_month_close (month);
