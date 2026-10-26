-- ─────────────────────────────────────────────────────────────
-- 2026-10-26_qf15_s2_shortcuts.sql  (사이트 Q:F-15 S2 「앞세우기 · 접기」 — 지시서 docs/qf15-s2-front-directive.md B · 기획 ../docs/identity-plan-2026-10-24.md 9.2 Q3)
--
-- 무엇을
--   저장된 개인 홈 구성(public.product_personal_dashboards.layout)에서 상단 바로가기 키 `shortcuts` 하나만 지운다.
--   `layout ? 'shortcuts'` 인 행만: layout = layout - 'shortcuts' · revision = revision + 1 · updated_at = now().
--   다른 키(widgets · source · view · guide · version) · 다른 표(product_space_dashboards 포함) · DDL · 권한 0. 행을 지우지 않는다.
--
-- 왜 (사용자 결정 2026-10-26 Q3 B 「반영 때 한 번 새 기본으로」)
--   홈 바로가기 기본이 업무 홈 · 자료실 · 메신저 · 우리 공간 넷으로 바뀌었다(DESIGN 2.31 ③ · D-4). 바로가기를 저장해 둔 계정도
--   새 기본을 보게 하려고 그 칸만 한 번 지운다 — 지운 뒤 화면(home-dashboard.js)은 「저장한 적 없는 계정」과 같이 기본 넷을 그린다.
--   revision + 1 은 반영 순간 홈 편집을 열어 둔 탭이 옛 바로가기로 덮어쓰지 못하고 409(revision_conflict)를 받게 한다
--   (ProductSpaces::dashboardSave — 받은 revision 이 지금 값과 다르면 409).
--
-- 여러 번 돌려도 같다
--   두 번째 실행부터는 `shortcuts` 가 남은 행이 없어 바뀌는 행 0 (revision 도 그대로).
--
-- 적용 계정
--   ARCHITECTURE.md 10장 끝 「마이그레이션 적용 계정」 그대로 — postgres 로 붙고 첫 줄 `SET ROLE tbox_owner;`(지금 마이그레이션들과 같음 · 표 소유자 tbox_owner).
--
-- 되돌리는 길 (사용자가 정할 때만 — 운영 데이터 규칙상 메인도 스스로 복원하지 않는다)
--   반영 전에 따로 남긴 목록(user_id · layout->'shortcuts' · revision — 지시서 B 「반영 순서」 2)으로 그 행들의 layout 에 `shortcuts` 만 다시 넣고 revision + 1.
--   목록이 없으면 반영 전 백업(DB 덤프)에서 그 칸만 꺼낸다. 다른 칸은 그 사이 사용자가 바꿨을 수 있으니 건드리지 않는다 · 전체 복원은 하지 않는다.
-- ─────────────────────────────────────────────────────────────
BEGIN;
SET LOCAL lock_timeout = '3s';
UPDATE public.product_personal_dashboards
   SET layout = layout - 'shortcuts',
       revision = revision + 1,
       updated_at = now()
 WHERE layout ? 'shortcuts';
COMMIT;
