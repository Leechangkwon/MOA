> **2026-09-22 통합 결정(사용자)**: 커뮤니티는 별도 앱이 아니라 **Qaredi 한 앱 안의 모듈**이다(사이트 `PRD.md` 3장). 이 문서의 「별개 앱」·`/var/www/community`·8083·`community_app` 언급은 v0.1~v0.2 이력이며, 통합 계획(`docs/integration-plan.md`) B·D 단계에서 설계를 갱신할 때 고친다.

# 커뮤니티 ARCHITECTURE — t-box 골격 위에, t-box 계정으로

> PRD 의 "무엇을" 을 "어떻게" 로 옮긴 문서. t-box `ARCHITECTURE.md`(`/var/www/tbox-v2`)와 **같은 절 구성**을 따르고,
> 같은 것은 "t-box n장과 같다" 로 적고 **다른 것만** 자세히 쓴다. 코드를 짜거나 서버를 세울 때 읽는다.

| 항목 | 내용 |
|---|---|
| 문서 버전 | v0.1 (설계) 2026-09-21 |
| 스택 | 순수 PHP 8.1 · PostgreSQL 14 · nginx 1.18 · Ubuntu 22.04 — t-box 와 같은 VM, 같은 인스턴스 |
| 원칙 | ① t-box 를 **고치지 않는다** ② 판정은 서버 `Authz` 만 ③ 못 보는 것은 SQL 에서 제외 ④ 본문은 평문 ⑤ 외부 요청 0 |

---

## 1. 전체 그림 — 한 장으로 보기

```
브라우저 ──https──▶ 강사 리버스 프록시 (changkwon.mso.so, TLS 종단)
                        └──http──▶ 이 VM :80  nginx
                                    ├── [지금] tbox-v2 (root /var/www/tbox-v2/public)      ← t-box, 바뀌지 않는다
                                    └── [3단계 Q-3 뒤] 커뮤니티 공개 경로 (루트 또는 /community)
                    로컬 검토용  127.0.0.1:8083 → /var/www/community/public/index.php
                                                       │ fastcgi  unix:/run/php/php8.1-fpm-community.sock (풀 community, 계정 community-php)
                                                       ▼
                                   PostgreSQL  DB tbox_re
                                     ├── 스키마 public     users · teams · permissions   ← 커뮤니티는 읽기(+ users 3열 UPDATE)
                                     │                     files · folders · sessions · audit_logs · api_tokens … ← 커뮤니티 권한 0
                                     └── 스키마 community  sessions · audit_logs · boards · posts · comments   ← 커뮤니티 소유
                                   파일  /var/log/community/{app.log, audit.log}   (저장 파일 없음 — 업로드가 없다)
```

**한 DB·두 스키마·두 앱.** 두 앱은 코드·프로세스·세션·감사 로그·쿠키 이름이 전부 다르고, 만나는 곳은 **`public.users`·`teams`·`permissions` 세 표를 함께 읽는 것** 하나다(+ 잠금 3열 갱신, 5장).

---

## 2. 구성요소 역할표

| 구성요소 | 역할 | t-box 와 |
|---|---|---|
| nginx (`deploy/sites/community-local.conf` · `community.conf`) | `public/` 만 노출, 보안 헤더, `/api/login`·`/api/me/reauth` 속도 제한 | 같은 모양. 속도 제한 zone 은 **자기 것**(`comm_login`, `deploy/sites/community-common.conf` → `/etc/nginx/conf.d/`) |
| php-fpm 풀 `community` (`deploy/php-fpm-pool.conf`) | 계정 `community-php`, 소켓 `/run/php/php8.1-fpm-community.sock`, `open_basedir=/var/www/community:/var/log/community:/tmp`, `disable_functions`·`allow_url_fopen=Off` | 같은 제한. 업로드가 없으므로 `upload_max_filesize=1M`·`post_max_size=1M` |
| `public/index.php` 라우터 | 라우트 표 + 관문(4장) | t-box 4장 복사. 토큰 가지·「역할로 보기」·강제 절차 두 갈래 **제거**, 관문 `tbox_required` **추가** |
| `src/Auth.php` | 로그인·세션·재인증·잠금 | t-box 복사. `api_tokens`·`act_*`·비밀번호 변경·MFA 등록 **제거**. 세션 표는 `community.sessions`(search_path) |
| `src/Authz.php` | 정책·매트릭스·행 단위 판정 | t-box 복사. `file.*`·`org.manage`·`user.manage`·`audit.read`·폴더 함수 **제거**, `post.write`·`board.manage`·`decidePostCreate`·`decideCommentCreate`·`decidePostChange`·`canWrite`·`canManageBoards`·`visibleWhere(u,'b')` **추가** |
| `src/Audit.php` | 감사 로그 표 + 파일 | t-box 복사. 문장 표(8.2)만 이 앱 것으로 교체 |
| `src/Http.php` · `Db.php` · `Env.php` · `Totp.php` · `Changelog.php` | 응답·쿠키·CSRF·쿼리 좁힘 / PDO / .env / TOTP 검증 / 버전 | t-box 복사(문구 상수 목록만 다름). `Db::perm()` 은 **없다** |
| `src/handlers/*.php` | 엔드포인트 18개 | 새로 쓴다(7장). 화면 3개는 t-box 화면 핸들러 골격 |
| `src/views/shell_top.php` · `shell_bottom.php` | 공통 셸 | t-box 복사. 내비는 「게시판」·「t-box 로 가기」·(Admin) 「게시판 관리」 |
| `public/assets/app.css` | 디자인 토큰·구성 요소 | t-box **그대로 복사**(DESIGN.md 가 같은 파일). 커뮤니티 전용 규칙은 파일 끝 한 절 |
| `public/assets/app.js` | 공통 도우미 + 로그인 + 셸 + 커뮤니티 화면 | **새로 쓴다** — t-box 의 공통부(setText·paintIcons·readJson·toLogin·재인증 모달·initLogin·initShell·도움말·다크 모드)만 옮기고 나머지는 이 앱 것 |
| `db/schema.sql` · `grants.sql` · `seed.sql` | 스키마 `community` 5표 · 역할 권한 · 시드 게시판 2개 | 새로 쓴다(6·9장) |
| `deploy/install.sh` | 계정·디렉터리·DB 역할·스키마·`.env`·풀·nginx | t-box 것을 줄여서. **사용자가 sudo 로 실행** |
| `scripts/security_lint.sh` | 금지 패턴 | t-box 복사. cURL 예외(`Fetch.php`)와 `Db::perm`·`act_role` 절 **제거** — 예외 0 |
| `tests/stage1.sh` · `stage2.sh` | PRD 10장 T-1~T-13 | t-box `tests/*.sh` 형식 |

---

## 3. 디렉터리 구조

```
/var/www/community/
├── public/                       nginx root — 여기만 URL 로 보인다
│   ├── index.php                 단일 입구(라우터)
│   └── assets/ app.css · app.js
├── src/                          URL 에 없다
│   ├── bootstrap.php  Env.php  Db.php  Http.php  Auth.php  Authz.php  Audit.php  Totp.php  Changelog.php  Text.php(본문 규칙 한 곳)
│   ├── handlers/                 엔드포인트별 처리 (7장)
│   ├── views/ shell_top.php · shell_bottom.php
│   └── help/ screens.php         「사용 방법」 글 한 벌
├── db/ schema.sql · grants.sql · seed.sql · migrations/
├── deploy/ install.sh · php-fpm-pool.conf · sites/{community-common,community-local,community}.conf · logrotate.d/community
├── scripts/ security_lint.sh · release.sh · changelog_md.php · totp_code.php(복사)
├── tests/ stage1.sh · stage2.sh
├── docs/ 지시서
├── .env                          (git 제외) 소유 changkwon:community-php 0640
├── .env.example  VERSION  CHANGELOG.json  CHANGELOG.md
└── PRD.md  ARCHITECTURE.md  SECURITY.md  STAGES.md  CLAUDE.md  DESIGN.md  README.md
```

시스템 쪽: `/var/log/community/{app.log,audit.log}`(0750 `community-php:adm`, audit.log `chattr +a`) · `/etc/community/owner.pgpass`(0600 root) · `/etc/php/8.1/fpm/pool.d/community.conf` · `/etc/nginx/sites-available/community-local`(→ enabled) · `/etc/nginx/conf.d/community-common.conf`. **저장 파일 디렉터리는 없다**(업로드 없음).

---

## 4. 모든 요청이 거치는 공통 관문 — t-box 4장과 같다, 세 곳만 다르다

