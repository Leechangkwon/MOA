-- db/grants.sql — DB 계정 최소 권한 (ARCHITECTURE 10장 · 7.2, PRD S-09-10, S-07)
--
-- 실행 계정: postgres (슈퍼유저). schema.sql · seed.sql 이 끝난 뒤 실행한다.
-- 실행 예:  sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/grants.sql
--
-- 원칙
--   · 앱 계정 tbox_app 은 "필요한 표에, 필요한 동작만".
--   · 파일 삭제는 논리 삭제(UPDATE deleted_at)이므로 files 에 DELETE 권한이 아예 필요 없다.
--   · permissions 는 SELECT 만  → 화면·API 로 권한 표를 바꿀 수 없다(권한 상승 경로 차단, ARCH 6.2-1).
--   · audit_logs 는 SELECT·INSERT 만 → 코드 버그가 있어도, 침입자가 앱 계정을 얻어도 지울 수 없다(Append-only).
--   · DROP / TRUNCATE / ALTER 는 어디에도 주지 않는다(테이블 소유자 tbox_owner 만 가능).
--   · 스키마에 표를 만들 권한(CREATE)과 이 DB 에 접속할 권한(CONNECT)은 PUBLIC 에서 회수하고
--     필요한 역할에만 준다(아래 ①~③).

\set ON_ERROR_STOP on

-- 스키마 사용 권한.
-- `REVOKE ALL ON SCHEMA public FROM PUBLIC` 은 하지 않되(USAGE 를 통째로 끊으면 확장·도구 동작을
-- 예측 불가하게 바꾼다), CREATE 와 CONNECT 는 회수한다. 아래 두 REVOKE 가 그것이다.
GRANT USAGE ON SCHEMA public TO tbox_app;

-- ① 앱 계정(및 PUBLIC 에 속한 모든 역할)이 public 스키마에 표를 만들 수 없게 한다. — PRD S-09-10
--    PostgreSQL 14 는 public 스키마의 CREATE 를 PUBLIC 에 기본 부여한다. 그 결과 1단계 검토에서
--    tbox_app 으로 `CREATE TABLE hax(i int)` 가 성공했다(최소 권한 위반).
--    USAGE 는 남겨 두므로 기존 표 접근에는 영향이 없다.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- ②  마이그레이션 계정만 표를 만들 수 있다. tbox_owner 는 배포 시에만 쓰이고
--    비밀번호는 /etc/tbox/owner.pgpass (0600 root) 에만 있다. — ARCH 10장
--    (①로 PUBLIC 의 CREATE 를 회수했으므로, 이 GRANT 가 없으면 db/schema.sql 재적용이 실패한다.)
GRANT CREATE, USAGE ON SCHEMA public TO tbox_owner;

-- ③ 이 DB 에 접속할 수 있는 역할을 명시한 둘로 한정한다. — PRD S-09-10
--    같은 PostgreSQL 인스턴스에는 옛 t-box 의 역할(`tbox` 등)이 함께 있다.
--    PUBLIC 의 기본 CONNECT 를 그대로 두면 그 역할들로도 tbox_re 에 붙을 수 있다.
REVOKE CONNECT ON DATABASE tbox_re FROM PUBLIC;
GRANT  CONNECT ON DATABASE tbox_re TO tbox_app, tbox_owner;

-- ─── 먼저 전부 회수한다(재실행 시 과거 권한이 남지 않게) ───
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders FROM tbox_app;

-- ─── 표별 권한 ───
GRANT SELECT, INSERT, UPDATE                 ON users     TO tbox_app;
GRANT SELECT, INSERT, UPDATE                 ON teams     TO tbox_app;
GRANT SELECT, INSERT, UPDATE                 ON files     TO tbox_app;
GRANT SELECT, INSERT, UPDATE, DELETE         ON sessions  TO tbox_app;   -- 로그아웃·세션 폐기(R-10)에 DELETE 필요
GRANT SELECT                                 ON permissions TO tbox_app; -- ★ SELECT 만
GRANT SELECT, INSERT                         ON audit_logs  TO tbox_app; -- ★ UPDATE/DELETE/TRUNCATE 없음 (PRD S-07)
-- 15-2 공유 링크 (F-13, ARCH 5.12 · 6.2-3). 회수도 UPDATE(revoked_at) 이므로 DELETE 가 필요 없다 —
-- 행이 남아야 "어떤 링크로 새어 나갔나" 를 감사 로그의 detail.share_id 로 되짚을 수 있다.
GRANT SELECT, INSERT, UPDATE                 ON share_links TO tbox_app; -- ★ DELETE 없음
-- 18-1 폴더 (F-26, ARCH 5.16 · 6.2-4). **DELETE 가 있는 두 번째 표**다(첫째는 sessions) —
-- 빈 폴더는 논리 삭제가 아니라 실제로 지운다. 지워도 잃을 기록이 없고(파일은 files 에 그대로),
-- 지운 사실은 감사 로그 folder.delete 에 남는다. 파일이 참조하는 폴더는 FK(ON DELETE RESTRICT)가 막는다.
GRANT SELECT, INSERT, UPDATE, DELETE         ON folders   TO tbox_app;

