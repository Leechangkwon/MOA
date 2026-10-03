> **2026-09-22 통합 결정(사용자)**: 커뮤니티는 별도 앱이 아니라 **Qaredi 한 앱 안의 모듈**이다(사이트 `../PRD.md` 3장). 이 문서의 「별개 앱」·`/var/www/community`·8083·`community_app` 언급은 v0.1~v0.2 이력이며, 통합 계획(`docs/integration-plan.md`) B·D 단계에서 설계를 갱신할 때 고친다.

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
> 근거·판단 과정은 `community/notion-structure-and-design.md`(노션 7층·블록 카탈로그·결정 N/D). 여기서는 **구현 명세**만 적는다.

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
             "4": ["toggle","table","child_page"], "5": ["image","file","column_list","column","link_to_page"], "7": ["embed"] },
  "stage_marks": { "3": ["bold","italic","underline","strike","code","color","link"], "7": ["mention"] } }
```
- `richtext` = `[{ "t": "문자열", "m": { marks } }]`. `m` 은 비어도 있어야 한다.
- `mention` = `{kind: user|page|date, id}` — 사람은 `users.id` 정수(1~2⁵³), 페이지는 `pages.public_id` 소문자 UUID, 날짜는 `YYYY-MM-DD`(1900~2100). 그 조각의 글자는 정확히 「@」(7-D4 · 21.27). `stage_marks` 는 문서 단계 표의 마크 칸.
- `color|palette` = 기본 18값 또는 `palettes` 표에 등록된 이름(`pal:회사파랑`).
- `tbox-ref` = `tbox:` + UUID. `lang` = 허용 목록 ~40(`plain`·`python`·`sql`·`javascript`·`bash`·`json`·`yaml`·`html`·`css`·`php`·`java`·`c`·`cpp`·`csharp`·`go`·`rust`·`kotlin`·`swift`·`typescript`·`markdown`·`mermaid`…). 모르는 언어 → `plain` 으로 **바꾸지 않고 400**(조용한 변환 없음).
- 단계에 없는 블록 타입은 그 단계에서 400 — `stage` 표가 곧 활성 목록이고, 서버 `.env` 의 `DOC_STAGE` 로 켠다.

### 12.3 검증기 `src/Doc.php`
`Doc::validate(array $doc, int $stage): array{ok, errors[]}` — 재귀 한 벌. 순서: 크기(바이트) → `schema` → 블록 수·깊이 → 블록마다 `type` 허용 → 키 집합 정확히(필수·선택 외 키 → 오류) → 값 형식(위 표) → `id` UUID v4 **유일** → 링크 스킴·길이 → 멘션은 **모양만**(키 둘 · kind · id 모양 · 글자 「@」 · 링크와 함께 오지 않음 — 대상이 있는지·볼 수 있는지는 보지 않는다: 저장 창구가 남의 계정·페이지의 존재를 대답하지 않게 · 7-D4 M-5) → `file_ref` 등급 규칙(13.5) → 제어문자(`Text::clean` 규칙, `code` 는 `\t` 허용). 오류는 `[경로, 사유]` 목록으로 400 본문에(예 `blocks[12].text[3].m.color: not_in_palette`). 감사 `page.update deny bad_doc` detail 에 **사유 코드만**(내용 없음). 파생 추출에 `mentions[]`(이름 풀이용 `{kind, id}` · 역참조 `links[]` 에는 넣지 않는다 — E-7 · 7-D4).
파생 추출 `Doc::extract($doc)` → `plain_text`·`blocks[]`·`links[]`·`todos[]`.

### 12.4 렌더러 두 벌
- `public/assets/doc-render.js` — `renderDoc(doc, ctx) → DocumentFragment`. 블록 → `createElement`, 텍스트 → `textContent`, 마크 → 중첩 `<strong><em><u><s><code>`·`<span class="nt-red nt-bg-yellow">`, 링크 → `<a href>`(스킴 재검사, 외부는 `target=_blank rel=noopener noreferrer`), 멘션 → 사람·날짜 `<span class="doc-mention">@라벨</span>` · 페이지 `<a class="doc-mention" href="/community/p/<uuid>">@제목</a>`(사이트 안 — `target` 없음) · 볼 수 없으면 `<span class="doc-mention doc-mention--unknown">@볼 수 없음</span>`. 라벨은 ctx 의 `mention_labels`(창구가 준 볼 수 있는 것만)에서만 — 렌더러는 표를 읽지 않는다. 원문 「@」 는 라벨로 바뀐다(텍스트 노드 = 「@」 + 라벨) · `MARK_ORDER` 끝(가장 바깥) · 문구 상수 `MENTION_UNREADABLE` 두 벌 같은 글자, `toc` → 제목 블록 수집, `child_page`/`link_to_page` → 카드(ctx 가 준 제목·경로만, 없으면 「볼 수 없는 페이지」), `image` → `<img src="/tbox/api/files/{id}/preview/raw">`(같은 도메인 — 15장), 모르는 타입 → `<div class="block-unsupported">지원하지 않는 블록</div>`. **`innerHTML` 0**(린트).
- `src/DocRender.php` — 같은 규칙으로 HTML 문자열(모든 값 `htmlspecialchars`, 속성은 허용 목록 → 클래스). 쓰는 곳: 검색 발췌·알림 미리보기·인쇄(`?print=1`)·향후 이메일. 페이지 본문 화면은 클라이언트 렌더러가 그린다.
- 두 렌더러의 출력이 같은지 `tests/doc_render_parity.sh` 가 표본 문서 24개(3단계 20 + 7-D4 멘션 4 · `tests/community/stage3.sh` C:T-19)로 비교(텍스트 노드·클래스 집합).

### 12.5 색·크기·정렬의 표시
`DESIGN.md` 2.13(신설)에 토큰 — 글자색 `--nt-<color>`·배경 `--nt-<color>-bg`(라이트·다크 각각), 크기 `--size-small/normal/large/xlarge`(13/15/18/22px), 정렬 클래스 `.al-center/.al-right`. 관리자 팔레트는 `<style>` 이 아니라 **`:root` 에 CSS 변수로 서버가 그린다** — 창구 `GET /community/palette.css` 가 살아 있는 줄마다 `--pal-<이름>`·`--pal-<이름>-bg`(라이트 `:root` · 다크 `[data-theme="dark"]` 와 `prefers-color-scheme` 두 곳에 같은 값)와 클래스 `.pal-<이름>`·`.pal-<이름>-bg` 를 내고, 셸이 커뮤니티 화면 셋에 `<link id="palette-css" … ?v=<내용 해시 12자리>>` 한 줄로 부른다(같은 출처 — CSP `style-src 'self'` 그대로). 이름 `[a-z0-9-]{1,20}`(끝 `-bg` 아님 · 기본 9색 이름·`none` 예약) · 값 `#[0-9a-f]{6}` 통과값만, 배경값은 서버가 섞는다(21.25).

## 13. 공간·페이지·권한 (PRD F-17·F-18·F-24, S-13)

### 13.1 표 (12장 DDL 은 `community/notion-structure-and-design.md` 6.2 — 여기 확정본)
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
      status NULL CHECK IN ('todo','doing','review','done','hold'), assignee_id NULL → public.users,   -- 4-D 고정 속성 둘 (F-37)
      doc JSONB NOT NULL, doc_version INT NOT NULL DEFAULT 1, plain_text TEXT NOT NULL DEFAULT '', search_tsv tsvector, block_count INT,
      author_id → public.users, last_edited_by → public.users, created_at, updated_at,
      deleted_at NULL, deleted_by NULL, purge_after NULL, view_count INT DEFAULT 0,
      CHECK (visibility <> 'published' OR published_space_id IS NOT NULL), CHECK (jsonb_typeof(doc) = 'object'))
page_versions(id, page_id → pages, version INT, title, doc JSONB, edited_by, edited_at, summary NULL, UNIQUE(page_id, version))   -- SI 만
page_blocks(page_id, block_id UUID, block_type, ord INT, excerpt TEXT, PRIMARY KEY(page_id, block_id))                              -- 파생, 재생성 가능
page_links(from_page_id, from_block_id UUID, to_page_id, PRIMARY KEY(from_page_id, from_block_id, to_page_id))                  -- 파생
checklist_events(id, page_id, block_id UUID, checked BOOL, by_user_id, at)                                                           -- SI 만
page_property_events(id, page_id, prop CHECK IN ('status','assignee'), from_value, to_value, by_user_id, at)                         -- SI 만 (4-D)
page_reads(page_id, user_id, first_read_at, last_read_at, PRIMARY KEY(page_id, user_id))                                            -- 공지 읽음(F-30)
page_edit_sessions(page_id, user_id, last_seen_at, PRIMARY KEY(page_id, user_id))                                                   -- 「편집 중」
tags(id, name, UNIQUE(lower(name))) · page_tags(page_id, tag_id) · space_tags(space_id, tag_id)                                      -- 추천 태그
palettes(name PK [a-z0-9-]{1,20}, light CHAR(7), dark CHAR(7), created_by, created_at)                                               -- F-33
posts · comments(v0.1) → pages 로 이관(14.4), comments 는 15장에서 확장
```
앱 계정 권한: `pages`·`spaces`·`tags`·`page_tags`·`page_reads`·`page_edit_sessions`·`palettes` SIU(+`page_edit_sessions`·`page_blocks`·`page_links` 는 D) · `page_versions`·`checklist_events`·**`page_property_events`** **SI 만**(S-14).

### 13.2 판정 함수 (`Authz`, 새 것은 셋)
- `decidePageRead(u, row)` — `row` 에 `space_kind`·`space_access_level`·`space_team_id`·`space_owner_user_id`·**`space_owner_team_id`**(개인 공간 주인의 t-box 팀, 4-B)·`visibility`·`published_space_*`·`author_id` 가 있다. 순서: 삭제된 페이지 → `not_found` · `personal`: `private` → 소유자(`owner`) **또는 Admin(`admin_all` — 사용자 결정 2026-09-22, 열람은 `page.read allow admin_all` 로 감사 기록)** / `team` → 소유자 팀 = 내 팀(`same_team`, 감사 기록) / `internal` → `canCommunityWrite`(Guest 제외)(`matrix`) / `published` → 게시판 행으로 `decideRead` · `team`/`board`: 공간 행으로 `decideRead`(1부 그대로). Manager 에게는 특권 없음.
  - **4-B 구현 상태**: `published` 는 화면·API 가 받지 않고 판정도 **닫힌 실패**(`role_not_permitted`)다 — 게시판 공간이 없다(5단계). `team` 공간은 그 팀원이면 `team_member`(읽기·쓰기), Admin 읽기 `admin_all`, 그 밖 `not_member`. 개인 공간의 세 범위 모두에 Admin 갈래를 둔다 — `private` 을 볼 수 있는 역할이 그보다 넓은 `team` 을 못 본다면 앞뒤가 맞지 않는다.
- `decidePageChange(u, row)` — `personal`: 소유자만 · `board`/`team`: `decideOwnership`(작성자·같은 팀 Manager·Admin). 게시된 개인 페이지에 대해 게시판 Manager·Admin 은 **`unpublish` 만**(`decideUnpublish`).
- `fitsUnderParent(childVisibility, parentVisibility)` — private(0) < team(1) < internal(2) < published(3): 자식 ≤ 부모. 부모가 `null`(뿌리)이면 언제나 참, **모르는 값이면 거짓**(모르면 막는다). 부모 좁히기는 `cascade` 로 하위 전부 갱신(항목마다 감사 `page.visibility`). **옮기기도 같은 규칙을 지킨다** — 견주는 값은 옮길 페이지 하나가 아니라 **옮겨 가는 가지에서 가장 넓은 범위**(`community_move_shape` 의 `subtree_widest`)다.
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
PATCH /api/pages/{id}/properties         pages_properties.php  post.write → decidePageChange  {status?, assignee?}   (4-D)
GET  /api/assignees?page=&q=             assignees_list.php    authenticated → decidePageRead(page)                  (4-D 담당자 후보 — **그 페이지를 볼 수 있는 사람만**)
GET  /api/pages?assignee=me&status=      pages_list.php        (4-D 거르개 — 둘 다 SQL WHERE. `assignee=me` 는 공간을 가리지 않되 행 관문은 그대로)
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

### 14.3 UX (**사용자 결정 2026-09-23 으로 고쳐 씀** — `stage3b-editor-rework-directive.md`)
- **「블록 추가」 창**(`<dialog class="modal">`, DESIGN 2.7·2.14): 편집 모드 화면 머리의 「블록 추가」 단추와 블록 손잡이(⋮⋮)가 연다. 두 칸 — 왼쪽 `.list` 로 종류 목록(3단계 10종 = 본문·제목 1~3·글머리·번호·할 일·인용·강조 상자·구분선·코드·목차), 오른쪽에 **그 종류의 미리보기(읽기 렌더러 `renderDoc` 이 그린다)와 한 줄 설명**. ↑↓ 이동 · Enter 넣기 · Esc 닫기. 넣기는 커서가 있던 블록 **다음**에, 손잡이로 연 「블록 바꾸기」는 **그 블록의 종류만**(글자 유지).
- **`/` 슬래시 메뉴는 없앴다** — 코드·마크업·CSS 어디에도 없다. `/` 는 그냥 글자로 들어간다. (없앤 이유: 타이밍에 기댄 구조라 빠르게 치면 열리지 않았다 — 관문 ③ 2026-09-22)
- 블록 손잡이(⋮⋮): 3단계에서는 「블록 바꾸기」 창을 여는 단추 하나다. 드래그 이동·복제·댓글·링크 복사는 4·5단계.
- 마크다운 입력 규칙(inputrules): `# `·`## `·`### `·`- `·`* `·`1. `·`[] `·`[x] `·`> `·"```"·`---`.
- **Enter·Backspace 는 우리 명령이 `baseKeymap` 앞에서 받는다**(평평한 블록 구조라 PM 의 기본 분리가 목록을 문단으로 떨어뜨렸다): 목록·인용·강조 상자는 글자가 있으면 **같은 종류로 이어지고**(`to_do` 의 `checked` 는 새 줄에서 false), 비어 있으면 그 블록이 `paragraph` 가 된다 · `heading` 다음은 `paragraph` · `code` 안에서 Enter 는 줄바꿈이고 Shift+Enter 또는 빈 줄 두 번으로 빠져나온다 · 블록 맨 앞의 Backspace 는 목록·인용·강조 상자를 `paragraph` 로 되돌린다(지우지 않는다).
- 인라인 툴바(선택 시) **단추 셋**: 「색」 「배경」 「링크」. **문자 꾸미기(B·I·U·S·인라인 코드)는 만들지 않고 Ctrl+B 등도 등록하지 않는다**(PM 스키마의 마크와 `doc-schema.json` 은 그대로 둔다 — 이미 저장된 글의 읽기 호환). 팔레트는 9색 + 「없앰」이고 눌렀을 때만 펼쳐진다. 링크 입력칸의 **Enter = 「걸기」**, Esc = 닫기, 허용 스킴 밖이면 **그 줄 안에서** 알린다.
- `@` 팝업: `GET /api/mention-candidates?q=`(같은 팀 + 열람자), 페이지(`GET /api/search?type=page`), 날짜.
- 붙여넣기: `clipboardData` 의 HTML 을 PM `DOMParser` 로 **우리 스키마 규칙**(허용 노드·마크만, 나머지 평문)으로 들여온다. 이미지 → 13.5 자동 업로드.
- 자동 저장: 1.5초 디바운스, 최대 10초. 409 → 모달 「다른 곳에서 수정됨 — 새로 고침 / 내 것으로 덮어쓰기(새 버전으로)」. **경합 처리**: 변경마다 번호를 올리고 보낼 때 그 번호를 기억한다. 200 이 와도 그 사이에 번호가 올랐으면 「저장됨」을 **보이지 않고** 곧바로 한 번 더 보낸다(배지는 「저장 중…」·「저장됨」·「저장 못 함」 셋뿐이고, 변경이 남아 있는 동안 「저장됨」은 나오지 않는다). 편집기는 **한 번만** 만든다. 창을 닫거나 다른 탭으로 갈 때(`beforeunload`·`visibilitychange`) 남은 변경을 보낸다.
- 읽기 모드 = 렌더러(12.4), 편집 모드 = PM. 같은 URL, 소유·편집 권한(`can_edit`) 있을 때만 「편집」.

## 15. 토론·참조·알림 (PRD F-21~F-23·F-30·F-35) — 5단계 명세 요지
- `comments` 확장: `page_id`, `block_id UUID NULL`, `quote TEXT NULL`(선택 문구 ≤ 300자, 인용 저장), `parent_comment_id NULL`(1단), `resolved_at/by`, `hidden_at/by`. 답글의 답글은 400.
- `reactions(page_id|comment_id, user_id, kind='helpful')` · `mentions` · `notifications(user_id, kind IN ('mention','reply','comment_on_my_page','handover','publish_to_my_board'), page_id, comment_id, actor_id, created_at, read_at)`.
- 멘션 후보 API 는 `decidePageRead` 를 통과하는 **활성 사용자 전체(Guest 제외)** — 사용자 결정 ⑥(도움 요청이 목적이라 넓게). 후보 응답은 아이디·표시 이름만.
- 공지 읽음(F-30): `board_kind='notice'` 공간의 페이지를 `GET /api/pages/{id}` 로 열면 `page_reads` upsert. 「읽은 사람 n/N」은 작성자·Admin 만(`GET …/reads`), N = 그 게시판을 볼 수 있는 활성 사용자 수.
- 게시판 종류(F-35): `spaces.board_kind` 가 켜는 기능 — `qna`: 댓글 「해결됨 채택」 · `case`: 상태·담당자 칸(F-37 — **4-D 에 구현 완료**(2026-09-23). 모든 페이지 공통 고정 속성으로 먼저 들어왔다: `pages.status CHECK IN ('todo','doing','review','done','hold') NULL`, `pages.assignee_id NULL → public.users`, 변경 이력 `page_property_events(page_id, prop, from_value, to_value, by_user_id, at)` append-only — 칸 이름은 감사 `detail` 등록부와 같게 `from_value`·`to_value` 로 확정했다) · `doc`: 트리 표시 · `notice`: 읽음 확인 + Admin 만 쓰기(기존 `notice`). 코드는 갈리지 않고 화면·검증 플래그만.

## 16. 이력·인계·휴지통 (PRD F-19·F-20·F-28·F-29, S-14)

> **4-C 구현 상태 (2026-09-23)** — 버전 이력·되돌리기·처리 이력·휴지통은 **21.9 에 확정본**이 있다.
> 아래 명세와 달라진 것 둘: ① 되돌리기 표시는 `summary = 'restore from v<n>'`(판 번호 앞에 `v`)이고
> 화면에는 **그 다음 판**의 줄에 붙는다 ② `history` 는 **감사 로그를 읽지 않는다**(우리 표 둘 + 지금 판뿐).
> 인계(F-28)와 30일 정리는 7단계 그대로다.

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
- **편집기 파서 두 벌(7-D4)** — `editorDomParser`(`EditorView.domParser` · 편집기가 **자기 DOM 을 다시 읽을 때만** · 스키마 규칙 + `MENTION_DOM_RULE` 하나(`span.doc-mention[data-mention-kind]` → mention 마크 · `data-mention-id` 는 `mentionAllowed` 모양 검사 뒤에만)) · `clipboardDomParser`(`clipboardParser` — 붙여넣기·끌어 놓기 · `DOMParser.fromSchema(schema)` 그대로라 멘션 규칙 0). 스키마 mention 마크에는 `parseDOM` 이 없다. 클립보드 길(`paste`)·`execCommand('insertHTML')` 길 모두 멘션 0(7-D4 수락 시험 · 독립 검토). 되읽기 규칙이 있는 까닭: 멘션 바로 뒤에 친 글자·한글 조합이 span 안에 들어가 ProseMirror 가 그 자리를 DOM 에서 다시 읽으면 마크가 사라졌다. 저장 값은 어느 길이든 `{kind, id}` 뿐(M-5 — 판단은 그릴 때).

## 21. Qaredi 한 앱 안에서의 구현 기준 — 3단계부터 (2026-09-22, 1~11장의 「별도 앱」 전제를 대체한다)

