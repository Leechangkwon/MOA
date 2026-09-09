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
-- ─────────────────────────────────────────────────────────────
CREATE TABLE teams (
    id   BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

-- ─────────────────────────────────────────────────────────────
-- users — 역할은 4종으로 DB 가 강제한다 (PRD 9장 제약 조건)
--   password_hash 는 Argon2id 해시만 저장(2단계, PRD S-09-4). 평문 비밀번호는 어디에도 저장하지 않는다.
--   failed_count / locked_until — 실패 5회 10분 잠금 (PRD S-09-4)
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
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
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
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;

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
    detail       JSONB                   -- 이전값→새값 등. 비밀번호·토큰·파일내용 금지
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
