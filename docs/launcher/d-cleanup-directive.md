# 구현 지시서 — Qaredi D: 옛 커뮤니티 시험 앱 정리 · 스키마 `community` 비우기 · 설치 점검 주소 `/tbox`

> Fable 작성 2026-09-22. 계획 `docs/integration-plan.md` D. 사용자 결정: 「단계별로 순차 진행」(2026-09-22) — C 뒤 D, 그 뒤 B·커뮤니티 3단계.
> **먼저 읽을 것**: `CLAUDE.md` 4·5장 · `docs/integration-plan.md` D 절 · `docs/tbox/ARCHITECTURE.md` 5.2(t-box 표에 GRANT 만)·9장(서버 설정) · `/var/www/community/deploy/install.sh`(옛 앱이 **무엇을 만들었는지** 읽기 전용 참고 — 그 저장소는 고치지 않는다).

## 목표
옛 커뮤니티 시험 앱(`/var/www/community`, 2026-09-21~22)이 서버에 남긴 것을 **정확히 그것만** 지우는 스크립트 하나와, 스키마 `community` 를 비워 3단계 스키마가 들어올 자리를 만드는 마이그레이션, 그리고 `deploy/install.sh` 의 설치 뒤 점검 주소를 `/tbox` 로 맞추는 것. **t-box 데이터·계정·표·권한은 한 글자도 바뀌지 않는다.** 실행은 전부 **사용자**(root).

## 지울 것 (2026-09-22 실측 인벤토리 — 이것 밖은 건드리지 않는다)
| 종류 | 이름 | 확인한 상태 |
|---|---|---|
| nginx | `/etc/nginx/sites-enabled/community-local`(링크) · `/etc/nginx/sites-available/community-local` | 8083 이 302 로 살아 있음 |
| php-fpm | `/etc/php/8.1/fpm/pool.d/community.conf` | 있음 |
| 시스템 계정 | `community-php`(uid 997, nologin) | 있음 |
| 로그 | `/var/log/community/`(owner community-php:adm) | 있음 |
| DB 역할 | `community_app`(LOGIN) · `community_owner`(LOGIN, 스키마 `community` 소유) | 있음. `community_app` 은 `public.users`·`teams`·`permissions` SELECT(+users 3열 UPDATE) 를 받았고 `community_owner` 는 `public.users(id)`·`teams(id)` REFERENCES 를 받았다 |
| DB 스키마 | `community` — 표 `audit_logs`·`boards`·`comments`·`posts`·`sessions`(시험 데이터: boards 3·posts 3) | 있음 |
| 설정 파일 | `/var/www/community/.env`(있으면) | 저장소는 참고용 보관 — **파일은 지우지 않고** 안내만 |

## 수정·생성 파일 — 허용 목록
| 파일 | 할 일 |
|---|---|
| `deploy/uninstall_community_app.sh`(새) | 아래 「스크립트 요구」. root 로 실행. |
| `db/migrations/2026-09-22_community_reset.sql`(새) | 트랜잭션 하나: ① 사전 확인 — `community.posts`·`comments` 행 수를 세어 **둘 다 시험 데이터 범위(각 ≤ 10행)** 가 아니면 `RAISE EXCEPTION`(운영 데이터를 모르고 지우지 않는다) ② `DROP TABLE IF EXISTS community.sessions, community.comments, community.posts, community.boards, community.audit_logs CASCADE` ③ `ALTER SCHEMA community OWNER TO tbox_owner` ④ `REVOKE ALL ON SCHEMA community FROM community_app, community_owner`(있을 때만 — `DO $$ … IF EXISTS(SELECT 1 FROM pg_roles …) $$`) ⑤ `GRANT USAGE ON SCHEMA community TO tbox_app, tbox_backup`. 표는 **만들지 않는다**(3단계 스키마가 만든다). 머리 주석에 되돌리는 법(백업: 옛 앱 저장소 `db/schema.sql` + 태그) |
| `deploy/install.sh` | 64행 `LOCAL_HTTP` 는 그대로 두고, 584~607행의 점검 URL 중 **t-box 라우트인 것**만 `${LOCAL_HTTP}/tbox/...` 로(`/api/health` ×3 · `/` 는 이제 **런처**이니 `/` 그대로 두고 기대값 「200 런처」로 주석 · `/nope` → `/tbox/nope` · 보안 헤더 확인은 `/tbox/`). `/.env` `/src/Db.php` `/db/schema.sql` `/index.php` `/protected/x` 는 **루트 그대로**(404 기대가 유지되는지가 곧 점검이다 — 옛 경로 308 이 아니라 404 여야 한다: 라우트가 아니므로). 659~660행 안내 문장의 v2 주소 뒤에 `/tbox/` |
| `docs/integration-plan.md` D 절 | 실행 순서·되돌리기 한 줄씩(문서만) |

**만들지 않는 것**: t-box 표·역할·`db/schema.sql`·`db/grants.sql` 변경 · `/var/www/community` 저장소 편집 · 스키마 `community` 안에 새 표 · `.env` 키 · 커밋 · 실행(sudo·psql DDL·systemctl).