### 21.1 자리
- 웹 앱 본체는 `../tbox-v2/`(t-box 모듈 폴더)다. 커뮤니티 코드는 그 안에 **격리**해서 둔다: `src/community/`(핸들러·`Doc.php`·`DocRender.php`·`CommunityAuthz.php`·`routes.php`) · `public/assets/community/`(`editor.js`·`editor-schema.js`·`doc-render.js`·`community.css`·`vendor/pm/`) · `db/community/`(schema·grants·migrations) · `tests/community/` · `src/help/community.php`. 이 폴더(`/var/www/qaredi/community/`)는 기획·설계·기록만.
- 라우트는 전부 **`/community` 접두**. `public/index.php` 의 한 층(런처·`/tbox`·308·404, `../docs/launcher/ARCHITECTURE.md` 2장)에 `/community` 갈래가 하나 더 생긴다: `$reqPath === '/community' || str_starts_with('/community/')` → `src/community/routes.php` 의 `COMMUNITY_ROUTES` 표를 **t-box 와 같은 매칭·정책·인증·CSRF·디스패치 코드**로 돈다(라우트 표만 다르다). 핸들러 디렉터리는 `src/community/handlers/`.
- 상수 `COMMUNITY_BASE = '/community'`(`src/bootstrap.php`), `Http::communityUrl('/…')`. 커뮤니티 화면·JS 의 절대 경로는 이 함수/`COMMUNITY_BASE`(`<body data-community-base>`) 로만 만든다. t-box 창구를 부를 때는 `Http::url()`/`APP_BASE`.

### 21.2 계정·세션·권한 — t-box 것 그대로
- 세션 쿠키 `tbox_session`(path=/) 하나. `Auth::requireLogin()`·CSRF·재인증·감사 **무변경**. 5장의 `community_app`·`comm_session`·잠금 공유 설계는 **폐기**(한 앱이라 공유할 것이 없다). DB 는 `tbox_app` 이 스키마 `community` 를 쓴다(`db/community/grants.sql`: `pages`·`spaces`·`page_edit_sessions`·`page_blocks`·`page_links` SIUD, `page_versions`·`checklist_events` **SI 만**, `tbox_backup` SELECT).
- 라우트 정책은 `Authz::KNOWN_POLICIES` 에 둘 추가: `community.read`(= authenticated) · `community.write`(Guest 제외 — `Authz::canWrite` 와 같은 판정, 새 함수 `canCommunityWrite`). 행 단위 판정은 `src/community/CommunityAuthz.php` 의 `decidePageRead`·`decidePageChange`(13.2)이고, `Authz` 본체는 `authorize()` 의 case 두 줄만 는다.
- 미로그인 화면 요청 → 302 `/tbox/login?to=community`. 로그인 화면은 `to` 를 **허용 목록**(`tbox`·`community`) 으로만 받아 `<body data-to>` 에 두고, 로그인 성공 뒤 `to=community` 면 `/community/` 로 간다(`app.js`). 그 밖 값은 무시(`/tbox/`).
- 3단계의 권한: 개인 공간만 있으므로 `decidePageRead` = 소유자 또는 Admin(`admin_all`, 감사 `page.read allow admin_all`), `decidePageChange` = 소유자만. `visibility` 열은 만들되 값은 `private` 고정(4단계에서 공개 범위 도입).

### 21.3 3단계 범위 (PRD 11장 재정의 — 1·2단계 게시판은 버림)
| 만드는 것 | 어디 |
|---|---|
| 스키마 `community` v2: `spaces`(kind `personal` 만 채움)·`pages`·`page_versions`·`page_blocks`·`page_links`·`checklist_events`·`page_edit_sessions` (13.1 DDL 그대로, `boards`·`board_kind`·`tags`·`palettes` 는 4단계 이후) | `db/community/schema.sql`·`grants.sql`, 마이그레이션 `db/migrations/2026-09-2x_community_stage3.sql`(스키마 `community` 는 이미 있고 비어 있음 — `legacy_community` 는 건드리지 않는다) |
| 문서 모델: `doc-schema.json`(12.2 전체, `stage.3` 활성) · `Doc::validate`·`Doc::extract`(12.3) · 렌더러 두 벌(12.4) · 동등성 시험 | `community/doc-schema.json`(이 폴더 = 진실) + `tbox-v2/public/assets/community/doc-schema.json`(**같은 파일의 사본**, 시험이 diff 로 동일 확인) · `src/community/Doc.php`·`DocRender.php` · `public/assets/community/doc-render.js` |
| 화면: `GET /community/` 홈 = 내 공간(페이지 목록·새 페이지) · `GET /community/p/{uuid}` 페이지(읽기 = 렌더러, 「편집」 = 같은 화면에서 PM) | `src/community/handlers/home_page.php`·`page_view.php`, 셸은 t-box `shell_top.php` 를 **모듈 모드**(`$shellModule='community'`: 브랜드 「커뮤니티」, 내비 = 내 공간·새 페이지, 그리드 현재 앱 = 커뮤니티)로 재사용 |
| API: `GET /community/api/me/space` · `GET /community/api/pages?parent=&q=&page=` · `POST /community/api/pages` · `GET /community/api/pages/{uuid}` · `PATCH /community/api/pages/{uuid}`(13.4, 409) · `DELETE /community/api/pages/{uuid}`(soft) · `POST /community/api/pages/{uuid}/heartbeat` | `src/community/handlers/*.php` |
| 에디터 1차(14장): ProseMirror **ESM 파일 vendoring(node 없이)** — `scripts/vendor_prosemirror.sh` 가 고정 버전 tarball 을 registry 에서 받아 sha256 대조 → `dist/index.js` 를 `public/assets/community/vendor/pm/<pkg>.js` 로 두고 **bare import 를 상대 경로로 치환**(`from "prosemirror-model"` → `from "./prosemirror-model.js"`); `deploy/DEPENDENCIES.md` 에 14 패키지 버전·MIT·sha256. `editor.js` 는 `<script type="module">`(CSP `'self'` 그대로, import map 없음). 스키마 매핑 `editor-schema.js`(`toDoc`/`fromDoc` + 창이 쓰는 표 `BLOCK_TYPES`), **「블록 추가」 창**, 마크다운 inputrules, 우리 Enter·Backspace 명령, 인라인 툴바(**글자색·배경색·링크 셋**), Ctrl+Z, 붙여넣기 HTML → 스키마 규칙, 자동 저장 1.5초 + 409 모달 (14.3 — 2026-09-23 재작성). **드래그 손잡이·멘션·댓글·이미지는 4·5단계** | `public/assets/community/` |
| 첫 로그인 때 개인 공간 자동 생성 + 「시작하기」 페이지(기획 ③) | `space_me.php` 가 없으면 만든다(트랜잭션, `spaces UNIQUE(owner_user_id) WHERE kind='personal'`) |
| 런처 타일 `community` 의 `status: soon` 제거 → 눌림 | `config/apps.json` |
| 감사 문장: `page.create|allow` · `page.read|allow|admin_all` · `page.read|deny|not_owner` · `page.update|allow` · `page.update|deny|not_owner` · `page.update|deny|version_conflict` · `page.update|deny|invalid_doc` · `page.delete|allow` · `space.create|allow` | `src/Audit.php` SUMMARY_TEMPLATES + ALLOWED_DETAIL_KEYS(`page_title`·`doc_version`·`block_count`·`error_path`) |

ProseMirror 패키지·버전(2026-09-22 registry 최신, 전부 MIT): `prosemirror-model` 1.25.12 · `-state` 1.4.4 · `-view` 1.42.5 · `-transform` 1.12.1 · `-commands` 1.7.2 · `-keymap` 1.2.3 · `-history` 1.5.0 · `-inputrules` 1.5.1 · `-dropcursor` 1.8.3 · `-gapcursor` 1.4.1 · `-schema-list` 1.5.1 · `orderedmap` 2.1.1 · `w3c-keyname` 2.2.8(ESM 진입 `index.js`) · `rope-sequence` 1.3.4.

**부품 주소의 버전 표시(2026-09-24 핫픽스 — 사용자가 폰에서 겪은 먹통)**: `/tbox/assets/` 는 7일 캐시이고 셸은 진입 파일 `editor.js` 만 `?v=<파일 시각>` 으로 부른다. 부품(`./doc-render.js` 등 · `vendor/pm/*`)의 주소에 버전이 없던 때, 7-C 이전 `doc-render.js` 를 캐시한 브라우저는 새 editor.js 의 `import { embedHost }` 에서 모듈 연결이 실패해 JS 가 0 실행됐다. 이제 **import 문마다 `?v=<버전>`** 이 찍힌다 — `scripts/community_asset_version.php`(쓰기 · `--check` · `--print`). 값은 둘: vendor/pm 을 부르는 import 는 ProseMirror 버전(vendor 내용 해시), 우리 부품을 부르는 import 는 부품 버전(ProseMirror 버전 + 우리 부품 전체 내용 해시) — ES 모듈은 주소가 정체라 한 파일은 어디서 부르든 **같은 문자열**이어야 한다(두 벌이면 ProseMirror instanceof 가 깨진다). 부품을 고치면 찍기를 다시 돌려 같은 커밋에 넣는다 — `release.sh` 가 낡은 표시를 거부하고, 시험은 `tests/community/asset_version.sh`(정적)·`stale_modules.sh`(옛 부품 캐시 흉내 브라우저 회귀). import map 은 여전히 쓰지 않는다(CSP `script-src 'self'`).

### 21.4 3단계 수락 기준 (C:T-14~C:T-20, 스크립트 `tests/community/stage3.sh`)
| ID | 무엇 | 기대 |
|---|---|---|
| C:T-14 | 스키마 퍼징 — 모르는 키·모르는 타입·4단계 블록(`table`)·색 팔레트 밖·링크 스킴 `javascript:`·깊이 11·블록 3,001·세그먼트 2,001자·문서 1MB+·`id` 중복·`m` 누락 | 전부 400 + `errors[].path` 존재, 감사 `page.update deny invalid_doc`, DB 불변 |
| C:T-15 | 렌더 XSS — 세그먼트 텍스트 `<img src=x onerror=alert(1)>`·링크 `href` 에 `"><script>`·`code` 블록에 `</script>` | `GET /community/api/pages/{id}` JSON 원문 그대로 · `DocRender.php` 출력에 `<img`·`<script` 0 · 브라우저(Playwright, 있으면) dialog 0·텍스트 글자 그대로 |
| C:T-16 | 낙관적 잠금 — 같은 `doc_version` 으로 두 번 PATCH | 두 번째 409 `{error, current_version, doc}` + 감사 `version_conflict`, 첫 번째 저장 유지 |
| C:T-17 | 개인 공간 격리 — qa_employee02 가 employee01 페이지 GET·PATCH·DELETE · qa_manager01(같은 팀) GET · qa_admin01 GET | 403 `not_owner`(GET 은 404 가 아니라 403 — 존재 여부 노출은 UUID 라 무해, 13.2) · Manager 403 · Admin 200 + 감사 `admin_all` |
| C:T-18 | 쓰기 자격·CSRF — qa_guest01 `POST /community/api/pages` · 쿠키만 헤더 없이 PATCH | 403 `authz.route deny` · 403 `csrf` |
| C:T-19 | 렌더러 동등성 — 표본 문서 20개 `DocRender.php` vs `doc-render.js`(node 없음 → `php -r` 로 PHP 출력, JS 는 Playwright `innerText`·클래스 집합; Playwright 없으면 PHP 출력의 텍스트 노드·클래스만 검사하고 JS 쪽은 「미검증」) | 텍스트 노드 열·클래스 집합 동일 |
| C:T-20 | vendoring 무결성 — `scripts/vendor_prosemirror.sh --verify` | `deploy/DEPENDENCIES.md` 의 sha256 과 `vendor/pm/*.js` 원본 tarball 해시 일치 · bare import 0(`grep -E 'from "[a-z]' vendor/pm` 0건) · `editor.js` 가 `../../tbox/api` 등 절대 경로 리터럴 0 |
| 회귀 | t-box `stage26`·`help_button`·`q_launcher`(런처 타일 커뮤니티가 링크가 된 것만 기대값 변경) | 통과 수 불변 |

### 21.5 4-A 범위 — 페이지 트리 · 하위 페이지 · `child_page` (2026-09-23 구현)

4단계는 네 조각으로 나눠 들어온다. **4-A 가 트리**이고, 나머지는 뒤에 온다.

| 조각 | 무엇 | 상태 |
|---|---|---|
| **4-A** | 페이지 트리(부모·순서·깊이 8) · 하위 페이지 · `child_page` 블록 · 「내 공간」 트리 화면 · 경로(breadcrumb) · 옮기기 | **구현 완료** |
| **4-B** | 공개 범위(비공개·팀·사내) · 팀 공간 · 트리 불변 규칙. **게시는 5단계로 미룸**(게시판 공간이 없다) | **구현 완료** (21.7) |
| 4-C | 버전 이력 화면(F-19) · 되살리기 | 뒤 |
| 4-D | 상태·담당자 칸(F-37) — **구현 완료 2026-09-23** (마이그레이션 `2026-09-23_community_page_properties.sql` + `grants.sql`, 운영 적용은 메인 세션) | ✅ |

**마이그레이션 0.** `pages.parent_page_id`·`position` 은 3단계 스키마에 이미 있다. 새 표·새 열이 없다.

만든 것
| 무엇 | 어디 |
|---|---|
| 단계 표를 4로 — `child_page` 만 4단계, `toggle`·`table` 은 **5단계로 옮김**(단계 표가 곧 활성 목록이어야 한다) | `community/doc-schema.json` + `public/assets/community/doc-schema.json` 사본 · `COMMUNITY_DOC_STAGE = 4` |
| `child_page.page_id` 는 **UUID 형태만** 본다. 그 페이지가 있는지·볼 수 있는지는 저장 때 판정하지 않는다(검증기는 DB 를 읽지 않는다) — 읽기 판정은 **렌더 시점**이다 | `src/community/Doc.php` (`uuid` 모양) |
| `Doc::extract` 가 `child_page` 의 `page_id` 를 `links[]` 가 아니라 새 칸 **`child_pages[]`** 로 모은다 | 같은 파일 |
| `decidePageMove(u, page, parent)` — 같은 공간·같은 주인 · 자기 자신/자기 후손이면 `cycle` · 깊이 8 초과면 `depth_exceeded`. **DB 를 읽지 않는다**(핸들러가 재귀 CTE 한 번으로 모양을 모아 넘긴다) | `src/community/CommunityAuthz.php` |
| `PATCH /api/pages/{id}/move` (`community.write`) — `{parent_id: uuid\|null, position: int}` | `routes.php` · `handlers/pages_move.php` |
| 트리 질의 셋 — 경로(`community_breadcrumb`) · 자식+손자 수(`community_children`) · 이동 모양(`community_move_shape`) · 깊이(`community_page_depth`) · 형제 맨 뒤(`community_next_position`). **전부 한 문장** | `routes.php` |
| `pages_get` 응답에 `breadcrumb[]`(뿌리~자기 자신) · `children[]`(제목·아이콘·`child_count`) · `child_titles{}`(볼 수 있는 것만) | `handlers/pages_get.php` |
| `pages_list?tree=1` — 그 공간의 살아 있는 페이지 전부를 **한 질의로**. 트리는 화면이 만든다 | `handlers/pages_list.php` |
| 만들기에 `parent_id` — 같은 공간·소유자 확인, `position` 은 형제 맨 뒤, 깊이 초과 400 | `handlers/pages_create.php` |
| 화면 — 경로 줄 · 하위 페이지 목록 + 「하위 페이지 만들기」 · 「옮기기」 창 · 내 공간 트리(`<details>`) | `page_view.php` · `home_page.php` · `editor.js` · `community.css` |
| `child_page` 렌더 두 벌 — 카드(`.card--link.doc-child-page`), 제목을 못 찾으면 「볼 수 없는 페이지」(`.card--soon`). **문구·클래스가 두 벌 같다** | `DocRender.php` · `doc-render.js` |

규칙 (4-A 지시서 「규칙」과 같다)
- 깊이는 **뿌리를 1** 로 세어 **8 까지**. 9 가 되면 400 `depth_exceeded`.
- 부모·자식은 **같은 공간**. 남의 공간 페이지를 부모로 주면 **403**(+ 감사 `page.read deny not_owner`), 아무 데도 없는 UUID 는 **404**.
- 순환(자기 자신·자기 후손)은 400 `cycle` + 감사 `page.move deny cycle`.
- 삭제는 **하위까지 함께** 논리 삭제(재귀 CTE 한 번), 감사 `detail.cascaded` 에 건수.
- 순서는 형제 안에서 **0..n-1 로 다시 매긴다**. 떠나온 자리도 함께 다시 매긴다.
- 트리 질의는 **재귀 CTE 한 번**. N+1 질의를 만들지 않는다.

감사 문장 세 줄이 는다 (`src/Audit.php`): `page.move|allow` · `page.move|deny|cycle` · `page.move|deny|depth_exceeded`.
`detail` 등록부에 `parent_title`(옮겨 간 부모 제목, 없으면 뿌리로 올린 것) · `cascaded`(함께 바뀐 항목 수)가 는다.

### 21.6 4-A 수락 기준 (C:T-22 ~ C:T-29, 스크립트 `tests/community/stage4a.sh`)
| ID | 무엇 | 기대 |
|---|---|---|
| C:T-22 | 하위 페이지 3단 | 각 201 · `breadcrumb` 길이 1·2·3 · `children` 수가 맞다 |
| C:T-23 | 깊이 9 시도(만들기·옮기기) | 400 `depth_exceeded` · DB 불변 |
| C:T-24 | 자기 자신 / 자기 후손을 부모로 | 둘 다 400 `cycle` · 감사 `page.move deny cycle` · 트리 불변 |
| C:T-25 | 남의 페이지를 부모로(qa_employee02) | 403 + 감사 `page.read deny not_owner` · 없는 UUID 는 404(감사 없음) |
| C:T-26 | 옮기기 + 순서 | `position` 이 형제 안에서 0..n-1 로 다시 매겨진다 · 떠나온 자리도 다시 매겨진다 |
| C:T-27 | 부모 삭제 | 하위 전부 `deleted_at` · 목록에서 사라짐 · 감사 `cascaded` 건수 일치 |
| C:T-28 | `child_page` 저장 | 저장 JSON 에 `child_page` · `child_titles` 에 제목 · 남의 페이지 id 는 **400 이 아니고** 화면에 「볼 수 없는 페이지」 |
| C:T-29 | 4단계 밖 블록 | `toggle`·`table` 저장 → 400 `type_not_in_stage`(5단계로 옮겼다) |
| 브라우저 | `browser_smoke.py` 21~26 | 창에서 하위 페이지 만들기 → 카드 클릭 → 이동 → 경로 → 뒤로 · 트리 접기·펼치기 · 옮기기 창 |
| 회귀 | `stage3.sh` · t-box `stage26`·`help_button` · `security_lint` · `php -l` | 통과 수 불변 |

### 21.7 4-B 범위 — 공개 범위 · 팀 공간 · 트리 불변 규칙 (2026-09-23 구현)

지시서 `stage4b-visibility-directive.md`. **마이그레이션 0** — `spaces.kind`·`team_id`·`pages.visibility` 는 3단계 스키마에 이미 있고, 팀 공간은 **행을 넣는 일**이라 DDL 이 없다.

**범위 밖**: 게시(`published`)와 게시판은 만들지 않는다 — 게시판 공간(`kind='board'`)이 아직 없기 때문이다(5단계). 인계(F-28)·버전 이력 화면(4-C)·상태·담당자(4-D)도 범위 밖.

