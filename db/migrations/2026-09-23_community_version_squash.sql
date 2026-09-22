-- db/migrations/2026-09-23_community_version_squash.sql
--   커뮤니티 4-C — 버전 이력 · 되돌리기 · 처리 이력 · 휴지통 (C:F-19 · C:F-20 · C:S-14)
--
-- 요구사항: ../../../community/PRD.md F-19 · F-20 · S-14,
--           ../../../community/ARCHITECTURE.md 13.1(표) · 16장(이력·휴지통),
--           ../../../community/stage4c-history-directive.md 「데이터 — 마이그레이션 하나」
--
-- 실행 계정: tbox_owner (표 소유자). 앱 계정 tbox_app 의 권한은 **한 줄도 바뀌지 않는다** —
--            새 칸은 표 단위 GRANT(SELECT·INSERT)를 그대로 물려받고, 이 파일은 GRANT 를 쓰지 않는다.
-- 실행 예:  PGPASSFILE=/etc/tbox-v2/owner.pgpass psql -h 127.0.0.1 -U tbox_owner -d tbox_re \
--             -v ON_ERROR_STOP=1 -f db/migrations/2026-09-23_community_version_squash.sql
--
-- 하는 일 (표·열 **삭제 0**, 데이터 손실 0, 여러 번 돌려도 같다)
--   1. `page_versions.created_at` — **스냅샷 행이 만들어진 시각**을 자기 이름으로 들고 있는 칸.
--   2. 옛 행 채우기 — 지금까지 `edited_at` 이 「스냅샷을 뜬 시각」이었으므로 그 값을 그대로 옮긴다.
--   3. `checklist_events(page_id, at DESC)` 인덱스 — 3단계 스키마에 이미 있다(아래 주석).
--
-- ★ **앱은 이 파일을 적용하기 전에도, 적용한 뒤에도 그대로 동작한다.**
--   4-C 런타임(`src/community/routes.php`)은 `created_at` 을 읽지도 쓰지도 않는다 — 새 스냅샷의
--   이 칸은 `DEFAULT now()` 가 스스로 채운다. 5분 합치기는 같은 뜻을 이미 들고 있는
--   `edited_at`(3단계부터 `DEFAULT now()`)을 본다. 그래서 운영 적용에 배포 창이 필요 없고,
--   Opus 는 운영 DB 에 DDL 을 돌리지 않고도 4-C 전부를 실측할 수 있었다(보고 ⑦).
--
-- ★ 왜 그래도 칸을 나눠 두나
--   `edited_at` 은 뒤 단계에서 「그 판의 **내용이 쓰인** 시각」(`pages.updated_at`)으로 옮길 수
--   있는 이름이다. 그때 「언제 스냅샷이 남았나」를 적을 자리가 없으면 5분 합치기가 흔들린다.
--   지금 값을 옮겨 둔 칸 하나가 그 갈림길을 미리 막는다(지시서 「데이터 — 마이그레이션 하나」).
--
-- ★ 인덱스 `page_versions(page_id, version DESC)` 는 **만들지 않는다** — 같은 뜻의 인덱스가
--   이미 있다. 제약 `page_versions_unique UNIQUE (page_id, version)` 이 만든 btree 가
--   `ORDER BY version DESC LIMIT 1`(5분 합치기가 직전 스냅샷을 찾는 질의)을 그대로 쓴다
--   (PostgreSQL 은 btree 를 뒤로 훑는다 — `Index Scan Backward`). 같은 열 짝에 인덱스를
--   하나 더 두면 INSERT 마다 두 번 갱신될 뿐이라 두지 않았고, 이 판단은 임시 DB 에서
--   `EXPLAIN` 으로 확인했다(4-C 보고 ⑤·⑦).
--
-- 되돌리기 (아무도 새 칸을 쓰기 전):
--   ALTER TABLE community.page_versions DROP COLUMN created_at;

\set ON_ERROR_STOP on

BEGIN;

-- ─── 1. 새 칸 (NULL 을 허용한 채로 붙인다 — 큰 표에서도 즉시 끝난다) ───
ALTER TABLE community.page_versions
      ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ;

-- ─── 2. 옛 행 채우기 ───
-- 지금까지 `edited_at` 은 `INSERT` 시각(= 스냅샷을 뜬 시각)이었다. 그 사실을 그대로 옮긴다.
-- 이미 값이 있는 행은 건드리지 않는다(여러 번 돌려도 같다).
UPDATE community.page_versions
   SET created_at = edited_at
 WHERE created_at IS NULL;

-- ─── 3. 기본값과 NOT NULL ───
ALTER TABLE community.page_versions
      ALTER COLUMN created_at SET DEFAULT now();
ALTER TABLE community.page_versions
      ALTER COLUMN created_at SET NOT NULL;

-- ─── 4. 인덱스 ───
-- `checklist_events(page_id, at DESC)` 는 3단계 스키마(db/community/schema.sql)의
-- `checklist_events_page_idx` 가 이미 같은 모양이다. 없는 환경(옛 사본)을 위해 같은 이름으로
-- 한 번 더 적어 둔다 — 있으면 아무 일도 하지 않는다.
CREATE INDEX IF NOT EXISTS checklist_events_page_idx
    ON community.checklist_events (page_id, at DESC);

COMMIT;

-- ─── 확인 (적용한 사람이 눈으로 본다) ───
--   \d community.page_versions
--   SELECT count(*) FILTER (WHERE created_at IS NULL) AS 빈칸 FROM community.page_versions;
--   SELECT indexname FROM pg_indexes WHERE schemaname='community' AND tablename IN ('page_versions','checklist_events');
