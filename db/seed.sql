-- db/seed.sql — 초기 데이터 (ARCHITECTURE 6.2-1, PRD 5.1 · 5.2)
--
-- 실행 계정: tbox_owner. schema.sql 직후 1회.
-- users 는 여기에 넣지 않는다 — 비밀번호는 Argon2id 해시로만 저장해야 하므로(PRD S-09-4)
-- 2단계에서 PHP password_hash() 로 생성한다. SQL 시드에 평문·고정 해시를 두지 않는다.

\set ON_ERROR_STOP on

-- 권한 매트릭스 16행 — PRD 5.1(읽기) / 5.2(업로드) 표 그대로 (+ 아래 G-0 개인 4행).
-- 변경은 마이그레이션 파일 + 코드 리뷰 + SECURITY.md 이력이 필요하다(권한 표 변경 = 보안 정책 변경).
INSERT INTO permissions (role, access_level, can_read, can_upload) VALUES
('Admin','public',true,true),    ('Admin','internal',true,true),    ('Admin','team',true,true),    ('Admin','admin-only',true,true),
('Manager','public',true,true),  ('Manager','internal',true,true),  ('Manager','team',true,true),  ('Manager','admin-only',false,false),
('Employee','public',true,true), ('Employee','internal',true,true), ('Employee','team',true,true), ('Employee','admin-only',false,false),
('Guest','public',true,false),   ('Guest','internal',false,false),  ('Guest','team',false,false),  ('Guest','admin-only',false,false);

-- G-0 (PRD F-73) — 「개인」 4행. 읽기는 전부 거짓(개인은 표가 아니라 주인·받은 권한으로 판정 — CHECK permissions_private_by_people),
-- 올리기는 쓰기 자격 있는 역할만. (기존 DB 는 db/migrations/2026-10-10_access_grants.sql 이 같은 4행을 넣는다.)
INSERT INTO permissions (role, access_level, can_read, can_upload) VALUES
('Admin','private',false,true),  ('Manager','private',false,true),  ('Employee','private',false,true),  ('Guest','private',false,false);

-- 팀 2개 (PRD Q-1 미결 — 실습용 최소 구성)
INSERT INTO teams (name) VALUES ('개발팀'), ('영업팀');