만든 것
| 무엇 | 어디 |
|---|---|
| `decidePageRead` 확장 — 개인 공간의 `private`/`team`/`internal` 세 갈래(`owner`·`admin_all`·`same_team`·`matrix`) · 팀 공간(`team_member`·`admin_all`·`not_member`) · `published` 는 닫힌 실패 | `src/community/CommunityAuthz.php` |
| `decidePageChange` — 개인 공간은 **범위와 무관하게 소유자만**, 팀 공간은 그 팀원 누구나 | 같은 파일 |
| `fitsUnderParent(child, parent)`·`visibilityRank()`·상수 `VISIBILITIES`(셋) | 같은 파일 |
| `decidePageMove` 를 `decidePageChange` 위에 다시 올리고 **`wider_than_parent`** 갈래 추가 | 같은 파일 |
| `GET /api/spaces` — 내 개인 공간 + 내 팀 공간. **없으면 그 자리에서 만든다**(내 팀·활성 팀에 한해, 이름은 t-box 팀 이름 그대로, 감사 `space.create allow team_member`). 멱등이고, 동시 요청은 `UNIQUE(team_id) WHERE kind='team'` 이 막는다 | `handlers/spaces_list.php`(새) · `routes.php` |
| `PATCH /api/pages/{id}/visibility` — `{visibility, cascade?}`. 소유자만. 넓히기 `wider_than_parent` · 좁히기 `children_wider` / `cascade:true` 면 하위 전부 + **항목마다 감사** | `handlers/pages_visibility.php`(새) · `routes.php` |
| 목록의 **공간 관문**(`community_space_gate`)과 **행 조건절**(`community_visible_pages_where`) — 조건절은 `decidePageRead` 를 SQL 로 옮긴 것이고, `pages_list`(평평·`tree=1`)·`pages_get` 의 `children`·`child_titles` 가 **같은 문장**을 쓴다 | `routes.php` · `handlers/pages_list.php` · `handlers/pages_get.php` |
| `pages_create` 에 `space_id`(없으면 내 개인 공간, 팀 공간이면 그 팀원만) · 새 페이지 범위는 **부모와 같게**, 뿌리는 `private` | `handlers/pages_create.php` |
| `pages_move` 가 `fitsUnderParent` 를 본다 — 가지 전체에서 가장 넓은 값으로 | `handlers/pages_move.php` · `community_move_shape` 의 `subtree_widest` |
| 화면 — 머리의 범위 배지(`.badge--admin-only`/`--team`/`--internal`) · 「공개 범위」 단추 → `<dialog id="visibility-dialog">`(라디오 셋 + 「하위까지 함께」 체크 + 설명) · 홈의 **공간 고르개**(`.toolbar select`) · 목록·트리 줄의 범위 배지 · 셸 내비의 「팀 공간」 묶음 | `handlers/page_view.php` · `handlers/home_page.php` · `src/views/shell_top.php` · `public/assets/community/editor.js` |
| 감사 — `page.visibility\|allow` · `\|deny\|wider_than_parent` · `\|deny\|children_wider` · `\|deny\|not_owner` · `page.read\|allow\|same_team` · `page.move\|deny\|wider_than_parent` · `space.create\|allow\|team_member`. `detail` 등록부에 `visibility_from`·`visibility_to`(`cascaded` 는 이미 있던 키) | `src/Audit.php` |

규칙
- 화면 전용 클래스 **0**. 배지 색은 t-box 등급 배지의 뜻 그대로다(좁을수록 빨강) — 새 색값을 만들지 않았다 (DESIGN 2.2).
- **목록은 SQL `WHERE` 로 거른다.** 화면·PHP 배열 필터가 한 곳도 없다. 자식은 부모보다 **좁을 수 있으므로**(넓은 페이지 아래의 비공개 하위) 하위 목록·`child_titles` 에도 같은 조건절이 붙는다.
- 남의 **개인 공간 목록은 통째로 403** 이다 — 그 안에 사내로 열어 둔 페이지가 있어도 남의 공간 목차를 넘겨보는 일은 공개 범위가 허락한 일이 아니다. 그 페이지 한 건은 `GET /api/pages/{id}` 로 볼 수 있다.
- 팀 판정은 **역할이 아니라 id 비교**다. 값은 세션의 유효 팀(`$u['team_id']`)과 t-box `users`·`teams` 표에서만 온다.

### 21.8 4-B 수락 기준 (C:T-30 ~ C:T-37, 스크립트 `tests/community/stage4b.sh`)
| ID | 무엇 | 기대 |
|---|---|---|
| C:T-30 | `team` 으로 | 200 · 같은 팀 200(감사 `same_team`) · 다른 팀 403 · Guest 403 |
| C:T-31 | `internal` 로 | 다른 팀 200(`matrix`) · **Guest 403** · Admin 200 · `published` 는 400 |
| C:T-32 | 남이 고치기 | `team`·`internal` 이어도 소유자 아닌 사람의 PATCH(본문·범위)는 403 + 감사 `page.visibility deny not_owner` |
| C:T-33 | 부모 `private`, 자식을 `internal` 로 | 400 `wider_than_parent` · 감사 · DB 불변 |
| C:T-34 | 부모 `internal`→`private`, 하위에 `internal` | `cascade` 없이 400 `children_wider` / `cascade:true` 면 200 + 하위 전부 `private` + **항목마다 감사** |
| C:T-35 | 목록 격리 | 남의 개인 공간 목록 403 · 내 목록 원문에 남의 제목 0건 · 그 `internal` 페이지 한 건은 직접 읽기로 200 |
| C:T-36 | 팀 공간 | `GET /api/spaces` 가 만들어 돌려준다(두 번째는 `created:false`, 행 수 1) · 팀원은 만들고 고친다 · 다른 팀 403 |
| C:T-37 | 판정 위치 | psql 로 센 「볼 수 있는 행 수」 = API 가 준 수 · 응답 원문에 볼 수 없는 제목 0건 |
| 브라우저 | `browser_smoke.py` 27~29 | 범위 창·배지·새로 고침 유지 · 하위까지 적용 체크 · 공간 고르개로 팀 공간 왕복 · 콘솔 오류 0 |
| 회귀 | `stage3.sh`·`stage4a.sh`·`stage26.sh`·`help_button.sh`·`security_lint`·`php -l` | 통과 수 불변 |

### 21.9 4-C 범위 — 버전 이력 · 되돌리기 · 처리 이력 · 휴지통 (2026-09-23 구현)

지시서 `stage4c-history-directive.md`. **마이그레이션 하나**
(`db/migrations/2026-09-23_community_version_squash.sql` — `page_versions.created_at` 추가 +
옛 행 채움 + `checklist_events(page_id, at DESC)` 확인. 표·열 삭제 0).
★ **앱은 그 파일을 적용하기 전에도 뒤에도 그대로 동작한다** — 4-C 코드는 새 칸을 읽지도 쓰지도
않고(기본값이 스스로 채운다), 5분 합치기는 같은 뜻을 이미 들고 있는 `edited_at` 을 본다.
인덱스 `page_versions(page_id, version DESC)` 는 **만들지 않았다**: 제약
`UNIQUE (page_id, version)` 이 만든 btree 를 `Index Scan Backward` 가 그대로 쓴다(임시 DB `EXPLAIN` 확인).

**범위 밖**: 인계(F-28) · 30일 자동 정리(둘 다 7단계) · 감사 로그 화면.

만든 것
| 무엇 | 어디 |
|---|---|
| **5분 합치기** — 직전 스냅샷이 **같은 사람**이고 **5분 안**이면 새 스냅샷을 만들지 않는다(그 사이 변화는 다음 스냅샷에 합쳐진다). 판 번호는 **매번 오른다**(낙관적 잠금이 그 번호에 걸려 있다). 판정은 **DB 를 읽지 않는 함수** `community_squash_snapshot()`, 재료는 `community_last_snapshot()` 한 질의 | `routes.php` · `handlers/pages_update.php` |
| 창구 여섯 — `GET …/versions` · `GET …/versions/{num}` · `POST …/versions/{num}/restore` · `GET …/history` · `GET /api/me/trash` · `POST …/restore` | `routes.php` · `handlers/versions_list.php`·`version_get.php`·`version_restore.php`·`history.php`·`trash_list.php`·`page_restore.php`(전부 새) |
| 이력 창구 넷의 **공통 관문** `community_history_gate()` — 행 읽기 + `decidePageRead` + 「관리자·같은 팀이라서 열렸다」 감사까지 한 자리. 같은 스무 줄이 네 벌이 되지 않게 | `routes.php` |
| **되돌리기 = 새 판으로 복사** — ① 지금 판을 스냅샷(`summary='restore from v<n>'`)으로 남기고 ② 지난 판의 `doc`·제목을 현재로, `doc_version` +1. `WHERE doc_version = 읽은 값` 으로 남의 저장을 덮어쓰지 않는다(어긋나면 409). 파생 표도 되돌린 내용으로 다시 쓴다. 그 판이 **지금 규칙(`Doc::validate`)을 통과하지 못하면** 400 `invalid_doc` — 페이지가 잠기지 않게 | `handlers/version_restore.php` |
| **되돌리기도 체크 이력을 남긴다** — 저장과 같은 함수 `community_check_changes()`·`community_insert_check_events()` | `routes.php` |
| `decidePageRestore()` — `decidePageChange` 와 **같은 자격**(지워진 행이라는 것만 다르다). 규칙을 다시 적지 않고 같은 함수를 부른다 | `CommunityAuthz.php` |
| 휴지통 — 「한 줄 = 한 번의 삭제」(뿌리만, 함께 내려간 하위는 `descendants` 숫자). 되살리기는 **`deleted_at` 이 같은 가지**를 통째로 올리고, **원래 자리로 돌아갈 수 없으면 뿌리로 올리고 `moved_to_root: true`** 로 알린다(까닭은 응답의 `moved_reason`: `parent_deleted` 부모가 아직 휴지통에 있다 · `parent_narrower` 부모가 그 사이 좁아졌다 — 좁히기 검사는 **살아 있는 하위만** 보므로 지워진 가지는 옛 범위를 들고 남는다). 목록 조건절은 다른 목록과 **같은 문장**(`community_visible_pages_where`) + 「내가 지웠다」 | `handlers/trash_list.php`·`page_restore.php` |
| 화면 — 머리의 「이력」 → `<dialog id="history-dialog" class="modal">` 탭 둘(**단추 둘**이라 탭 전용 클래스 0) · 줄마다 「보기」(읽기 렌더러가 그 판을 그린다)·「되돌리기」(창 안에서 한 번 더 확인 — 모달 안에 모달 없음) · 「내 공간」의 「휴지통」 창 | `handlers/page_view.php`·`home_page.php` · `public/assets/community/editor.js`·`community.css` |
| 감사 다섯 줄 — `page.restore_version\|allow`·`\|deny\|not_owner` · `page.restore\|allow`·`\|deny\|not_owner` · **`page.delete\|deny\|not_owner`(3단계에 빠졌던 것 보완)** · `detail` 등록부에 `restored_from`·`moved_to_root` | `src/Audit.php` · `handlers/pages_delete.php` |

규칙
- **기록은 지울 수 없다** (S-14). 새 코드도 `page_versions`·`checklist_events` 에 SELECT·INSERT 만 쓴다. 되돌리기는 과거 행을 **한 글자도 고치지 않는다** — 새 판을 하나 더 만들 뿐이다.
- **이력 응답에 본문이 없다.** `versions`·`history` 는 `doc` 을 고르지도 않고, 블록 수는 DB 가 `jsonb_array_length(doc->'blocks')` 로 세어 숫자만 준다. 본문은 `versions/{num}` 에서만, 그것도 읽기 판정 뒤다. 체크 줄은 할 일의 글자 대신 **블록 id** 를 싣는다.
- **목록에 지금 판을 함께 싣는다**(`is_current: true`). 지금 판은 `pages` 에 있고 `page_versions` 에는 없으므로, 그 줄이 없으면 화면이 없는 것을 지어내야 한다.
- 되돌리기 표시(`summary`)는 「이 판이 어떻게 끝났나」이므로 화면에는 **그 다음 판**의 줄에 붙는다(`community_restore_source()` + 옮겨 붙이기).
- 「누가 지웠나」와 「누가 되살리나」는 같은 사람이다 — 자격(`decidePageRestore`)을 지나도 **`deleted_by` 가 나**여야 되살린다.

### 21.10 4-C 수락 기준 (C:T-38 ~ C:T-45, 스크립트 `tests/community/stage4c.sh`)

| ID | 무엇 | 기대 |
|---|---|---|
| C:T-38 | 5분 합치기 | 판정 함수 단위 8가지 전부 기대대로 · 연속 저장 3회 → 스냅샷 **1개만** 늘고 판 번호는 **3 오른다** |
| C:T-39 | 되돌리기 | 과거 판 내용이 현재가 되고 `doc_version` 이 오른다 · 과거 스냅샷은 **개수·내용 불변**(md5) · 감사 1건 |
| C:T-40 | 기록 불변(S-14) | **앱 계정으로** `UPDATE`/`DELETE` × `page_versions`·`checklist_events` → 전부 **42501** (SELECT 는 된다) |
| C:T-41 | 체크 이력 | 켜고 끄고 다시 켜기 → `checklist_events` **3행** · 「처리 이력」 3줄 시간순 · 누가·언제가 맞다 |
| C:T-42 | 남의 이력 | 다른 팀 계정의 `versions`·`history`·`versions/{n}`·`restore` → **403** · 없는 판 번호 **404** · 관리자는 **보되(200) 되돌리지 못한다(403)** |
| C:T-43 | 휴지통 | 하위까지 삭제 → 휴지통에 **한 줄**(하위는 숫자) → 되살리면 하위까지 함께 · 부모가 아직 지워져 있거나 **그 사이 좁아졌으면 뿌리로** + `moved_to_root: true`(+`moved_reason`) · 남이 지운 것은 목록에도 없고 403 |
| C:T-44 | 본문이 새지 않는가 | `versions`·`history` 응답 원문에 본문 글자 **0건**(`doc` 키도 없다) · 본문은 `versions/{num}` 에만 |
| C:T-45 | 되돌린 뒤 파생 표 | `page_blocks` 가 되돌린 `doc` 과 같다 · `page_links` 맞음 · `block_count` 맞음 |
| 브라우저 | `browser_smoke.py` 30~31 | 탭 둘 동작 · 「보기」가 그 판을 그린다 · 「되돌리기」 확인 뒤 본문이 바뀌고 새로 고침해도 유지 · 휴지통에서 되살리기 · 콘솔 오류 0 |
| 회귀 | `stage3.sh`·`stage4a.sh`·`stage4b.sh`·`stage26.sh`·`help_button.sh`·`security_lint`·`php -l` | 통과 수 불변 |

### 21.11 5-A 범위 — 소유자·인계 · 사람 지정 공유 (2026-09-23 구현, 지시서 `stage5a-owner-share-directive.md`)

사용자 결정 2026-09-23: ① 「담당자」는 그 문서의 **소유자**이고 바꾸는 일은 **인계**다(기존 소유자 공간에서 빠지되, 공유로 남길 수 있다) ② 공개 범위에 더해 **아이디로 보기·편집**을 준다 — PRD NG-8 해제 ③ 인계 대상은 팀 제한 없음(Guest·비활성 제외) ④ 남길 권한은 모달로 묻고 기본값 없음 ⑤ 편집 공유자의 하위 만들기는 권한대로.

**판정 — `CommunityAuthz` 한 곳** (규칙을 두 벌로 적지 않는다)

| 함수 | 쓰는 곳 | 갈래 |
|---|---|---|
| `decidePageRead` | 읽기 전부 | 기존 순서 + **[4b] 공유**(이 페이지나 조상에 **이 사람 몫**의 공유, `shareLevelFor` 가 `share_user_id` 를 대조) → `shared`. `published`·모르는 범위 검사 **뒤**에 둔다(닫힌 실패 유지) |
| `decidePageChange` | 저장·체크·상태·편집 신호·되돌리기·하위 만들기(부모 행에) | 소유자 · 팀원 · **편집 공유 `shared_edit`**(쓰기 자격 있는 역할만 — Guest 는 편집 공유여도 거짓) |
| `decidePageManage` (새) | 삭제·옮기기·되살리기·공개 범위·공유 추가·바꾸기·빼기(남의 줄) | 소유자 · 팀 공간 팀원 — **편집 공유로는 열리지 않는다** |
| `decidePageHandover` (새) | 인계 | 소유자 · Admin(`admin_all`, **재인증**) · Manager(주인이 자기 팀원일 때 `team_scope`, 내용 비열람). 팀 공간 페이지는 `space_governed` 400 |
| `decideHandoverTarget` · `decideShareTarget` (새) | 받는 사람 | 활성 계정만 · 지금 주인 아님 · 인계는 쓰기 자격 있는 역할만 |

`community_page_row($id, $viewerId)` 가 재귀 CTE 로 **가장 강한 공유 등급**을 읽어 싣고, 목록 조건절 `community_visible_pages_where` 는 같은 SQL 조각(`COMMUNITY_SHARE_RANK_SQL`)을 쓴다 — 판정 함수와 한 줄씩 짝(C:T-56 전수 대조).

**데이터** — `db/migrations/2026-09-23_community_owner_share.sql`: `page_shares`(page·user·level view|edit·granted_by, UNIQUE(page,user) — 앱 SIUD, 바뀐 일은 감사) · `handovers`(root·from·to·by·page_count·kept_level — 앱 **SELECT·INSERT 만**). 4-D 의 `pages.assignee_id` 는 지우지 않고 **쓰기를 멈췄다**(`properties` 는 `status` 만, `/api/assignees` 404). 운영 적용 2026-09-23 메인(`../scripts/apply-community-5a-2026-09-23.sh`, 표 → 권한 → 코드 순).

**창구** — `GET /api/pages/{id}/shares` · `PUT /api/pages/{id}/shares/{username}` `{level}` · `DELETE /api/pages/{id}/shares/{username}`(Manage, 또는 자기 줄) · `GET /api/share-candidates?page=&q=[&for=handover]` · `POST /api/pages/{id}/handover` `{to, keep: none|view|edit}`(keep 필수) · `GET /api/pages?shared=me`.

**인계 동작** — 그 페이지 + **살아 있는** 하위 전부를 받는 사람 개인 공간의 **뿌리**로(없으면 공간을 만든다). 판·체크·속성 기록과 작성자는 그대로, 받는 사람의 옛 공유 줄은 지우고, `keep` 이면 옛 주인에게 공유 한 줄. 「처리 이력」에 인계 줄(`kind: handover`).

**남은 것** — Manager 는 비열람이라 화면이 없다(창구만, 7단계 「인계 대기」에서) · 옛 부모의 `child_page` 카드는 「볼 수 없는 페이지」로 그려진다 · 팀 공간 페이지는 공유 대상 아님(사용자 확인 대기).

### 21.12 5-B 범위 — 검색 (2026-09-23 구현)

- 창구 `GET /api/search?q=`(community.read) — `q` 는 앞뒤 공백을 뗀 1~100자, 비면 빈 결과. `ILIKE`(`#` 이스케이프, 바인딩)로 `title`·`plain_text`. 최대 20건, 제목에 맞은 것 먼저 · 최근 고친 순. pg_trgm 은 설치돼 있지 않다 — 규모가 커지면 확장·색인을 운영 적용(사용자 승인)과 함께.
- 범위: **공간 관문 없음 · 행 관문 = `community_visible_pages_where`**(5-A 공유 포함, 5-A″ 팀 공간 공유도 저절로). 단 `:vis_admin` = false — 관리자 특권 갈래는 검색에 쓰지 않는다.
- 응답: `{id, title, icon, space:{kind,name}, path:[{id,title}], snippet, in_title}`. `path` 는 `community_breadcrumb`(볼 수 있는 조상까지 — 관리자는 특권 갈래로 조상 제목이 보일 수 있다, 받아들인 한계). `snippet` 은 찾은 자리 앞 40자·뒤 80자 평문(`/u` 정규식 — mbstring 없음).
- 화면: `public/assets/community/search.js`(editor.js 와 별개) · 사이드바 `#nav-community-search` · `<dialog id="search-dialog">`(시작 때 `body` 끝으로 옮긴다 — 좁은 화면에서 숨은 사이드바 안에 있으면 보이지 않으므로) · Ctrl/⌘+K · ↑↓ Enter Esc. 강조는 `<mark>` + `textContent`.
- 시험: `tests/community/stage5b_search.py <SITE> <UA> <직원1> <직원2> <관리자>` — `SEARCH_FAIL=0`.

### 21.13 5-C 범위 — 게시판 공간 · 새 글 · 게시 · 태그 · 조회수 (2026-09-23 구현 — 복제본, 운영 적용 대기)

지시서 `stage5c-board-publish-directive.md`. 사용자 결정: 글 올리기는 **둘 다**(게시판에서 바로 새 글 + 개인 페이지 게시) · 태그는 **자유 입력 + 자동완성**.

**데이터** — `db/migrations/2026-09-24_community_boards.sql`(삭제 0, 멱등): `spaces.board_kind`(notice·discussion·qa) + CHECK 셋(`spaces_board_shape` — 게시판이면 종류가 있고 `notice = (board_kind='notice')` · `spaces_board_team` · 이름 UNIQUE(lower) 게시판만) · `pages.published_board_id`·`published_from`·`published_at`·`published_by`·`hidden_at`·`hidden_by`·`resolved` + CHECK(`pages_published_shape` — `published` 면 게시판·원래 범위·시각이 있고, 아니면 게시 칸이 비어 있다) · `page_property_events.prop` 에 `publish`·`hide`·`resolved` · `page_tags(page_id, tag, tag_norm=lower(tag))` PK(page_id, tag_norm) — 앱 SIUD · `page_views(page_id, user_id, day)` PK — 앱 **SI 만** · 시드 셋(공지사항 public·자유게시판 internal·질문과 답변 internal).

