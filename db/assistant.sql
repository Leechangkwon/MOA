-- 비서 실행 MVP. psql로만 적용; 새 설치/업그레이드의 동일 테이블 정의.
CREATE TABLE IF NOT EXISTS public.assistant_runs (
    id UUID PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES public.users(id),
    token_id BIGINT NOT NULL REFERENCES public.api_tokens(id),
    idempotency_hash CHAR(64) NOT NULL CHECK (idempotency_hash ~ '^[0-9a-f]{64}$'),
    request_hash CHAR(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
    request JSONB NOT NULL CHECK (jsonb_typeof(request)='object'),
    provider TEXT NOT NULL CHECK (provider IN ('native','test')),
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN
        ('queued','planning','awaiting_confirmation','running','cancel_requested','succeeded','failed','cancelled')),
    phase TEXT NOT NULL DEFAULT 'queued' CHECK (phase IN
        ('queued','planning','approval','running','cancelling','completed','failed','cancelled')),
    plan JSONB CHECK (plan IS NULL OR jsonb_typeof(plan)='object'),
    steps JSONB NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(steps)='array'),
    result JSONB CHECK (result IS NULL OR jsonb_typeof(result)='object'),
    error JSONB CHECK (error IS NULL OR jsonb_typeof(error)='object'),
    approval_id UUID,
    plan_hash CHAR(64) CHECK (plan_hash IS NULL OR plan_hash ~ '^[0-9a-f]{64}$'),
    approval_expires_at TIMESTAMPTZ,
    decision TEXT CHECK (decision IN ('approve','reject')),
    worker_id UUID,
    lease_expires_at TIMESTAMPTZ,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 3),
    event_seq INTEGER NOT NULL DEFAULT 0 CHECK (event_seq >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    UNIQUE(user_id,idempotency_hash),
    CHECK ((worker_id IS NULL) = (lease_expires_at IS NULL)),
    CHECK ((approval_id IS NULL) = (plan_hash IS NULL)),
    CHECK ((approval_id IS NULL) = (approval_expires_at IS NULL)),
    CHECK (decision IS NULL OR approval_id IS NOT NULL),
    CHECK (status <> 'awaiting_confirmation' OR (approval_id IS NOT NULL AND decision IS NULL AND worker_id IS NULL)),
    CHECK ((status IN ('succeeded','failed','cancelled')) = (finished_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS assistant_runs_owner_recent ON public.assistant_runs(user_id,created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS assistant_runs_token ON public.assistant_runs(token_id);
CREATE INDEX IF NOT EXISTS assistant_runs_queue ON public.assistant_runs(created_at,id)
    WHERE status IN ('queued','planning','running','cancel_requested','awaiting_confirmation');
CREATE TABLE IF NOT EXISTS public.assistant_events (
    run_id UUID NOT NULL REFERENCES public.assistant_runs(id) ON DELETE CASCADE,
    seq INTEGER NOT NULL CHECK (seq > 0),
    name TEXT NOT NULL CHECK (name IN ('run.created','phase.changed','approval.required',
        'tool.started','tool.finished','artifact.created','message.delta','run.completed','run.failed','run.cancelled')),
    payload JSONB NOT NULL CHECK (jsonb_typeof(payload)='object'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(run_id,seq)
);
-- 역할/서버 설정은 만들거나 고치지 않는다. 표 권한만 부여한다.
GRANT SELECT,INSERT,UPDATE ON public.assistant_runs TO tbox_app;
GRANT SELECT,INSERT ON public.assistant_events TO tbox_app;
GRANT SELECT ON public.assistant_runs,public.assistant_events TO tbox_backup;
