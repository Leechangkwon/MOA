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
    mfa_recovery_required BOOLEAN NOT NULL DEFAULT false, -- explicit administrator recovery, distinct from optional invitation setup
    password_changed_at  TIMESTAMPTZ,
    -- 임시 비밀번호 유효기간 (SECURITY R-39 · 보안 넷 ④ 2026-09-26, migrations/2026-10-04_users_temp_password_issued_at.sql).
    --   Admin 이 임시 비밀번호를 발급한 때(계정 만들기·재발급). 본인이 비밀번호를 바꾸면 NULL.
    --   must_change_password 이고 72시간이 지났으면 로그인 401(auth.login deny temp_expired). NULL = 만료 없음(시드·이전 발급)
    temp_password_issued_at TIMESTAMPTZ,
    -- 5-A″ (2026-09-23, migrations/2026-09-23_users_display_name.sql) — 사람이 알아보는 **이름**.
    --   NULL 이 기본(이름 없으면 화면은 아이디만). 유일하지 않다(동명이인) — 화면은 언제나 아이디를 함께 보인다.
    --   쓰기는 Admin 의 계정 관리 창구뿐. 아래 CHECK 은 앱 검증(1~40자·앞뒤 공백·제어 문자)의 뒷받침이다.
    display_name         TEXT,
    CONSTRAINT users_display_name_shape CHECK (
        display_name IS NULL
        OR (    char_length(display_name) BETWEEN 1 AND 40
            AND display_name = btrim(display_name)
            AND display_name !~ '[[:cntrl:]]')
    ),
    -- 커뮤니티 7-D3 (2026-09-27, migrations/2026-10-08_users_high_contrast.sql) — 화면 취향 「고대비 화면」(DESIGN.md 2.16).
    --   기본 끔(false). 켜면 셸·런처가 <html data-contrast="high"> 를 그린다. 쓰기는 본인 창구 PATCH /api/me/settings 하나.
    --   **권한 판단에 쓰지 않는다** — 세션 조회(Auth::SQL_LOOKUP)가 함께 읽을 뿐이다. 표 단위 권한에 포함(열 단위 GRANT 0).
    high_contrast        BOOLEAN     NOT NULL DEFAULT false,
    invited_account     BOOLEAN     NOT NULL DEFAULT false,
    -- Guest 는 만료일 필수 (PRD 3장 Guest 생명주기, SECURITY R-12).
    -- 앱 코드가 한 군데라도 검사를 빠뜨려도 만료 없는 Guest 행은 존재할 수 없다.
    CONSTRAINT guest_requires_expires_at
        CHECK (role <> 'Guest' OR expires_at IS NOT NULL)
);