**판정 — `CommunityAuthz` 한 곳**

| 함수 | 갈래 |
|---|---|
| `decidePageRead` | [2] 게시판 글: 쓴 사람 `owner` · 숨김이면 게시판 관리자 `board_moderator` · 아니면 `decideBoardRead` → `board` · [4] **기준 범위**(`baseVisibility` — 게시 중이면 `published_from`) · [4a] 게시 중 + 숨기지 않음 + 게시판 읽기 → `board` · 공유·사내·팀·Admin 은 기준 범위로 · [7b] 게시 중 + 숨김 + 게시판 관리자 → `board_moderator`(**맨 뒤** — 같은 팀 열람 `same_team`·관리자 열람 `admin_all` 감사가 빠지지 않게) |
| `decidePageChange` · `decidePageManage` | 게시판 글은 **쓴 사람만**(`not_author`) — 관리자도 내용 편집 불가(PRD F-18). 비활성 게시판은 고치기 `board_inactive` |
| `decidePageShare` (새) | 관리와 같되 게시판 글은 `space_governed`(게시판 등급이 곧 범위 — 공유 갈래 없음) |
| `decideBoardRead` · `decideBoardWrite` · `decideBoardModerate` (새) | t-box `Authz::decideRead(게시판 등급)` 그대로 · 쓰기 = 읽기 + 쓰기 자격 + 공지는 Admin + 활성(아니면 400) · 관리자 = Admin `admin_all` · 팀 등급 게시판의 그 팀 Manager `team_scope` |
| `decidePublish` · `decideUnpublish` · `decideHide` (새) | 게시 = 개인 공간 주인만(편집 공유자 불가) · 해제 = 주인 또는 게시판 관리자 · 숨김 = 게시판 관리자만 |

목록 조건절 `community_visible_pages_where` 는 같은 갈래를 SQL 로 — 게시판 등급은 `community_board_read_sql`(등급 넷을 `Authz::decideRead` 에 물어 만든 바인딩 값, 역할 문자열 0), 관리자는 `community_board_moderate_sql`, 게시한 게시판은 조건절 안의 `EXISTS`(부르는 SQL 별칭 불변). 검색은 `community_search_pages_where` — `admin_all`·`board_moderator` 두 특권 갈래를 끈다 + 숨긴 게시판 글 제외. 전수 대조(목록·검색 두 벌) 어긋남 0.

**트리 규칙** — 게시는 트리의 넓이가 아니다: 부모·가지의 넓이는 전부 기준 범위로 견준다(`community_move_shape`·`descendants_*`·되살리기·새 하위). 게시 중에는 공개 범위를 바꾸지 않는다(400 `published`), cascade 는 게시 중인 하위의 `published_from` 만 맞춘다.

**창구** — `GET /b/{num}` 화면 · `GET /api/boards` · `POST /api/boards` · `PATCH /api/boards/{num}`(`role:Admin` + 재인증 — 새 정책 없음, 등급·팀·종류 고정) · `GET/POST /api/boards/{num}/pages` · `GET /api/boards/{num}/tags?q=` · `POST/DELETE /api/pages/{id}/publish` · `POST/DELETE /api/pages/{id}/hide` · `PUT /api/pages/{id}/tags` · `PATCH …/properties {resolved}` · 조회수는 `GET /api/pages/{id}` 가 센다. 없는·못 보는 게시판 번호는 전부 **403 동일 문구**.

**시험** — `tests/community/stage5c.sh` (C:T-80~90, 브라우저 `browser_5c.py`).

### 21.14 5-D 범위 — 댓글 · 멘션 · 알림 · 공지 읽음 (2026-09-23 구현 — 복제본, 운영 적용 대기)

지시서 `stage5d-discussion-directive.md`(결정 D-1~D-9 기본안). 마이그레이션 `2026-09-24_community_discussion.sql`: `comments`(앱 SIU — 지우기는 논리) · `comment_reactions`(SID) · `notifications`(SIU) · `page_reads`(SIU, C:S-14).

