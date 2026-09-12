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
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;
-- 휴지통 정리 배치(scripts/purge_trash.php)가 훑는 행만 담는 부분 인덱스 (9단계)
CREATE INDEX files_purge_idx   ON files (deleted_at) WHERE deleted_at IS NOT NULL AND purged_at IS NULL;

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
    user_agent   TEXT
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
