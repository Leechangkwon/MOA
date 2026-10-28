-- 시험·임시 DB 정리 (2026-10-26 사용자 결정 「시험·임시 DB」) — 운영 tbox_re · 시스템 postgres 는 건드리지 않는다
-- 모두 offsite-backups-20261026-predelete 에 pg_dump 가 있다.
\set ON_ERROR_STOP on
DROP DATABASE IF EXISTS tbox;               -- 옛 t-box v1 (/var/www/html)
DROP DATABASE IF EXISTS tbox_qf9_8421_test;
DROP DATABASE IF EXISTS tbox_qf9_8423_test;
DROP DATABASE IF EXISTS tbox_rvg0_base;
DROP DATABASE IF EXISTS tbox_rvg0_main;
DROP DATABASE IF EXISTS tbox_rvg0_mig;
DROP DATABASE IF EXISTS tbox_rvg0_race;
DROP DATABASE IF EXISTS tbox_rvg0_reh;
DROP DATABASE IF EXISTS tbox_rvg0_tpl;
DROP DATABASE IF EXISTS tbox_uxr1_tpl;
DROP DATABASE IF EXISTS tbox_predel_test;   -- 이번 모의 실행용
SELECT datname FROM pg_database WHERE NOT datistemplate ORDER BY 1;
