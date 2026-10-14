-- db/migrations/2026-10-15_member_ai_model_36.sql — 모아 AI 기록의 모델 이름에 gemini-3.6-flash 를 허용 (R1b · 2026-10-15)
--
-- 원인: 2f5e99a(Gemini 3.6 직접 호출 · v2.67.1)가 MemberAi::MODEL 을 'gemini-3.6-flash' 로 바꿨으나 DB CHECK 는 3.5 만 허용 —
--   메신저 「문장 도우미」(다듬기 · 맞춤법 · 번역)의 member_ai_assists INSERT 가 member_ai_assists_model_check 에 걸려 실패한다
--   (밤 회귀 R1 — memory_ai_http · messenger_store_browser). 대화(member_ai_turns)는 열을 넣지 않아 기본값 3.5 로 기록됐다.
-- 하는 일: 두 CHECK 를 다시 만든다 — 옛 값(3.5)은 그대로 허용(이미 있는 행 보존), 3.6 을 더한다. 이미지 모델 · check1(action↔model) 은 그대로.
--   새 설치는 db/schema.sql 의 같은 두 CHECK(같은 이름 · 같은 식). 앱 권한은 표 단위라 바꿀 것 0.
-- 추가 전용 · 되풀이 안전(정의에 이미 3.6 이 있으면 아무것도 하지 않는다) · 지우거나 고치는 행 0. 실행 계정: tbox_owner(표 소유자).
-- 되돌리기(3.6 행이 없을 때만 — 있으면 좁힐 수 없다): 같은 이름의 CHECK 를 옛 식으로 다시 만든다.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'public.member_ai_assists'::regclass AND conname = 'member_ai_assists_model_check'
                      AND position('gemini-3.6-flash' in pg_get_constraintdef(oid)) > 0) THEN
        ALTER TABLE public.member_ai_assists DROP CONSTRAINT IF EXISTS member_ai_assists_model_check;
        ALTER TABLE public.member_ai_assists ADD CONSTRAINT member_ai_assists_model_check
            CHECK (model IN ('gemini-3.5-flash','gemini-3.6-flash','gemini-3.1-flash-lite-image'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'public.member_ai_turns'::regclass AND conname = 'member_ai_turns_model_check'
                      AND position('gemini-3.6-flash' in pg_get_constraintdef(oid)) > 0) THEN
        ALTER TABLE public.member_ai_turns DROP CONSTRAINT IF EXISTS member_ai_turns_model_check;
        ALTER TABLE public.member_ai_turns ADD CONSTRAINT member_ai_turns_model_check
            CHECK (model IN ('gemini-3.5-flash','gemini-3.6-flash'));
    END IF;
END
$$;

COMMIT;
