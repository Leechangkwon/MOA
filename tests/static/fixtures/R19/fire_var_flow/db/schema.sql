-- 견본 스키마(가짜) — 감사·기록 표 사전을 코드에서 뽑는지 보는 용도
CREATE TABLE demo_users (
    id        BIGSERIAL PRIMARY KEY,
    username  TEXT NOT NULL
);
CREATE TABLE audit_logs (
    id          BIGSERIAL PRIMARY KEY,
    happened_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    actor_id    BIGINT,
    actor_name  TEXT,
    action      TEXT NOT NULL,
    result      TEXT NOT NULL,
    reason      TEXT,
    target_id   BIGINT,
    target_name TEXT,
    detail      JSONB
);
CREATE TABLE demo_pages (
    id        BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL,
    title     TEXT
);
-- 이름에 기록 낱말이 없어도 추가 전용 권한이면 기록 표다
CREATE TABLE demo_snapshots (
    id         BIGSERIAL PRIMARY KEY,
    page_id    BIGINT NOT NULL REFERENCES demo_pages(id),
    version    INT NOT NULL,
    by_user_id BIGINT NOT NULL REFERENCES demo_users(id),
    made_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE demo_events (
    id      BIGSERIAL PRIMARY KEY,
    kind    TEXT NOT NULL,
    at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