순서(`public/index.php`): 라우트 표 검증(부팅 시, 정책·핸들러 파일 존재) → 경로 매칭(`{id}` UUID · `{num}` 정수) → `[1]` 세션(`Auth::requireLogin`, 없으면 화면은 302 `/login`·API 는 401) → `[2]` CSRF(`Http::requireCsrf`, 상태 변경 메서드) → **`[3] 관문 tbox_required`** → `[4]` 인가(`Authz::authorize(policy, user)`, 실패 403 동일 문구 + `authz.route deny`) → `[5]` 재인증(`Authz::requiresReauth(policy)` 이면 `reauth_fresh` 필수, 아니면 403 `{next:"reauth"}`) → 핸들러.

| 다른 곳 | t-box | 커뮤니티 |
|---|---|---|
| 토큰 가지 `[2-T]` | `X-API-Token` 라우트 집합 셋 | **없다.** 헤더를 읽는 코드가 없다 |
| 강제 절차 관문 | `password_change` → 비밀번호 변경 창구 허용, `mfa_setup` → 등록 창구 허용 | **`tbox_required` 하나.** `Auth::nextStep(user)` 가 `'tbox'` 를 돌려주면(`must_change_password` 또는 Admin 인데 `mfa_enabled=false`) 허용 목록 `GET /`·`GET /api/me`·`POST /api/logout` 밖은 전부 `Audit::deny('auth.gate','tbox_required')` 뒤 API 403 `{error, next:"tbox"}`, 화면은 302 `/`. 홈은 `me.next === 'tbox'` 면 안내만 그린다 |
| 재인증 되돌아갈 화면 표 | `users·org·audit·trash·wiki·admin` | **비어 있다** — 재인증이 필요한 라우트는 API(`board.manage`) 뿐이고, 화면은 모달로 묻는다. `/reauth` 화면 없음 |
| 「역할로 보기」 | `act_*` 네 열·유효 역할 | **없다.** `Auth::currentUser()` 의 `role`·`team_id` 는 언제나 `users` 행 그대로. `real_role`·`acting` 키를 만들지 않는다 |

정책 이름(`Authz::KNOWN_POLICIES`): `public` · `authenticated` · `post.write`(`Authz::canWrite` — 매트릭스 `can_upload` 가 하나라도 참, Guest 는 여기서 끝) · `board.manage`(`Authz::canManageBoards` = Admin). `REAUTH_POLICIES = ['board.manage']`.

---

## 5. 계정 공유 — 한 DB, 두 스키마, 최소 권한 ★

### 5.1 왜 이렇게 하나
- 사용자 결정 Q-1 ②: 계정을 새로 만들지 않고 t-box 것을 쓴다. 두 앱이 한 PostgreSQL 인스턴스에 있으므로 **같은 DB 안의 다른 스키마**가 가장 단순하다(DB 를 갈라 놓으면 `users` 를 읽을 길이 없다 — dblink·FDW 는 새 공급망).
- t-box 코드를 복사했으므로 SQL 은 `users`·`sessions` 처럼 **스키마 없는 이름**이다. 이름을 전부 `community.sessions` 로 고치는 대신 **역할의 `search_path`** 로 푼다: `ALTER ROLE community_app SET search_path = community, public`. 그러면 `sessions`·`audit_logs`·`boards` 는 `community.*` 로, `users`·`teams`·`permissions` 는(community 에 그런 표가 없으니) `public.*` 로 풀린다. **커뮤니티 스키마에 `users`·`teams`·`permissions` 라는 이름의 표를 절대 만들지 않는다**(만들면 조용히 그쪽이 읽힌다 — SECURITY R-03, 린트 규칙 (h)).

### 5.2 역할과 권한 (`db/grants.sql`, postgres 로 실행)

| 역할 | 무엇 | 권한 |
|---|---|---|
| `community_owner` | 스키마·표 소유(DDL). 설치 때만. 비밀번호 `/etc/community/owner.pgpass` 0600 root | DB CONNECT · `CREATE SCHEMA community AUTHORIZATION community_owner` · **`REFERENCES (id) ON public.users, public.teams`** — FK 를 만들려면 대상 열의 REFERENCES 가 표 소유자에게 있어야 한다(PostgreSQL 규칙, 2026-09-22 임시 DB 검증에서 발견). `id` 열 하나뿐이고 값을 읽지도 쓰지도 못한다. 런타임 계정에는 주지 않는다 |
| `community_app` | 앱 런타임(`.env` `DB_USER`) | DB CONNECT · `USAGE ON SCHEMA public, community` · `search_path = community, public` · **t-box 표**: `SELECT ON public.users, public.teams, public.permissions` + `UPDATE (failed_count, locked_until, mfa_last_step) ON public.users` · **자기 표**: `sessions` SIUD · `audit_logs` SI · `boards`·`posts`·`comments` SIU · 시퀀스 USAGE·SELECT. 그 밖(t-box 의 `files`·`folders`·`sessions`·`audit_logs`·`api_tokens`·`share_links`·`fetch_hosts`, 스키마 CREATE, DROP·TRUNCATE·ALTER) **0** |
| `tbox_backup` (t-box 것) | t-box 백업이 DB 전체를 `pg_dump` 한다 | `USAGE ON SCHEMA community` + `SELECT ON ALL TABLES/SEQUENCES IN SCHEMA community` + `ALTER DEFAULT PRIVILEGES IN SCHEMA community … GRANT SELECT … TO tbox_backup`. **이것이 없으면 t-box 백업이 새 스키마에서 `permission denied` 로 실패한다**(G-4 위반) — grants.sql 이 이 역할이 없으면 예외로 멈춘다 |
| `tbox_app` (t-box 것) | 손대지 않는다 | search_path 기본(`"$user", public`) 그대로 → `community` 스키마를 보지도 못한다(USAGE 없음) |

t-box `db/grants.sql` 은 `REVOKE … FROM tbox_app`·`FROM PUBLIC` 만 하므로 재실행해도 `community_*` 의 권한은 건드리지 않는다. 반대로 커뮤니티 grants.sql 은 `public.*` 표에 **GRANT 만** 하고 REVOKE 는 자기 역할에 대해서만 한다 — 두 파일이 서로의 권한을 지우지 않는다.

### 5.3 왜 `users` 3열을 갱신하나 (SECURITY R-01)
로그인 실패 카운터와 잠금(`failed_count`·`locked_until`), TOTP 1회성 기준선(`mfa_last_step`)을 **공유하지 않으면**: 공격자가 t-box 에서 5회 막힌 비밀번호 시도를 커뮤니티에서 이어 갈 수 있고(계정 하나에 **잠금 두 벌**), t-box 에서 쓴 6자리 코드를 30초 안에 커뮤니티에 다시 쓸 수 있다. 세 열은 「이 계정이 지금 공격받고 있나」의 상태이므로 계정과 함께 가야 한다. 열 단위 GRANT 라 역할·비밀번호·팀·활성·MFA 비밀은 커뮤니티가 **바꿀 수 없다**(T-5).

### 5.4 공유하지 않는 것
| 것 | 왜 |
|---|---|
| 세션 | 앱마다 자기 표·자기 쿠키(`comm_session`). t-box 에서 로그아웃해도 커뮤니티는 살아 있고, 그 반대도 같다. 잠금이 걸리면 커뮤니티는 **자기** 세션만 끊는다(Q-5, R-02) |
| 감사 로그 | 앱마다 자기 표·자기 파일. 한 사람의 행동을 두 앱에 걸쳐 보려면 `actor_name`·시각으로 나란히 본다(`psql`) |
| CSRF 키 `APP_SECRET` | 다른 값. 한 앱의 키가 새어도 다른 앱의 토큰은 계산할 수 없다 |
| 계정 관리·비밀번호·MFA 등록 | 커뮤니티에 없다(NG-4). 「t-box 로 가기」 |
| 재인증 창 `reauth_at` | 커뮤니티 세션 열. t-box 에서 재인증해도 커뮤니티 관리 기능은 다시 묻는다 |

### 5.5 로그인 흐름에서 달라지는 한 줄
t-box `Auth::login` 5)~8) 그대로다. 성공 뒤 `nextStep()` 이 `'tbox'` 면 세션은 발급하되(로그아웃 창구가 있어야 하므로) 응답 `next:"tbox"` — 4장 관문이 나머지를 막는다. `Auth::MFA_REQUIRED_ROLES = ['Admin']` 도 t-box 와 같은 값이어야 한다(다르면 한 앱에서만 막히는 Admin 이 생긴다).

---

## 6. 데이터 — 스키마 `community` (`db/schema.sql`, `community_owner` 로 실행)

