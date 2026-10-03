-- 비서 MVP. 2026-10-03 일괄 진행 지시에 따른 통합: 백업·업그레이드 검증 후 적용.
-- 기존 토큰 데이터와 범위를 확장하지 않고 독립 assistant.run 값만 추가한다.
\set ON_ERROR_STOP on
BEGIN;
DO $$
DECLARE definition text;
BEGIN
 SELECT pg_get_constraintdef(oid) INTO definition FROM pg_constraint
 WHERE conrelid='public.api_tokens'::regclass AND conname='api_tokens_scope_check';
 IF definition IS NULL THEN RAISE EXCEPTION 'token scope constraint missing'; END IF;
 IF position('assistant.run' in definition)=0 THEN
  ALTER TABLE public.api_tokens DROP CONSTRAINT api_tokens_scope_check;
  EXECUTE 'ALTER TABLE public.api_tokens ADD CONSTRAINT api_tokens_scope_check CHECK (('
   || substr(definition,7) || ') OR scope = ''assistant.run'')';
 END IF;
END
$$;
\ir ../assistant.sql
COMMIT;