-- 19단계 구매·재고 모듈 기초정보 (F-28 · F-29, ARCH 5.18 · 10장 마지막 행).
-- ★ **DELETE 가 한 줄도 없다** — 기초정보를 그만 쓰는 것은 `is_active = false` 다.
--   앱 계정에 지우는 자격 자체가 없으므로, 핸들러에 결함이 생겨도 전표가 참조하는
--   기초정보가 사라져 추적이 끊기는 경로가 만들어지지 않는다 (audit_logs 와 같은 원칙).
-- ★ 전표·재고 표(p_docs · p_doc_lines · p_stock_moves)는 20·21단계에 생긴다. 그때
--   `p_stock_moves` 만 INSERT·SELECT 로 갈라 준다(원장 불변, SECURITY R-66).
REVOKE ALL ON p_brands, p_branches, p_locations, p_vendors, p_categories,
              p_items, p_item_branches, p_par_levels, p_import_mappings FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_brands          TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_branches        TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_locations       TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_vendors         TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_categories      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_items           TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_item_branches   TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_par_levels      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_import_mappings TO tbox_app;

-- ─── 시퀀스 (INSERT 하는 표의 id 발급에 필요) ───
GRANT USAGE, SELECT ON SEQUENCE users_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE teams_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE files_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE audit_logs_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE share_links_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE folders_id_seq     TO tbox_app;
-- 19단계 p_* 시퀀스. p_item_branches 는 (item_id, branch_id) 복합 PK 라 시퀀스가 없다.
GRANT USAGE, SELECT ON SEQUENCE p_brands_id_seq          TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_branches_id_seq        TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_locations_id_seq       TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_vendors_id_seq         TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_categories_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_items_id_seq           TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_par_levels_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_import_mappings_id_seq TO tbox_app;

-- 20단계 구매 전표 (F-30~F-32, ARCH 5.18 「연결」·「채번」, SECURITY R-66).
-- ★ 여기에도 **DELETE 가 한 줄도 없다** — 전표 취소는 `status = 'void'` 다.
--   지운 전표는 잔량 계산(요청 qty − Σ 자식 qty)에서 조용히 사라지므로, 지울 수 있다는 것은
--   곧 "발주 이력을 흔적 없이 바꿀 수 있다" 는 뜻이다 (audit_logs 와 같은 원칙).
-- ★ `p_stock_moves`(재고 원장)는 21단계다. 그때 그 표만 INSERT·SELECT 로 갈라 준다.
REVOKE ALL ON p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_docs       TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_doc_lines  TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_doc_seq    TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_lists      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_list_items TO tbox_app;
GRANT SELECT                 ON p_line_chain TO tbox_app;   -- 줄 연결 사슬 뷰 (읽기 전용)
GRANT USAGE, SELECT ON SEQUENCE p_docs_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_doc_lines_id_seq TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_lists_id_seq     TO tbox_app;
-- p_doc_seq·p_list_items 는 복합 PK 라 시퀀스가 없다.
-- sessions.id 는 CHAR(64) 해시이므로 시퀀스가 없다. permissions 도 시퀀스가 없다.

-- ─── 앞으로 만들 표에 자동으로 권한이 새지 않게 한다 ───
-- (기본 권한을 설정하지 않는다 = 새 표는 명시적으로 GRANT 해야 앱이 쓸 수 있다)