```sql
CREATE TABLE sessions (           -- t-box 6.2-2 에서 act_* 네 열만 뺀 것
  id CHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES public.users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at TIMESTAMPTZ NOT NULL, ip INET, user_agent TEXT, reauth_at TIMESTAMPTZ);
CREATE TABLE audit_logs ( … t-box 7.2 와 같은 15열, summary NOT NULL … );   -- 앱 계정 SELECT·INSERT 만
CREATE TABLE boards (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 50),
  description TEXT NOT NULL DEFAULT '' CHECK (length(description) <= 200),
  access_level TEXT NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
  team_id BIGINT REFERENCES public.teams(id),
  notice BOOLEAN NOT NULL DEFAULT false, is_active BOOLEAN NOT NULL DEFAULT true,
  created_by BIGINT REFERENCES public.users(id),          -- NULL = 시드
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT boards_team_requires_team_id CHECK (access_level <> 'team' OR team_id IS NOT NULL));
CREATE UNIQUE INDEX boards_name_lower_uidx ON boards (lower(name));
CREATE TABLE posts (
  id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
  board_id BIGINT NOT NULL REFERENCES boards(id), author_id BIGINT NOT NULL REFERENCES public.users(id),
  title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 100),
  body  TEXT NOT NULL CHECK (length(body)  BETWEEN 1 AND 10000),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at TIMESTAMPTZ, deleted_by BIGINT REFERENCES public.users(id));
CREATE INDEX posts_board_live_idx ON posts (board_id, created_at DESC) WHERE deleted_at IS NULL;
CREATE TABLE comments (
  id BIGSERIAL PRIMARY KEY, public_id UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
  post_id BIGINT NOT NULL REFERENCES posts(id), author_id BIGINT NOT NULL REFERENCES public.users(id),
  body TEXT NOT NULL CHECK (length(body) BETWEEN 1 AND 2000),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), deleted_at TIMESTAMPTZ, deleted_by BIGINT REFERENCES public.users(id));
CREATE INDEX comments_post_live_idx ON comments (post_id, created_at) WHERE deleted_at IS NULL;
```

- 외부 키는 t-box 표를 가리키되 `ON DELETE` 절이 없다 — t-box 는 계정·팀을 지우지 않는다(비활성만). 만약 지우려 하면 이 FK 가 막는다(그 사실은 t-box 쪽에 알려야 한다, SECURITY R-04).
- `public_id` 는 URL 에 노출되는 ID(UUID, 열거 방지 — t-box S-09-5 와 같은 결정). 게시판은 `{num}` — 목록 자체가 권한으로 걸러지고, 없는 id 는 못 보는 id 와 같은 403 이라 열거해도 새는 정보가 없다(t-box 폴더와 같은 판단).
- 시드(`db/seed.sql`): 「공지사항」(public, notice) · 「자유게시판」(internal), `created_by NULL`. 팀 게시판은 Admin 이 화면에서 만든다.
- 마이그레이션: 초판이라 없다. 뒤에 표를 바꾸면 t-box 와 같은 형식(`db/migrations/YYYY-MM-DD_*.sql`, 실행은 사용자)으로.

---

## 7. 흐름 — 순서대로, 권한 검사 위치 표시

공통: 핸들러 첫 줄은 라우터가 넘긴 `$user`(요청 값이 아니다) · 본문은 `Http::jsonBody()` → **허용 키 밖이 하나라도 있으면 400** · 거부는 전부 `Audit::deny(action, reason, …)` 뒤 동일 문구 · 허용은 `Audit::allow(action, … detail.granted_by)` 를 **같은 트랜잭션** 안에서(기록이 실패하면 저장도 남지 않는다).

### 7.1 게시판 목록 — `GET /api/boards` (`boards_list.php`, `authenticated`)
`[권한 ✔]` `Authz::visibleWhere($user, 'b')` 를 `WHERE` 에 붙인다(별칭 허용 목록은 `b` 하나 — 파일 `f`·폴더 `d` 는 이 앱에 없다). `LEFT JOIN public.teams t` 로 팀 이름, 서브쿼리로 살아 있는 글 수. 항목마다 `can_post = Authz::decidePostCreate($user, $row)[0]`(비활성이면 false). 허용은 기록하지 않는다(목록 조회 — t-box 와 같은 취급).

### 7.2 글 목록·검색 — `GET /api/boards/{num}/posts` (`posts_list.php`, `authenticated`)
`[1]` 게시판 행 SELECT → 없으면 **403** + `post.read deny not_found`(못 보는 것과 같은 문구·같은 코드) → `[권한 ✔ A]` `Authz::decideRead($user, $board)` 실패 403 + `post.read deny <code>` → `[2]` `q = Http::queryText('q', 200)` → `ILIKE :q ESCAPE '#'`(`%`·`_`·`#` 은 `#` 로 이스케이프해 바인딩) 제목·본문 · `sort = queryChoice('sort', ['new','old'], 'new')` → 고정 조각 `ORDER BY p.created_at DESC, p.id DESC` / `ASC, ASC` · `page = queryInt('page', 1, 1, 100000)` → `[3]` 살아 있는 글(`deleted_at IS NULL`) 20건 + `total` + 댓글 수 서브쿼리 + 글쓴이 `JOIN public.users` → 항목마다 `can_edit = Authz::decidePostChange($user, $row)[0]`(행에 `author_id`·게시판 `access_level`·`team_id` 를 함께 싣는다).

### 7.3 글 쓰기 — `POST /api/boards/{num}/posts` (`posts_create.php`, `post.write`)
허용 키 `title`·`body` → `[1]` 본문 규칙: 문자열·유효 UTF-8·`\r` 제거·`trim` → 제목 1~100자·본문 1~10,000자(`iconv_strlen`)·제어문자는 `\n` 만(`preg_match('/[^\P{Cc}\n]/u')` 가 걸리면 400) → `[2]` 게시판 행 → 없음 403 `not_found` → `[권한 ✔]` `Authz::decidePostCreate($user, $board)`: `decideRead` 실패 → 그 코드(403) · `!canWrite` → `role_not_permitted`(403) · `notice && !canManageBoards` → `role_not_permitted`(403) → `[3]` `!is_active` → 400 `board_inactive` → `[4]` 트랜잭션: INSERT `RETURNING public_id` + `Audit::allow('post.create', target=제목, level=게시판 등급, detail {board_id, granted_by})` → 201 `{id}`.

### 7.4 글 읽기 — `GET /api/posts/{id}` (`posts_get.php`, `authenticated`)
`[1]` 글 + 게시판 JOIN(`deleted_at IS NULL`) → 없음 **404** + `post.read deny not_found` → `[권한 ✔]` `decideRead($user, board)` 실패 403 → `[2]` 살아 있는 댓글 목록(작성자 JOIN) → 항목마다 `can_delete = decidePostChange($user, comment+board)[0]`, 글에 `can_edit`. 허용은 기록하지 않는다.

### 7.5 글 수정·삭제 — `PATCH`·`DELETE /api/posts/{id}` (`posts_update.php`·`posts_delete.php`, `post.write`)
`[1]` 글+게시판 → 404 → `[권한 ✔ A]` `decideRead(board)` 403 → `[권한 ✔ B]` `Authz::decidePostChange($user, $post)` — 행을 `['uploaded_by' => author_id, 'team_id' => board.team_id]` 로 옮겨 t-box 와 **같은** `decideOwnership` 을 부른다(`admin_all`·`owner`·`team_scope` / `not_owner`·`team_mismatch`·`role_not_permitted`) 실패 403 → 수정: 허용 키 `title`·`body` 1개 이상, 7.3 과 같은 규칙, `UPDATE … updated_at = now()` + `post.update allow detail {changed:[…], granted_by}` / 삭제: `UPDATE SET deleted_at = now(), deleted_by = :me` + `post.delete allow {granted_by}`. 비활성 게시판의 글도 수정·삭제는 된다(정리 목적).

### 7.6 댓글 — `POST /api/posts/{id}/comments` · `DELETE /api/comments/{id}` (`comments_create.php`·`comments_delete.php`, `post.write`)
쓰기: 허용 키 `body`(1~2,000자, 7.3 규칙) → 글+게시판 404 → `Authz::decideCommentCreate($user, $board)`(= `decideRead` + `canWrite`, 공지 게시판도 허용) 403 → `!is_active` 400 → INSERT + `comment.create allow {board_id, post_id(글 public_id), granted_by}` → 201. 삭제: 댓글+글+게시판(둘 다 살아 있어야) 404 → `decideRead(board)` → `decidePostChange($user, ['author_id' => comment.author_id, …])` → 논리 삭제 + `comment.delete allow`.

### 7.7 게시판 관리 — `POST /api/boards` · `PATCH /api/boards/{num}` (`boards_create.php`·`boards_update.php`, `board.manage` + 재인증)
라우터가 Admin·재인증을 이미 확인했다(4장 `[4]`·`[5]`). 만들기: 허용 키 `name`·`description`·`access_level`·`team_id`·`notice`·`reason_text` → 이름 1~50자(제어문자·앞뒤 공백 규칙은 t-box 팀 이름과 같다) → 등급 4종·`team` 이면 활성 팀 필수(그 밖은 `team_id` 가 오면 400) → `reason_text` 필수 1~500 → `lower(name)` 중복 409 → INSERT + `board.create allow {reason_text, notice, team_id}` → 201. 고치기: 허용 키 `name`·`description`·`notice`·`is_active`·`reason_text`, **`access_level`·`team_id` 가 오면 400**(허용 키 밖), 1개 이상 + 사유 → 404 → 중복 409 → `UPDATE … updated_at = now()` + `board.update allow {changed, before, after, reason_text}`. **DELETE 라우트는 없다.**

