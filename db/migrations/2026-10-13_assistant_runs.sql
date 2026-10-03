-- 비서 MVP. 2026-10-03 일괄 진행 지시에 따른 통합: 백업·업그레이드 검증 후 적용.
-- 기존 토큰 데이터와 범위를 확장하지 않고 독립 assistant.run 값만 추가한다.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE public.api_tokens DROP CONSTRAINT api_tokens_scope_check;
ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_scope_check
    CHECK (scope IN ('read','read+upload','read+upload+delete','community.write','session.open','assistant.run'));
\ir ../assistant.sql
COMMIT;