-- ─── tbox_backup (읽기 전용 백업 계정) — PRD S-08 / ARCH 10장 · 11.1 (9단계, 2026-09-11)
--
-- 역할 **생성**과 비밀번호는 deploy/install.sh 가 한다(무작위 → /etc/tbox-v2/backup.pgpass 0600 root).
-- 이 파일은 권한만 다룬다 — 권한이 두 곳에 흩어지면 회수 누락이 생긴다.
--
-- 왜 읽기 전용인가: 백업은 "정리된 형태의 전체 데이터" 라 원본보다 위험하다 (PRD S-08 백업 보안).
-- 그 계정이 쓰기까지 되면, 백업 키가 새는 순간 데이터 유출이 아니라 데이터 **조작**이 된다.
-- pg_dump 에 필요한 것은 SELECT 뿐이다(시퀀스 현재값까지 뜨려면 시퀀스 SELECT 도 필요하다).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_backup') THEN
        RAISE EXCEPTION 'tbox_backup 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE tbox_re TO tbox_backup;
GRANT USAGE   ON SCHEMA   public  TO tbox_backup;

-- 재실행 시 과거 권한이 남지 않게 먼저 전부 회수한다(위 tbox_app 과 같은 방식).
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders,
              p_brands, p_branches, p_locations, p_vendors, p_categories,
              p_items, p_item_branches, p_par_levels, p_import_mappings,
              p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain FROM tbox_backup;

GRANT SELECT ON ALL TABLES    IN SCHEMA public TO tbox_backup;   -- ★ SELECT 만
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;   -- 시퀀스 현재값 (pg_dump)

-- 앞으로 만들 표에 자동으로 권한이 붙지 않게 기본 권한(ALTER DEFAULT PRIVILEGES)은 설정하지 않는다.
-- 새 표를 만들면 이 파일에 한 줄을 더해 다시 실행한다 — 백업에서 표가 빠지는 것은
-- 여기서 드러나야 하고, 조용히 권한이 따라붙는 것보다 낫다.

-- ─── tbox_perm (권한 매트릭스 편집 전용) — PRD F-25 ⑤ / ARCH 5.15 · 10장 (17-2, 2026-09-12)
--
-- 역할 **생성**과 비밀번호는 deploy/install.sh 가 한다(무작위 → .env 의 DB_PERM_USER/DB_PERM_PASS).
-- 이 파일은 권한만 다룬다 — 권한이 두 곳에 흩어지면 회수 누락이 생긴다(tbox_backup 과 같은 방식).
--
-- 왜 별도 롤인가 (SECURITY R-60 · R-43, PRD Q-12)
--   화면에서 권한 매트릭스를 고칠 수 있게 하면서도 "앱 런타임 롤은 이 표에 SELECT 만" 이라는
--   방어(위 tbox_app 의 ★ 줄)를 **그대로 지키기 위해서**다. 쓰기 자격은 이 롤에만 있고,
--   이 롤로 여는 연결은 핸들러 하나(src/handlers/admin_permissions_update.php 의 Db::perm())
--   에서만 만들어진다. 다른 핸들러에 SQL 인젝션 결함이 생겨도 그 연결은 tbox_app 이라
--   권한 표를 바꾸지 못한다.
--
--   · permissions 에 SELECT, UPDATE 만 — INSERT·DELETE 는 주지 않는다. 16행은 고정이며
--     행을 늘리거나 지우는 길 자체를 만들지 않는다 (ARCH 6.2-1).
--   · 다른 표 권한 0 — 이 자격이 새어도 계정·파일·감사 로그·세션에는 닿지 못한다.
--   · 시퀀스 권한 없음 (INSERT 가 없으므로 필요 없다).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tbox_perm') THEN
        RAISE EXCEPTION 'tbox_perm 역할이 없다 — deploy/install.sh 가 먼저 만든다 (ARCH 10장)';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE tbox_re TO tbox_perm;
GRANT USAGE   ON SCHEMA   public  TO tbox_perm;

-- 재실행 시 과거 권한이 남지 않게 먼저 **전부** 회수한다(위 두 롤과 같은 방식).
-- 19단계: 구매 모듈 표에도 이 롤은 **아무 권한이 없다** (SECURITY R-60).
REVOKE ALL ON teams, users, permissions, files, audit_logs, sessions, share_links, folders,
              p_brands, p_branches, p_locations, p_vendors, p_categories,
              p_items, p_item_branches, p_par_levels, p_import_mappings,
              p_docs, p_doc_lines, p_doc_seq, p_lists, p_list_items, p_line_chain FROM tbox_perm;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM tbox_perm;

GRANT SELECT, UPDATE ON permissions TO tbox_perm;   -- ★ 이 한 줄이 전부. INSERT·DELETE 없음