### 7.8 팀 목록 — `GET /api/teams` (`teams_list.php`, `authenticated`)
게시판 관리 폼의 팀 선택지용. **활성 팀의 `id`·`name` 만**(`SELECT id, name FROM teams WHERE is_active ORDER BY name`). t-box 의 같은 창구(`GET /api/teams`, 모든 로그인 사용자)와 같은 노출 범위라 새 정보가 새지 않는다 — 팀 이름은 이미 `/api/boards` 의 `team_name` 과 `/api/me` 의 `team_name` 으로 나간다. 허용은 기록하지 않는다(목록 조회). 화면은 이 목록을 `<select>` 에 채우되 **서버는 여전히 `boards_create.php` [4] 에서 활성 팀인지 다시 본다**(선택지는 편의).

### 7.9 로그인·로그아웃·`/api/me`·재인증·상태 확인
t-box 5.x 의 해당 절과 같다(`login.php`·`logout.php`·`me.php`·`reauth.php`·`health.php` 는 t-box 파일에서 필요한 키만 남긴다). `me.php` 응답 키는 PRD 7장. `health.php` 는 `SELECT 1` 만.

---

## 8. 감사 로그 — t-box 7장과 같은 구조, 이 앱의 문장 표

### 8.1 두 곳에 동시에
`community.audit_logs`(SELECT·INSERT 만) + `/var/log/community/audit.log`(JSON 한 줄, `chattr +a`). `Audit::write()` 는 t-box 그대로 — 문장 틀이 없으면 예외 → 요청 500(반쪽 기록 없음). 자동 detail: `route`(라우트 패턴)·`actor_team`·`login_ref`. **`auth`·`token_*`·`acting_as`·`real_role` 은 이 앱에 없다.**

### 8.2 요약 문장 표 (`Audit::SUMMARY_TEMPLATES`) — 키 `action|result|reason`

| 키 | 문장 틀 |
|---|---|
| `auth.login\|allow` · `deny\|bad_credentials` · `deny\|locked` · `deny\|inactive` · `auth.mfa\|deny\|code_required` · `deny\|bad_code` · `deny\|code_reused` · `auth.logout\|allow` · `auth.required\|deny\|not_authenticated` · `user.expired\|deny\|guest_expired` · `deny\|session_expired` · `csrf\|deny\|token_mismatch` · `authz.route\|deny\|role_not_permitted` · `auth.reauth\|allow` · `auth.reauth\|deny\|bad_credentials` · `auth.gate\|deny\|reauth_required` | **t-box 7.4 와 글자 그대로 같다** (문장 끝에 「(커뮤니티)」 를 붙이지 않는다 — 앱은 표·파일이 다르므로 구분된다) |
| `auth.gate\|deny\|tbox_required` | `{target} 이(가) t-box 의 최초 로그인 절차(비밀번호 변경 또는 2단계 인증 설정)를 마치지 않아 커뮤니티 사용을 막았습니다` |
| `board.create\|allow` | `{actor} 이(가) 게시판 「{target}」 을(를) {level} 등급으로 만들었습니다 (공지 여부·팀·사유는 상세 기록)` |
| `board.create\|deny\|bad_request` | `{actor} 이(가) 게시판 만들기를 허용되지 않는 형식(모르는 항목·이름 규칙·등급·팀·사유 없음)으로 요청해서 거부했습니다` |
| `board.create\|deny\|conflict` | `{actor} 이(가) 이미 있는 이름 「{target}」 으로 게시판을 만들려 해서 거부했습니다` |
| `board.update\|allow` | `{actor} 이(가) 게시판 「{target}」 의 이름·설명·공지 여부 또는 활성 상태를 바꿨습니다 (이전값→새값·사유는 상세 기록)` |
| `board.update\|deny\|bad_request` · `deny\|not_found` · `deny\|conflict` | `{actor} 이(가) 게시판 변경을 허용되지 않는 형식으로 요청해서 거부했습니다` · `{actor} 이(가) 없는 게시판을 바꾸려 해서 거부했습니다` · `{actor} 이(가) 게시판 이름을 이미 있는 「{target}」 으로 바꾸려 해서 거부했습니다` |
| `post.read\|deny\|role_not_permitted` · `deny\|team_mismatch` · `deny\|not_found` | `{actor} 이(가) 볼 권한이 없는 {level} 등급 게시판의 글을 보려 해서 막았습니다` · `{actor} 이(가) 다른 팀 게시판의 글을 보려 해서 막았습니다` · `{actor} 이(가) 없는 게시판 또는 글을 보려 해서 막았습니다` |
| `post.create\|allow` | `{actor} 이(가) {level} 등급 게시판에 글 「{target}」 을(를) 썼습니다` |
| `post.create\|deny\|bad_request` · `deny\|role_not_permitted` · `deny\|team_mismatch` · `deny\|not_found` · `deny\|board_inactive` | `{actor} 이(가) 글쓰기를 허용되지 않는 형식(모르는 항목·길이·제어문자)으로 요청해서 거부했습니다` · `{actor} 이(가) 글 「{target}」 을(를) 쓸 권한이 없는 {level} 등급 게시판에 쓰려 해서 막았습니다` · `{actor} 이(가) 다른 팀 게시판에 글 「{target}」 을(를) 쓰려 해서 막았습니다` · `{actor} 이(가) 없는 게시판에 글을 쓰려 해서 막았습니다` · `{actor} 이(가) 보관된(비활성) 게시판에 글 「{target}」 을(를) 쓰려 해서 거부했습니다` |
| `post.update\|allow` · `post.delete\|allow` | `{actor} 이(가) 글 「{target}」 을(를) 고쳤습니다 (바뀐 항목은 상세 기록)` · `{actor} 이(가) 글 「{target}」 을(를) 삭제했습니다` |
| `post.update\|deny\|bad_request` · `deny\|not_owner` · `deny\|team_mismatch` · `deny\|role_not_permitted` · `deny\|not_found` | `{actor} 이(가) 글 고치기를 허용되지 않는 형식으로 요청해서 거부했습니다` · `{actor} 이(가) 다른 사람의 글 「{target}」 을(를) 고치려 해서 막았습니다` · `{actor} 이(가) 다른 팀의 글 「{target}」 을(를) 고치려 해서 막았습니다` · `{actor} 이(가) 글 「{target}」 을(를) 고칠 권한이 없어 막았습니다` · `{actor} 이(가) 없는 글을 고치려 해서 거부했습니다` |
| `post.delete\|deny\|not_owner` · `deny\|team_mismatch` · `deny\|role_not_permitted` · `deny\|not_found` | 위 `post.update` 문장에서 「고치」 → 「삭제하」 |
| `comment.create\|allow` · `comment.delete\|allow` | `{actor} 이(가) 글 「{target}」 에 댓글을 썼습니다` · `{actor} 이(가) 글 「{target}」 의 댓글을 삭제했습니다` |
| `comment.create\|deny\|bad_request` · `deny\|role_not_permitted` · `deny\|team_mismatch` · `deny\|not_found` · `deny\|board_inactive` | `{actor} 이(가) 댓글 쓰기를 허용되지 않는 형식으로 요청해서 거부했습니다` · `{actor} 이(가) 댓글을 쓸 권한이 없는 글 「{target}」 에 댓글을 쓰려 해서 막았습니다` · `{actor} 이(가) 다른 팀 게시판의 글 「{target}」 에 댓글을 쓰려 해서 막았습니다` · `{actor} 이(가) 없는 글에 댓글을 쓰려 해서 막았습니다` · `{actor} 이(가) 보관된 게시판의 글 「{target}」 에 댓글을 쓰려 해서 거부했습니다` |
| `comment.delete\|deny\|not_owner` · `deny\|team_mismatch` · `deny\|role_not_permitted` · `deny\|not_found` | `{actor} 이(가) 다른 사람의 댓글(글 「{target}」)을 삭제하려 해서 막았습니다` · `{actor} 이(가) 다른 팀 게시판의 댓글(글 「{target}」)을 삭제하려 해서 막았습니다` · `{actor} 이(가) 댓글(글 「{target}」)을 삭제할 권한이 없어 막았습니다` · `{actor} 이(가) 없는 댓글을 삭제하려 해서 거부했습니다` |

`{target}` = 글 제목(댓글 행도 글 제목, 게시판 행은 게시판 이름), `{level}` = 게시판 등급. 치환은 `strtr` 한 번(t-box 7.4 규칙 — 제목이 `{level}` 을 담아도 왜곡되지 않는다, T-10).

