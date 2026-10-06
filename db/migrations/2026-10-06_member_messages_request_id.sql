-- db/migrations/2026-10-06_member_messages_request_id.sql
--   사이트 Q:F-11 「어느 화면에서나 이어지는 메신저」 지시서 A-4 — 모아 문자 메시지 멱등(request_id)
--   (docs/qf11-floating-messenger-directive.md A-4 · SECURITY R-118 · API.md 「모아 초대 가입·메신저」 POST /api/messages/{num})
--
-- 하는 일 (추가 전용 — 표·열·행 삭제 0, 기존 행 변경 0, 여러 번 돌려도 같다)
--   · member_messages.request_id uuid 열 — 같은 보낸 사람·같은 request_id 는 한 행(여러 탭·네트워크 재시도에 같은 글이 두 번 저장되지 않는다)
--   · UNIQUE (sender_id, request_id) WHERE request_id IS NOT NULL — 마이그레이션 전 옛 행은 NULL 그대로(멱등 없음, R-118 「남는 것」)
--   소유자·권한(GRANT) 변경 0: 열은 표의 기존 권한(db/grants.sql · 2026-10-02_moa.sql 의 tbox_app SELECT/INSERT/UPDATE)을 따른다.
--   새 설치는 db/schema.sql 이 같은 열·인덱스를 만든다.
--
-- 실행 계정: 표 소유자(tbox_owner) 또는 postgres.
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f - < db/migrations/2026-10-06_member_messages_request_id.sql
--   앱 코드(src/handlers/member_messages.php — request_id 필수)보다 **먼저** 적용한다(열이 없으면 전송이 500).
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.member_messages ADD COLUMN IF NOT EXISTS request_id uuid;
CREATE UNIQUE INDEX IF NOT EXISTS member_messages_request_idx ON public.member_messages(sender_id, request_id) WHERE request_id IS NOT NULL;
COMMIT;