## 스크립트 요구 (`deploy/uninstall_community_app.sh`)
- `set -euo pipefail`, root 확인, `--dry-run`(무엇을 할지 출력만) 지원. **멱등**: 없는 것은 건너뛰고 「없음」이라고 찍는다.
- 순서와 안전장치:
  1. 사전 확인: `tbox_re` DB 존재 · `tbox_app`·`tbox_owner`·`tbox_backup` 역할 존재(없으면 중단 — t-box 가 정상인 서버에서만 돈다) · `community.posts` 행 수 ≤ 10(아니면 중단, 위 마이그레이션과 같은 기준).
  2. 마이그레이션 실행: `sudo -u postgres psql -v ON_ERROR_STOP=1 -d tbox_re -f db/migrations/2026-09-22_community_reset.sql`.
  3. DB 역할: `REVOKE` 잔여 권한(`public.users`·`teams`·`permissions` 의 GRANT, REFERENCES) → `REASSIGN OWNED BY community_owner TO tbox_owner`(스키마 소유 이전 뒤 남은 것) → `DROP OWNED BY community_app, community_owner` → `DROP ROLE community_app, community_owner`. **`DROP OWNED` 는 그 두 역할이 소유·권한을 가진 것만 지운다**는 것을 주석에 적고, 실행 전에 `\dp public.*` 에서 두 역할이 가진 권한 목록을 출력해 남긴다.
  4. nginx: `sites-enabled/community-local` 링크 제거 → `sites-available/community-local` 을 `sites-available/legacy-community/` 로 **옮겨 보존**(지우지 않음, `switch-site.sh` 의 legacy 폴더와 같은 방식) → `nginx -t` 통과해야 `systemctl reload nginx`(실패면 링크를 되돌리고 중단).
  5. php-fpm: `pool.d/community.conf` 를 `pool.d/legacy-community.conf.disabled` 로 옮김(pool.d 는 `*.conf` 만 읽는다) → `php-fpm8.1 -t` → `systemctl reload php8.1-fpm`.
  6. 시스템 계정 `community-php`: 프로세스 0 확인 뒤 `userdel`(홈 없음). 로그 `/var/log/community` → `/var/backups/tbox/legacy-community-log-<날짜>.tar.gz` 로 묶어 보존한 뒤 폴더 제거.
  7. 사후 확인 출력: `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8083/` 가 `000`(연결 거부) · `psql -Atc "SELECT rolname FROM pg_roles WHERE rolname LIKE 'community%'"` 가 빈 줄 · `\dn community` 소유 `tbox_owner` · 표 0개 · **t-box 회귀는 스크립트가 돌리지 않고** 「Fable 이 `tests/stage26.sh` 로 확인」이라고 안내만.
- 비밀번호·토큰을 출력하지 않는다. `.env` 를 읽지 않는다.

## 금지 패턴
`DROP SCHEMA community`(스키마 자체는 남긴다 — 3단계가 쓴다) · t-box 표에 `REVOKE`(t-box 역할 것) · `DROP DATABASE` · `rm -rf` 로 설정·로그 삭제(옮겨 보존) · `userdel -r` · 조건 없는 `DROP OWNED` · 사용자 확인 없는 실행.

## 완료 기준 — Opus 가 직접 실행할 것
| # | 항목 | 실행·기대 |
|---|---|---|
| 1 | `bash -n deploy/uninstall_community_app.sh` · `bash deploy/uninstall_community_app.sh --dry-run`(root 아니어도 dry-run 은 돌아 계획만 출력) | 문법 통과 · 7단계가 순서대로 「할 일」로 찍힘, 실제 변경 0 |
| 2 | 마이그레이션 문법: `sudo -n -u postgres psql -d tbox_re -c 'BEGIN;' -f db/migrations/2026-09-22_community_reset.sql -c 'ROLLBACK;'` 은 **실행하지 않는다**(운영 DB DDL 금지). 대신 **임시 DB** 로 검증: `createdb` 권한이 없으면 `psql -d tbox_re` 로 `EXPLAIN` 불가한 DDL 이므로 **문법 검사만** — `python3 -c` 로 세미콜론 단위 분리 뒤 `psql -d postgres -c "…"` 대신 **`pg_dump -s -n community tbox_re` 로 현재 표 목록을 확인**하고, 스크립트가 지우는 표 이름 5개가 그 목록과 정확히 같은지 대조해 표로 보고 | 표 이름 5개 일치 · 그 밖 표 0 |
| 3 | `bash -n deploy/install.sh` · 바뀐 줄 diff 원문 · `grep -n "LOCAL_HTTP}/" deploy/install.sh` 로 `/tbox` 접두가 붙은 줄과 안 붙은 줄(의도적으로 루트인 5개)을 표로 | |
| 4 | `bash scripts/security_lint.sh` 0건 | |

## 보고 형식
① 변경·새 파일 ② 실행 명령과 출력 원문(dry-run 전체 포함) ③ 「지우는 것」 표와 인벤토리 대조 ④ 사용자가 실행할 명령 **정확히 한 줄씩**(순서: `sudo bash deploy/uninstall_community_app.sh --dry-run` → 확인 뒤 `sudo bash deploy/uninstall_community_app.sh`) ⑤ 못 한 것과 이유 ⑥ 설계와 다르게 한 것.