### 8.3 `detail` 표준 키
t-box 7.5 의 `route`·`actor_team`·`login_ref`·`granted_by`·`changed`·`before`·`after`·`reason_text`·`policy`·`next` 를 그대로 쓰고, 이 앱만의 키: `board_id`(정수, 어디에 — `post.*`·`comment.*`) · `post_id`(글 `public_id`, 무엇을 — `comment.*`) · `notice`(bool) · `target_team`(정수, `board.create`). 금지 키 부분 일치 목록(비밀번호·토큰·코드…)은 t-box 그대로.

---

## 9. 서버 설정

### 9.1 nginx — `deploy/sites/community-local.conf`(`listen 127.0.0.1:8083`) · `community.conf`(공개용, Q-3 뒤에 켠다)
t-box `tbox-v2-local.conf` 에서 `/protected/`(내부 파일)·`totp-generator` 두 location·`client_max_body_size 101m`·413 처리를 **뺀** 것. `root /var/www/community/public` · 보안 헤더 5개 동일 · `location ~ /\. { return 404; }` · `/assets/` 정적 · `/` → `index.php` · `= /api/login`·`= /api/me/reauth` 에 `limit_req zone=comm_login burst=10 nodelay` · fastcgi 소켓 `php8.1-fpm-community.sock`. `community-common.conf`(→ `/etc/nginx/conf.d/`): `limit_req_zone $binary_remote_addr zone=comm_login:1m rate=30r/m;` 하나.

### 9.2 php-fpm 풀 — `deploy/php-fpm-pool.conf` → `/etc/php/8.1/fpm/pool.d/community.conf`
`[community]` `user = community-php` · `listen = /run/php/php8.1-fpm-community.sock`(owner www-data 0660) · `pm = ondemand, max_children 5` · `upload_max_filesize 1M`·`post_max_size 1M`·`file_uploads = Off`(업로드 없음) · `display_errors off`·`error_log /var/log/community/app.log` · `allow_url_fopen off` · `open_basedir /var/www/community:/var/log/community:/tmp` · `disable_functions` t-box 와 같은 10개 · `expose_php 0`. (PHP 파일 세션은 쓰지 않으므로 `session.*` 키는 두지 않는다.)

### 9.3 `.env` (저장소 루트, `changkwon:community-php` 0640)
`DB_HOST`·`DB_PORT`·`DB_NAME=tbox_re`·`DB_USER=community_app`·`DB_PASS`·`APP_SECRET`(t-box 와 다른 값)·`SESSION_IDLE_MIN=30`·`SESSION_MAX_HOURS=8`·`AUDIT_LOG_FILE=/var/log/community/audit.log`·`APP_URL`·`TBOX_URL`(화면 링크용, `https://changkwon.mso.so`). 키 이름은 `.env.example` 에만.

### 9.4 `deploy/install.sh` (sudo, 멱등)
① 패키지 확인(설치 없음: nginx·php8.1-fpm·php8.1-pgsql·postgresql) ② 계정 `community-php`(nologin) · `/var/log/community` 0750 `community-php:adm` · `audit.log`·`app.log` 0640 · `chattr +a audit.log` · `/etc/community` 0750 ③ PostgreSQL: DB `tbox_re` 가 **있어야** 한다(없으면 중단 — t-box 가 먼저다) · 역할 `community_owner`(설치용 비밀번호 → `/etc/community/owner.pgpass`) · `community_app`(`.env` 의 `DB_PASS` 유지/생성) · `tbox_backup` 존재 확인 · `CREATE SCHEMA IF NOT EXISTS community AUTHORIZATION community_owner` · **schema.sql 보다 먼저** `GRANT CONNECT`(t-box grants 가 PUBLIC 의 CONNECT 를 회수해 두었다)와 `GRANT REFERENCES (id) ON public.users, public.teams TO community_owner` · `community.boards` 가 없으면 `schema.sql`+`seed.sql`(owner 로) · `grants.sql`(postgres 로, 항상 — 최종 상태를 한 파일이 문서한다) ④ `.env` 생성/유지 ⑤ 풀·`community-common.conf`·`community-local.conf`(enabled 링크) · `logrotate.d/community` ⑥ `php-fpm8.1 -t`·`nginx -t` → reload ⑦ 자체 확인 `curl 127.0.0.1:8083/api/health` 200 · `community_app` 로 `SELECT 1 FROM public.files` 가 **permission denied 인지**(최소 권한 자가 검사, T-5).
**공개 사이트(`community.conf`)는 이 스크립트가 켜지 않는다**(Q-3).

---

## 10. t-box 와 무엇이 다른가 (대응표)

| 항목 | t-box | 커뮤니티 |
|---|---|---|
| 계정 표 | 자기 `users` | t-box `public.users` 읽기(+3열) |
| 세션 쿠키 | `tbox_session` | `comm_session` |
| 정책 | 12개 | 4개 (`public`·`authenticated`·`post.write`·`board.manage`) |
| 재인증 대상 | 계정·조직·감사·휴지통·위키·관리자 | 게시판 관리 API 만 |
| 외부 요청 | `Fetch.php` 한 곳 허용 | **0** (린트 예외 없음) |
| 저장 파일 | `/var/lib/tbox-v2/storage` | 없음 |
| 관문 | 비밀번호 변경·MFA 설정 | `tbox_required` (t-box 로 보낸다) |
| 감사 표 | `public.audit_logs` | `community.audit_logs` |
| 위키·변경 이력 | 있음 | 변경 이력만(`VERSION`·`CHANGELOG.json`), 위키 없음 |

---

## 11. 새 기능을 추가할 때 체크리스트

t-box 13장 그대로 + 이 앱만의 두 줄: ☐ `community` 스키마에 `users`·`teams`·`permissions` 이름의 표를 만들지 않았나(5.1) ☐ `public.*` 표에 새 권한을 달았다면 PRD S-08 표와 SECURITY R-01 을 먼저 고쳤나.

---
---

# 2부 — v0.2 「개인 업무 공간과 커뮤니티」 (2026-09-22)

> 1부(1~11장)는 1·2단계(게시판·계정 공유)의 설계이며 그대로 유효하다. 2부는 PRD v0.2 F-15~F-35 의 설계다.
> 근거·판단 과정은 `docs/community/notion-structure-and-design.md`(노션 7층·블록 카탈로그·결정 N/D). 여기서는 **구현 명세**만 적는다.

## 12. 문서 모델 — 블록 JSON (PRD F-15, S-11)

### 12.1 저장 단위와 파생 표
페이지 본문은 `pages.doc JSONB` **하나**다(문서 모델, D-2). 저장할 때 서버가 파생값을 뽑는다: `plain_text`(검색·발췌) · `search_tsv` · `block_count` · `page_blocks`(블록 id·타입·발췌·순서 — 댓글 앵커·목차) · `page_links`(역참조) · `to_do` 체크 변화 → `checklist_events`. 파생 표는 언제든 `doc` 에서 다시 만들 수 있다(`scripts/reindex_pages.php`).

### 12.2 스키마 파일 `docs/doc-schema.json` — 검증기·에디터·렌더러의 공통 진실
```json
{ "schema": 1,
  "limits": { "blocks": 3000, "depth": 10, "segment_chars": 2000, "segments_per_text": 100, "doc_bytes": 1048576, "code_chars": 50000, "table_rows": 200, "table_cols": 20 },
  "colors": ["gray","brown","orange","yellow","green","blue","purple","pink","red"],
  "color_suffix_bg": "_bg",
  "marks": { "bold": "bool", "italic": "bool", "underline": "bool", "strike": "bool", "code": "bool",
             "color": "color|palette", "link": { "href": "url" }, "mention": { "kind": ["user","page","date"], "id": "uuid|int|date" } },
  "link_schemes": ["https", "http", "mailto", "page"],
  "block_common": { "id": "uuid4", "color": "color|palette|null", "align": ["left","center","right"], "size": ["small","normal","large","xlarge"] },
  "blocks": {
    "paragraph": { "text": "richtext", "children": "blocks?" },
    "heading":   { "level": [1,2,3], "text": "richtext", "toggle": "bool?", "children": "blocks?" },
    "bulleted":  { "text": "richtext", "children": "blocks?" },
    "numbered":  { "text": "richtext", "start": "int?", "children": "blocks?" },
    "to_do":     { "text": "richtext", "checked": "bool", "children": "blocks?" },
    "quote":     { "text": "richtext", "children": "blocks?" },
    "callout":   { "text": "richtext", "icon": "emoji|icon", "children": "blocks?" },
    "divider":   {},
    "code":      { "code": "string", "language": "lang", "caption": "richtext?" },
    "toc":       {},
    "toggle":    { "text": "richtext", "children": "blocks" },
    "table":     { "header_row": "bool", "header_col": "bool", "col_widths": "int[]?", "rows": [{ "color": "color|null", "cells": [{ "text": "richtext", "color": "color|null", "rowspan": "int?", "colspan": "int?" }] }] },
    "child_page":   { "page_id": "uuid" },
    "link_to_page": { "page_id": "uuid" },
    "image":     { "file_ref": "tbox-ref", "caption": "richtext?", "width": ["full","wide","normal","small"] },
    "file":      { "file_ref": "tbox-ref", "caption": "richtext?" },
    "column_list": { "children": "columns" }, "column": { "ratio": "int?", "children": "blocks" },
    "embed":     { "url": "url", "caption": "richtext?" }
  },
  "children_allowed": ["paragraph","heading","bulleted","numbered","to_do","quote","callout","toggle","column"],
  "stage": { "3": ["paragraph","heading","bulleted","numbered","to_do","quote","callout","divider","code","toc"],
             "4": ["toggle","table","child_page"], "5": ["image","file","column_list","column","link_to_page"], "7": ["embed"] } }
```
- `richtext` = `[{ "t": "문자열", "m": { marks } }]`. `m` 은 비어도 있어야 한다.
- `color|palette` = 기본 18값 또는 `palettes` 표에 등록된 이름(`pal:회사파랑`).
- `tbox-ref` = `tbox:` + UUID. `lang` = 허용 목록 ~40(`plain`·`python`·`sql`·`javascript`·`bash`·`json`·`yaml`·`html`·`css`·`php`·`java`·`c`·`cpp`·`csharp`·`go`·`rust`·`kotlin`·`swift`·`typescript`·`markdown`·`mermaid`…). 모르는 언어 → `plain` 으로 **바꾸지 않고 400**(조용한 변환 없음).
- 단계에 없는 블록 타입은 그 단계에서 400 — `stage` 표가 곧 활성 목록이고, 서버 `.env` 의 `DOC_STAGE` 로 켠다.