- **판정** — 댓글은 페이지를 따른다(창구마다 `community_history_gate` 먼저, 댓글 창구는 `community_comment_gate`). 새 함수 `decideCommentCreate`(읽기 + 쓰기 자격 + 보관 게시판 아님) · `decideCommentChange`(쓴 사람만) · `decideCommentHide`(Admin · 게시판 관리자) · `decideCommentResolve`(첫 댓글 쓴 사람 · 페이지 관리자) · `decideReadsView`(공지 글의 쓴 사람 · Admin) · `decideMentionTarget`(그 사람 몫으로 읽은 페이지 행 + `decidePageRead`, `admin_all`·`board_moderator` 로만 보는 사람 제외).
- **알림** — `community_notify` 가 받는 사람마다 판정한 뒤에만 만든다. 목록은 **읽을 때 다시 판정**(공유가 빠지면 제목·주소 null). 창구 응답에는 알림 대상·수가 없다(볼 수 있는 사람 목록이 수로도 새지 않게).
- **게시 전 기록** — 게시판 등급으로만 보는 사람에게는 게시 뒤 판·이력·댓글만(`community_history_gate` 의 `since`, 5-C #9).
- **창구** — `GET/POST /api/pages/{id}/comments` · `PATCH/DELETE /api/comments/{id}` · `POST/DELETE /api/comments/{id}/resolve|helpful|hide` · `GET /api/mention-candidates?page=&q=` · `GET /api/notifications[?unread=1]` · `POST /api/notifications/read {all|ids}` · `GET /api/pages/{id}/reads`(n/N 수만).
- **화면** — 페이지 아래 댓글 절 · 사이드바 알림 종(30초 폴링) · 공지 속성 줄 「읽은 사람 n / N」. 확인은 모달이 아니라 두 번 누르기, 고치기는 그 자리에서.
- **시험** — `tests/community/stage5d.sh`(C:T-100~105) · `browser_5d.py`.
- **7-D4** — 글 안 멘션의 이름 지도(`community_mention_labels`)도 사람 범위는 이 절의 후보 규칙과 같은 좁은 쪽이다(보는 사람·대상 모두 `canCommunityWrite` · 대상 활성). 사람 라벨 「이름 (아이디)」는 **창구가 `tbox_person_label()` 로 잇는다** — 5-A″ 「창구(JSON)는 잇지 않는다 — 화면이 잇는다」의 예외 하나(문서 안 글자라 두 렌더러 동등성이 우선 · 잇는 규칙은 여전히 `bootstrap.php` 한 함수 · 부르는 자리는 셸·런처 + routes.php 셋).

### 21.15 5-E 범위 — 첨부 · 토글 · 표 · 페이지 링크 · 역참조 (2026-09-23 구현 — 복제본, 운영 적용 대기)

지시서 `stage5e-attach-blocks-directive.md`(결정 E-1~E-8 기본안). **새 표·마이그레이션 없음.** 문서 단계 `COMMUNITY_DOC_STAGE` 3 → **5**, `doc-schema.json` 단계 표에서 `column_list`·`column` 을 6 으로 옮기고 사유 `bad_ref`·`ragged_row`·`merge_not_in_stage` 를 더했다(원본·사본 동일).

- **검증(`Doc.php`)** — `tbox-ref`(`^tbox:` + UUID, 형태만) · 표 `rows`(1~200행 · 1~20열 · 행마다 칸 수 같음 · `rowspan`/`colspan` 은 1 만 · 색은 팔레트) · `col_widths`(40~2000 정수, 열 수와 같은 길이) · `extract()` 가 `files`(첨부 UUID)와 `link_to_page` 링크, 표 칸·그림 설명 글자를 모은다.
- **판정** — `CommunityAuthz::pageAudiences(row, ?extraBoard)`: 페이지를 읽는 범위 목록(개인: 기준 범위 — 비공개는 빈 목록 · 게시 중이면 게시판 등급 더함 · 팀 공간: 그 팀 · 게시판 글: 게시판 등급). `fitsFileRef(file, aud)`: t-box `LEVEL_RANK` 에서 파일 ≤ 범위, 둘 다 팀이면 같은 팀. 공유 갈래는 범위에 넣지 않는다(사람 지정 — t-box 가 사람마다 판정).
- **검사 자리** — `community_file_refs_ok(ids, audiences, user)`: 저장하는 사람이 t-box `decideRead` 로 볼 수 있고 모든 범위에 맞아야 참. 없는·못 보는·좁은 파일은 **같은 400**. `pages_update`(새로 붙인 파일만) · `version_restore` · `pages_create`(본문에 파일이 있으면 400) · `pages_publish`(게시판 범위 더해 400 `file_narrower`) · `pages_visibility`(이 페이지 + 「하위까지 함께」로 넓어지는 하위, 400 `file_narrower` + 감사).
- **그리기** — `pages_get` 이 `file_titles`(보는 사람이 볼 수 있는 파일만: 이름·크기·그림인가) · `link_titles`(볼 수 있는 페이지만) · `backlinks`(목록 조건절로 거른 역참조)를 준다. 렌더러 두 벌(`DocRender`·`doc-render.js`)은 지도에 없으면 「볼 수 없는 파일/페이지」, 그림 주소는 t-box `/api/files/{id}/preview/raw`(t-box 가 다시 막는다).
- **에디터** — 새 블록 다섯은 덩어리 노드. 「블록 추가」 → 「블록 고치기」 창(`#atom-dialog`) → 넣기, 두 번 누르기·Enter 로 다시 고친다. 토글 안·표 칸은 평문(꾸밈은 저장하면 사라진다 — 결정 E-2). 파일 목록은 t-box 창구 `GET /tbox/api/files` 그대로.
- **경합** — 본문 저장은 읽은 범위(`visibility`·`published_board_id`)가 그대로일 때만, 범위 넓히기·게시는 읽은 `doc_version` 이 그대로일 때만 쓴다(사이에 바뀌면 409) — 첨부를 견준 상태와 쓰는 상태가 갈라지지 않게(독립 검토 L1).
- **편집기 보호** — 꾸밈(마크·색·열 폭)이 든 토글·표·설명이 있으면 `fromDoc` 이 던져 편집기를 열지 않는다(창이 평문이라 말없이 지우지 않게 — 독립 검토 M2).
- **남은 것(누출 아님 — 그림이 「볼 수 없는 파일」로 보일 뿐, t-box 가 막는다)** — 인계로 `team` 범위가 새 주인 팀을 뜻하게 될 때 옛 팀 파일 · 같은 팀 Guest(범위 판정은 팀 단위, Guest 는 t-box 에서 사내·팀 파일을 못 봄) · 「하위까지 함께」 중 하위 본문이 동시에 저장되는 틈 · 페이지당 고유 파일 200개 초과면 넓히기·게시가 막힘. 기능 남은 것: 붙여넣기 자동 업로드(F-25) · 단 나누기 · 칸 병합(6단계).
- **시험** — `tests/community/stage5e.sh`(C:T-110~115) · `browser_5e.py`.
- **7-D4** — 글 안 멘션의 페이지 라벨은 이 절의 `community_link_titles` 를 그대로 쓴다(목록 조건절 `community_visible_pages_where` 짝 · 관리자 특권 갈래도 그 조건절대로). 사람 라벨의 5-A″ 예외는 21.14 끝 줄.

### 21.16 6-A 범위 — 데이터베이스(표) 핵심 (2026-09-23 구현)

지시서 `stage6a-database-directive.md`(결정 G-1~G-12 기본안). 마이그레이션 `2026-09-25_community_databases.sql`: `databases`(앱 SIU) · `pages.database_id`·`properties`(CHECK 둘이 함께) · 처리 이력 prop `db:<key>`.

- **모델** — DB 한 개 = DB 페이지 하나. 「DB 페이지 바로 아래 페이지 = 행」(`community_db_sync_row` 가 만들기·옮기기·인계·되살리기 뒤 맞춘다). 행은 보통 페이지라 본문·댓글·이력·공유·판정이 그대로다. 트리·하위 목록은 `database_id IS NULL` 만.
- **속성** — `DbProps`(순수): 열 가지 종류 + 기본 열 셋(제목·상태·소유자). key 는 서버가 만든다(`p`+8hex). 값은 키 단위로 합친다(`properties || set - del`), 바뀐 칸마다 이력.
- **거르기·정렬(T-28)** — 허용 목록 → `DbProps::FRAG`/`SORT_FRAG` 고정 조각, key·값 바인딩, `ORDER BY` 글자는 상수 한 줄. 행 목록은 목록 조건절 안에서만.
- **판정** — 행 = 행 페이지 판정 · 새 행 = DB 페이지 `decidePageChange` · 스키마 = DB 페이지 `decidePageManage` · 사람 값 = `decideMentionTarget`. DB 안 DB·게시 400.
- **창구** — `GET /api/databases/{id}/rows` · `PATCH /api/databases/{id}/schema` · `PATCH /api/pages/{id}/values` · `POST /api/pages {database|properties}` · `GET /api/pages/{id}` 의 `database`·`row`.
- **화면** — `database.js`(표·칸 편집·거르기·정렬·새 행·속성 창·행 속성 줄). DESIGN 2.14 등록.
- **독립 검토 반영** — 새 행의 DB 검사는 부모 판정 **뒤**(M-1) · 행 한도는 DB 줄을 잠그고 다시 센다(L-1) · 게시 중인 페이지는 DB 안으로 못 옮긴다(L-2). 수용한 것: 행만 공유받은 사람에게 그 DB 의 스키마(속성 이름·선택지)가 보인다(값을 그리려면 필요, L-3) · 사람 값·이력의 계정명은 행을 좁힌 뒤에도 남는다(4-D 담당자와 같음) · 되살리기는 행 한도를 보지 않는다.
- **남은 것** — 6-B(보드·리스트 뷰·계산·관계·파일 속성·뷰 저장) · 6-C(단 나누기·칸 병합) · 지운 속성 되살리기 · 새 공간 첫 화면의 「새 데이터베이스」.
- **시험** — `tests/community/stage6a.sh`(C:T-130~137) · `browser_6a.py`.

### 21.17 6-B 범위 — 데이터베이스 뷰 · 보드 · 계산 · 관계 · 파일 (2026-09-23 구현)

지시서 `stage6b-database-views-directive.md`(결정 H-1~H-5 기본안). **새 표·마이그레이션 없음**(6-A 의 `databases.views` 를 쓴다).

- **뷰** — `databases.views` = `[{id:v+8hex, name, type: table|board|list, filter?, sort?, hidden?}]` ≤10. 저장할 때 `DbProps::cleanView` 가 거르기·정렬을 지금 스키마로 컴파일해 본다(읽을 때도 — 지운 속성을 가리키는 뷰는 빠진다). 자격 = DB 페이지 `decidePageChange`(H-1). `rows?view=` 는 뷰의 거르기에 요청 거르기를 AND.
- **보드** — 상태 다섯 + 없음 열. 끌기·← → 가 `PATCH …/properties {status}` 한 곳을 부른다.
- **계산(T-29)** — `GET /api/databases/{id}/summary` : 행 목록과 같은 조건절 + 거르기 안에서 `DbProps::AGG` 고정 조각 집계 한 번. 결과는 수만(행 수 · 완료 수 · 속성마다 채움 · 숫자 합·평균 · 체크 수 · 가장 가까운 앞날).
- **관계** — `{type: relation, target: 같은 공간 DB}`. 값 = 행 UUID ≤20, `community_db_refs_ok`: 넣는 사람이 읽을 수 있는 대상 DB 의 행만(같은 400). 제목은 `community_db_ref_titles`(목록 조건절로 거른 지도). 이력은 개수만. 대상 고르기는 `GET /api/databases?space=`(볼 수 있는 DB 만).
- **파일** — `tbox:UUID` ≤10, 5-E 규칙(`community_file_refs_ok`, 새로 붙인 것만) · 범위 넓히기(하위까지 함께 포함)가 행의 파일 속성도 다시 본다 · 이름은 `community_file_titles`.
- **시험** — `tests/community/stage6b.sh`(C:T-140~145) · `browser_6b.py`.

### 21.18 6-C 범위 — 단 나누기 · 표 칸 합치기 (2026-09-23 구현)

지시서 `stage6c-columns-merge-directive.md`(결정 I-1~I-5 기본안). 새 표 없음. `COMMUNITY_DOC_STAGE` 5 → **6**.

- **검증(`Doc.php`)** — `column_list` 의 자식 = `column` 2~3 · `column` 은 목록 안에만 · 단 안에 단 없음(`state.parent_type`·`in_column`) · 표 칸 `rowspan`·`colspan` 1~20, 합친 칸은 표 안(`merge_out_of_table`) · 덮이는 칸은 비고 1×1(`merge_overlap`). 단계 5 문서는 전과 같다.
- **렌더러 두 벌** — `div.doc-columns.doc-columns--2|3 > div.doc-column` · `coveredCells` 같은 규칙으로 덮인 칸을 건너뛰고 시작 칸에 rowspan·colspan. 720px 미만은 단을 쌓는다.
- **편집기** — `columns` 덩어리(단 수 + 단마다 평문 문단) · 표 덩어리 `spans` · toDoc 이 문서 전체 id 겹침을 새로 만든다 · 꾸밈·비율·여러 줄이 든 단/토글은 편집기를 열지 않는다.
- **`mention` 마크** — 단계 7 로 옮겼다(그리는 곳이 아직 없다 — 결정 I-5).
- **시험** — `tests/community/stage6c.sh`(C:T-150~155) · `browser_6c.py`.

### 21.19 7-A 범위 — 휴지통 30일 정리 (2026-09-24 구현·운영 v2.16.0)

지시서 `stage7-finish-directive.md` 7-A(결정 J-1·J-2). 마이그레이션 `2026-09-26_community_purge.sql`: `pages.purged_at` · 함수 `community.purge_trash(dry, max)`(소유자 tbox_owner, EXECUTE 는 PUBLIC 에서 회수).

- **지우는 것** — 판 이력 · 댓글·답글·반응·알림 · page_blocks · 나가는 page_links · 편집 중 · 공유 · 태그 · 조회 · 읽음 · DB 값 이력(`db:*`) · DB 스키마·뷰(줄은 남김).
- **남기는 것** — 페이지 행(제목 「영구 삭제된 페이지」, 본문·찾기 글 비움) · 체크 기록 · 상태·게시·숨김·해결됨 기록 · 인계 · 감사. 백업(보관 45일)에는 정리 전 내용이 남는다 · 감사의 `target_name` 에 제목이 남는다(J-1 로 수용).
- **동시성** — 고른 행을 `FOR UPDATE SKIP LOCKED` 로 잠근다 · 되살리기는 영구 삭제된 것·정리 시각이 지난 것을 404 로.
- **부르는 곳** — `scripts/community_purge.php`(root, 소유자 pgpass, 기본 dry) → 앱 감사 `page.purge`(DB + 감사 파일, 해시 체인 그대로) · cron `deploy/cron.d/tbox-v2` 04:10(설치는 사용자).
- **시험** — `tests/community/stage7a.sh`(C:T-160~163).

### 21.20 7-B 범위 — 템플릿 (2026-09-24 구현·운영 v2.17.0, claude)

지시서 `stage7-finish-directive.md` 「7-B 확정」(결정 J-3a~J-3f). 마이그레이션 `2026-09-27_community_templates.sql`: 표 `community.templates`(범위 칸 하나만 채우는 CHECK · 논리 삭제 · `seed_key` 로 기본 셋 셋 — 회의록 · 인수인계서 · 업무 체크리스트). 권한 `tbox_app` SELECT·INSERT·UPDATE(DELETE 없음).

- **판정** — `CommunityAuthz::decideTemplateRead`(personal 만든 사람 · team 그 팀원 · site 쓰기 자격 · database = DB 페이지의 `decidePageChange`) · `decideTemplateDelete` · `decideTemplateSave`(원칙: 템플릿을 볼 사람 ⊆ 원본을 볼 수 있는 사람 — 사이트는 숨긴 게시 글·`admin_all`/`board_moderator` 로만 읽는 원본을 막는다: `pageAudiences` 는 숨김을 보지 않는다). 목록 SQL 은 범위로 거르고 한 줄씩 판정을 다시 지난다. 감사 `template.create`·`template.read`·`template.list`(DB 목록 거부)·`template.delete`.
- **크기** — 본문은 앱이 빈칸 없는 JSON 1MB 로 먼저 잰다(400 `too_large`). DB CHECK 는 jsonb 글자 모양(공백이 붙는다)을 생각해 2MB.
- **본문** — `Doc::templateBody`(image·file·child_page 블록 제거, to_do 체크 해제, 빈 뿌리는 빈 문단) → 저장 때 `Doc::validate` · 쓸 때 `Doc::freshIds` 뒤 다시 `Doc::validate`(규칙이 바뀌었으면 400 `template_outdated`).
- **DB 행 템플릿** — 속성 값은 `community_template_props`(지금 스키마에 있고 · 글·숫자·체크·주소·메일·전화·날짜·선택만 · 모양이 맞는 값)로 저장 때와 쓸 때 두 번 거른다. 쓸 때 부모는 그 DB 페이지로 고정, 요청 값이 템플릿 값 위에.
- **한도** — 범위별 `pg_advisory_xact_lock` 뒤 다시 센다.
- **화면** — `templates.js`(홈 「템플릿에서」 창 · 페이지 「템플릿으로 저장」 창 · DB 새 행 고르개). 역할·범위를 따지는 코드 없음, 미리보기는 `renderDoc`.
- **시험** — `tests/community/stage7b.sh`(C:T-170~177) · `browser_7b.py`.

### 21.21 7-C 범위 — 임베드 허용 목록 (2026-09-24 구현·운영 v2.19.0, claude)

지시서 `stage7-finish-directive.md` 「7-C 확정」(K-1~K-7 · J-4). 마이그레이션 `2026-09-28_community_embeds.sql`: 표 `community.embed_domains`(살아 있는 호스트 하나 · 모양 CHECK · 빼기는 `removed_at`). 문서 단계 6 → 7(`embed`), 글 안 `mention` 은 8 로 미룸.

- **호스트 모양** — `Doc::isEmbedHost`(소문자 ASCII 라벨 · 점 하나 이상 · 마지막 라벨은 글자로 시작 · 253자 · `D`) · `Doc::embedHost`(https · 사용자 정보·포트 없음 · 경로는 ASCII 인쇄 가능 글자만). JS `embedHost` 가 같은 규칙.
- **저장** — 검증기는 모양만(`url`: `bad_scheme`·`bad_host`·`control_char`). 허용 목록 검사는 만들기·고치기·되돌리기 창구가 **새로 넣은** 임베드만(`embed_not_allowed` + 감사). 템플릿은 담을 때 견준다.
- **그리기** — 판단은 **그릴 때의 목록**(저장 검사는 보안 경계가 아니다): 목록 안 + 틀 열림 → `<iframe sandbox="" referrerpolicy="no-referrer" loading="lazy">` · 목록 안 → 「허용 대기」 카드 + 새 탭 링크 · 목록 밖 → 링크 없는 카드. 표를 못 읽으면 빈 목록(닫힌 쪽).
- **CSP** — 코드 상수 `COMMUNITY_EMBED_FRAMES`(기본 끔). 켜면 `community_embed_csp()` 가 페이지 화면 응답의 CSP 에 허용 목록 호스트를 `frame-src` 로 덧붙인다(3,000자 넘으면 넓히지 않음). **nginx 가 따로 붙이는 CSP 도 바꿔야** 틀이 열린다(사용자 설정).
- **화면** — 블록 추가 「임베드」 창(허용된 사이트 안내 · 목록 밖은 넣기 전에 알림 · 주소는 `URL` 로 맞춤) · 홈 「임베드 허용 목록」 관리 창(Admin, 재인증) — `embeds.js`.
- **시험** — `tests/community/stage7c.sh`(C:T-180~186, claude_ 검토 계정) · `browser_7c.py`.

### 21.22 7-D1 범위 — 많이 읽힌 문서 (2026-09-24 구현·운영 v2.20.0, claude)

지시서 「7-D 확정」 L-1 · 결정 J-6. 새 표 없음. 창구 `GET /api/admin/popular`(role:Admin — 재인증) · 감사 `page.popular`.
- **범위는 판정과 같게** — 개인 공간 기준 범위 internal · 사내 전체가 읽는 등급(`Authz::levelsReadByAllWriters` — 쓰기 자격 있는 모든 역할이 읽는 등급을 매트릭스에 물음)의 **게시판**(`pb.kind='board'`)에 게시되어 숨기지 않은 것 · 그런 게시판의 글(숨기지 않은 것, 보관 게시판 포함). 비공개·팀·팀 공간·팀·관리자 게시판·숨긴 비공개 원본·지운 것은 제목이 없다.
- **세기** — 지난 30일 `page_views`(사람 × 날) · 검토 계정(qa_·claude_ — `Origin` 접두와 같음, 시험이 대조) 제외 · 상위 20 · 누가 읽었는지는 돌려주지 않음.
- **화면** — `popular.js`(관리자에게만 단추 · 재인증은 셸 창).
- **시험** — `tests/community/stage7d1.sh`(C:T-190~193, claude_ 계정) · `browser_7d1.py`.


### 21.23 쓰던 글 임시저장 · 불러오기 — Qaredi 저장소 쪽 (Q:F-4 B1-2 · 사이트 PRD Q:F-7, 2026-09-24 구현·운영 68bdbb9, claude)

지시서 `../docs/qf4-quick-fixes-directive.md` 라운드 B 「B1-2」(결정 W-1 브라우저에만 · W-2 7일 · W-3 ⓑ 암호화·다시 로그인해야 풀림 · W-4 페이지 제목·본문 + 댓글·답글). **U-3(자동 요청은 세션을 늘리지 않는다 — t-box ARCH 4.0)과 같은 커밋·같은 발행** — U-3 만 들어가면 무활동 30분 로그아웃이 실제로 일어나 쓰던 글을 잃게 한다. 보안 판단은 SECURITY **C:R-10**.

- **왜 브라우저인가** — 자동 저장(14.3 — 1.5초 멈춤·늦어도 10초)이 이미 서버에 저장한다. 잃는 것은 「마지막 몇 초」와 「끊긴 뒤 쓴 글」이고 **그 순간은 서버가 받을 수 없다**. 서버는 사본을 받지 않는다(창구는 열쇠 하나뿐).
- **보관 열쇠·모양** — `localStorage` 열쇠 `qaredi-draft:v1:<아이디>:page:<페이지 uuid>` · `qaredi-draft:v1:<아이디>:comment:<페이지 uuid>:<답글 대상 uuid 또는 new>`. 값 `{"v":1,"iv","ct","saved_at"}` — `ct` 는 `{base_version,title,doc}`(댓글 `{body}`) JSON 의 암호문, **평문은 `saved_at`(ms) 하나**(7일 정리용). `<아이디>` 는 **진짜 사용자**의 username(역할로 보기 중에도 `/api/me` 의 `username` 은 실제 계정). ★ 지시서는 `<user_id>` 였으나 화면은 내부 id 를 모른다(`/api/me` 가 내부 id 를 내지 않는다 — t-box S-09-5 취지) — 로그아웃 지우기(`app.js`)도 같은 값으로 접두를 만든다. 아이디 글자 집합(`[a-z0-9_.-]`)에 `:` 가 없어 접두가 겹치지 않는다. 암호 열쇠는 서버가 **진짜 user_id** 로 파생하므로(아래) 같은 아이디의 새 계정이 생겨도 옛 사본은 풀리지 않는다.
- **암호화** — WebCrypto AES-GCM 256 · 쓸 때마다 새 무작위 IV 12바이트 · **AAD = 보관 열쇠 문자열**(다른 사람·다른 페이지 칸으로 옮겨 붙이면 풀리지 않는다). 풀기 실패(열쇠 바뀜·손상·옮겨 붙임) → 그 항목을 조용히 지운다.
- **열쇠 창구 `GET /community/api/me/draft-key`**(`draft_key.php`, 정책 `community.read` — 라우트 표 한 줄) — `HMAC-SHA256(APP_SECRET, "qaredi-draft-key:v1:" . 진짜 user_id)` 32바이트 → base64url, 응답 `{"v":1,"key":"…"}` · `Cache-Control: no-store` · ETag·Last-Modified 없음 · GET 이라 CSRF 없음. 새 비밀 0(CSRF 의 `csrf:` 접두와 입력이 겹치지 않는다 — 운영 `/etc` 변경·sudo 없음). **감사 없음**(본인 열쇠 받기는 `GET /api/me` 와 같은 급 · 편집·댓글 시작마다 불려 소음). 값은 감사·`app.log`·오류 응답·예외 메시지에 0, 핸들러는 예외를 삼키지 않는다(일반 500). `APP_SECRET` 이 비었거나 32바이트 미만이면 **503** + `app.log` 한 줄 「community draft key unavailable」(값·길이 없음) → 화면은 임시저장을 끈다. 부르는 때: 편집 모드에 들어갈 때 · 댓글 쓰기 칸에 처음 입력할 때 · 첫 화면·페이지에서 그 사람 항목이 있을 때 — **한 화면 수명 동안 한 번**(실패도 기억, 다시 조르지 않는다).
- **브라우저의 열쇠** — `drafts.js` 모듈 안에만: `importKey('raw', …, false, ['encrypt','decrypt'])`(extractable=false), 받은 바이트는 곧바로 0 으로 덮는다. 저장소·전역·쿠키 0. **열쇠를 못 받았으면 쓰지 않는다 — 평문 대체 0.** 모듈은 네트워크·DOM·콘솔에 닿지 않는다(열쇠 받기는 `editor.js` 가 준 함수).
- **쓰는 때** — 저장 안 된 변경이 있는 동안만: 변경마다 1초 묶음 · **401 을 받은 그 순간**(`toLogin` 이 떠나기 전에 `leaveHooks` 로 — 열쇠는 이미 메모리에 있다, 늦어도 1.5초 뒤에는 떠난다) · `beforeunload`·`visibilitychange(hidden)`. 쓰기마다 번호를 올려, 암호화가 끝났을 때 더 새 쓰기가 시작됐거나 그 판이 이미 저장됐으면 쓰지 않는다(늦게 끝난 옛 사본이 새 사본을 덮지 않게).
- **지우는 때** — 그 판이 서버에 **200** 으로 저장된 뒤(`serial === savedSerial`) · 댓글이 보내진 뒤 · 「버리기」 · 409 창의 「새로 고침」(이 창의 내용을 버리는 선택) · `saved_at` 이 7일을 넘긴 것(커뮤니티 화면을 열 때마다 그 사람 접두를 훑어 정리) · **로그아웃 단추**와 그 단추를 누르는 「강제 로그아웃」 링크(`app.js` `clearMyDrafts` — `drafts.js` 를 부르지 않고 그 사람 접두 이름만 지운다, 값을 읽지 않는다, 다른 사람 접두는 건드리지 않는다).
- **되살리기** — 페이지를 열 때 그 사람·그 페이지 항목이 있으면 열쇠를 받아 풀어 보고, 풀리는 것만 편집 영역 위(`#msg` 뒤)에 알림 줄(DESIGN 2.6 `.notice` + `.notice__title` + `.toolbar.notice__actions` 의 `.btn--quiet.btn--small` 둘 — 새 클래스 0): 「저장 안 된 내용이 있습니다 (2026-09-24 15:12 · 본문 · 답글) — 불러오기 / 버리기」. **불러오기**(사람이 누를 때만) → 본문은 `fromDoc`(스키마 통로)을 지나 편집 모드의 문서를 한 번의 편집으로 바꾸고(Ctrl+Z 로 서버 판으로 되돌릴 수 있다) **평소 저장 경로**로 보낸다 — 보낼 판 번호는 사본의 `base_version` 이라, 그사이 서버 판이 바뀌었으면 **기존 409 창**(14.3)이 뜬다(새 병합 코드 0). 서버 `Doc::validate` 가 다시 본다. 댓글·답글은 쓰기 칸에 **넣기만**(대상 댓글이 남아 있으면 답글 표시까지, 자동 전송 0). 고칠 수 없게 된 페이지의 본문·댓글을 쓸 수 없는 페이지의 댓글은 묻지 않는다(사본은 7일 뒤 정리). **버리기** → 서버 요청 0, 그 사본만 지운다(그사이 새로 쓰기 시작해 지키는 중인 사본은 두고).
- **첫 화면 「저장 안 된 글 n개」** — 그 사람 항목이 있을 때만 열쇠를 받아 풀리는 것만 센다. 줄마다 그 페이지 링크(제목은 풀어서 · 댓글은 앞 40자) · 이 브라우저 시각. 열쇠를 못 받으면 그리지 않는다. B1-1(로그인 뒤 보던 페이지로)이 없어도 찾아가게.
- **크기·실패** — 한 사람 20개(넘으면 오래된 것부터 버림). 쓰기 실패(용량·사생활 모드·막힌 저장소)·열쇠 없음(503·WebCrypto 없음) → 배지 「임시저장 못 함」(`.badge.badge--off`, 저장 상태 배지 옆) — 서버 저장은 막지 않는다.
- **더해서(설계 7)** — 창을 닫거나 탭이 숨을 때의 저장 요청에 `keepalive: true`(본문 60,000바이트 이하일 때 — 브라우저 한도 64KiB, 넘으면 평소대로) · 저장 안 된 변경이 있을 때 창을 닫으면 브라우저 기본 경고(`beforeunload` 의 `preventDefault` — 문구는 브라우저가 정한다. 401 로 로그인 화면에 가거나 409 창에서 「새로 고침」을 고른 때는 묻지 않는다).
- **바뀌지 않은 것** — 페이지 판정·저장 창구·스키마·렌더러. 임시저장은 **이미 그 사람 화면에 있던 내용**의 사본이라 새로 보이는 사람이 없다.
- **U-3 과의 짝** — 알림 폴링(`GET /api/notifications?unread=1`)과 편집 heartbeat(`POST /api/pages/{id}/heartbeat`)는 `X-Qaredi-Background: 1` 을 달아 세션을 늘리지 않는다(`editor.js` 상수 한 곳 — 서버 `Auth::BACKGROUND_HEADER`). 편집 모드로 켜 두기만 한 탭도 30분 뒤 로그아웃되고, 다음에 한 줄 쓰는 순간 401 → 사본 → 로그인 → 「n개」 → 페이지 → 불러오기.
- **시험** — `tests/community/qf4_draft_key.sh`(임시 인스턴스 포트 8102 · `SESSION_IDLE_MIN=2`: [A] 열쇠 창구 ⑧ · [B] U-3 ①~⑦ + P-3 Low-3 · [C] 브라우저) · `tests/browser/qf4_drafts.py`(B1-2 ①~⑦ · ⑨ · ⑩, 3분 방치 실측).
- **검토** — 독립 검토 두 번(취합 쪽: Medium 1 — 로그아웃 뒤 떠나는 길이 사본을 다시 쓰던 것 → `qaredi:logout` 을 먼저 받아 쓰기를 멈춤 · 개발 세션 쪽: High·Medium 0 · Low 7). 남은 개선 후보: 창을 닫는 순간의 암호화 쓰기는 최선 노력 · 알림 줄은 댓글 사본 가장 새 1개만 · 알림 줄을 두고 편집을 시작하면 옛 사본이 덮임 · 불러온 뒤 서버 판이 더 새면 내용이 같아도 409 창 · 읽은 평문 버퍼 0 채우기 (tbox-v2 SECURITY 7장).
- **보관 열쇠 접두가 아이디인 이유** — 화면이 내부 id 를 모른다(`/api/me` 는 내부 id 를 내지 않는다). 암호 열쇠는 서버가 진짜 user_id 로 파생하므로, 아이디가 같은 새 계정이 생겨도 옛 사본은 풀리지 않는다. `Auth::USERNAME_RE` 에 `:` 가 없어 접두가 겹치지 않고, 아이디를 바꾸거나 지우는 창구가 없다(검토 확인).

### 21.24 즐겨찾기 · 최근 — 커뮤니티 쪽 (개발 대기열 6 · Q:F-3a 라운드 A, 2026-09-25 구현 — 복제본, 운영 반영 전, claude)

지시서 `../docs/qf3a-favorites-recent-bulk-directive.md` 1.1 · 1.2 · 결정 M-1~M-3 · M-8 · 구현 기록 `../tbox-v2/docs/qf3a-favorites-recent-implementation.md`.
t-box 쪽(파일·폴더 즐겨찾기 · 감사 로그에서 읽는 최근)은 t-box ARCH 5.33. **새 판정 코드 0.**

- **표** — `community.page_favorites(user_id, page_id, position, created_at)` 기본키 (사람, 페이지) · 사람당 200 · 뒤에 들어올 「모음」(F-52, 22.16)의 기본 모음으로 옮길 수 있게 모양을 맞췄다. `community.page_recent(user_id, page_id, last_viewed_at)` 기본키 (사람, 페이지) · 사람당 50 — **기록이 아니라 화면 편의**(결정 M-3)라 앱 계정에 DELETE 가 있다(C:S-14 「지울 수 없는 기록」 표가 아니다). 페이지 외래 키 `ON DELETE CASCADE` · 사람 외래 키는 ON DELETE 절 없음. ★ 30일 정리(21.19)는 페이지 **행을 남기므로**(J-1) CASCADE 가 불리지 않고, 정리된 페이지의 줄은 남되 목록 조건(지워진 것 제외)으로 **나오지 않는다** — 정리 함수는 고치지 않았다(구현 기록 D-2 · U-2).
- **창구**(정책 전부 `community.read` — 대상은 세션의 본인뿐, 요청에서 사람을 받지 않는다 · 상태를 바꾸는 넷은 CSRF)

| 창구 | 판정 · 조건 | 응답 |
|---|---|---|
| `GET /api/me/favorites` | 내 줄 ⋈ 페이지 + **검색과 같은 조건절** `community_search_pages_where`(관리자·게시판 관리자 특권 갈래 끔) + `deleted_at IS NULL` + `NOT (board AND hidden)` · position 순 · 200 | `{pages: [{id, title, icon, space: {kind, name}}], limit}` — **전체 개수 칸 없음** |
| `PATCH /api/me/favorites` `{ids}` | 몸은 `ids` 하나(UUID · 겹침 없음 · 200 이하 — 아니면 400) · **내 줄**의 position 만 1..n | `{ok}` |
| `PUT /api/pages/{id}/favorite` | 페이지 읽기와 같은 행(`community_page_row_nodoc`)·같은 판정(`decidePageRead`) — 없음·지움·못 읽음은 **같은 404 문구**(`pages_get` 의 403 `not_owner` 와 다르다 — 존재가 갈리지 않게) · 가득 차면 목록에 안 나오는 내 줄을 걷고 다시 셈 → 그래도 409 | `{ok, favorite, listed}` — `listed` = 이 페이지가 목록 조건 안인가(관리자가 `admin_all` 로 연 남의 비공개 페이지는 넣어도 false) |
| `DELETE /api/pages/{id}/favorite` | 내 줄만(있든 없든 200) | `{ok, favorite:false}` |
| `GET /api/me/recent` | 내 줄 ⋈ 페이지 + 위와 같은 조건 · 마지막 연 때 내림 · 50 | `{pages: [{…, last_viewed_at}], limit}` |
| `DELETE /api/me/recent` | 내 줄만 전부(「최근 기록 지우기」) — 조회수(`page_views`)·감사는 그대로 | `{ok}` |

- **최근 쓰기** — `pages_get.php` 가 읽기 판정을 **지난 뒤** 조회수 바로 옆에서 `community_record_recent()`: `INSERT … ON CONFLICT DO UPDATE SET last_viewed_at = now() WHERE last_viewed_at < now() - 5분` — 5분 안에 다시 열면 갱신 0 · 새 줄일 때만 50 줄 넘는 가장 오래된 줄을 지운다 · 실패해도 읽기는 막지 않는다(try/catch — 조회수와 같은 태도). 관리자가 `admin_all` 로 연 남의 페이지도 줄은 남지만(조회수와 같은 자리) 목록 조건이 거른다.
- **감사 없음** — 결정 M-2(개인 화면 설정 · 거부도 — 새 감사 행동 0).
- **화면**(`shell_top.php` · `page_view.php` · `editor.js`, 새 클래스·색값 0) — 사이드바 맨 위 「즐겨찾기」(`<details open>` + `summary.nav-title` — 접기에 스크립트 0, 접은 상태는 이 브라우저 localStorage 에만) → 「최근」 5줄 + 「더 보기」 → 「내 공간」 → 「팀 공간」 → 「게시판」 → 「공유받은 페이지」(결정 M-1). 줄은 사이드바 트리와 같은 `.nav-tree` 줄. 즐겨찾기 순서는 줄을 끌어 놓거나 링크에 초점을 두고 **Alt+↑/↓**. 페이지 ☆ 는 머리 「더보기」 메뉴의 **맨 위 항목**(머리 단추가 이미 넷 — DESIGN 2.9) 「즐겨찾기에 넣기/빼기」(`aria-pressed` · 별 아이콘 둘은 이 화면 템플릿). 「더 보기」 창(`.modal--form.modal--stack` · `.list`) = 최근 50까지 + 「최근 기록 지우기」(빨간 단추 **두 번** — 댓글 지우기와 같은 방식). 런처에는 보이지 않는다(M-8).
- **시험** — `../tbox-v2/tests/community/qf3a.sh`(T1·T2·T3·T5 + 순서·한도 · `REGRESS=1`) · `../tbox-v2/tests/browser/qf3a_favorites.py`(T9 라운드 A 몫 — 두 앱). 라운드 B(일괄 — 선택 모드·휴지통 되살리기)·C(zip)는 따로.

### 21.25 7-D2 범위 — 팔레트 관리 (2026-09-27 구현 · 검토 통과 review-fix/7-D2 620e0dc · 2026-09-28 운영 v2.34.0, claude)

지시서 `stage7d-palette-contrast-mention-directive.md` 1장 · 「7-D 확정」 L-2 · 결정 P-1~P-17(전부 기본안). 마이그레이션 `2026-10-07_community_palettes.sql`(패스키 `2026-10-06` 뒤): 표 `community.palettes`(id · name · light · dark · added/updated/removed 의 사람·시각 쌍 · 모양 CHECK · 살아 있는 이름 부분 UNIQUE) — 앱 SELECT·INSERT·UPDATE(**DELETE 없음** — 빼기는 `removed_at`) · 백업 SELECT · perm·push 0. `db/community/grants.sql` 끝 절에 같은 권한 줄(어느 쪽을 다시 돌려도 같은 권한 — 시험이 모양·권한 md5 로 대조).

- **이름·값** — 이름 `[a-z0-9-]{1,20}` 이고 **끝 `-bg` 아님**(`Doc::isPaletteName` — 이름 `x-bg` 의 글자색 클래스가 이름 `x` 의 배경 클래스 `pal-x-bg` 와 겹치지 않게 · 지시서 C:T-204 「`pal:x-bg` 400」) · 기본 9색 이름·`none` 예약(400 `reserved` — 목록은 `doc-schema.json` `colors` 에서 읽는다, DB CHECK 에는 없음) · 값은 앞뒤 빈칸 떼고 소문자로 맞춘 뒤 `#rrggbb`(아니면 400 `bad_color`). 이름은 고칠 수 없다(PATCH 에 `name` 400).
- **저장 형식** — `pal:<이름>`(글자) · `pal:<이름>_bg`(배경). 검증기는 **모양만**(`Doc::isPaletteColor` — 블록 `color` · 표 행·칸 `color` · 마크 `color` 넷이 같은 함수 · 모양 밖 400 `not_in_palette`) · `doc-schema.json` 변경 0. `Doc::extract` 가 `palette_names`(`_bg` 뗀 이름, 한 번씩)를 더한다.
- **있는 이름인지** — 저장 창구 넷이 **새로 넣은 이름만** 살아 있는 목록과 견준다(7-C 임베드와 같은 자리): `pages_update`(지금 문서와의 차 · 거부 감사 `page.update deny palette_not_found`) · `pages_create`(첫 본문 전부 — 템플릿에서 만들 때는 막지 않음, K-7) · `version_restore`(되돌릴 판에서 새로 들어오는 것 · 감사 `page.restore_version deny palette_not_found`) · `templates_create`(담을 때 전부). 게시판 새 글(`board_pages_create`)은 본문을 받지 않는다(5-C — 첫 저장이 `pages_update` 의 같은 판정). 뺀 이름이 이미 든 글은 다른 곳을 고쳐 저장할 수 있다.
- **그리기** — 렌더러 두 벌 `colorClass`: `pal:x` → `pal-x` · `pal:x_bg` → `pal-x-bg` · 모양 밖 → 클래스 없음. **목록을 보지 않는다** — 뺀 이름은 CSS 규칙이 없어 색 없이(부모 색) 그려진다. 편집기 `editor-schema.js` 도 같은 모양(붙여넣은 `pal-` 클래스 읽기 포함).
- **CSS 창구** `GET /community/palette.css`(`palette_css.php` · 정책 `community.read`) — `text/css; charset=utf-8` · `Cache-Control: private, max-age=604800` · `ETag: "<버전>"`(`If-None-Match` 같으면 304) · 버전 = 살아 있는 줄을 이름순(바이트 순) `name|light|dark` 로 줄바꿈 이어 sha256 앞 12자리(줄이 없으면 빈 글자의 해시 `e3b0c44298fc`) — 셸 `?v=` · `ETag` · 목록 창구 `version` 이 같은 함수(`community_palette_version`). 배경값 = 채널마다 `(값×w + 바탕×(100−w) + 50) ÷ 100`(라이트 w=12 · 바탕 `#ffffff` / 다크 w=24 · 바탕 app.css 다크 `--bg` `#09090b`) — `Palette::tint`(DB·요청 없는 순수 계산 `src/community/Palette.php`), 브라우저 `color-mix()` 0. 그릴 때 한 번 더 모양을 보고 밖의 줄은 건너뛴다(`error_log` 한 줄).
- **창구** — `GET /api/palettes`(누구나 — 편집기가 이름을 안다 · `id`·`added_at`·`added_by`·`updated_at` 은 관리자에게만 · `{palettes, limit:12, version, can_manage}`) · `POST /api/palettes {name, light, dark}` · `PATCH /api/palettes/{num} {light?, dark?}` · `DELETE /api/palettes/{num}` — 셋 다 `role:Admin`(재인증이 저절로 · 새 정책 이름 0). 한도 12 · 겹침은 `pg_advisory_xact_lock(hashtext('community.palettes'))` 트랜잭션 안에서 센다. 없는 줄·뺀 줄은 같은 404. 감사 `palette.add|update|remove allow`(target 이름 · detail name·light·dark · update 는 before).
- **실패 폐쇄(P-17)** — 표를 못 읽으면(반영 순서 어긋남 · 권한) 빈 목록으로 닫는다: 페이지 열기 200 · 빈 CSS 200 · 새 `pal:` 저장 400 · 편집기는 기본 9색. 읽기는 트랜잭션 밖에서만(실패한 SELECT 가 트랜잭션을 깨지 않게).
- **화면** — 홈 「더보기」 → 「팔레트 관리」(관리자에게만 — `GET /api/boards` 의 `can_manage`) · `palettes.js`(`bindPalettes` — 더하기·고치기·빼기 뒤 `#palette-css` 의 `?v=` 를 창구가 준 버전으로 바꿔 그 자리에서 다시 읽는다 · 대비 경고 WCAG 2.1 4.5:1 미만은 알리기만 · 빼기는 두 번 누르기 · 재인증은 셸 창) · 편집기 「색」·「배경」 줄에 기본 9 뒤 살아 있는 이름 견본(편집기를 만들 때 목록 한 번 — 실패하면 기본 9).
- **시험** — `tests/community/stage7d2.sh`(C:T-200~208, 임시 인스턴스 8100 · 기준 판 비교 8101 · claude_ 계정) · `browser_7d2.py`.
- **남은 것** — 다른 글에서 붙여넣은 `pal-` 클래스가 뺀(또는 없는) 이름이면 자동 저장이 400 이 된다(7-C L-5 와 같은 모양 — 메시지로 알림, 붙여넣기에서 거르지는 않음) · 뺀 이름의 클래스는 규칙이 없어 시각 점검(ui_snapshot)의 「규칙 없는 class」 로 셀 수 있다(뜻한 동작).
- **검토 반영(620e0dc)** — 관리 창구 셋이 표 없음(42P01)·권한 없음(42501)에서 503 `palette_unavailable`(`community_palette_unavailable` · 감사 0) · 반영 스크립트의 미로그인 401 확인 뺌 · 모양 밖 줄 로그 요청당 한 줄.

### 21.26 7-D3 범위 — 고대비 화면 (2026-09-27 구현 · 검토 통과 review-fix/7-D3 d52fca2 · 2026-09-28 운영 v2.34.0, claude)

지시서 `stage7d-palette-contrast-mention-directive.md` 2장 · 결정 J-5 · L-3 · DESIGN.md 2.16. 기본안 그대로(★ 0).
- **저장** — `public.users.high_contrast BOOLEAN NOT NULL DEFAULT false`(마이그레이션 `2026-10-08_users_high_contrast.sql` · 열 하나 · GRANT/REVOKE 0 — 표 단위 권한에 포함, `tbox_push` 열 단위 셋 밖) ·
  **코드보다 먼저**: `Auth::SQL_LOOKUP` 이 이 칸을 읽는다(칸 없이 새 코드 → 로그인한 요청 500 — 임시 인스턴스에서 실측). 쿠키·localStorage 로 판단하지 않는다.
- **창구** — `PATCH /tbox/api/me/settings {high_contrast: bool}`(t-box 라우트 표 한 줄 · `authenticated` · CSRF · 대상은 세션의 본인 — UPDATE … WHERE id = 세션 사용자 ·
  **토큰 집합 밖**) · 본문은 그 한 칸뿐(다른 키 · 둘 이상 · `"true"` · 1 · null · 배열 · 빈 본문 · JSON 아님 → 400, 기록 0) · 감사 `me.settings|allow` detail `{high_contrast}` ·
  응답 `{high_contrast}`(RETURNING) · `updated_at` 은 밀지 않는다(역할·활성·비밀번호·2단계 시각) · `/api/me` 에 `high_contrast`.
- **화면** — 서버가 `<html … data-contrast="high">` 를 그린다: 셸 `shell_top.php`(t-box · 커뮤니티 · 재인증 화면) · 런처 `launcher.php`(로그인했을 때만) — `<html>` 한 줄 안에서 계산(새 PHP 함수 0 ·
  `Http.php` 그대로) · 로그인 화면 없음. 「역할로 보기」 중에도 실제 사람의 칸(H-8 — 따로 코드 0, 시험으로 확인).
  토큰은 `app.css`·`community.css` 의 세 블록(`:root[data-contrast="high"]` · `…[data-theme="dark"]` · `@media (prefers-color-scheme: dark) …:not([data-theme="light"])`)에만 —
  새 이름 0 · 화면 CSS 갈래 0 · 다크 기본 블록 뒤(같은 무게라 밝은 고대비가 이기므로 어두운 고대비 블록이 밝은 고대비의 이름을 모두 다시 적는다).
  「내 계정」 「화면」 패널(`.panel` — `.grid-2` 안 「내 정보」 옆) · `app.js initContrastToggle` — 바꾸면 PATCH 하나 → 200 이면 속성 놓기·떼기 · 실패면 체크 되돌림 + `#msg` 서버 문구.
  7-D2 팔레트 값은 고대비에서 그대로(H-7) · `forced-colors` 는 다루지 않음(H-9).
- **시험** — `tests/community/stage7d3.sh`(C:T-210~214 · 임시 인스턴스 둘 = 새 판 + 기준 판 ad5a795 · 같은 임시 DB) · `tests/lib/contrast.py`(WCAG 2.1 · 외부 라이브러리 0 · 116 쌍) ·
  `tests/browser/hc_screens.py`(화면 일곱 × 360·412 터치·마우스·820·1280 × 라이트·다크) · C:T-214 끈 상태 토큰 66개 계산값 = 기준 판 · ui_snapshot 결함 수 = 기준 판 · dbg_s_screens.
- **검토 반영(d52fca2)** — 저장소 되돌리기는 두 단계(라우트 표가 든 `public/index.php` 먼저 → 3초 → 나머지 → 핸들러 파일 마지막 — opcache 가 새 라우트 표를 쥔 채 핸들러가 사라지는 500 창 0 실측) · t-box `ARCHITECTURE.md` 7.4·7.5 · `API.md` 3장·18장 · `tests/api_whoami.sh` 키 목록.

### 21.27 7-D4 범위 — 글 안 멘션 (2026-09-27 구현 · 검토 통과 review-fix/7-D4 279db5d · 2026-09-28 운영 v2.35.0, claude)

지시서 `stage7d-palette-contrast-mention-directive.md` 3장 · 「7-D 확정」 L-4 · 결정 J-7 · M-1~M-13(전부 기본안 — 아래 메인 결정 예외 셋). 기준 판 `review-fix/7-D2`(620e0dc — 운영 ad5a795 위의 7-D2 검토 통과판). **DB 변경 0**(새 표·열·마이그레이션 0).

- **문서 단계 표** — `doc-schema.json`(원본 + 사본) `stage_marks` `"8": ["mention"]` → `"7": ["mention"]`. `COMMUNITY_DOC_STAGE` 7 그대로 · `marks.mention` · `reasons` 그대로.
- **모양(M-2)** — `Doc::mention()`: 키 정확히 둘 · `kind` 문자열 `user|page|date`(아니면 `bad_type`/`bad_value`) · `user` = JSON 정수(`is_int` — `"12"`·`1.5` 는 `bad_type`) 1~2⁵³(`Doc::MENTION_USER_ID_MAX` — JS 배정밀도에서 같은 값으로 읽히는 끝) · `page` = 소문자 UUID 36자 · `date` = `YYYY-MM-DD` + `checkdate`(1900~2100) · 그 세그먼트 `t` 정확히 「@」 · `link` 와 함께 → `bad_value`. 오류 경로는 모두 `blocks[i].text[j].m.mention`. 모양 함수 셋(`isMentionUserId`·`isMentionPageId`·`isMentionDate`)과 `mentionShapeOk` 를 검증기·이름 풀이·서버 렌더러가 함께 쓴다(JS 두 벌은 같은 규칙).
- **저장 검사(M-5)** — 존재를 보지 않는다. 없는 id 도 저장되고 「@볼 수 없음」으로 그려진다(임베드 K-4 「판단은 그릴 때」와 같은 태도). 감사 문장 추가 0(`page.update deny invalid_doc`).
- **파생(M-8)** — `Doc::extract` 에 `mentions`(모양 맞는 것만 · 한 번씩 · 200 까지 — 본문 글·설명·표 칸). `page_links`·역참조에는 넣지 않는다(E-7). `plain_text`·`search_tsv`·`page_blocks.excerpt` 에는 「@」 만.
- **이름 풀이(M-3·M-4)** — `community_mention_labels(array $mentions, array $u)`(routes.php): 사람 = 보는 사람이 `Authz::canCommunityWrite` 이고 대상이 `is_active` 이며 **대상도 `Authz::canCommunityWrite`**(손님 계정 제외 — 댓글 @ 후보 `decideMentionTarget`·편집기 후보와 같은 범위 · 지시서 예외 ⑥ 2026-09-28)일 때만 「이름 (아이디)」(`tbox_person_label`) — 보는 사람이 쓰기 자격이 없으면 **SQL 을 부르지 않는다** · 있으면 `WHERE id = ANY(CAST(:ids AS bigint[])) AND is_active` 한 문장(대상 줄에 `role`·`team_id` 칸 — 대상 자격은 PHP 한 줄 · 새 정책 이름 0 · 손님 대상은 없는 계정과 똑같이 지도에 자리 없음 → 「@볼 수 없음」) · 페이지 = `community_link_titles` 그대로(목록 조건절 — 관리자 특권 갈래도 그 조건절이 주는 대로) · 날짜는 지도에 없다. 지도 `mention_labels` 는 `GET /api/pages/{id}` · `GET …/versions/{num}`(지금 판·지난 판 둘 다 — 이름은 **지금** 보는 사람 기준) 에만. 라벨에 「@」 없음. 새 창구 0.
- **렌더러(M-6)** — 두 벌 `MARK_ORDER` 끝에 `mention` · 원문 「@」 를 라벨로 바꿔 그림 · 모양 밖 id 는 「@볼 수 없음」 · 멘션과 링크가 함께 있으면(검증기 밖 문서) 링크는 그리지 않는다. `DocRender::rich` 가 `$ctx` 를 받는다(블록 글·설명·표 칸).
- **편집기(M-7)** — `editor-schema.js` 마크 `mention`(attrs kind·id·label·key — label·key 는 편집기 안에서만 · `inclusive: false` · `excludes: 'mention link'` · 스키마 `parseDOM` 없음) · `toDOM` `span.doc-mention[data-label][data-mention-kind][data-mention-id][data-mention-key]` + CSS `::after { content: attr(data-label) }`. **파서 두 벌**: `editorDomParser`(편집기가 자기 DOM 을 다시 읽을 때만 — 멘션 바로 뒤에 친 글자·한글 조합이 span 안에 들어가 ProseMirror 가 그 자리를 다시 읽으면 마크가 사라지던 것을 막는 규칙 하나) · `clipboardDomParser`(붙여넣기·끌어 놓기 — 스키마 그대로라 멘션 0). `fromDoc` 이 `mention_labels` 로 라벨을 붙이고 `toDoc` 은 `kind`·`id` 만 — 「@」 한 글자가 아닌 조각(또는 링크와 함께)의 마크는 뗀다(편집기 자기 출력 정리 — 서버는 400). `key` 는 이웃한 같은 대상 멘션이 한 조각(「@@」)으로 합쳐지지 않게.
  `editor.js` 툴바 넷째 단추 「멘션」(글자를 고르지 않아도 쓸 수 있다) → `.editor-palette__row#mention-row`(종류 `select` · 찾기 `input[type=search]` · 날짜 `input[type=date]` + 「넣기」 · 후보 `ul.list.list--scroll` 줄 안). 사람 = `GET /api/mention-candidates?page=&q=`(200ms · 한 글자부터 · 10명 — 댓글 @ 와 같은 창구·판정) · 페이지 = `GET /api/search?q=` 결과 앞 10. 고르면 커서 자리에 「@」 + 마크(뒤 글자는 멘션 밖). `@` 키로 여는 길 없음(3-B 교훈). 새 멘션의 라벨은 이름 지도에 넣어 두어 저장 뒤 읽기 화면이 곧바로 그린다(다음 `load()` 에서 서버 것으로).
- **알림(M-9)** 0 · **템플릿(M-10)** `Doc::templateBody` 가 멘션 마크를 뗀다(글·설명·표 칸 — 「@」 남음) · **판 이력(M-11)** 마크 그대로 저장·복원.
- **불변식 예외 하나(메인 결정 2026-09-27)** — 5-A″ 「창구(JSON)는 「이름 (아이디)」를 잇지 않는다 — 화면이 잇는다」의 예외: 멘션 이름 지도의 사람 라벨은 창구가 `tbox_person_label()` 로 잇는다(지시서 M-4 · 문서 안 글자라 두 렌더러 동등성이 우선 — 잇는 규칙은 여전히 한 함수, 부르는 자리가 셸·런처 + `community_mention_labels` 셋). `bootstrap.php` 머리글은 고치지 않는 파일이라 그대로 — 이 절이 예외의 기록이다.
- **지시서 예외 ⑥(메인 결정 2026-09-28 — 검토 반영)** — M-3 「사람 = 보는 사람 쓰기 자격 + 대상 활성」 두 조건에 **대상 쓰기 자격**을 더함(좁은 쪽 · 5-D 후보 규칙과 같음). 편집기로는 넣을 수 없는 대상(손님 계정)을 API 로 넣어도 이름이 풀리지 않는다. 지시서 3.1 M-3 · 3.6 「사람은 `canCommunityWrite` + `is_active` 두 조건뿐」 문장은 이 셋으로 읽는다.
- **메인 결정 예외(2026-09-27)** — ① `mention_candidates.php` 항목에 `id`(users.id 정수) 한 칸: 문서 JSON 의 마크 id 와 `mention_labels` 키로 이미 그 글을 읽는 사람에게 나가는 값이고, 후보 범위·판정·정렬·개수는 그대로(새로 드러나는 사람 0) — 댓글 @ 화면은 이 칸을 쓰지 않는다 ② `community.css` 툴바 안 단추 좌우 12 → 8px(넷째 단추 뒤에도 닫힌 폭 320 이하 — 303px) ③ `tests/community/stage7c.sh:935` 옛 기대값(mention 8단계)을 「단계 6 → mark_not_in_stage · 단계 7 에서 글자 「@」 아님 → bad_value」 둘 다 보는 한 항목으로. ④ 위 불변식을 세던 회귀 12 한 항목씩을 「셸·런처 + routes.php」 파일 이름 목록 대조로(그 파일들의 다른 줄 0) ⑤ `tests/community/browser_stale_modules.py:152` — 대조군 B(옛 v2.18.0 부품)의 모듈 연결 오류 단언을 「빠진 이름이 새 export 집합(embedHost · mentionAllowed · editorDomParser · clipboardDomParser) 중 하나」로(크롬이 먼저 알리는 이름에 묶지 않게): `tests/community/stage5a2.sh:726` · `tests/community/stage5c.sh:809` · `tests/community/stage5d.sh:568` · `tests/community/stage5e.sh:662` · `tests/community/stage6a.sh:623` · `tests/community/stage6b.sh:593` · `tests/community/stage6c.sh:527` · `tests/community/stage7a.sh:496` · `tests/community/stage7b.sh:716` · `tests/community/stage7c.sh:954` · `tests/community/stage7d1.sh:555` · `tests/community/qf2_search.sh:803`.
- **시험** — `tests/community/stage7d4.sh`(C:T-220~227, 임시 인스턴스 8109 · claude_ 계정) · `browser_7d4.py` · stage3 C:T-19 표본 24.
- **남은 것** — 편집기 안의 캐럿이 멘션 끝에 있을 때 크롬은 친 빈칸을 nbsp 로 넣는다(편집기 공통 동작 — 저장 글자에 U+00A0) · 한글 IME 조합은 헤드리스 브라우저로 흉내 내지 못해 `keyboard.type` 의 글자 넣기로만 봤다(미검증 — 사람 확인 항목) · 고대비 화면(7-D3) 토큰은 이 판에 없어 `data-contrast` 속성만 켠 채 넘침·콘솔을 봤다(색 대비는 7-D3 판 위에서 다시).
- **검토 반영(2026-09-28 · review-fix/7-D4)** — 위 예외 ⑥ · 넓은 화면(900px 초과) 멘션 줄만 폭 상한(툴바 오른쪽 ~ 화면 끝 · `calc(100vw - 100% - var(--sidebar-w) - 72px)`) + 넘치면 후보 목록이 다음 줄 · 후보 라벨 한 줄 말줄임(900px 이하는 그대로 줄바꿈 · 같은 규칙을 쓰는 글자색·배경·링크 줄 그대로) · 도움말 「표 칸·설명 안의 멘션은 아직 편집기로 넣거나 고칠 수 없습니다(글 안에서만)」 · 아래 ⑤⑥.

---

### 21.28 8-A — 검증·만료·절차서 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

`stage8-trust-handover-directive.md` C:F-38·39 구현. 기존 `PATCH …/properties`에 `verify`(30·90·180·0·null), `kind`(page·procedure)를 더했다. 행을 잠근 뒤 최신 값을 다시 읽고 편집 권한을 확인하여 동시 속성 변경이 서로 덮어쓰지 않는다. 확인자·확인일·유효일과 절차서 표시는 페이지에, 바뀐 일은 추가 전용 `page_property_events`에 남는다. 만료 상태는 조회 시각으로 계산한다.

절차서 목록은 검색과 같은 일반 열람 조건으로 제한한다. 만료 알림은 알림 목록을 불러올 때 받는 사람의 현재 권한을 확인하고 중복을 막는다. 인계는 검증 정보와 절차서 표시를 보존하며 문서 제목 목록 대신 절차서 수를 보고한다. 마이그레이션은 `2026-09-29_community_trust.sql`이다. 예전 위키에 적힌 8-A 보관판은 원본이 확인되지 않아 이번 사본은 확정 지시서를 기준으로 다시 구현했다.

### 21.29 8-B — 내보내기·인쇄 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

C:F-40·S-17. `decidePageExport`는 일반 읽기와 쓰기 자격을 함께 요구하고 관리자·게시판 관리자 특권으로만 보이는 문서는 제외한다. 한 장은 Markdown/HTML, 하위 포함은 ZIP이다. 각 페이지에서 다시 판정하며 거부된 가지는 자식까지 건너뛴다. 제외 항목은 개수만 알리고 데이터베이스 행은 내보내지 않는다. 한도는 200문서·결과20MB다.

`DocMarkdown`·`DocExport`는 입력을 받는 순수 변환기다. 제목·머리말·파일 이름을 각각 이스케이프하고 출처 주소는 설정된 APP_URL로 만든다. HTML은 고정 CSS와 차단 CSP만 포함하며 첨부·임베드는 링크로 남긴다. ZIP 임시 파일은 허용된 Storage 임시 경로에서 만들고 종료 시 삭제한다. 인쇄는 판정된 문서를 서버에서 그리며 편집기를 열지 않는다. 통합 검증은 변환14·HTTP55·브라우저28, 합97개 통과했다.

### 21.30 9-A — 자동 작업·기한·반복 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

C:F-41·43·S-16. `Automation`은 허용된 일정·동작·제목 틀만 검사한다. 서울 시각으로 매일·매주·매월(1~28일 또는 말일), 10분 단위 시각을 받는다. 실행은 현재 소유자 권한으로 원본과 부모를 다시 판정하며, 놓친 일정은 가장 최근 한 몫만 처리한다. 유일 실행 몫과 파일 잠금으로 중복을 막는다. 작업200개 단위의 키셋 순회는 앞 규칙에 다음 규칙이 가려지지 않게 한다. 총 실행200회·생성500페이지 상한과 저장소의 이어가기 상태로 다음 배치에서 남은 규칙을 계속한다. 배치 경계·공정성39개 시험을 통과했다.

반복·버튼은 규칙을 저장하고 시험 실행한 뒤 켠다. 시험 실행은 미리보기/실행 기록만 쓰며 페이지·알림·대기 행을 만들지 않는다. 실행 기록의 페이지·알림 식별자는 그 실행이 직접 만든 것만 담는다. 실행자가7일 안에 되돌릴 수 있으나 남이 수정한 페이지는 보존한다. 인계할 가지에 묶이거나 그 가지를 원본으로 쓰는 규칙은 받는 사람에게 꺼진 채 이동하고 재시험이 필요하다. `due_date`와 자동 작업3표·알림 회수 칸은 `2026-09-30_community_automation.sql`, 최소 권한은 `db/community/grants.sql`에 있다.

배치는 웹과 같은 `tbox-php` 계정으로5분마다 실행한다. 사용자 「반영해」 승인으로 운영 반영을 진행하며, cron은21:29:22 설치했고 tbox-php 수동 첫 실행0run·0work·0created를 확인했다. 21:30:01 첫 자연 실행의 CMD와 cursor 갱신을 확인했다. 자연 종료코드는 직접 수집하지 않았으며 수동 첫 실행exit0과 구분한다. `--now` 시각 주입은 전용 합성 시험 DB에서만 허용한다. 실행 때 외부 요청·시스템 특권 사용은 없다.

### 21.31 9-B — 검토·기한 알림 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

C:F-44. 고정 규칙 `on_status`·`due_reminder`는 알림 동작만 허용한다. 검토 상태 변경은 요청 안에서 실행하지 않고 같은 트랜잭션에 대기 행을 남긴다. 처리 시 현재 페이지 위치·범위·권한·상태를 다시 확인하므로 인계 전의 오래된 대기 행이 새 소유자에게 잘못 알리지 않는다. 기한 하루 전 규칙은 완료·보류를 제외한다. 담당자는 개인 공간 주인, 팀/게시판에서는 작성자다. 완료일은 마지막 완료 상태 기록에서 읽는다.

### 21.32 9-C — 버튼 블록 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

C:F-45. 문서 단계8의 `button`은30자 이내 라벨과 자동 작업 UUID를 저장한다. 새 버튼은 저장자의 버튼 규칙이 그 페이지에 묶였는지 확인한다. 읽기 화면에서 누를 때는 소유자만 또는 페이지 편집자 범위를 확인하고 각 동작은 누른 사람의 현재 권한으로 실행한다. 같은 사람이10초 안에 다시 누르면409다. 꺼졌거나 삭제된 규칙은 실행되지 않으며 내보내기에는 실행 없는 글자만 들어간다.

### 21.33 9-D — 오늘 브리핑 (2026-10-01 v2.41.1 운영 반영·공개 화면 검증 완료)

C:F-42. `Briefing`은 열 때마다 계산한다. 커뮤니티는 검색의 일반 열람 조건, t-box는 내 업로드·내 공유 링크와 파일 열람 조건을 함께 쓴다. 각 종류는20건까지 모아 기한 지남·오늘 기한·검토·미응답 멘션·최근 순의 최우선3개, 중복을 뺀 어제 놓친 항목2개, 접힌 나머지로 나눈다. 문장은 고정 `BriefingText`로 만든다.

`GET /community/today`·`GET /community/api/briefing`·로그인된 런처의 「오늘 n건」은 읽기만 한다. 런처에는 제목을 내보내지 않고 실패하면 그 줄만 생략한다. 체크는 원본의 현재 판을 읽은 뒤 기존 저장 경로를 사용하여 처리 이력과 충돌 검사를 유지한다. 새 페이지는 사용자가 눌렀을 때만 개인 공간을 준비하고 생성한다. 통합 HTTP26·브라우저29, 합55개 통과했다.

> 절 번호 정정: 지시서 작성 뒤 21.23~21.27에 임시저장·즐겨찾기·7-D2~D4가 들어왔다. 그 절과 기존 참조를 보존하기 위해 이 묶음은21.28~21.33으로 배정한다. 운영은v2.41.0 DB·78파일 적용 후v2.41.1 CSS 보정까지 반영했고 공개 HTTPS42/0을 확인했다. 검토·회귀의 정확한 범위와 남은 한계는 STAGES와 발행 인계서를 따른다.

## 22. 편의 기능 설계 — 8~11단계 (PRD 5-2절 F-38~F-49, S-16~S-18 · 계획 2026-09-23)

> 근거: PRD 5-2절 · 11장 로드맵 8~12행 · `notion-features-2026-09-23.md`(노션 추가 점검·아침 브리핑 해부). 기본 구성(3~7단계)이 끝난 뒤 착수한다 — 이 장은 **착수 전 설계 초안**이며, 단계마다 지시서를 쓸 때 그 단계의 절을 확정본으로 고친다.
> 이름은 21장의 구현을 따른다: 판정 `CommunityAuthz::decidePageRead`·`decidePageChange`·`decidePageManage`, 목록 조건 `community_visible_pages_where`, 지울 수 없는 속성 기록 `page_property_events`(4-D), 공유 `page_shares`(5-A), 저장 경로 `Doc::validate`·409(13.4), 알림 `notifications`(5-D), 템플릿(7단계 F-31), 휴지통(4-C). 라우트는 전부 `/community/api/…`.

### 22.1 공통 전제 — 기본 구성에서 먼저 있어야 하는 것
| 편의 기능 | 기대는 기본 구성 |
|---|---|
| F-38 검증 · F-44 상태 자동화 | 4-D 속성 기록(`page_property_events`) · 5-D 알림 |
| F-39 절차서 · F-43 반복 체크리스트 | 7단계 템플릿(F-31) — 「템플릿에서 새 페이지」 함수 한 벌을 재사용 |
| F-40 내보내기 | 12.4 서버 렌더러 `DocRender` |
| F-42 브리핑 | 4-C 체크 · 4-D 상태·담당자 · 5-A 공유 · 5-C 공지 게시판 · 5-D 멘션·알림·읽음 · t-box `files`(검사 중·공유 만료) |
| F-46 수정 제안 | 4-C 판 이력 · 13.4 저장·409 · 에디터(14장) |
| F-47~F-49 | 6단계 데이터베이스(F-27): 속성·뷰·필터 허용 목록 |

### 22.2 8단계 — 검증·만료 (F-38)

> 2026-10-01 구현 기준 → 21.28절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
- 열: `pages.verified_by NULL → public.users` · `verified_at TIMESTAMPTZ NULL` · `verify_expires_at TIMESTAMPTZ NULL`(NULL = 만료 없음). 마이그레이션 1개(운영 적용은 사용자).
- 창구: 기존 속성 창구 `PATCH /community/api/pages/{uuid}/properties` 에 `verify: 30|90|180|0(만료 없음)|null(해제)` 키(지시서 `stage8-trust-handover-directive.md` 8-A — 따로 된 `…/verify` 창구는 두지 않는다). 판정 = `decidePageChange`(편집할 수 있는 사람만 「확인함」을 붙인다, K8-1 사용자 확인 대기). 매번 `page_property_events`(prop `verified`, from/to = 만료일·`none`·NULL) — 지울 수 없음.
- 만료 판정은 **읽을 때 SQL 로**(`verify_expires_at <= now()` → `expired`) — 배치가 값을 바꾸지 않는다. **알림도 배치·cron 없이**(K8-4, 기본안 수락 취합 2026-09-24 09:57): 받는 사람이 알림 목록 창구(`GET /api/notifications` — 종 폴링)를 부를 때 그 사람 몫의 만료됐고 아직 알리지 않은 페이지(최대 20)에 `notifications(kind='verify_expired')` 를 **그 사람 요청 안에서** 만든다 — 서버 배치·`/etc/cron.d` 변경 0, 5-2절 원칙 ④ 「사람의 권한으로」. 「알리지 않음」 = 같은 사람·페이지에 `verify_expired` 가 그 페이지 `verified_at` 뒤로 없음(다시 확인하면 다음 만료에 또 한 번). 받는 사람 = 개인 공간 주인 · 팀 공간은 확인한 사람 · 게시판 글은 쓴 사람(K8-5), `community_notify` 의 읽기 판정을 그대로 지나고 행위자는 없음(`actor_id` NULL).
- 화면: 페이지 머리 속성 줄에 「✓ 검증됨 · 박사원 · 09-23 · 12-22 까지」 또는 「⚠ 검증 만료」(검증 뒤 고쳤으면 「검증 뒤 고침」 — K8-3). 거르개 「검증 만료」는 페이지 목록·절차서 모음(검색 창은 Q:F-2 로 — K8-10).
- 감사: 속성 창구의 `page.property`(detail `prop: verified`) — 따로 된 `page.verify`·`page.unverify` 문장은 두지 않는다.

### 22.3 8단계 — 절차서 (F-39)

> 2026-10-01 구현 기준 → 21.28절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
- 열: `pages.page_kind TEXT NOT NULL DEFAULT 'page' CHECK IN ('page','procedure')`. 바꾸기 = 속성 창구 `PATCH …/pages/{uuid}/properties {kind}`(`decidePageChange`, `page_property_events` prop `kind` — 본문 저장 창구에 섞지 않는다, 8-A).
- 목록: `GET /community/api/procedures?q=&verify=` — 행 관문 기본안 `community_search_pages_where`(검색과 같게 특권 갈래 끔 — K8-8 사용자 확인 대기) + `page_kind='procedure'`, 항목에 검증 상태. 사이드바 「절차서」 묶음.
- 인계 화면(5-A)에 「넘어가는 절차서 n건」을 따로 센다(내용 비열람 원칙은 그대로 — 제목·개수만).

### 22.4 8단계 — 내보내기 (F-40)

> 2026-10-01 구현 기준 → 21.29절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
- 창구: `GET /community/api/pages/{uuid}/export?format=md|html&tree=0|1` → 한 장이면 파일 하나(`Content-Disposition: attachment`), `tree=1` 이면 zip(`ZipArchive` — php8.1-zip 설치 확인 2026-09-23). 인쇄(PDF)는 서버가 만들지 않는다 — `GET /community/p/{uuid}?print=1` 인쇄용 화면(서버 렌더러 HTML + 인쇄 CSS)을 브라우저 「PDF 로 저장」.
- 변환기: `src/community/DocMarkdown.php`(블록 JSON → Markdown, 12.2 블록 전부; 색·배경은 Markdown 에 없으므로 버리고, 콜아웃은 `> 아이콘 …` 인용으로) · HTML 은 `DocRender`. 둘 다 **순수 함수**(DB·요청 없음) — 동등성 시험에 넣는다.
- 권한: 트리의 **페이지마다** `decidePageRead`(S-17). 못 보는 하위는 빼고 `README.md`(또는 HTML 끝)에 「볼 수 없어 빠진 페이지 n개」만. 첨부(t-box 파일)는 넣지 않고 링크만(파일 권한은 t-box 가 판단).
- 크기: 트리 ≤ 200 페이지 · 응답 ≤ 20MB, 넘으면 400(나눠 받기). 서버 임시 파일은 요청이 끝나면 지운다(`tmpfile()`).
- 절차서의 AI 형식(N-39): `format=md` 에 머리말(`---\ntitle · owner · verified_at · expires · source_url\n---`)을 붙인 것 — 사용자의 AI 도구가 그대로 읽는다. 별도 창구 없음.
- 감사: `page.export|allow`(detail `format`·`pages`·`skipped`).

### 22.5 9단계 — 자동 작업 틀 (F-41, S-16)

> 2026-10-01 구현 기준 → 21.30절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
**표**
```sql
automations(id BIGSERIAL, public_id UUID UNIQUE, owner_user_id → public.users, kind CHECK IN ('recurring','on_status','button'),
            name TEXT(1~100), source_page_id → pages NULL,          -- 절차서·템플릿 원본
            trigger JSONB NOT NULL, actions JSONB NOT NULL,         -- 아래 허용 목록만(서버 검증 — Doc::validate 와 같은 방식의 Automation::validate)
            is_enabled BOOL NOT NULL DEFAULT false,                 -- 시험 실행을 한 번 통과해야 true 로 바꿀 수 있다
            last_dry_run_at TIMESTAMPTZ NULL, created_at, updated_at, deleted_at NULL)
automation_runs(id BIGSERIAL, automation_id → automations, run_by → public.users, trigger_kind, dry_run BOOL,
                started_at, finished_at, status CHECK IN ('ok','partial','denied','failed'),
                steps JSONB NOT NULL,                               -- [{step, result, count, ids[]}] — 「9개 단계」처럼 펼쳐 보는 기록
                created_page_ids UUID[] NOT NULL DEFAULT '{}', notification_ids BIGINT[] NOT NULL DEFAULT '{}',
                undone_at NULL, undone_by NULL)                     -- 앱 계정: SI + UPDATE(undone_at, undone_by) 만
```
**허용 목록** (그 밖의 키·값은 400)
- 트리거: `schedule {freq: daily|weekly|monthly, weekday?, monthday?(1~28|last), time: HH:MM}`(시간대 Asia/Seoul 고정) · `on_status {to: todo|doing|review|done|hold}` · `button {}`.
- 동작: `create_from_template {template_page_id, parent_page_id, title_pattern(「{원본 제목} – {YYYY-MM}」 등 자리표시자 4개), set: {status?, assignee?: owner|source_assignee|user_id, due_in_days?}}` · `notify {to: owner|assignee|author, text_key}`(문장은 고정 틀) · `set_property {prop: completed_at|status, value}`.
- 한 자동 작업의 동작 ≤ 5, 한 번 실행에 만드는 페이지 ≤ 50, 사용자당 자동 작업 ≤ 20.

**실행** — `scripts/community/run_automations.php`(cron 5분마다, php-fpm 과 같은 `tbox-php` 계정, 잠금 파일로 겹침 방지). 각 실행은
1. 실행자를 정한다: `schedule`·`on_status` = `owner_user_id`, `button` = 누른 사람. 그 사람의 **현재** `users` 행을 읽어(비활성·만료면 `denied`) 요청과 같은 모양의 `$user` 를 만든다.
2. 동작마다 **일반 창구와 같은 판정 함수**를 부른다 — 페이지 만들기 = `decidePageChange(부모)`·트리 불변 규칙, 속성 = `decidePageChange`, 알림 대상 = 그 페이지를 볼 수 있는 사람인지 `decidePageRead`. 하나라도 거부면 그 동작만 `denied` 로 적고 계속(`partial`).
3. 쓰기는 한 트랜잭션, 감사는 동작마다(`actor` = 실행자, `detail.automation_id`·`run_id`). 외부 요청 0(S-05).
- `on_status` 는 상태가 바뀌는 저장 경로(4-D 창구)가 트랜잭션 끝에 **실행 대기 행**을 넣고(`automation_queue`), 배치가 처리한다 — 요청 안에서 자동 작업을 돌리지 않는다(응답 지연·재귀 방지). 자동 작업이 바꾼 상태는 다시 `on_status` 를 부르지 않는다(깊이 1).

**시험 실행** — `POST /community/api/automations/{uuid}/dry-run` → 같은 코드를 `dry_run=true` 로: 판정·계산은 다 하고 쓰기는 하지 않는다. 결과 `{steps:[…], would_create: n, would_notify: [아이디…]}` 를 `automation_runs(dry_run=true)` 에 남긴다. **켜기(`is_enabled=true`)는 마지막 시험 실행 뒤 규칙이 바뀌지 않았을 때만**(`updated_at <= last_dry_run_at`).
**되돌리기** — `POST /community/api/automation-runs/{id}/undo`(실행자 또는 Admin): `created_page_ids` 를 휴지통으로(4-C, 하위 함께), 알림은 `retracted_at` 표시(받은 사람 목록에서 사라짐), `undone_at`. 기록은 지우지 않는다. 이미 누가 그 페이지를 고쳤으면 그 페이지는 되돌리지 않고 목록으로 알려 준다.
**창구 요약** — `GET/POST /community/api/automations` · `PATCH/DELETE …/{uuid}` · `POST …/{uuid}/dry-run` · `POST …/{uuid}/run`(button) · `GET …/{uuid}/runs` · `POST /community/api/automation-runs/{id}/undo`. 판정: 자동 작업은 소유자만 보고 고친다(Admin 은 보기·끄기).

### 22.6 9단계 — 틀 위의 세 기능

> 2026-10-01 구현 기준 → 21.30~21.32절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
- **F-43 반복 체크리스트** = `kind='recurring'` + `schedule` + `create_from_template`(+ `notify assignee`). 화면: 절차서·템플릿 페이지 머리 「⟳ 반복 만들기」 → 주기·시각·담당자·기한 → **시험 실행 결과를 보여 준 뒤** 켜기. 만들어진 페이지는 원본을 가리키는 속성(`source_page_id`)과 「반복: 매월 1일」 배지.
- **F-44 상태 변경 자동화** = `kind='on_status'`, 규칙은 화면에서 고르는 **고정 네 가지**만(검토 → 소유자 알림 · 완료 → 완료일 기록 · 담당자 변경 → 새 담당자 알림 · 기한 하루 전 → 담당자 알림(이것만 `schedule daily 09:00` 로 구현)). 공간·페이지 단위로 켜고 끔.
- **F-45 버튼 블록** = 블록 타입 `button {label(1~30), automation_id(uuid)}` — `doc-schema.json` `stage.9`. 렌더러는 `<button>`(텍스트만), 누르면 `POST …/automations/{uuid}/run`. 누른 사람이 그 자동 작업을 쓸 수 있는지는 서버가(자동 작업 소유자가 「이 페이지를 편집할 수 있는 사람은 누를 수 있음」으로 열어 둔 경우만).

### 22.7 9단계 — 아침 브리핑 (F-42)

> 2026-10-01 구현 기준 → 21.33절. 아래는 이전 설계 기록이며 차이는 구현 기준을 따른다.
- 창구 `GET /community/api/briefing` — **저장하지 않고 계산**(B-2). 모든 조회가 `community_visible_pages_where`(t-box 항목은 t-box `Authz::visibleFilesWhere`) 안.
- 모으는 것(각 SQL 하나, 최근 N건 상한): 내가 담당인 미완료(상태·기한 — 4-D) · 내 페이지의 안 끝난 체크(4-C) · 나를 멘션한 댓글 중 답 없는 것(5-D) · 안 읽은 공지(5-C/5-D `page_reads`) · 공유받은 페이지의 새 판(5-A) · 검증 만료(F-38) · 오늘 생긴 반복 체크리스트(F-43) · t-box: 내가 올린 파일 중 `scan_status='pending'` · 내가 만든 공유 링크 중 24시간 안 만료.
- 정렬(🏆 최대 3): ① 기한 지남 ② 오늘 기한 ③ 「검토」 대기 ④ 답하지 않은 멘션 ⑤ 최근 순. 👀 어제 놓친 것(최대 2): 어제 기한 미완료 · 어제 받은 멘션·댓글 미응답 · 어제 공지 미열람. 나머지는 「그 밖에」 접힘.
- 문장은 고정 틀(`BriefingText::…`, 예 「오늘 기한 2건 · 검토 대기 1건」). 항목 = 원본 링크 + 체크 블록이면 그 자리에서 체크(`POST …/pages/{uuid}/check` 그대로 — 원본에 기록).
- 자리(B-3): 런처(`GET /`, 공개 라우트)는 **세션이 있을 때만** 서버가 「오늘 n건 →」 한 줄을 그린다(숫자는 위 SQL 의 합계만, 스크립트 없음 — S-15 유지). 전체는 `GET /community/today`.
- AI 자리: `BriefingText` 를 인터페이스로 두고 기본 구현은 고정 틀. 13단계(F-56)에서 AI 구현을 끼울 수 있게(AI-1~3 결정 전에는 만들지 않음).

### 22.8 10단계 — 수정 제안 (F-46, S-18)
```sql
page_suggestions(id BIGSERIAL, public_id UUID, page_id → pages, base_version INT NOT NULL, author_id → public.users,
                 ops JSONB NOT NULL,        -- [{op: replace|insert_after|delete, block_id, block?}] — block 은 12.2 스키마 블록 하나
                 note TEXT(≤500) NULL, status CHECK IN ('open','accepted','rejected','stale','withdrawn'),
                 decided_by NULL, decided_at NULL, applied_version INT NULL, created_at)
```
- 제안 자격: `decidePageRead` 통과 + `canCommunityWrite`(Guest 제외) + `decidePageChange` **실패**(편집할 수 있는 사람은 그냥 고친다). 각 `block` 은 `Doc::validate` 로 검증(S-18).
- 받아들이기(`POST …/suggestions/{uuid}/accept`, `decidePageChange`): 현재 문서에 ops 를 적용 → 일반 저장 경로(13.4, `doc_version` 비교). `base_version` 이 현재와 다르면 ops 대상 블록이 그 사이 바뀌었는지 보고, 바뀌었으면 `stale`(제안자에게 알림, 다시 제안). 적용된 판의 `page_versions.summary` 에 「제안 반영 — 제안자 ○○」(4-C 이력에 남음).
- 화면: 읽기 화면 「제안하기」 → 에디터가 **제안 모드**(원본 편집 대신 블록마다 바꾼 내용을 ops 로 모음) → 보내기. 소유자 쪽: 페이지 머리 「제안 n건」 → 블록 비교(전·후 나란히, 렌더러 두 번) → 받아들이기·거절(사유). 알림 5-D.
- 감사: `suggestion.create|allow` · `suggestion.accept|allow` · `suggestion.reject|allow` · `…|deny|<code>`.

### 22.9 11단계 — 데이터베이스 뷰 확장 (F-47~F-49)
6단계 DB(F-27)의 `databases.views JSONB` 에 뷰 타입을 더한다. 필터·정렬·집계는 전부 **속성 이름·연산자 허용 목록 → SQL 조각**(6단계 규칙 그대로).
- **폼** `{type:'form', questions:[{prop, required, show_if?:{prop, equals}}], anonymous_display: bool, open: bool}` — 제출 `POST /community/api/databases/{uuid}/forms/{view}/submit` → 행 페이지 생성(`created_by` 는 언제나 기록, `anonymous_display` 면 일반 사용자 화면에서만 가림 — 소유자·Admin 과 감사에는 보임). 사내 로그인 사용자만(웹 공개 없음). 제출 자격 = 그 DB 가 있는 공간에 쓰기 자격.
- **캘린더** `{type:'calendar', date_prop}` · **타임라인** `{type:'timeline', start_prop, end_prop, depends_prop?}` — 렌더러는 CSS 격자(라이브러리 없음). 선후행 = 관계 속성 `depends_on`(같은 DB, 순환 금지 — 저장 시 재귀 CTE 검사).
- **차트** `{type:'chart', kind: bar|line|donut|number, group_by, aggregate: count|sum|avg, on_prop?}` — 서버가 집계 SQL(허용 목록)로 숫자만 돌려주고 화면이 SVG 로 그린다.
- **대시보드 페이지** — 페이지 종류 `page_kind='dashboard'`(22.3 CHECK 에 추가) + 본문에 `db_view {database_id, view}` 블록 ≤ 12 + 전체 거르개(담당자·기간). 위젯마다 그 DB 의 읽기 판정.

### 22.10 13단계 — AI (F-56, 조건부 — 설계하지 않음)
AI 원칙(AI-1 넣을지 · AI-2 보낼 글 범위 · AI-3 기록)이 정해지기 전에는 설계하지 않는다. 자리만 둔다: `BriefingText`(22.7) · 검색 순위 함수(5-B) · 자동 작업 동작 목록(22.5)에 `ai_*` 동작을 더할 수 있는 구조. 외부 AI 호출은 t-box `Fetch.php` 한 곳 원칙을 따른다.

### 22.11 감사 문장 추가 (단계 지시서에서 표로 확정)
(8단계 검증·절차서 표시는 기존 `page.property` — 22.2) · `page.export|allow` · `automation.create|update|delete|enable|allow` · `automation.dry_run|allow` · `automation.run|allow|partial|denied` · `automation.undo|allow` · `suggestion.create|accept|reject|allow` · 각 `…|deny|not_owner|role_not_permitted|not_found|bad_request`.

### 22.12 수락 기준 초안 (단계 지시서에서 확정) — 번호 배정은 PRD 11장 표 아래 「시험 번호 배정」(8단계부터 단계마다 백 단위)
| 단계 | 번호 | 요지 |
|---|---|---|
| 8 | C:T-300~ (확정 목록은 `stage8-trust-handover-directive.md` C:T-300~321) | 검증: 편집 못 하는 사람 403 · 만료 판정은 SQL(배치 없이도 만료로 보임) · 기록 불변 42501 · 알림 하루 한 번 / 절차서 목록은 볼 수 있는 것만 / 내보내기: 못 보는 하위가 파일에 0(제목도 없음) · `javascript:` 링크가 Markdown·HTML 어디에도 살아나지 않음 · 20MB 상한 · 감사 행 |
| 9 | C:T-400~ | 시험 실행이 아무것도 쓰지 않음(행 수 전후 동일) · 시험 없이 켜기 400 · 권한 잃은 소유자의 자동 작업 `denied` · 알림 대상이 못 보는 페이지면 알림 0 · 50건 상한 · 되돌리기 뒤 페이지는 휴지통·기록은 남음 · `on_status` 재귀 없음 · 브리핑 항목이 전부 볼 수 있는 것 · 런처 「오늘 n건」 이 로그인 전에는 없음 |
| 10 | C:T-500~ | 편집 가능자는 제안 불가(그냥 고친다) · Guest 403 · 제안 블록도 스키마 검증 · 사이에 바뀐 블록이면 `stale` · 받아들인 판에 제안자 이름 · 거절은 문서 불변 |
| 11 | C:T-600~ | 폼 필터·집계 인젝션(속성 이름·연산자 허용 목록 밖 400) · 익명 표시여도 감사·소유자에게 응답자 보임 · 선후행 순환 400 · 대시보드 위젯이 못 보는 DB 는 비어 있음 |

### 22.13 12단계 전제 — 공개 사이트라는 사실 (사용자 정정 2026-09-23)
Qaredi 는 강사 리버스 프록시로 **인터넷에 공개**돼 있다(`changkwon.mso.so` → VM:80, t-box ARCH 3장 배포 메모). 1~11단계의 「로그인 없이는 데이터 0」 은 그대로 지킨다. 12단계에서 로그인 없는 창구는 **웹 게시(22.14) 하나**만 새로 생긴다 — 그 창구는 t-box 공유 링크(`/s/{token}`, t-box F-13)와 같은 성질의 설계로 좁힌다.

### 22.14 웹 게시 (F-50, S-19)
```sql
web_publications(id BIGSERIAL, token_hash CHAR(64) UNIQUE, page_id → pages, include_children BOOL,
                 created_by → public.users, created_at, expires_at NULL, revoked_at NULL, revoked_by NULL,
                 allow_index BOOL NOT NULL DEFAULT false, view_count INT NOT NULL DEFAULT 0, last_viewed_at NULL)
spaces.web_publish_allowed BOOL NOT NULL DEFAULT false            -- 공간별 Admin 허용
```
- 만들기 `POST /community/api/pages/{uuid}/web-publish {include_children, expires_days: null|7|30|90}` — `decidePageManage`(소유자) + 공간 `web_publish_allowed` + (Admin 만) `allow_index`. 토큰 `random_bytes(32)` → base64url 43자, 응답에 **한 번만**, 저장은 sha256(t-box F-13 과 같은 방식). 회수 `DELETE …/web-publish/{id}`. 목록 `GET …/web-publications`(내 것 · Admin 은 전부).
- 보기 `GET /community/pub/{token}`(정책 `public`) → 토큰 해시 조회 → 만료·회수·페이지 삭제면 **404 동일 문구** → `DocRender` HTML(셸 없음, 읽기 전용, `X-Robots-Tag: noindex` 는 `allow_index=false` 일 때) · 하위 포함이면 왼쪽 목차(같은 게시에 포함된 하위만). 사람 정보는 작성자 표시 이름만(이메일·아이디 없음). t-box 첨부는 파일이 **t-box `public` 등급**일 때만 `/tbox/api/files/{id}/preview/raw` 대신 **게시 전용 프록시 경로**가 아니라 「로그인 필요」 자리 — 비로그인에게 t-box 파일을 여는 길은 t-box 공유 링크뿐으로 둔다(여기서 새로 만들지 않는다). 동기화 블록(22.15)은 원본이 같은 게시에 포함될 때만 그린다.
- 속도 제한: nginx `limit_req zone=comm_pub`(IP 당 분당 60). 조회는 `view_count` 만 올리고 감사는 IP 만(`pub.view`, 1시간 안 같은 IP 는 한 번).
- 편집 화면에 「🌐 웹에 공개 중 · 만료 …」 배지를 늘 표시(게시 뒤 고친 내용도 그대로 공개되므로). 비공개·팀 범위 페이지를 게시하려 하면 확인 창 한 번 더.
- 공개 폼(F-47): 폼 뷰에 `public_token` — 같은 표·같은 규칙, 제출은 `POST /community/pub/{token}/submit`(CSRF 대신 폼 전용 1회용 토큰 + 속도 제한 + 크기 상한 + 첨부 없음), 제출 행의 `created_by` 는 NULL·`submitted_ip` 기록.

### 22.15 동기화 블록 (F-51)
```sql
synced_sources(id BIGSERIAL, public_id UUID, page_id → pages,   -- 원본이 있는 페이지(권한의 기준)
               blocks JSONB NOT NULL, version INT, updated_by, updated_at)   -- 원본 블록 묶음(12.2 블록 배열, Doc::validate)
```
- 원본 페이지 본문에는 `synced_source {source_id}` 블록(편집 가능, 안의 블록을 에디터가 그대로 편집 → 저장 시 `synced_sources.blocks` 갱신 — 같은 트랜잭션, 13.4 저장 경로). 다른 페이지에는 `synced_ref {source_id}` 블록(**읽기 전용**, 「원본에서 고치기」 링크).
- 그리기: 참조 자리마다 보는 사람이 `synced_sources.page_id` 를 `decidePageRead` 통과할 때만 `blocks` 를 렌더, 아니면 「볼 수 없는 원본」(내용 0). 응답 조립 시 서버가 판정해 **못 보는 원본의 블록은 응답에 넣지 않는다**(S-02).
- 파생: 원본이 바뀌면 참조 페이지들의 `plain_text`·`search_tsv` 재계산은 하지 않는다 — 검색은 원본 페이지에서만 걸린다(참조 페이지 검색에 원본 문장이 나오면 권한이 다른 사람에게 새므로). 역참조(F-23)에 「동기화로 쓰는 곳 n」.
- 원본 페이지를 지우면 참조 자리는 「원본이 삭제됨」, 휴지통에서 되살리면 다시 보인다. 원본 블록 묶음은 원본 페이지의 판 이력(4-C)에 같이 남는다.

### 22.16 개인 정리 폴더 「모음」 (F-52)
```sql
collections(id BIGSERIAL, owner_user_id → public.users, name(1~50), parent_id NULL → collections, position, created_at)
collection_items(collection_id → collections, page_id → pages, position, added_at, PRIMARY KEY(collection_id, page_id))
```
- 소유자만 보고 고친다. 권한과 무관 — 목록을 그릴 때 `collection_items` ⋈ `community_visible_pages_where`(볼 수 없게 된 페이지는 조용히 빠짐, 행은 남겨 다시 보이게 되면 돌아옴). 사이드바 「모음」 절, 깊이 ≤ 3. 감사 없음(개인 정리 — 조회와 같은 취급), 단 개수 상한(모음 ≤ 50, 항목 ≤ 500).

### 22.17 권한 그룹 (F-53)
```sql
share_groups(id BIGSERIAL, public_id UUID, name(1~50) UNIQUE(lower), created_by, manager_team_id NULL, created_at, deleted_at NULL)
share_group_members(group_id, user_id, added_by, added_at, PRIMARY KEY(group_id, user_id))
group_member_events(… append-only — 추가·제거 기록)
page_shares 확장: share_user_id NULL 허용 + share_group_id NULL → share_groups, CHECK (정확히 하나)
```
- 만들기·구성: Admin(누구나) · Manager(자기 팀원 + 자기 자신만 넣을 수 있음). 그룹 이름·구성은 그 그룹에 속한 사람·만든 사람·Admin 만 본다.
- 판정: `community_visible_pages_where` 의 공유 갈래에 `share_group_id IN (SELECT group_id FROM share_group_members WHERE user_id = :me)` 한 줄. 편집 공유도 같은 방식(`decidePageChange` 의 공유 갈래). 구성원 제거 즉시 반영(캐시 없음).

### 22.18 메신저 알림 (F-54, S-20)
- `messenger_hooks(id, name, url_host, url_path, secret_ref, is_active, created_by)` — Admin 이 등록(호스트는 t-box `fetch_hosts` 와 같은 허용 목록 규칙: https·443·사설 IP 금지). 사용자는 내 계정 설정에서 「메신저로도 받기」 + 훅 선택 + 받을 종류.
- 5-D `notifications` 를 쓴 뒤 `notification_outbox` 에 넣고, 배치 `scripts/community/send_outbox.php`(1분)가 **t-box `Fetch.php` 를 통해서만** POST(제목·링크·종류 — 본문·댓글 내용 없음). 실패는 3회 재시도 뒤 포기·기록. `Fetch.php` 밖 외부 요청 0 은 린트가 계속 지킨다.

### 22.19 외부 데이터 동기화 (F-55, S-20)
- `data_connectors(id, name, database_id → databases, url_host, url_path, auth_ref, schedule, field_map JSONB, is_active, created_by)` — Admin 전용. 인증 키는 `.env` 가 아닌 `/etc/tbox-v2/connectors/<id>.key`(0600 root:tbox-php 0640) 에 두고 DB 에는 참조 이름만.
- 배치가 `Fetch.php` 로 GET → 응답 JSON 을 `field_map`(원본 키 → DB 속성, 허용 타입만)으로 행 upsert(외부 키 `external_id` 로 한 번만). 끌어온 행은 `properties.readonly=true` — 화면·API 에서 고치기 400. 응답 크기 ≤ 5MB, 행 ≤ 5,000/회.
- 예: 「플란치과 ERP 외부 API」 `/api/external/usage`(월별 품목 사용량) → 「월별 사용량」 DB.

### 22.20 12단계 수락 기준 초안 (C:T-700~)
| 기능 | 요지 |
|---|---|
| 웹 게시 | 공간 허용 없으면 403 · 토큰 원문이 DB·감사·로그에 0 · 만료·회수·삭제 뒤 404 동일 문구 · 게시 안 된 하위는 목차·본문에 0 · 비공개 원본의 동기화 블록은 내용 0 · `noindex` 헤더 · 편집기·API 창구가 `/pub` 에서 닿지 않음 · 공개 폼 속도 제한 429 |
| 동기화 블록 | 원본을 못 보는 사람에게 참조 자리 내용 0(응답 원문) · 참조 자리에서 편집 요청 400 · 참조 페이지 검색에 원본 문장이 걸리지 않음 |
| 모음 | 남의 모음 403 · 볼 수 없게 된 페이지가 모음 응답에 0 |
| 권한 그룹 | 구성원 제거 즉시 403 · Manager 가 남의 팀원 추가 403 · 그룹 이름이 비구성원에게 0 |
| 메신저·외부 동기화 | 허용 목록 밖 호스트 거부(Fetch.php 로그) · 나가는 본문에 페이지 본문 0 · 인증 키 원문이 화면·DB·감사에 0 · 끌어온 행 고치기 400 |
