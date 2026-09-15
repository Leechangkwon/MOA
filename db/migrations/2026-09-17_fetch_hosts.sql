-- ─────────────────────────────────────────────────────────────
-- 2026-09-17_fetch_hosts.sql  (F-43 — URL 가져오기 허용 사이트 목록을 DB 로,
--                              PRD F-43 · S-05 ③, ARCHITECTURE 5.22,
--                              SECURITY R-76, docs/purchase/15-f43-fetch-allowlist-directive.md)
--
-- 목적: 「URL 로 가져오기」가 갈 수 있는 곳(허용 호스트)을 `.env` 의 문자열에서
--       **표 하나**로 옮긴다 — 관리자가 화면에서 넣고 빼되, 권한 매트릭스와 같은 급의
--       조건(재인증 · 사유 · 인증 앱 코드 · 감사 · 경보)을 지나게 하기 위해서다.
--
-- ★ 왜 표인가 (PRD F-43)
--   `.env` 는 서버에 들어갈 수 있는 사람만 고칠 수 있고, 고친 기록이 어디에도 남지 않는다.
--   허용 호스트 한 줄은 "서버가 어디로 나갈 수 있나" 를 바꾸는 값(S-05)이라, 누가·언제·왜
--   바꿨는지가 감사 로그에 남아야 한다. 표로 옮기면 그 기록이 `fetch_host.change` 로 남는다.
--
-- ★ 왜 DELETE 가 없나
--   허용에서 뺀 호스트도 "언제부터 언제까지 열려 있었나" 가 조사에 필요하다. 행을 지우면
--   그 기간을 되짚을 수 없다 — `teams.is_active`·`files.deleted_at` 와 같은 방식으로
--   **비활성화**만 한다. 앱 계정에 DELETE 권한을 주지 않아 지우는 길 자체를 없앤다.
--
-- ★ 왜 드라이브·OneDrive 호스트를 여기서 넣지 않나 (회사 결정 2026-09-15)
--   기본으로 넣으면 "관리자가 켠 적 없는데 서버가 구글로 나가는" 상태가 된다. 어떤 바깥
--   서비스로 나갈지는 사람이 화면에서 켜는 결정이어야 한다 — 이 파일은 행을 0개 만든다.
--
-- 열
--   host        소문자 호스트 이름 **정확히 한 개**. 와일드카드(`*`)·공백·IP 리터럴 금지.
--               CHECK 는 마지막 그물이고, 형식 판정은 앱(fetch_hosts_update.php)이 먼저 한다.
--   note        메모 (왜 열었나 — 화면 표시용)
--   is_active   지금 허용인가. false 면 `Fetch::allowedHosts()` 가 내려 주지 않는다.
--   created_by  넣은 사람 (users.id). 마이그레이션이 옮긴 행은 NULL 이다.
--
-- ★ 종류(kind) 열이 없다 (사용자 결정 2026-09-15 「OAuth 는 빼자 — 전체 공유된 링크만 지원」).
--   2026-09-17 초안에는 계정 연결(OAuth)이 쓸 `kind='connector'` 고정 행 자리가 있었으나,
--   그 기능을 하지 않기로 하면서 열째 걷어냈다. 쓰지 않는 열은 "언젠가 쓸 것" 이 아니라
--   읽는 사람을 헷갈리게 하는 값이다.
--
-- 권한: tbox_app  SELECT · INSERT · UPDATE (★ DELETE 없음)
--       tbox_backup SELECT 만 (PRD S-08) · tbox_perm 없음 (SECURITY R-60)
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-17_fetch_hosts.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 이미 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): CREATE TABLE/INDEX IF NOT EXISTS · GRANT · INSERT … ON CONFLICT DO NOTHING.
--   기존 행의 값을 덮어쓰는 UPDATE 가 한 줄도 없고 DELETE·TRUNCATE 가 없다
--   (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

CREATE TABLE IF NOT EXISTS fetch_hosts (
    id         BIGSERIAL   PRIMARY KEY,
    host       TEXT        NOT NULL,
    note       TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- 소문자 영숫자·점·붙임표만, 1~253자. `*` 가 들어간 값은 어떤 경로로도 들어갈 수 없다.
    -- 점이 최소 하나 있어야 한다 — `localhost` 같은 한 낱말 이름을 표 자체가 거부한다.
    CONSTRAINT fetch_hosts_host_fmt CHECK (
        host ~ '^[a-z0-9.-]{1,253}$'
        AND host NOT LIKE '%*%'
        AND host LIKE '%.%'
    ),
    CONSTRAINT fetch_hosts_note_len CHECK (note IS NULL OR length(note) <= 200)
);

-- 같은 호스트는 한 행뿐이다 — 「이미 있는 호스트」 판정과 ON CONFLICT 의 근거.
CREATE UNIQUE INDEX IF NOT EXISTS fetch_hosts_host_uidx ON fetch_hosts (host);
-- `Fetch::allowedHosts()` 가 늘 활성 행만 읽는다.
CREATE INDEX IF NOT EXISTS fetch_hosts_active_idx ON fetch_hosts (is_active);

-- 2026-09-15 결정(OAuth 철회)으로 종류 열을 걷어낸다. 같은 날 만든 초안에만 있던 열이라
-- 값이 들어간 행이 없다 — 지우는 것은 열 하나뿐이고 행은 한 줄도 건드리지 않는다.
ALTER TABLE fetch_hosts DROP COLUMN IF EXISTS kind;

-- ─── `.env` 의 FETCH_ALLOWED_HOSTS 를 옮긴다 ───
--
-- 2026-09-17 실측: `.env` 의 값은 **빈 문자열**이라 옮길 행이 0개다(검토자 실측, 지시서 0장).
-- 값이 있는 환경에 이 파일을 적용한다면 아래 VALUES 에 호스트를 **소문자로** 적는다.
-- 문자열 결합이 아니라 VALUES 목록이며, 잘못된 표기는 위 CHECK 가 막는다.
INSERT INTO fetch_hosts (host, note, created_by)
SELECT v.host, '.env FETCH_ALLOWED_HOSTS 에서 옮김', NULL
  FROM (VALUES (NULL::text)) AS v(host)
 WHERE v.host IS NOT NULL
ON CONFLICT (host) DO NOTHING;

COMMIT;

-- ─── 권한 (db/grants.sql 과 같은 내용) ───
REVOKE ALL ON fetch_hosts FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON fetch_hosts TO tbox_app;   -- ★ DELETE 없음 (비활성화만)
GRANT USAGE, SELECT ON SEQUENCE fetch_hosts_id_seq TO tbox_app;
GRANT SELECT ON fetch_hosts TO tbox_backup;
GRANT SELECT ON SEQUENCE fetch_hosts_id_seq TO tbox_backup;