### 12.3 검증기 `src/Doc.php`
`Doc::validate(array $doc, int $stage): array{ok, errors[]}` — 재귀 한 벌. 순서: 크기(바이트) → `schema` → 블록 수·깊이 → 블록마다 `type` 허용 → 키 집합 정확히(필수·선택 외 키 → 오류) → 값 형식(위 표) → `id` UUID v4 **유일** → 링크 스킴·길이 → 멘션 대상 존재(사용자 활성·페이지 열람 가능) → `file_ref` 등급 규칙(13.5) → 제어문자(`Text::clean` 규칙, `code` 는 `\t` 허용). 오류는 `[경로, 사유]` 목록으로 400 본문에(예 `blocks[12].text[3].m.color: not_in_palette`). 감사 `page.update deny bad_doc` detail 에 **사유 코드만**(내용 없음).
파생 추출 `Doc::extract($doc)` → `plain_text`·`blocks[]`·`links[]`·`todos[]`.

### 12.4 렌더러 두 벌
- `public/assets/doc-render.js` — `renderDoc(doc, ctx) → DocumentFragment`. 블록 → `createElement`, 텍스트 → `textContent`, 마크 → 중첩 `<strong><em><u><s><code>`·`<span class="nt-red nt-bg-yellow">`, 링크 → `<a href>`(스킴 재검사, 외부는 `target=_blank rel=noopener noreferrer`), 멘션 → `<a class="mention">`, `toc` → 제목 블록 수집, `child_page`/`link_to_page` → 카드(ctx 가 준 제목·경로만, 없으면 「볼 수 없는 페이지」), `image` → `<img src="/tbox/api/files/{id}/preview/raw">`(같은 도메인 — 15장), 모르는 타입 → `<div class="block-unsupported">지원하지 않는 블록</div>`. **`innerHTML` 0**(린트).
- `src/DocRender.php` — 같은 규칙으로 HTML 문자열(모든 값 `htmlspecialchars`, 속성은 허용 목록 → 클래스). 쓰는 곳: 검색 발췌·알림 미리보기·인쇄(`?print=1`)·향후 이메일. 페이지 본문 화면은 클라이언트 렌더러가 그린다.
- 두 렌더러의 출력이 같은지 `tests/doc_render_parity.sh` 가 표본 문서 20개로 비교(텍스트 노드·클래스 집합).

### 12.5 색·크기·정렬의 표시
`DESIGN.md` 2.13(신설)에 토큰 — 글자색 `--nt-<color>`·배경 `--nt-<color>-bg`(라이트·다크 각각), 크기 `--size-small/normal/large/xlarge`(13/15/18/22px), 정렬 클래스 `.al-center/.al-right`. 관리자 팔레트는 `<style>` 이 아니라 **`:root` 에 CSS 변수로 서버가 그린다**(`pal-<이름>` 값은 `#[0-9a-f]{6}` 정규식 통과값만, 이름은 `[a-z0-9-]{1,20}`).

## 13. 공간·페이지·권한 (PRD F-17·F-18·F-24, S-13)

### 13.1 표 (12장 DDL 은 `docs/community/notion-structure-and-design.md` 6.2 — 여기 확정본)
```sql
-- boards → spaces (마이그레이션 2026-10-xx_spaces.sql: RENAME + kind·owner_user_id 추가, 기존 행은 kind='board')
spaces(id, kind CHECK IN ('personal','team','board'), owner_user_id NULL → public.users, team_id NULL → public.teams,
       name, description, access_level, notice, board_kind CHECK IN ('discussion','qna','case','doc','notice') NULL,
       is_active, created_by, created_at, updated_at,
       CHECK (kind <> 'personal' OR owner_user_id IS NOT NULL), CHECK (kind <> 'team' OR team_id IS NOT NULL),
       UNIQUE (owner_user_id) WHERE kind='personal', UNIQUE (team_id) WHERE kind='team')
pages(id BIGSERIAL, public_id UUID UNIQUE, space_id → spaces, parent_page_id NULL → pages, position INT,
      title (1~200), icon TEXT NULL, cover_ref TEXT NULL, font CHECK IN ('default','serif','mono'), small_text BOOL, full_width BOOL,
      visibility CHECK IN ('private','team','internal','published'), published_space_id NULL → spaces, published_at NULL,
      database_id NULL → databases, properties JSONB NULL,
      doc JSONB NOT NULL, doc_version INT NOT NULL DEFAULT 1, plain_text TEXT NOT NULL DEFAULT '', search_tsv tsvector, block_count INT,
      author_id → public.users, last_edited_by → public.users, created_at, updated_at,
      deleted_at NULL, deleted_by NULL, purge_after NULL, view_count INT DEFAULT 0,
      CHECK (visibility <> 'published' OR published_space_id IS NOT NULL), CHECK (jsonb_typeof(doc) = 'object'))
page_versions(id, page_id → pages, version INT, title, doc JSONB, edited_by, edited_at, summary NULL, UNIQUE(page_id, version))   -- SI 만
page_blocks(page_id, block_id UUID, block_type, ord INT, excerpt TEXT, PRIMARY KEY(page_id, block_id))                              -- 파생, 재생성 가능
page_links(from_page_id, from_block_id UUID, to_page_id, PRIMARY KEY(from_page_id, from_block_id, to_page_id))                  -- 파생
checklist_events(id, page_id, block_id UUID, checked BOOL, by_user_id, at)                                                           -- SI 만
page_reads(page_id, user_id, first_read_at, last_read_at, PRIMARY KEY(page_id, user_id))                                            -- 공지 읽음(F-30)
page_edit_sessions(page_id, user_id, last_seen_at, PRIMARY KEY(page_id, user_id))                                                   -- 「편집 중」
tags(id, name, UNIQUE(lower(name))) · page_tags(page_id, tag_id) · space_tags(space_id, tag_id)                                      -- 추천 태그
palettes(name PK [a-z0-9-]{1,20}, light CHAR(7), dark CHAR(7), created_by, created_at)                                               -- F-33
posts · comments(v0.1) → pages 로 이관(14.4), comments 는 15장에서 확장
```
앱 계정 권한: `pages`·`spaces`·`tags`·`page_tags`·`page_reads`·`page_edit_sessions`·`palettes` SIU(+`page_edit_sessions`·`page_blocks`·`page_links` 는 D) · `page_versions`·`checklist_events` **SI 만**(S-14).