-- ─────────────────────────────────────────────────────────────
-- permissions — 권한 매트릭스를 "데이터"로 (ARCH 6.2-1)
--   앱 계정은 이 표에 SELECT 만 가진다 → 화면·API 로는 권한 표를 바꿀 수 없다(권한 상승 경로 차단)
--   16행 시드는 db/seed.sql (+ G-0 「개인」 4행 = 20행 — 매트릭스 화면·편집은 개인 줄을 빼고 16칸만 다룬다)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE permissions (
    role         TEXT NOT NULL CHECK (role IN ('Admin','Manager','Employee','Guest')),
    access_level TEXT NOT NULL CONSTRAINT permissions_access_level_check
                 CHECK (access_level IN ('public','internal','team','admin-only','private')),
    can_read     BOOLEAN NOT NULL DEFAULT false,   -- 목록·다운로드 (team 은 "같은 팀이면" 의 전제)
    can_upload   BOOLEAN NOT NULL DEFAULT false,   -- 이 레벨로 올릴 수 있나
    PRIMARY KEY (role, access_level),
    -- G-0 (PRD F-73) — 「개인」 등급은 표가 아니라 **사람**(주인·받은 권한)으로 판정한다. 읽기 칸은 언제나 거짓이다 —
    -- 권한 매트릭스 편집(F-25, tbox_perm)으로도 켤 수 없다(켜면 그 역할 전원이 남의 개인 파일을 읽는다).
    CONSTRAINT permissions_private_by_people CHECK (access_level <> 'private' OR can_read = false)
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
    access_level TEXT        NOT NULL CONSTRAINT folders_access_level_check
                             CHECK (access_level IN ('public','internal','team','admin-only','private')),
    team_id      BIGINT      REFERENCES teams(id),
    created_by   BIGINT      NOT NULL REFERENCES users(id),   -- 관리 범위 판정 (decideFolderChange) · G-0 개인 폴더의 주인
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT folders_name_len        CHECK (length(name) BETWEEN 1 AND 50),
    CONSTRAINT folders_depth_range     CHECK (depth BETWEEN 1 AND 5),
    CONSTRAINT folders_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    -- team 등급이면 team_id 필수 (files 의 team_requires_team_id 와 같은 규칙)
    CONSTRAINT folders_team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL),
    -- G-0 (PRD F-73) — 「개인」 은 팀이 없다 (files_private_no_team 과 같은 규칙)
    CONSTRAINT folders_private_no_team
        CHECK (access_level <> 'private' OR team_id IS NULL)
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
    access_level  TEXT        NOT NULL CONSTRAINT files_access_level_check
                              CHECK (access_level IN ('public','internal','team','admin-only','private')),
    team_id       BIGINT      REFERENCES teams(id),
    uploaded_by   BIGINT      NOT NULL REFERENCES users(id),   -- G-0 개인 파일의 주인
    scan_status   TEXT        NOT NULL DEFAULT 'clean' CHECK (scan_status IN ('pending','clean','infected')),
    -- F-65 (ARCH 5.29) — 실행 파일이 든 압축본을 관리자가 확인 문구·비밀번호·OTP 로 확인하고
    -- 올린 파일이면 참. 참이면 공유 링크·프로그램 토큰·미리보기·등급 변경이 막히고, 받을 때도
    -- 요청마다 비밀번호+OTP 를 묻는다(`POST /api/files/{id}/exec-download`).
    -- 업로드 트랜잭션에서만 참이 되고 다시 거짓이 되지 않는다.
    -- (기존 DB 는 db/migrations/2026-09-21_files_exec_content.sql 로 같은 모양이 된다.)
    exec_content  BOOLEAN     NOT NULL DEFAULT false,
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
    -- 대기열 5b (Q:F-2 J-2) — 이름 + 설명의 검색 색인. **파생값**(src/SearchText.php 한글 두 글자 조각 + 한 글자)이고
    -- 앱이 올릴 때·설명을 고칠 때 채운다. NULL 이어도 검색에서 빠지지 않는다(목록 창구가 `OR search_tsv IS NULL`).
    -- (기존 DB 는 db/migrations/2026-10-02_qf2b_files_search.sql 로 같은 모양이 된다.)
    search_tsv    TSVECTOR,
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL),
    -- G-0 (PRD F-73) — 「개인」 은 팀이 없다
    CONSTRAINT files_private_no_team
        CHECK (access_level <> 'private' OR team_id IS NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;
-- 휴지통 정리 배치(scripts/purge_trash.php)가 훑는 행만 담는 부분 인덱스 (9단계)
CREATE INDEX files_purge_idx   ON files (deleted_at) WHERE deleted_at IS NOT NULL AND purged_at IS NULL;
-- 18-1 (F-26) — 폴더 안 목록(`GET /api/files?folder=`)과 폴더 삭제 전 "파일이 있나" 조회용
CREATE INDEX files_folder_idx  ON files (folder_id) WHERE deleted_at IS NULL;
-- 대기열 5b — 한글이 든 파일 검색의 후보를 좁히는 색인 + 아직 채우지 않은 행만 담는 부분 색인(다 채우면 비어 있다)
CREATE INDEX files_search_tsv_idx      ON files USING GIN (search_tsv);
CREATE INDEX files_search_tsv_null_idx ON files (id) WHERE search_tsv IS NULL;

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
    reauth_at    TIMESTAMPTZ,
    -- 26단계 (PRD F-39, ARCH 5.20 「역할로 보기」): 이 **세션**이 지금 어떤 역할로 보고 있나.
    -- act_role 이 NULL 이면 보는 중이 아니다. 실제 역할(users.role)은 한 글자도 바뀌지 않는다 —
    -- Auth::currentUser() 가 이 값들로 **유효 역할**을 만들어 주고, 판정 코드는 그대로 따라온다.
    -- 만료는 「활동 없는 30분」이다: 요청마다 act_last_seen_at 을 밀고 그 값으로만 판정한다
    -- (시작 뒤 30분이면 쓰는 중에 끊긴다 — 사용자 결정 2026-09-15 ③).
    -- CHECK 두 개가 **위로 가는 조합**을 표 수준에서 막는다(act_role 에 'Admin' 은 넣을 수 없다).
    -- (기존 DB 는 db/migrations/2026-09-16_sessions_act_as.sql 로 같은 모양이 된다.)
    act_role          TEXT,
    act_team_id       BIGINT,
    act_started_at    TIMESTAMPTZ,
    act_last_seen_at  TIMESTAMPTZ,
    -- Q:F-9 (2026-09-29, ARCH 4.9 · 6.2-2): 이 세션이 **어디서 났나**. `login` = 비밀번호(+2단계)·지문 로그인,
    -- `token` = 「이 PC 로그인 전용」 토큰(api_tokens 범위 session.open)의 1회용 링크. token 세션은 유휴 10분 ·
    -- 민감 동작 재인증 필수 · 그 토큰이 회수·만료되거나 묶인 IP 밖에서 쓰이면 조회되지 않는다(Auth::SQL_LOOKUP).
    -- token_id 의 외래 키는 api_tokens 가 만들어진 **뒤**(아래 api_tokens 절 끝)에 건다 — 표 순서 때문이다.
    -- (기존 DB 는 db/migrations/2026-10-12_session_open.sql 로 같은 모양이 된다.)
    origin            TEXT        NOT NULL DEFAULT 'login',
    token_id          BIGINT,
    CONSTRAINT sessions_act_role_check
        CHECK (act_role IS NULL OR act_role IN ('Manager', 'Employee', 'Guest')),
    CONSTRAINT sessions_origin_check
        CHECK ((origin = 'login' AND token_id IS NULL) OR (origin = 'token' AND token_id IS NOT NULL))
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

-- ─────────────────────────────────────────────────────────────
-- api_tokens — 사용자별 프로그램 토큰 (F-59, PRD F-59 ①, ARCH 4.6 · 6.2-5)
--   계정마다 발급하는 개인 토큰이다. 토큰 원문은 발급 응답에 **한 번** 나가고 서버에는
--   **sha256 만** 남는다(sessions·share_links 와 같은 방식). 화면 식별용으로 앞 6자
--   `hint` 만 따로 둔다 — 48자 중 6자이고 남은 42자의 무작위성으로 추측이 성립하지 않는다.
--   회수는 `revoked_at`·`revoked_by` UPDATE 이고 **행은 지우지 않는다** — 「그 토큰이
--   언제부터 언제까지 살아 있었나」 가 조사에 필요하다 → 앱 계정에 DELETE 권한이 없다.
--   살아 있는 토큰 계정당 5개 상한은 앱이 본다(다른 행들의 상태에 달린 조건이라 CHECK 로
--   표현하기 어렵다). (기존 DB 는 db/migrations/2026-09-16_api_tokens.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE api_tokens (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users(id),
    name         TEXT        NOT NULL CHECK (length(name) BETWEEN 1 AND 50),   -- 라벨 「사무실 PC」
    token_hash   CHAR(64)    NOT NULL UNIQUE,   -- 48자 토큰의 sha256. 원문은 저장하지 않음
    hint         CHAR(6)     NOT NULL,          -- 토큰 앞 6자 — 화면에서 어느 토큰인지 알아보는 용도
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    revoked_by   BIGINT      REFERENCES users(id),
    reason_text  TEXT        CHECK (reason_text IS NULL OR length(reason_text) <= 500),
    -- F-66 (2026-09-22, ARCH 4.7 · 6.2-5): 범위·만료.
    --   scope      창구를 좁힐 뿐이고 파일별 판정(decideRead·decideUploadAs·decideDelete)은
    --              요청마다 그대로 한다 — 토큰이 계정보다 커질 수 없다.
    --   expires_at 발급 + 180일 고정. 지난 토큰은 401 `token_expired`.
    -- (기존 DB 는 db/migrations/2026-09-22_api_tokens_scope_expiry.sql 로 같은 모양이 된다 —
    --  기존 행은 `read+upload+delete` · `created_at + 180일` 로 채운다.)
    -- Q:F-8 (2026-09-28, ARCH 4.8): 넷째 값 `community.write`(독립 값 — 커뮤니티 창구 셋만, 파일 창구 없음).
    --   db/migrations/2026-10-11_api_tokens_scope_community.sql 과 **같은 네 값**이다(제약 이름도 같은
    --   `api_tokens_scope_check` — 열 CHECK 의 자동 이름). 새 설치는 마이그레이션 없이 이 모양이 된다.
    -- Q:F-9 (2026-09-29, ARCH 4.9): 다섯째 값 `session.open`(「이 PC 로그인 전용」 — 링크 창구 하나 · 독립 값)과
    --   그 토큰이 묶인 공인 IP `bound_ip`(발급 때 서버가 본 Http::clientIp() — 요청 값 아님). 로그인 전용 토큰은 언제나
    --   IP 에 묶이고 다른 범위에는 IP 가 없다(api_tokens_bound_ip_check). 제약 이름은 마이그레이션
    --   db/migrations/2026-10-12_session_open.sql 과 같다(`api_tokens_scope_check` 는 지우고 다시 거는 이름이라 명시).
    -- Q:F-10 (2026-09-29, ARCH 4.10): 범위는 **원자 집합** — 원자 여섯(read · upload · delete · community.read ·
    --   community.write · session.open)을 정규화 순서로 `+` 로 이은 문자열(Authz::normalizeScope). 열은 그대로 TEXT 한 열
    --   (조회 경로 불변 · 마이그레이션 최소). CHECK 는 **원자 포함 여부만** 본다 — 순서·의존(upload 는 read · delete 는
    --   read+upload · community.write 는 community.read 를 덧붙임)은 앱이 본다. DB 는 마지막 그물이고(ARCH 6.2-5) 판정
    --   (Authz::tokenScopeAllows)은 행이 가진 원자만큼만 연다 — 의존이 깨진 행이 직접 들어와도 넓어지지 않는다.
    --   session.open 원자가 들면 IP 에 묶이고(bound_ip_check) 사람당 살아 있는 것 하나(api_tokens_one_session_open).
    --   db/migrations/2026-10-13_api_tokens_multi_scope.sql 과 같은 모양이다(제약·인덱스 이름 같음).
    scope        TEXT        NOT NULL,
    expires_at   TIMESTAMPTZ NOT NULL,
    bound_ip     INET,
    CONSTRAINT api_tokens_scope_check
        CHECK (scope = 'assistant.run' OR scope <> '' AND string_to_array(scope, '+')
               <@ ARRAY['read','upload','delete','community.read','community.write','session.open','travel.read','travel.write']::text[]),
    CONSTRAINT api_tokens_bound_ip_check
        CHECK (('session.open' = ANY (string_to_array(scope, '+'))) = (bound_ip IS NOT NULL))
);
-- token_hash 의 UNIQUE 가 조회 인덱스를 겸한다
-- (`WHERE token_hash = … AND revoked_at IS NULL` 이 유일한 조회 경로 — ARCH 6.2-5)
CREATE INDEX api_tokens_user_idx ON api_tokens (user_id);

-- Q:F-9 검토 RV-QF9-2 — 한 사람의 살아 있는 로그인 전용 토큰은 하나(1대 규칙을 DB 가 지킨다 · 마이그레이션과 같은 이름)
-- Q:F-10 — 「로그인 전용」 = session.open 원자가 든 토큰(다른 원자가 섞여도)
CREATE UNIQUE INDEX api_tokens_one_session_open
    ON api_tokens (user_id) WHERE 'session.open' = ANY (string_to_array(scope, '+')) AND revoked_at IS NULL;

-- Q:F-9 — sessions.token_id 의 외래 키(sessions 가 api_tokens 보다 먼저 만들어져 여기서 건다 · 이름은 마이그레이션과 같다)
ALTER TABLE sessions
    ADD CONSTRAINT sessions_token_id_fkey
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE;

-- ─────────────────────────────────────────────────────────────
-- session_links — 「이 PC 로그인 전용」 토큰의 1회용 30초 로그인 링크 (Q:F-9 · t-box F-75, ARCH 4.9, SECURITY R-113)
--   **코드(32바이트 난수의 base64url 43자)의 sha256 만** 둔다 — 원문은 링크 응답에 한 번 나가고 서버 어디에도 남지 않는다
--   (code 열 자체가 없다 — sessions·share_links 와 같은 방식). 한 문장으로 소비(used_at — UPDATE … WHERE used_at IS NULL).
--   30초 상한은 앱이 넣고 CHECK 가 마지막 그물이다. 일회용 값이라 앱 계정이 정리(DELETE)할 수 있다.
--   (기존 DB 는 db/migrations/2026-10-12_session_open.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE session_links (
    id          BIGSERIAL   PRIMARY KEY,
    code_hash   CHAR(64)    NOT NULL UNIQUE,                                   -- 코드의 sha256 hex. 원문은 저장하지 않음
    token_id    BIGINT      NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip          INET        NOT NULL,                                          -- 링크를 받은 IP(= 그 토큰의 bound_ip)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    CONSTRAINT session_links_expiry CHECK (expires_at > created_at AND expires_at <= created_at + interval '30 seconds')
);
CREATE INDEX session_links_token_idx ON session_links (token_id, created_at);

-- ─────────────────────────────────────────────────────────────
-- fetch_hosts — 「URL 로 가져오기」가 갈 수 있는 곳 (F-43, PRD F-43 · S-05 ③, ARCH 5.22)
--   허용 호스트 목록을 `.env` 문자열에서 표로 옮긴 것이다. 관리자가 화면에서 넣고 빼되
--   권한 매트릭스와 같은 급의 조건(재인증 · 사유 · 인증 앱 코드 · 감사 · AL-18)을 지난다.
--   **행을 지우지 않는다** — 뺀 호스트도 "언제부터 언제까지 열려 있었나" 가 조사에 필요하다.
--   그래서 앱 계정에 DELETE 권한이 없고 `is_active = false` 로만 내린다.
--   (기존 DB 는 db/migrations/2026-09-17_fetch_hosts.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE fetch_hosts (
    id         BIGSERIAL   PRIMARY KEY,
    host       TEXT        NOT NULL,
    note       TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- 소문자 영숫자·점·붙임표만, 점이 최소 하나. 와일드카드(`*`)는 어떤 경로로도 못 들어온다.
    CONSTRAINT fetch_hosts_host_fmt CHECK (
        host ~ '^[a-z0-9.-]{1,253}$'
        AND host NOT LIKE '%*%'
        AND host LIKE '%.%'
    ),
    CONSTRAINT fetch_hosts_note_len CHECK (note IS NULL OR length(note) <= 200)
);
CREATE UNIQUE INDEX fetch_hosts_host_uidx   ON fetch_hosts (host);
CREATE INDEX        fetch_hosts_active_idx  ON fetch_hosts (is_active);

-- ─────────────────────────────────────────────────────────────
-- file_favorites · folder_favorites — 즐겨찾기 (개발 대기열 6 · Q:F-3a 라운드 A, ARCH 5.33)
--   개인 화면 설정이다(감사에 남기지 않는다 — 지시서 결정 M-2). 넣을 때 그 순간 읽을 수 있어야 하고
--   (`Authz::decideRead`), 목록은 **지금의 읽기 조건절**(`visibleFilesWhere` · 폴더는 `visibleWhere(…, 'd')`) 안에서만
--   돌려준다 — 못 보게 된 파일·폴더는 저절로 빠진다. 사람당 파일 200 · 폴더 50(앱이 센다).
--   대상 외래 키는 ON DELETE CASCADE(빈 폴더는 행째 지워진다) · 사람 외래 키는 ON DELETE 절 없음(계정은 지우지 않는다).
--   (기존 DB 는 db/migrations/2026-10-03_qf3a_favorites_recent.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE file_favorites (
    user_id    BIGINT      NOT NULL REFERENCES users(id),
    file_id    BIGINT      NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, file_id)
);
CREATE INDEX file_favorites_file_idx ON file_favorites (file_id);
CREATE TABLE folder_favorites (
    user_id    BIGINT      NOT NULL REFERENCES users(id),
    folder_id  BIGINT      NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, folder_id)
);
CREATE INDEX folder_favorites_folder_idx ON folder_favorites (folder_id);

-- ─────────────────────────────────────────────────────────────
-- passkeys · passkey_challenges — 휴대폰 지문·얼굴로 로그인 (T:F-72 패스키, ARCH 5.35, SECURITY R-109)
--   passkeys: 등록한 열쇠의 **공개 키만**(SPKI DER). 행을 지우지 않는다(해제는 revoked_at — 앱 계정에 DELETE 없음).
--             90일 안 쓴 열쇠는 찾는 SQL 조건으로 꺼진다(배치 없음). 한 사람 5개는 앱이 센다(사용자 행 잠금 뒤).
--   passkey_challenges: 일회용 문제의 sha256 만. 한 문장으로 소비(used_at). 만료 정리는 앱이 옵션 창구마다.
--   (기존 DB 는 db/migrations/2026-10-06_passkeys.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE passkeys (
    id              BIGSERIAL   PRIMARY KEY,
    user_id         BIGINT      NOT NULL REFERENCES users(id),
    credential_id   BYTEA       NOT NULL UNIQUE CHECK (octet_length(credential_id) BETWEEN 16 AND 1023),
    public_key      BYTEA       NOT NULL CHECK (octet_length(public_key) BETWEEN 64 AND 1024),  -- SPKI DER (ES256 = 91)
    alg             INTEGER     NOT NULL CHECK (alg IN (-7)),          -- PC 단계에서 -257
    sign_count      BIGINT      NOT NULL DEFAULT 0 CHECK (sign_count BETWEEN 0 AND 4294967295),
    user_handle     BYTEA       NOT NULL CHECK (octet_length(user_handle) = 32),
    backup_eligible BOOLEAN     NOT NULL,
    backed_up       BOOLEAN     NOT NULL,
    transports      TEXT[]      NOT NULL DEFAULT '{}'
                    CHECK (transports <@ ARRAY['internal','hybrid','usb','nfc','ble','smart-card']::text[]),
    aaguid          UUID,                                              -- 기록만 (신뢰 근거 아님)
    name            TEXT        NOT NULL CHECK (char_length(name) BETWEEN 1 AND 40
                                AND name = btrim(name) AND name !~ '[[:cntrl:]]'),
    device_hint     TEXT        NOT NULL CHECK (char_length(device_hint) <= 40),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at    TIMESTAMPTZ,
    revoked_at      TIMESTAMPTZ,
    revoked_by      BIGINT      REFERENCES users(id),
    revoke_reason   TEXT        CHECK (revoke_reason IN
                    ('self','password_change','password_reset','mfa_reset','role_change','deactivate')),
    CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))
);
CREATE INDEX passkeys_user_live_idx ON passkeys (user_id) WHERE revoked_at IS NULL;

CREATE TABLE passkey_challenges (
    id             BIGSERIAL   PRIMARY KEY,
    challenge_hash CHAR(64)    NOT NULL UNIQUE,                        -- sha256 hex, 원문 저장 안 함
    purpose        TEXT        NOT NULL CHECK (purpose IN ('register','login','reauth')),
    user_id        BIGINT      REFERENCES users(id) ON DELETE CASCADE,
    session_id     CHAR(64)    REFERENCES sessions(id) ON DELETE CASCADE,
    binding_hash   CHAR(64),                                           -- 로그인 전 쿠키의 sha256
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at     TIMESTAMPTZ NOT NULL,
    used_at        TIMESTAMPTZ,
    CHECK ( (purpose = 'login' AND binding_hash IS NOT NULL AND user_id IS NULL AND session_id IS NULL)
         OR (purpose <> 'login' AND binding_hash IS NULL AND user_id IS NOT NULL AND session_id IS NOT NULL) )
);
CREATE INDEX passkey_challenges_expires_idx ON passkey_challenges (expires_at);

-- Moa: invitation-only onboarding and member messages (2026-10-02).
CREATE TABLE IF NOT EXISTS public.member_invites (
 id bigserial PRIMARY KEY, code_hash text NOT NULL UNIQUE CHECK(length(code_hash)=64),
 label text NOT NULL CHECK(length(label) BETWEEN 1 AND 80), team_id bigint REFERENCES public.teams(id),
 created_by bigint NOT NULL REFERENCES public.users(id), created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL, max_uses integer NOT NULL CHECK(max_uses BETWEEN 1 AND 100),
 used_count integer NOT NULL DEFAULT 0 CHECK(used_count>=0 AND used_count<=max_uses), revoked_at timestamptz
);
CREATE TABLE IF NOT EXISTS public.member_rate_limits (
 key_hash text PRIMARY KEY CHECK(length(key_hash)=64), window_start timestamptz NOT NULL DEFAULT now(), hits integer NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS public.member_settings (
 key text PRIMARY KEY CHECK(key IN ('buddy_download_url')), value text NOT NULL, updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS public.member_messages (
 id bigserial PRIMARY KEY, sender_id bigint NOT NULL REFERENCES public.users(id),
 recipient_id bigint NOT NULL REFERENCES public.users(id), body text NOT NULL CHECK(length(body) BETWEEN 1 AND 4000),
 sent_at timestamptz NOT NULL DEFAULT now(), read_at timestamptz, CHECK(sender_id<>recipient_id)
);
CREATE INDEX IF NOT EXISTS member_messages_pair_idx ON public.member_messages(sender_id,recipient_id,id DESC);
CREATE INDEX IF NOT EXISTS member_messages_unread_idx ON public.member_messages(recipient_id,sender_id) WHERE read_at IS NULL;

-- Moa messenger AI (2026-10-02).
CREATE TABLE IF NOT EXISTS public.member_ai_settings (
 id integer PRIMARY KEY CHECK(id=1), enabled boolean NOT NULL DEFAULT false,
 user_daily integer NOT NULL DEFAULT 40 CHECK(user_daily BETWEEN 1 AND 500),
 site_daily integer NOT NULL DEFAULT 300 CHECK(site_daily BETWEEN 1 AND 5000),
 updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO public.member_ai_settings(id) VALUES(1) ON CONFLICT(id) DO NOTHING;
CREATE TABLE IF NOT EXISTS public.member_ai_threads (
 id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 title text NOT NULL CHECK(length(title) BETWEEN 1 AND 80),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(id,user_id)
);
CREATE INDEX IF NOT EXISTS member_ai_threads_owner_idx ON public.member_ai_threads(user_id,id DESC);
CREATE TABLE IF NOT EXISTS public.member_ai_turns (
 id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 thread_id bigint NOT NULL, request_id uuid NOT NULL,
 prompt text NOT NULL CHECK(length(prompt) BETWEEN 1 AND 4000),
 answer text CHECK(length(answer) BETWEEN 1 AND 20000),
 state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','complete','failed')),
 error_code text, model text NOT NULL DEFAULT 'gemini-3.5-flash' CHECK(model='gemini-3.5-flash'),
 input_tokens integer CHECK(input_tokens>=0), output_tokens integer CHECK(output_tokens>=0),
 total_tokens integer CHECK(total_tokens>=0),
 created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
 FOREIGN KEY(thread_id,user_id) REFERENCES public.member_ai_threads(id,user_id),
 UNIQUE(user_id,request_id), CHECK((state='complete')=(answer IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS member_ai_turns_thread_idx ON public.member_ai_turns(user_id,thread_id,id DESC);
CREATE INDEX IF NOT EXISTS member_ai_turns_created_idx ON public.member_ai_turns(created_at,user_id);
CREATE INDEX IF NOT EXISTS member_ai_turns_pending_idx ON public.member_ai_turns(created_at) WHERE state='pending';

-- Human messenger AI previews and participant-only image attachments (2026-10-02).
ALTER TABLE public.member_ai_settings
 ADD COLUMN IF NOT EXISTS image_enabled boolean NOT NULL DEFAULT false,
 ADD COLUMN IF NOT EXISTS image_user_daily integer NOT NULL DEFAULT 3 CHECK(image_user_daily BETWEEN 1 AND 20),
 ADD COLUMN IF NOT EXISTS image_site_daily integer NOT NULL DEFAULT 20 CHECK(image_site_daily BETWEEN 1 AND 200);
CREATE TABLE IF NOT EXISTS public.member_ai_assists (
 id uuid PRIMARY KEY, user_id bigint NOT NULL REFERENCES public.users(id),
 peer_id bigint NOT NULL REFERENCES public.users(id), request_id uuid NOT NULL,
 action text NOT NULL CHECK(action IN ('polish','spelling','translate','image')),
 source_message_id bigint REFERENCES public.member_messages(id),
 input text NOT NULL CHECK(length(input) BETWEEN 1 AND 4000),
 language text NOT NULL CHECK(language IN ('ko','en','ja','zh','vi','es')),
 aspect text NOT NULL CHECK(aspect IN ('1:1','16:9','9:16')),
 state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','complete','failed')),
 result text CHECK(length(result) BETWEEN 1 AND 4000), error_code text,
 stored_name text UNIQUE, image_mime text CHECK(image_mime IN ('image/png','image/jpeg','image/webp')),
 image_bytes integer CHECK(image_bytes BETWEEN 1 AND 6291456), image_sha256 char(64),
 model text NOT NULL CHECK(model IN ('gemini-3.5-flash','gemini-3.1-flash-lite-image')),
 input_tokens integer CHECK(input_tokens>=0), output_tokens integer CHECK(output_tokens>=0), total_tokens integer CHECK(total_tokens>=0),
 created_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz,
 UNIQUE(user_id,request_id), CHECK(user_id<>peer_id),
 CHECK((action='image')=(model='gemini-3.1-flash-lite-image')),
 CHECK((state='complete')=(result IS NOT NULL OR stored_name IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_mime IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_bytes IS NOT NULL)),
 CHECK((stored_name IS NOT NULL)=(image_sha256 IS NOT NULL)),
 CHECK(stored_name IS NULL OR (action='image' AND stored_name ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(png|jpg|webp)$'))
);
CREATE INDEX IF NOT EXISTS member_ai_assists_owner_idx ON public.member_ai_assists(user_id,peer_id,created_at DESC);
CREATE INDEX IF NOT EXISTS member_ai_assists_created_idx ON public.member_ai_assists(created_at,user_id);
CREATE INDEX IF NOT EXISTS member_ai_assists_pending_idx ON public.member_ai_assists(created_at) WHERE state='pending';
ALTER TABLE public.member_messages ADD COLUMN IF NOT EXISTS ai_attachment_id uuid REFERENCES public.member_ai_assists(id);
CREATE UNIQUE INDEX IF NOT EXISTS member_messages_ai_attachment_idx ON public.member_messages(ai_attachment_id) WHERE ai_attachment_id IS NOT NULL;
-- Q:F-11 (지시서 A-4 · R-118): 문자 메시지 멱등 — 같은 보낸 사람·같은 request_id 는 한 행. 업그레이드는 2026-10-06_member_messages_request_id.sql.
ALTER TABLE public.member_messages ADD COLUMN IF NOT EXISTS request_id uuid;
CREATE UNIQUE INDEX IF NOT EXISTS member_messages_request_idx ON public.member_messages(sender_id, request_id) WHERE request_id IS NOT NULL;

-- 비서 실행 MVP: 새 설치와 업그레이드가 동일 DDL을 사용한다.
\ir assistant.sql

-- ─────────────────────────────────────────────────────────────
-- access_grants · access_requests — G-0 「개인」 등급의 사람 지정 권한 · 권한 요청 (PRD F-73, ARCH 4.1 · 5.36)
--   access_grants: 폴더 또는 파일 하나(둘 중 하나만) · 받은 사람 · 보기/편집. **개인 등급 항목에만 뜻이 있다**
--     (다른 등급은 권한 표가 판정 — 줄은 남아도 무시. 넓혔다가 다시 좁히면 되살아난다).
--     폴더 줄은 안의 모든 하위 폴더·파일에 이어진다 — 판정은 앱 한 벌(Authz::privateChainSql: 판정 함수와 목록 조건절이
--     같은 SQL 조각). 폴더 외래 키 ON DELETE CASCADE(빈 폴더는 행째 지워진다) · 파일 외래 키는 절 없음(파일 행은 남는다).
--   access_requests: 주소로 연 개인 항목이 403 일 때 주인에게 보내는 요청. 같은 (항목, 요청자) pending 하나(부분 UNIQUE) ·
--     한 사람 하루 20건(앱이 감사 행으로 센다 — 저장하지 않은 요청도 센다: 없는 id 와 못 보는 id 가 갈리지 않게).
--   (기존 DB 는 db/migrations/2026-10-10_access_grants.sql 로 같은 모양이 된다.)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE access_grants (
    id         BIGSERIAL   PRIMARY KEY,
    folder_id  BIGINT      REFERENCES folders(id) ON DELETE CASCADE,
    file_id    BIGINT      REFERENCES files(id),
    user_id    BIGINT      NOT NULL REFERENCES users(id),
    can_write  BOOLEAN     NOT NULL DEFAULT false,
    granted_by BIGINT      NOT NULL REFERENCES users(id),
    granted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_grants_one_target CHECK (num_nonnulls(folder_id, file_id) = 1)
);
CREATE UNIQUE INDEX access_grants_folder_user_uidx ON access_grants (folder_id, user_id) WHERE folder_id IS NOT NULL;
CREATE UNIQUE INDEX access_grants_file_user_uidx   ON access_grants (file_id, user_id)   WHERE file_id IS NOT NULL;
CREATE INDEX        access_grants_user_idx         ON access_grants (user_id);

CREATE TABLE access_requests (
    id           BIGSERIAL   PRIMARY KEY,
    folder_id    BIGINT      REFERENCES folders(id) ON DELETE CASCADE,
    file_id      BIGINT      REFERENCES files(id),
    requester_id BIGINT      NOT NULL REFERENCES users(id),
    want_write   BOOLEAN     NOT NULL DEFAULT false,
    message      TEXT        NOT NULL DEFAULT '',
    status       TEXT        NOT NULL DEFAULT 'pending',
    decided_by   BIGINT      REFERENCES users(id),
    decided_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT access_requests_one_target  CHECK (num_nonnulls(folder_id, file_id) = 1),
    CONSTRAINT access_requests_message_len CHECK (char_length(message) <= 200),
    CONSTRAINT access_requests_status      CHECK (status IN ('pending','approved','rejected','cancelled')),
    CONSTRAINT access_requests_decided_pair CHECK ((status = 'pending') = (decided_at IS NULL))
);
CREATE UNIQUE INDEX access_requests_folder_pending_uidx ON access_requests (folder_id, requester_id)
    WHERE folder_id IS NOT NULL AND status = 'pending';
CREATE UNIQUE INDEX access_requests_file_pending_uidx   ON access_requests (file_id, requester_id)
    WHERE file_id IS NOT NULL AND status = 'pending';
CREATE INDEX access_requests_requester_idx ON access_requests (requester_id, created_at DESC);
CREATE INDEX access_requests_folder_idx    ON access_requests (folder_id) WHERE folder_id IS NOT NULL;
CREATE INDEX access_requests_file_idx      ON access_requests (file_id)   WHERE file_id IS NOT NULL;