### 13.2 판정 함수 (`Authz`, 새 것은 셋)
- `decidePageRead(u, row)` — `row` 에 `space_kind`·`space_access_level`·`space_team_id`·`space_owner_user_id`·`visibility`·`published_space_*`·`author_id` 가 있다. 순서: 삭제된 페이지 → `not_found` · `personal`: `private` → 소유자(`owner`) **또는 Admin(`admin_all` — 사용자 결정 2026-09-22, 열람은 `page.read allow admin_all` 로 감사 기록)** / `team` → 소유자 팀 = 내 팀(`same_team`) / `internal` → `canWrite`(Guest 제외)(`matrix`) / `published` → 게시판 행으로 `decideRead` · `team`/`board`: 공간 행으로 `decideRead`(1부 그대로). Manager 에게는 특권 없음.
- `decidePageChange(u, row)` — `personal`: 소유자만 · `board`/`team`: `decideOwnership`(작성자·같은 팀 Manager·Admin). 게시된 개인 페이지에 대해 게시판 Manager·Admin 은 **`unpublish` 만**(`decideUnpublish`).
- `fitsUnderParent(childVisibility, parent)` — private(0) < team(1) < internal(2) < published(3): 자식 ≤ 부모. 부모 좁히기는 `cascade` 로 하위 전부 갱신(항목마다 감사 `page.visibility`).
- `fitsFileRef(fileLevel, fileTeam, page+space)` — 첨부 파일 등급이 페이지가 보이는 범위보다 **좁거나 같아야** 한다: `published`/`board` → 파일 등급 rank ≥ 공간 등급 rank(t-box 폴더 ①과 같은 방향: 파일이 더 좁아야 한다) · `internal` → 파일 ≥ internal · `team` → 파일 team(같은 팀) 또는 admin-only · `private` → 아무 등급(소유자만 본다). 검사는 저장 시 `Doc::validate` 안에서 t-box `files` 를 **읽어**(`community_app` 에 `SELECT (public_id, access_level, team_id, deleted_at) ON public.files` 열 단위 GRANT 추가 — S-08 표 갱신·SECURITY R-xx).

### 13.3 라우트 (3~5단계, 전부 세션+CSRF)
```
GET  /                                   home.php            authenticated   (홈: 내 공간·게시판·알림)
GET  /p/{id}                             page_view.php       authenticated   (페이지 화면 — 렌더러가 그린다, 편집 모드는 같은 화면)
GET  /api/me/space                       space_me.php        authenticated   (개인 공간 + 트리 요약; 없으면 만든다)
GET  /api/spaces                         spaces_list.php     authenticated   (볼 수 있는 공간: 개인·팀·게시판, visibleWhere 확장)
GET  /api/spaces/{num}/pages?parent=&q=&sort=&page=   pages_list.php  authenticated → decideRead(space) + 페이지별 decidePageRead
POST /api/spaces/{num}/pages             pages_create.php    post.write   {title, parent_id?, doc?, visibility?}
GET  /api/pages/{id}                     pages_get.php       authenticated → decidePageRead  (doc·breadcrumb·children·backlinks·can_*·edit_sessions)
PATCH /api/pages/{id}                    pages_update.php    post.write → decidePageChange  {title?, doc?, doc_version, icon?, cover_ref?, font?, small_text?, full_width?, tags?}
PATCH /api/pages/{id}/move               pages_move.php      post.write   {parent_id, position}  → fitsUnderParent
PATCH /api/pages/{id}/visibility         pages_visibility.php post.write  {visibility, published_space_id?, cascade?}
POST /api/pages/{id}/publish · /unpublish                    post.write   (게시판 Manager·Admin 은 unpublish 만)
POST /api/pages/{id}/check               pages_check.php     post.write   {block_id, checked}  → doc 갱신 + checklist_events
GET  /api/pages/{id}/versions · GET …/versions/{num} · POST …/versions/{num}/restore
GET  /api/pages/{id}/history             (checklist_events + versions + publish/unpublish + handover 를 시간순으로)
POST /api/pages/{id}/heartbeat           (편집 중 표시)      DELETE 로 끝
DELETE /api/pages/{id} · POST /api/pages/{id}/restore · GET /api/me/trash
GET  /api/search?q=&space=&type=         search.php          authenticated (pg_trgm + tsv, visibleWhere)
GET/POST /api/tags · GET /api/palettes · (7) POST/PATCH /api/admin/palettes
POST /api/pages/{id}/view                pages_view_count.php (조회수: 세션당 하루 1회)
```
v0.1 의 `/api/boards*`·`/api/posts*` 는 3단계 동안 **호환 유지**(내부적으로 pages 를 읽는다) → 4단계에서 제거.

### 13.4 흐름 — 저장 (`PATCH /api/pages/{id}`)
`[1]` 허용 키 → `[2]` 행+공간 → 404 → `[권한 A]` `decidePageRead` 403 → `[권한 B]` `decidePageChange` 403 → `[3]` `doc_version` 필수·정수 → 서버 값과 다르면 **409** `{error, current_version, doc}`(감사 `page.update deny version_conflict`) → `[4]` `Doc::validate(doc, DOC_STAGE)` 실패 400 + 경로 사유 → `[5]` `Doc::extract` → 트랜잭션: `UPDATE pages SET doc, doc_version = doc_version+1, title, plain_text, search_tsv, block_count, updated_at, last_edited_by` · `page_blocks`·`page_links` 재작성 · `to_do` 변화 → `checklist_events` · 스냅샷(직전 버전이 같은 사람·5분 안이면 그 버전을 **덮어쓰지 않고** 새 행을 만들되 `summary='auto-merge'` 로 표시 — 규칙: 목록에서 접어 보인다) · `Audit::allow('page.update', detail {changed, block_count, version})` → 200 `{doc_version}`.

### 13.5 첨부·이미지 (F-24·F-25)
- 참조만: `image.file_ref = tbox:<file public_id>`. `Doc::validate` 가 t-box `files` 행(`access_level`·`team_id`·`deleted_at IS NULL`)을 읽어 `fitsFileRef` 검사. 표시는 `<img src="/tbox/api/files/{id}/preview/raw">` — **같은 도메인**이므로 브라우저가 t-box 세션 쿠키를 함께 보내고, t-box 가 자기 `decideRead` 로 판단한다(커뮤니티는 파일 바이트를 만지지 않는다). t-box 세션이 없으면 이미지 자리에 「t-box 로그인 필요」.
- 자동 업로드: 에디터가 붙여넣기 이미지를 `POST /tbox/api/files`(t-box 웹 업로드, t-box CSRF 토큰은 `/tbox/api/me` 로 받아) 로 올리고 응답 `id` 를 `file_ref` 로 넣는다. 등급은 페이지 범위에 맞춰 화면이 고르고 서버(`fitsFileRef`)가 다시 본다. **커뮤니티 서버는 관여하지 않는다**(S-05 — 서버가 밖으로 나가지 않음).

## 14. 에디터 (PRD F-16, S-12)

### 14.1 엔진과 번들
ProseMirror 패키지 `prosemirror-model`·`-state`·`-view`·`-transform`·`-commands`·`-keymap`·`-history`·`-inputrules`·`-dropcursor`·`-gapcursor` 를 **한 파일**로 묶어 `public/assets/vendor/prosemirror-<ver>.min.js`(ESM 아닌 IIFE 전역 `PM`) — 빌드는 개발 PC 에서 1회(`esbuild`), 산출물·`package-lock`·sha256 을 저장소에. 서버·VM 에 node 를 두지 않는다. `deploy/DEPENDENCIES.md` 에 버전·라이선스·해시·재빌드 절차.

### 14.2 스키마 매핑 (PM ↔ 우리 JSON)
PM `doc > block+`, 블록 노드 = 우리 타입(`attrs`: id·color·align·size…), `text` 노드 + marks(bold·italic·underline·strike·code·color·link·mention). `children` 은 노드 안 `content`. 변환 `toDoc(pmJSON)`·`fromDoc(doc)` 두 함수(`assets/editor-schema.js`)가 **유일한 통로**이고, 저장 전 클라이언트도 같은 검증(`doc-schema.json` 을 `fetch` 로 읽어)을 한 번 돌려 400 을 미리 막는다(서버 검증이 진실).

### 14.3 UX
- `/` 슬래시 메뉴: 현재 단계의 블록 목록(스키마 `stage`) + 전환(「제목 2 로」). 키보드 탐색.
- 블록 손잡이(⋮⋮): 드래그 이동(dropcursor), 메뉴(삭제·복제·색·정렬·크기·전환·댓글·링크 복사 `#block-id`).
- 마크다운 입력 규칙(inputrules): `# `·`## `·`### `·`- `·`* `·`1. `·`[] `·`[x] `·`> `·"```"·`---`.
- 인라인 툴바(선택 시): B·I·U·S·코드·색(팔레트 팝업)·링크·댓글·멘션.
- `@` 팝업: `GET /api/mention-candidates?q=`(같은 팀 + 열람자), 페이지(`GET /api/search?type=page`), 날짜.
- 붙여넣기: `clipboardData` 의 HTML 을 PM `DOMParser` 로 **우리 스키마 규칙**(허용 노드·마크만, 나머지 평문)으로 들여온다. 이미지 → 13.5 자동 업로드.
- 자동 저장: 1.5초 디바운스, 최대 10초. 409 → 모달 「다른 곳에서 수정됨 — 새로 고침 / 내 것으로 덮어쓰기(새 버전으로)」.
- 읽기 모드 = 렌더러(12.4), 편집 모드 = PM. 같은 URL, 소유·편집 권한(`can_edit`) 있을 때만 「편집」.

## 15. 토론·참조·알림 (PRD F-21~F-23·F-30·F-35) — 5단계 명세 요지
- `comments` 확장: `page_id`, `block_id UUID NULL`, `quote TEXT NULL`(선택 문구 ≤ 300자, 인용 저장), `parent_comment_id NULL`(1단), `resolved_at/by`, `hidden_at/by`. 답글의 답글은 400.
- `reactions(page_id|comment_id, user_id, kind='helpful')` · `mentions` · `notifications(user_id, kind IN ('mention','reply','comment_on_my_page','handover','publish_to_my_board'), page_id, comment_id, actor_id, created_at, read_at)`.
- 멘션 후보 API 는 `decidePageRead` 를 통과하는 **활성 사용자 전체(Guest 제외)** — 사용자 결정 ⑥(도움 요청이 목적이라 넓게). 후보 응답은 아이디·표시 이름만.
- 공지 읽음(F-30): `board_kind='notice'` 공간의 페이지를 `GET /api/pages/{id}` 로 열면 `page_reads` upsert. 「읽은 사람 n/N」은 작성자·Admin 만(`GET …/reads`), N = 그 게시판을 볼 수 있는 활성 사용자 수.
- 게시판 종류(F-35): `spaces.board_kind` 가 켜는 기능 — `qna`: 댓글 「해결됨 채택」 · `case`: 상태·담당자 칸(F-37 — **4단계**에 모든 페이지 공통 고정 속성으로 먼저 들어온다: `pages.status CHECK IN ('todo','doing','review','done','hold') NULL`, `pages.assignee_id NULL → public.users`, 변경 이력 `page_property_events(page_id, prop, before, after, by_user_id, at)` append-only) · `doc`: 트리 표시 · `notice`: 읽음 확인 + Admin 만 쓰기(기존 `notice`). 코드는 갈리지 않고 화면·검증 플래그만.

## 16. 이력·인계·휴지통 (PRD F-19·F-20·F-28·F-29, S-14)
- 버전: 13.4. 되돌리기 = `POST …/versions/{n}/restore` → 그 `doc` 으로 **새 버전** 저장(`summary='restore from n'`), 감사 `page.restore_version`.
- 처리 이력(`GET …/history`): `checklist_events` ∪ `page_versions` ∪ 감사(`page.publish`·`page.unpublish`·`page.visibility`·`handover`) 를 시간순 한 목록으로. 지울 수 없다.
- 인계: `POST /api/handovers {scope: 'space'|'page', id, to_user_id, reason_text}` — 본인(개인 공간 소유자, 대상은 같은 팀 활성 사용자) · Manager(출발·도착 모두 자기 팀, 인계 화면은 제목·개수만 — 핸들러가 `doc` 을 SELECT 하지 않는다) · Admin(재인증, 대상 누구나. Admin 은 내용을 볼 수 있으나 인계 창구 자체는 `doc` 을 읽지 않는다). 트랜잭션: `spaces.owner_user_id` 또는 `pages` 하위 트리의 소유 이전(개인 공간 페이지를 다른 사람 개인 공간으로 **이동**: `space_id` 변경, 범위는 `private` 로 리셋) + `handovers` 행 + 감사 `handover.execute` + 알림 둘. 인계 대기: `GET /api/admin/handover-queue` = `public.users.is_active=false` 인 소유자의 개인 공간(7단계).
- 휴지통: `DELETE /api/pages/{id}` → `deleted_at`·`purge_after=now()+30d`, 하위 함께(재귀 CTE). `restore` 는 소유자·Admin, 부모가 지워졌으면 개인 공간 루트로. `scripts/purge_pages.php`(cron 일 1회) 가 `purge_after < now()` 를 지운다(문서·버전·파생·댓글 — 감사 `page.purge` 에 개수만).

## 17. 검색 (PRD F-26)
`plain_text` 위에 `search_tsv = to_tsvector('simple', title || ' ' || plain_text)` GIN + `plain_text gin_trgm_ops` GIN(`pg_trgm` 설치 확인 2026-09-22). 질의: `q` 2~200자 → `plainto_tsquery('simple', q)` OR `title ILIKE` OR `plain_text % q`(유사도 ≥ 0.3), 정렬 `ts_rank` → 최근. 범위는 `pages` ⋈ `spaces` 에 `decidePageRead` 를 **SQL 로 옮긴 조건**(`visiblePagesWhere(u)` — 1부 `visibleWhere` 확장: personal 은 소유자·팀·internal 규칙, board/team 은 등급). 발췌 `ts_headline` 은 서버 렌더러 텍스트 기준, 결과 20건.

## 18. 감사 문장 표 추가 (8.2 에 더한다)
`page.create|allow` · `page.update|allow` · `page.update|deny|bad_doc|version_conflict|not_owner|team_mismatch|role_not_permitted|not_found` · `page.move|allow` · `page.move|deny|parent_narrower|not_found` · `page.visibility|allow` · `page.visibility|deny|child_wider|not_owner` · `page.publish|allow` · `page.unpublish|allow` · `page.check|allow` · `page.restore_version|allow` · `page.delete|allow` · `page.restore|allow` · `page.purge|allow` · `page.read|deny|not_owner|team_mismatch|role_not_permitted|not_found` · `comment.*`(1부 + `resolve`·`hide`) · `mention.create|allow` · `handover.execute|allow` · `handover.execute|deny|not_owner|bad_target` · `palette.update|allow` · `tag.create|allow`. `{target}` = 페이지 제목, `{level}` = 공간 등급 또는 범위. 문장은 3단계 지시서에서 표로 확정.

## 19. t-box 쪽에 필요한 것 (G-4 「t-box 무변경」의 예외 후보 — 사용자 결정 필요)
| 필요 | 왜 | 대안 |
|---|---|---|
| `community_app` 에 `SELECT (public_id, access_level, team_id, deleted_at) ON public.files` | 첨부 등급 규칙(13.2 `fitsFileRef`)을 저장 시 검사 | 없으면 규칙을 표시 시점에만(t-box 가 403) — 「글은 보이는데 그림이 403」 이 생긴다. **권장: 열 단위 SELECT 추가**(S-08 표 갱신, t-box grants 는 그대로 — 커뮤니티 grants.sql 이 준다) |
| 같은 도메인 배치 `/tbox` | 이미지·첨부의 쿠키 동반 | 28단계(t-box base-path). **3단계 공개 주소와 함께** |
| t-box 업로드 API 의 CORS | 없음 — 같은 도메인이면 불필요 | — |

## 20. 앱 런처와 공개 주소 (PRD F-36 · Q-3, S-15) — 사용자 요청 2026-09-22 「구글처럼 기능별로 선택하여 접근」

```
https://changkwon.mso.so/            ← 런처 (정적 HTML, 스크립트 0, 쿠키 0)   nginx root /var/www/launcher/public
https://changkwon.mso.so/tbox/       ← t-box  (28단계 base-path)             proxy_pass 127.0.0.1:8082  또는 같은 서버 블록 location
https://changkwon.mso.so/community/  ← 커뮤니티 (COMM_BASE_PATH=/community)  proxy_pass 127.0.0.1:8083
```
- **런처**: 저장소 `launcher/`(이 저장소 안 별도 디렉터리 또는 별도 저장소 — 3단계 지시서에서 정함) — `apps.json`(이름·설명·경로·아이콘 SVG 파일명·표시 순서) → `scripts/build_launcher.php` 가 `public/index.html` 을 만든다(모든 값 `htmlspecialchars`, 아이콘은 저장소 안 SVG 인라인). CSS 는 t-box `DESIGN.md` 토큰의 부분집합(다크 모드 포함). 로그인 상태를 알지 못하고 알 필요도 없다 — 타일을 누르면 각 앱이 자기 로그인 화면으로 보낸다.
- **앱 전환 단추**: 두 앱의 `shell_top.php` 머리 오른쪽에 그리드 아이콘(⋮⋮⋮) → `<details>` 기반 팝업(스크립트 없이 열림)에 같은 `apps.json` 목록. t-box 쪽 변경은 **셸 한 조각**이라 28단계(base-path)와 함께 t-box 저장소에서 한다(G-4 예외를 사용자가 승인 — 19장 표에 추가).
- **커뮤니티 base-path**: `.env` `COMM_BASE_PATH=/community` → 라우터가 접두를 벗기고, 화면의 모든 링크·`fetch`·쿠키 `path`·CSP·`TBOX_URL` 이 접두를 쓴다(t-box 28단계와 같은 방식). 로컬 8083 은 접두 없이 그대로.
- 세션은 앱마다(Q-9 미결). 쿠키 이름이 다르고 `path` 를 각 접두로 좁혀 서로 보내지 않는다(`comm_session; path=/community`).
