# t-box 아키텍처 문서 (초보자용)

> 기준 문서: `PRD.md` v1.0 — 요구사항 ID(S-01 ~ S-09, F-01 ~ F-13)는 PRD의 것을 그대로 쓴다.
> 기술: **순수 PHP 8.1 (프레임워크 없음) + PostgreSQL 14 + nginx 1.18 · Ubuntu 22.04 (실습 VM 설치본, PRD 11장 Q-11)**

| 항목 | 내용 |
|---|---|
| 문서 버전 | v1.0 |
| 작성일 | 2026-09-08 |
| 읽는 순서 | 1장(전체 그림) → 3장(공통 관문) → 5장(4가지 흐름) → 나머지는 필요할 때 |

---

## 1. 전체 그림 — 한 장으로 보기

```
 [사용자 브라우저]                          인터넷(강사 프록시 경유) · 사내망 — 2026-09-26 d67 정정: 「사내망/VPN 안에서만」 이 아니다(SECURITY 6.7)
      │  HTTPS
      ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  nginx  (문지기)                                              │
 │   · HTTPS 종단, 보안 헤더 부착                                  │
 │   · client_max_body_size 100m  ← 큰 업로드는 여기서 먼저 차단     │
 │   · /public 폴더만 서비스. 그 밖의 폴더는 URL로 절대 못 닿음        │
 └──────────────┬───────────────────────────────────────────────┘
                │ FastCGI (유닉스 소켓)
                ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  PHP-FPM  (일꾼)   public/index.php  ← 모든 요청의 단일 입구     │
 │                                                              │
 │   ① 세션 확인(로그인했나?)  ② 권한 판단(해도 되나?)               │
 │   ③ 실제 처리              ④ 감사 로그 기록                     │
 └──────┬──────────────────────┬──────────────────────┬─────────┘
        │ PDO(값 바인딩)        │ 파일 읽기/쓰기          │ 파일 로그
        ▼                      ▼                      ▼
 ┌──────────────┐   ┌────────────────────────┐   ┌────────────────────┐
 │ PostgreSQL   │   │ /var/lib/tbox/storage/ │   │ /var/log/tbox/     │
 │  users       │   │  (실제 파일, 웹 루트 밖) │   │  audit.log         │
 │  teams       │   │  UUID 이름으로 저장      │   │  (감사 로그 사본)    │
 │  files  ←──── 파일 정보(메타데이터)만        │   │  app.log           │
 │  audit_logs ←─ 감사 로그(원본)              │   └────────────────────┘
 └──────────────┘   └────────────────────────┘
```

**초보자용 요약 세 줄**
1. 브라우저는 **요청만** 보내고, 결정은 **전부 PHP가** 한다. (PRD S-01)
2. 파일의 **정보**는 PostgreSQL에, **실제 파일**은 웹 루트 밖 디스크에 따로 둔다.
3. 무슨 일이 있었는지는 **DB(`audit_logs`) + 파일(`audit.log`)** 두 곳에 남긴다. (PRD S-07)

---

## 2. 구성요소 역할표

| 구성요소 | 비유 | 하는 일 | 하지 않는 일 |
|---|---|---|---|
| 브라우저(HTML/JS) | 창구 안내판 | 화면 그리기, 요청 보내기 | **권한 판단** (버튼 숨김은 편의일 뿐) |
| nginx | 건물 문지기 | HTTPS, 크기 제한, 보안 헤더, `public/`만 노출 | 비즈니스 로직 |
| PHP (index.php + src/) | 창구 직원 | 세션·권한·처리·로그 | SQL 문자열 조립, 사용자 URL 요청(SSRF) |
| PostgreSQL | 장부 | 사용자·팀·파일정보·감사 로그 저장 | 실제 파일 내용 저장 |
| 스토리지 디렉터리 | 금고 | 실제 파일 보관 (UUID 이름) | URL로 직접 노출 |
| 파일 로그 | 장부 사본 | 감사 로그 이중 기록 | — |

---

## 3. 디렉터리 구조 — "무엇이 URL로 보이고, 무엇이 안 보이나"

```
/var/www/tbox/                     ← 애플리케이션 (Git 관리)
├── public/                        ★ nginx root. 여기만 URL로 접근 가능
│   ├── index.php                    모든 요청의 단일 입구 (라우터)
│   └── assets/                      css, js (정적 파일)
├── src/                           ✖ URL로 접근 불가
│   ├── bootstrap.php                설정 로드, DB 연결, 보안 헤더
│   ├── Auth.php                     로그인/세션 (누구인가)
│   ├── Authz.php                    권한 판단 (해도 되는가)  ★ 가장 중요한 파일
│   ├── Audit.php                    감사 로그 기록
│   ├── Db.php                       PDO 래퍼 (prepared statement만 허용)
│   ├── Storage.php                  파일 저장·읽기 (경로 조작 방지)
│   ├── Upload.php                   업로드 검증 (크기·확장자·MIME)
│   ├── Http.php                     JSON 응답, 오류 응답 통일
│   └── handlers/                    엔드포인트별 처리
│       ├── files_list.php
│       ├── files_upload.php
│       ├── files_download.php
│       ├── files_delete.php
│       ├── audit_list.php
│       ├── files_preview.php · files_preview_raw.php   (12-2, F-18)
│       ├── org_page.php · org_teams.php · org_team_create.php · org_team_update.php · org_team_members.php · org_permissions.php  (12-3, F-19)
│       ├── trash_page.php · settings_page.php          (12-1, F-20)
│       └── ...
│   ├── Preview.php                  미리보기 형식 판정·본문 추출 (12-2). 외부 요청 0, XML 파서 0(정규식 발췌)
│   └── views/
│       ├── shell_top.php · shell_bottom.php   공통 셸(내비·사용자 칩) — 동적 값은 사용자명·역할·활성 메뉴 키 3개, 전부 이스케이프 (12-1)
├── views/                         ✖ HTML 템플릿 (출력 시 전부 이스케이프)
├── db/
│   ├── schema.sql                   테이블 정의
│   └── grants.sql                   DB 계정 권한 (최소 권한)
├── scripts/                       운영 스크립트 (백업·정합성 검사·휴지통 비우기)
├── deploy/                        nginx.conf, php-fpm-pool.conf, DEPENDENCIES.md
├── .env                           ✖ 실제 설정 (DB 비밀번호·APP_SECRET). changkwon:tbox-php 0640, .gitignore 로 Git 제외
└── .env.example                     키 이름만 적은 예시 (값 없음). 이것만 Git 에

/etc/tbox/CONTACTS.md              ✖ 사고 연락망 (저장소 밖)
/var/lib/tbox/storage/             ✖ 실제 업로드 파일. 권한 0750, PHP 실행 불가
/var/lib/tbox/quarantine/          ✖ 악성코드 검사 대기 파일 (선택)
/var/log/tbox/                     ✖ audit.log, app.log
/var/backups/tbox/                 ✖ 백업 (암호화, 0700)
```

> **실습 VM 배포 메모 (2026-09-09 개정)** — 이 VM 의 외부 노출 구조는 VM 에서 바꿀 수 없다.
>
> ```
> 브라우저 ──https──▶ 강사 리버스 프록시 (changkwon.mso.so = 112.220.89.250, TLS 종단, http→https 301)
>                        └──http──▶ 이 VM 192.168.105.44 : **80 포트 하나** (프록시 내부 IP 192.168.104.100)
> ```
> - 따라서 도메인으로 공개되는 웹은 **한 번에 한 버전**이다. 버전은 세 개를 나란히 유지하고 `deploy/switch-site.sh vN` 으로 80 포트에 꽂을 버전만 고른다.
>
> | 버전 | 디렉터리 | nginx 사이트 파일 | 항상 열어 두는 로컬 포트 | DB | php-fpm 풀 |
> |---|---|---|---|---|---|
> | v1 실습(취약) | `/var/www/html` (강사 저장소, 불변) | `tbox-v1` / `tbox-v1-local` | `127.0.0.1:8081` | `tbox` | 기본 `www` |
> | **v2 보안 보완 (이 저장소)** | `/var/www/tbox-v2` (= 위 그림의 `/var/www/tbox`) | `tbox-v2` / `tbox-v2-local` | `127.0.0.1:8082` | `tbox_re` | `tbox` (tbox-php) |
> | v3 새 T-BOX (미착수) | `/var/www/tbox-v3` | `tbox-v3` / `tbox-v3-local` | `127.0.0.1:8083` | 별도 | 별도 |
>
> - 공개용 파일(`tbox-vN`)은 `listen 80` 만, 로컬용(`tbox-vN-local`)은 `listen 127.0.0.1:808N` 만. 로컬 포트는 SSH 포트 포워딩으로 본인만 본다.
> - HTTPS(S-09-1)는 프록시가 담당한다. VM 의 nginx 는 TLS 를 하지 않는다(자체 서명 인증서 불필요). 프록시→VM 구간은 사내망 평문 → SECURITY R-30.
> - 실제 접속 IP: `set_real_ip_from 192.168.104.100` + `real_ip_header X-Forwarded-For` — **프록시 IP 가 보낸 헤더만** 신뢰. 다른 곳에서 온 위조 헤더는 무시된다. 프록시가 헤더를 보내는지는 전환 후 실측(로그 형식에 `$http_x_forwarded_for` 포함).
> - **v2 의 저장소 밖 디렉터리는 `-v2` 접미사로 v1 과 분리한다** (2026-09-09, 방향 A): 설정·비밀 `/etc/tbox-v2` (owner.pgpass·seed-accounts.txt), 업로드 `/var/lib/tbox-v2/storage`·세션 `/var/lib/tbox-v2/sessions`, 로그 `/var/log/tbox-v2` (audit.log·app.log). v1 은 이 폴더들을 쓰지 않는다. **아래 6·8·9·11장의 `/var/lib/tbox`·`/var/log/tbox`·`/etc/tbox` 는 설계 참조 경로이며, 이 VM 의 실제 배포 경로는 위 `-v2` 판이다** (실배포 값은 `deploy/*` 파일이 정본).
> - `wiki/`(저장소 루트, 14단계 F-21)는 위키 글 `*.json` 이 있는 곳이다. `public/` 밖이라 URL 로 직접 열 수 없고, 서버(`src/Wiki.php`)가 읽어 이스케이프해 그린다 (5.10).
> - v2 저장소는 `/var/www/tbox-v2` — v1 웹 루트(`/var/www/html`) 밖이다. 그래서 v1 경유로 v2 의 소스·`.env`·SQL 에 닿는 URL 이 애초에 없다 (SECURITY R-29 해소, 2026-09-09 이동). 실제 설정은 `.env` (저장소 루트, `public/` 밖, `changkwon:tbox-php` 0640 — php-fpm 계정이 그룹으로 읽음). `.gitignore` 로 Git 에서 제외한다. (운영 전환 시 `/etc/tbox/tbox.env` 로 이전 검토 — S-09-6)

| 왜 이렇게 나누나 | 막히는 공격 |
|---|---|
| `public/` 만 nginx root → `src/`, `config/`가 URL로 안 보임 | 설정 파일·소스 노출 |
| 업로드 파일이 웹 루트 밖 → `/uploads/shell.php` 같은 URL이 존재하지 않음 | 업로드 파일 실행(웹셸) |
| 실제 설정은 `.env` (public/ 밖, `.gitignore` 로 저장소에도 없음) → URL 로 안 보이고 Git 에도 안 들어감 | Git 유출 (PRD S-09-6). *(정정 2026-09-09: 기존 t-box 저장소도 `.gitignore` 로 `.env` 를 제외하고 있었다 — 실측. 기존 앱의 실제 문제는 `db.php` 에 DB 계정·비밀번호 기본값 `tbox/tbox` 가 코드에 박혀 있던 것이다)* |

---

## 4. 모든 요청이 거치는 공통 관문

어떤 API를 부르든 **같은 순서**를 거친다. 권한 검사를 "빠뜨릴 수 없게" 구조로 강제하는 것이 목적이다.

```
요청 도착 (public/index.php)
   │
   ├─ [0] bootstrap.php     설정 로드, DB 접속 정보(연결은 첫 질의 때 · 워커마다 재사용 — 5.31), 보안 헤더(CSP 등) 출력
   │
   ├─ [1] Auth::requireLogin()        ─── 세션에 user_id 없음 → 401, 끝
   │        세션의 user_id로 DB에서 role, team_id를 **다시 읽음**
   │        (요청에 실려온 role 값은 아예 읽지 않음)                     ← PRD S-01
   │
   ├─ [2] Http::requireCsrf()         ─── POST/PATCH/DELETE 인데 토큰 불일치 → 403
   │
   ├─ [3] 라우팅                       ─── 경로+메서드 → handlers/xxx.php
   │
   ├─ [4] Authz::…()  ★ 권한 판단      ─── 실패 → 403 (동일 문구) + Audit::deny()   ※ 없는 ID 만 404
   │
   ├─ [5] 실제 처리 (DB는 값 바인딩만, 파일은 Storage 통해서만)
   │
   ├─ [6] Audit::allow()  감사 로그    ─── 기록 실패 → 처리도 롤백
   │
   └─ [7] 응답 (JSON 또는 파일 스트림). HTML이면 전부 htmlspecialchars   ← PRD S-03
```

### 4.0 인증·세션·CSRF 세부 (2단계 결정, 2026-09-09)

| 항목 | 결정 | 이유 |
|---|---|---|
| 세션 쿠키 | **`__Host-tbox_session=<64자 hex>`**(디버깅 묶음 S2 · 사용자 결정 d72 ① — v2.30 까지 `tbox_session`); `HttpOnly; Secure; SameSite=Lax; Path=/`; **Domain 없음**(호스트 한정 — `__Host-` 접두가 브라우저에 Secure·Path=/·Domain 없음을 강제한다) · **Max-Age 없음**(브라우저 세션 쿠키). 유효성은 언제나 DB 가 판단(유휴 30분·절대 8시간, `.env`). 속성은 `Http::sendCookie` 한 곳. **옛 이름은 읽지 않는다** — 실려 오면 `Auth::dropLegacyCookie`(부르는 곳 `cookieSessionId` 하나 — `currentUser`·`login`)가 같은 속성의 만료 머리(Max-Age=0)로 치운다. 옛 세션 행은 그대로 두고 유휴·절대 만료와 로그인 때 만료 정리가 치운다(반영 순간 모두 한 번 다시 로그인) | 쿠키 만료를 늘려도 서버가 거부 |
| 세션 재발급 | 로그인 성공 시 기존 쿠키의 세션 행 삭제 → `random_bytes(32)` 새 토큰 → DB 에는 SHA-256 만 | 세션 고정 공격 차단 (S-09-2) |
| 만료 정리 | 로그인 때 `DELETE FROM sessions WHERE expires_at < now() OR last_seen_at < now() - 유휴` | 배치 없이 자연 정리 |
| **자동 요청은 세션을 늘리지 않는다** (Q:F-4 U-3, 2026-09-24) | 화면이 저절로 보내는 요청(커뮤니티 알림 폴링·편집 heartbeat)은 헤더 `X-Qaredi-Background: 1` 을 달고, `Auth` 는 그 헤더가 있으면 세션을 **확인만** 하고 `last_seen_at`·`act_last_seen_at` 을 밀지 않는다(SQL_TOUCH 를 건너뜀 — 이름·값은 `Auth::BACKGROUND_HEADER`·`BACKGROUND_VALUE` 한 곳, 값은 상수와 `===` 로만 견주고 SQL 에 들어가지 않는다). 사람이 누른 요청·쓰기(저장·댓글)는 헤더가 없어 전처럼 60초 규칙으로 연장. 쓰던 글은 임시저장(B1-2 — 커뮤니티 ARCH 「임시저장」 절)이 지킨다 | 켜 둔 탭이 「활동 없는 30분」·「역할로 보기 30분」을 끝없이 늘리지 않게. 헤더를 빼면 자기 세션이 늘 뿐이고 달면 줄 뿐이라 권한과 무관 (SECURITY C:R-10 · 7장) |
| 잠금 | `users.failed_count` 5회 → `locked_until = now()+10분`, 그동안 423 `{"error":"로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요"}`. 성공 시 0 으로. **잠금이 만료된 뒤의 첫 실패는 카운터를 1 부터 다시 센다**(13단계, R-49 — `SQL_COUNT_FAILED` 의 `CASE WHEN locked_until IS NOT NULL AND locked_until < now() THEN 1 ELSE failed_count + 1 END`, 그때 `locked_until` 도 NULL 로 치운다). 전에는 10분이 지나 잠금이 풀려도 카운터 5 가 남아 **실패 한 번이 곧바로 다시 10분 잠금**이었다 — 아이디만 아는 사람이 10분에 요청 하나로 남의 계정을 영구 잠금 상태에 둘 수 있었다(R-06 의 악화 형태, 2026-09-12 실측). Admin 의 임시 비밀번호 재발급(`POST /api/users/{uid}/password/reset`)도 같은 UPDATE 문장에서 `failed_count = 0, locked_until = NULL` 을 넣는다 — 재발급은 "이 계정을 다시 쓸 수 있게 한다" 는 뜻이다 **잠금이 걸린 순간**(카운터가 한도에 막 닿은 실패)마다 감사 `auth.lock deny failed_limit` 한 행(`Auth::countFailure` — 로그인 전이면 actor 없음 · 대상 = 그 계정 · `detail.lock_minutes`) — 경보 AL-2 가 「잠금 발생」으로 센다(디버깅 묶음 S2 · 11.4) | F-01, S-09-4, R-49 |
| **로그인 뒤 창구의 실패도 같은 카운터** (13단계, 사용자 결정 2026-09-12) | `POST /api/me/password`·`/api/me/mfa/confirm`·`/api/me/mfa/disable` 은 세션만 있으면 닿으면서 **비밀번호·OTP 를 다시 묻는다**. 이 세 곳의 실패(현재 비밀번호 오류·코드 오류·코드 재사용)를 `Auth::countFailure` 로 로그인과 **같은 카운터**에 센다. 5회면 잠기고, 잠기는 순간 `Auth::revokeAllSessions` 로 그 계정의 세션을 전부 끊는다 — 다음 요청은 401 이고 훔친 쿠키도 함께 죽는다. 실패 응답 문구는 셋 다 `MSG_BAD_REQUEST` **하나**다(사유는 감사 `reason` 에만: `bad_credentials`·`bad_code`·`code_reused`·`weak_password`·`same_password`). 전에는 비밀번호 오류만 `MSG_LOGIN_FAILED` 로 갈라져 "비밀번호는 맞혔다" 를 응답만으로 알 수 있는 오라클이었다. nginx `limit_req zone=tbox_login`(30r/m, burst 10, 429) 을 네 경로(끝 슬래시 변형 포함)에 `/api/login` 과 같은 방식으로 붙인다 | S-09-4, S-09-8, R-48 |
| **2단계 인증 코드는 1회성** (13단계, 사용자 결정 2026-09-12) | `users.mfa_last_step BIGINT` — 마지막으로 성공시킨 TOTP 스텝(= 유닉스 시각 ÷ 30). `Totp::verifyStep()` 이 맞은 스텝을 돌려주고, 그 값이 `mfa_last_step` **이하**면 코드가 맞아도 거부한다(RFC 6238 §5.2 "한 번 쓴 OTP 는 거부"). 검사하는 곳은 로그인(`Auth::login` → `auth.mfa deny code_reused`)·확정(`mfa_confirm` → `auth.mfa_enroll deny code_reused`)·해제(`mfa_disable` → `auth.mfa_disable deny code_reused`) 셋이고, **재사용도 실패로 센다**. 성공하면 그 스텝으로 갱신한다(`GREATEST` 로 뒤로 가지 않게). ±1 창(약 90초)은 그대로 둔다 — 좁히면 시계가 어긋난 휴대폰이 못 들어온다. 코드 6자리는 저장하지 않는다(그것은 인증 값 저장이다) — 시각에서 나오는 단조 증가 정수만 남긴다. 해제 성공 시에는 비밀을 지우므로 `mfa_last_step` 도 NULL 로 되돌린다 | R-45, T-31 |
| 실패 응답 | 아이디 없음·비밀번호 틀림·비활성 계정 모두 401 동일 문구. 아이디 없음일 때도 더미 해시로 `password_verify` 실행 | 계정 존재 여부·응답 시간 차이 최소화 (PRD 10장) |
| IP 속도 제한 | nginx `limit_req_zone $binary_remote_addr zone=tbox_login:1m rate=30r/m; burst=10 nodelay` → `POST /api/login` 만, 초과 429 | 계정 잠금(R-06 DoS)과 무차별 대입을 함께 완화. 30r/m 은 "6회 시도 → 잠금" 실습이 429 에 먼저 걸리지 않게 잡은 값 (S-09-8) |
| CSRF 토큰 | `hash_hmac('sha256', 'csrf:'.세션해시, APP_SECRET)` — **저장하지 않고 세션마다 계산**. 클라이언트는 `GET /api/me` 로 받아 `X-CSRF-Token` 헤더로 보냄. `Http::requireCsrf` 가 `hash_equals` 비교, 불일치·누락 403 | 스키마 변경 없이 세션에 결속. `APP_SECRET` 은 `.env`(install.sh 가 생성) |
| 로그인 CSRF | `/api/login` 은 `Content-Type: application/json` 만 받음(폼 인코딩 거부) + SameSite=Lax | 크로스사이트 폼 전송 불가 |
| 미로그인 처리 | `/api/*` → 401 JSON + `Audit::deny(auth.required)`. 그 외 화면 경로 → **302 `/login`** (감사 로그 없음 — 세션 만료 후 첫 방문은 공격이 아님) | API 는 명확한 거부, 화면은 사용성 |
| 시드 계정 | `scripts/seed_users.php`(CLI, install.sh 가 users 가 비었을 때 실행): admin01(Admin) · manager01(Manager, 개발팀) · employee01(Employee, 개발팀) · employee02(Employee, 영업팀) · guest01(Guest) — 사용자의 자기 공격·시연용. **검토 계정**은 같은 역할·팀의 두 벌이다: `claude_admin01` · `claude_manager01` · `claude_employee01` · `claude_employee02` · `claude_guest01`(**새로 쓰거나 고치는 시험**, 2026-09-24 F-71 — `--claude-only` 로 이 5개만 만들고, 첫 로그인 절차는 `scripts/review_account_setup.php` 가 창구를 그대로 타서 마친다) · `qa_admin01` · `qa_manager01` · `qa_employee01` · `qa_employee02` · `qa_guest01`(**옛 시험**, 2026-09-10 — 지우지 않는다, 옛 기록 확인용). AI 의 검토·시험 요청은 이 계정만 쓴다(PRD S-07 "검토 요청 표식"). 감사 로그에서 `actor_name` 이 `qa_` **또는** `claude_` 로 시작하면 검토 행동, 아니면 사용자 행동 — 시험의 판정 조각은 `tests/lib/review_accounts.sh` 한 벌(`REVIEW_NOT_SQL`, 계정 이름만 본다), 감사 화면의 「Claude」 배지는 계정 **그리고** UA `tbox-review/` 로 `src/Origin.php` 한 곳이 판정한다(`GET /api/audit` 행마다 `origin`·`origin_hint` — 보여 주기 전용, 권한·기록 여부와 무관). 운영 배포는 `--no-qa` 로 검토 계정(qa·claude 둘 다)을 만들지 않는다. 인자는 정확히 일치하는 하나만 받고 그 밖은 사용법 오류(종료 코드 2). 검토 계정의 2단계 비밀은 `/etc/tbox-v2/qa-totp.txt`(0600 root, 이름은 그대로 — 시험들이 이 이름을 읽는다). 비밀번호는 **무작위 16자**로 생성해 `/etc/tbox-v2/seed-accounts.txt`(0600 root) 에만 기록. 저장소·README 에 비밀번호를 적지 않는다 | 기본 비밀번호 커밋 금지 (S-09-6) |
| 비밀번호 해시 | `password_hash(…, PASSWORD_ARGON2ID)` 기본 파라미터 | S-09-4 |
| **2단계 인증 로그인 흐름 (10단계, 사용자 결정 2026-09-11)** | **순차 2단계**: ① 아이디·비밀번호만 보낸다 → 비밀번호가 맞고 그 계정에 TOTP 가 켜져 있으면 서버가 `401 {"error":"2단계 인증 코드가 필요합니다","mfa_required":true}` 로 답한다(세션 발급 없음, **실패 횟수에 세지 않음**, 감사 `auth.mfa deny code_required`) → ② 화면이 그때 코드 칸을 펼치고 아이디·비밀번호·코드를 다시 보낸다 → 코드가 틀리면 `401` 동일 문구 + `auth.mfa deny bad_code` + **실패 횟수 증가**(5회 잠금), 맞으면 세션 발급. 켜지 않은 계정은 ①에서 바로 로그인된다. 로그인 화면에는 코드 칸이 **처음엔 없다** | 비밀번호를 모르는 사람은 계정의 2단계 인증 여부를 알 수 없다(계정 정보 노출 방지, R-32 와 같은 원칙). 비밀번호가 맞은 사람에게만 다음 단계를 알려 준다 — 코드 칸을 모든 사용자에게 항상 보여 헷갈리게 하지 않는다(관문 ③ 피드백 ⑥) |
| **최초 로그인 강제 절차 (11단계, 사용자 결정 2026-09-11 · PRD F-16)** | `users.must_change_password`·`users.mfa_setup_required`(BOOLEAN NOT NULL DEFAULT false)·`users.password_changed_at`. **생성**: Admin 은 비밀번호를 보내지 않고 서버가 임시 비밀번호(16자, 헷갈리는 글자 `0O1lI` 제외, `random_int`)를 만들어 201 에 `temp_password` 로 한 번만 돌려준다 + 두 플래그 true. **로그인**: 기존 순차 2단계 그대로(TOTP 켜진 계정은 코드 필요) → 세션 발급 → 200 `{ok:true, next:"password_change"|"mfa_setup"|null}`. **관문(`public/index.php`, 인증 뒤·핸들러 앞)**: `must_change_password` 이면 허용 목록(`GET /`, `GET /api/me`, `POST /api/logout`, `POST /api/me/password`) 밖 요청은 `403 {"error":"비밀번호를 먼저 변경해야 합니다","next":"password_change"}` + `auth.gate deny password_change_required`(route 패턴); 아니고 `mfa_setup_required` 이면 허용 목록 + `POST /api/me/mfa/enroll`·`confirm` 밖은 `403 {…,"next":"mfa_setup"}` + `auth.gate deny mfa_setup_required`. **해제**: 비밀번호 변경 성공 → `must_change_password=false`, `password_changed_at=now()`(현재와 같은 값은 400 `same_password`); TOTP 확정 성공 → `mfa_setup_required=false`; TOTP 해제 → `mfa_setup_required=true`. **F-52(2026-09-16)**: `mfa_setup` 관문은 **실제 역할이 `Auth::MFA_REQUIRED_ROLES`(Admin)에 있고 `mfa_enabled=false`** 일 때만(5.26). 다른 역할은 플래그와 무관하게 통과하고 권장 카드만 본다. **Admin 재발급/초기화**: `POST /api/users/{uid}/password/reset`(새 임시 비밀번호 한 번만 응답, `must_change_password=true`, 세션 전부 종료), `POST /api/users/{uid}/mfa/reset`(`mfa_secret=NULL`, `mfa_enabled=false`, `mfa_setup_required=true`, 세션 전부 종료) — 둘 다 사유 필수·자기 자신 `self_change` 거부. **임시 비밀번호 유효기간(보안 넷 ④ 2026-09-26, SECURITY R-39)**: 계정 만들기·재발급이 같은 문장에서 `users.temp_password_issued_at = now()` 를 넣고, `Auth::login` 은 비밀번호가 맞은 **뒤**·2단계 인증 **앞**에서 `must_change_password` 이고 그 시각이 `Auth::TEMP_PASSWORD_HOURS`(72)시간보다 오래됐으면(SQL 이 판정) 세션 없이 `401 {"error":"임시 비밀번호가 만료되었습니다(발급 뒤 72시간). 관리자에게 다시 발급을 요청하세요"}` + `auth.login deny temp_expired`(실패 횟수에 세지 않음). 비밀번호 변경(`SQL_SET_PASSWORD`)이 같은 문장에서 NULL 로 되돌린다. NULL(이 기능 전 발급·CLI 시드)은 만료 없음. **이행**: 마이그레이션이 기존 활성 계정 전부에 `must_change_password=true`, `mfa_setup_required = NOT mfa_enabled` 를 넣는다 — 패치 뒤 첫 로그인이 강제 절차가 된다(행 삭제·재생성 없음) | 임시 비밀번호를 만든 Admin 이 그 값을 계속 아는 상태를 **첫 로그인에서 끝낸다**(R-33·R-39). "2단계 인증은 선택" 이던 10단계 결정을 사용자 결정으로 **필수**로 바꾼다 — 서버 관문이 막으므로 화면을 우회해도 절차를 건너뛸 수 없다. 관문을 라우터 한 곳에 두는 이유는 핸들러마다 검사하다 하나를 빠뜨리는 실수를 막기 위해서다(4단계 서버 인가와 같은 원칙) |
| **관리 기능 재인증 — sudo 모드 (16단계, 사용자 확정 2026-09-12 · PRD F-23)** | `sessions.reauth_at TIMESTAMPTZ NULL`(마이그레이션). `POST /api/me/reauth {password}` 가 현재 비밀번호를 `password_verify` 로 확인하고 **현재 세션 행**의 `reauth_at = now()` 를 놓는다(다른 기기 세션에는 영향 없음). 라우터 관문 **[3-2]** — 인가(`authorize`) **뒤**에, 라우트 정책이 `Authz::REAUTH_POLICIES`(`user.manage`·`org.manage`·`audit.read`·`role:Admin`)에 들면 `Auth::currentUser()['reauth_at']` 이 `now() - 15분` 보다 새로워야 한다(판정 SQL 은 세션 조회 `SQL_LOOKUP` 에 `reauth_at > now() - make_interval(mins => 15) AS reauth_fresh` 로 함께 싣는다 — DB 시계 하나). 아니면 `Audit::deny('auth.gate','reauth_required', target=본인)` 뒤 API 는 403 `{error, next:'reauth'}`, 화면은 302 `/reauth?to=<키>`(키는 `Authz`/라우터의 고정 표 `users·org·audit·trash·wiki` → 경로; 요청 값은 표의 키로만 쓴다 — 오픈 리다이렉트 없음). 화면 쪽 편의(D-9, DESIGN.md 2.7): `app.js` 가 관리 화면 링크 클릭을 가로채 `me.reauth_fresh` 가 거짓이면 기존 `openReauthModal` 을 먼저 띄우고 통과하면 `location.assign(href)` — 관문·정책·`/reauth` 화면은 그대로이고 새 API 요청도 없다. 링크의 `href` 는 마크업 값 그대로 쓰며 요청 값으로 경로를 받지 않는다. 로그인은 `reauth_at` 을 놓지 **않는다**(관리 영역 첫 진입에 반드시 한 번 묻는다는 사용자 요구). 실패는 `Auth::countFailureAndRevoke`(13단계 규칙) + nginx `tbox_login` 존. 재인증은 **권한을 만들지 않는다** — 관문 순서가 인가 뒤이므로 Employee 는 재인증해도 `authz.route deny` 다. 본인 계정 창구(`/api/me/*`)는 이미 현재 비밀번호(·코드)를 묻으므로 대상이 아니다. **핸들러가 묻는 자리(디버깅 묶음 S-8, 2026-09-26)**: `file.*` 라우트는 정책 목록 밖이지만 **Admin 의 결정 둘** — 파일 등급 **내리기**(노출 넓히기, `Authz::levelChangeNeedsReauth` — `decideLevelChange` 허용 뒤)·`admin_all` 로 만드는 공유 링크(`Authz::shareCreateNeedsReauth(editCode)` — `decideEdit` 뒤) — 는 핸들러가 같은 15분 창을 보고 관문 [3-2] 와 같은 모양(`auth.gate deny reauth_required` detail `policy=file.update` + 403 `{error, next:"reauth"}`)으로 막는다. 올리기·같은 등급·설명·폴더 이동은 묻지 않는다(일상 업무). 휴지통 `admin_all` 과 같은 모양 | GitHub·AWS 의 sudo 모드와 같은 원리 — 훔친 세션 쿠키·자리 비움만으로 계정 관리·감사 로그·위키에 닿지 못하게. 12단계 접수 사항 ③ |

### 4.1 Authz.php — 권한 판단은 이 파일 하나에 모은다

PRD 5장의 권한 매트릭스를 판단하는 **유일한 곳**이다. 역할 × 레벨 표는 `permissions` 테이블(6.2-1)에서 읽고, 소유자·팀 규칙은 아래 코드로 판단한다. (아래 예시는 이해를 위해 표를 코드로 풀어 쓴 것이며, 실제 구현은 `permissions` 조회로 대체한다.) 다른 파일에서 `if ($role === 'Admin')` 같은 판단을 직접 쓰는 것을 금지하고, 항상 여기 함수를 부른다.

```php
<?php
// src/Authz.php — PRD 5장 권한 매트릭스의 유일한 구현
final class Authz
{
    /** 5.1 읽기: 목록·검색 SQL에 붙일 WHERE 조건과 바인딩 값 (PRD S-02) */
    public static function visibleFilesWhere(array $u): array
    {
        return match ($u['role']) {
            'Admin'    => ['TRUE', []],
            'Manager', 'Employee' => [
                "(access_level IN ('public','internal')
                  OR (access_level = 'team' AND team_id = :team_id))",
                [':team_id' => $u['team_id']],
            ],
            'Guest'    => ["access_level = 'public'", []],
        };
    }

    /** 5.1 읽기: 파일 1건을 이 사용자가 볼 수 있나 (다운로드·상세) */
    public static function canRead(array $u, array $file): bool
    {
        return match ($file['access_level']) {
            'public'     => true,
            'internal'   => $u['role'] !== 'Guest',
            'team'       => $u['role'] === 'Admin'
                            || ($u['role'] !== 'Guest' && $u['team_id'] === $file['team_id']),
            'admin-only' => $u['role'] === 'Admin',
        };
    }

    /** 5.2 업로드: 이 레벨(+팀)로 올릴 수 있나 */
    public static function canUploadAs(array $u, string $level, ?int $teamId): bool
    {
        if ($u['role'] === 'Guest') return false;
        if ($level === 'admin-only') return $u['role'] === 'Admin';
        if ($level === 'team') {
            if ($teamId === null) return false;
            return $u['role'] === 'Admin' || $u['team_id'] === $teamId;
        }
        return in_array($level, ['public', 'internal'], true);
    }

    /** 5.3 삭제 */
    public static function canDelete(array $u, array $file): bool
    {
        return match ($u['role']) {
            'Admin'    => true,
            'Manager'  => $file['uploaded_by'] === $u['id'] || $file['team_id'] === $u['team_id'],
            'Employee' => $file['uploaded_by'] === $u['id'],
            'Guest'    => false,
        };
    }
}
```

**판정 코드 — "왜 허용/거부됐나"를 로그에 남기기 위해 (2026-09-10 추가, PRD S-07 "허용에도 근거")**

`canRead`·`canUploadAs`·`canDelete` 는 참/거짓만 돌려주므로 감사 로그의 "왜"가 비게 된다. 그래서 같은 판단을 하되 **코드까지 돌려주는** 함수를 두고, `can*` 은 그 함수의 결과가 허용인지 보는 얇은 껍데기로 만든다. 판정 로직은 여전히 이 파일 한 곳에만 있다.

```php
/** @return array{0: bool, 1: string}  [허용 여부, 코드] */
public static function decideRead(array $u, array $file): array;             // 5.1
public static function decideUploadAs(array $u, string $level, ?int $teamId): array;  // 5.2
public static function decideDelete(array $u, array $file): array;           // 5.3 (canRead 를 먼저 통과했다는 전제)
public static function decideRestore(array $u, array $file): array;          // 5.4 복구 — Admin 전부(admin_all) · 올리고 지운 본인(owner), 그 밖은 not_found_in_trash (Q:F-4 U-2b)
public static function restorableFilesWhere(array $u): array;                // 휴지통 목록의 SQL WHERE 조각 [조건, 바인딩, 범위] — decideRestore 와 짝
public static function canRead(array $u, array $file): bool { return self::decideRead($u, $file)[0]; }  // 나머지도 같은 꼴
```

| 결과 | 코드 (7.5 등록부와 동일) | 뜻 | 기록 위치 |
|---|---|---|---|
| 허용 | `matrix` | 역할 × 레벨 표(`permissions`)가 허용 (public·internal·admin-only) | `detail.granted_by` |
| 허용 | `same_team` | team 레벨, 사용자 팀 = 파일 팀 | `detail.granted_by` |
| 허용 | `all_teams` | team 레벨, 모든 팀을 보는 역할(Admin) | `detail.granted_by` |
| 허용 | `owner` | 삭제: 본인이 올린 파일 | `detail.granted_by` |
| 허용 | `team_scope` | 삭제: Manager 가 자기 팀 파일 | `detail.granted_by` |
| 허용 | `admin_all` | 삭제: Admin 은 모든 파일 | `detail.granted_by` |
| 거부 | `role_not_permitted` | 표가 거부 (Guest 의 internal, Employee 의 admin-only …) | `reason` |
| 거부 | `team_mismatch` | team 레벨인데 다른 팀 | `reason` |
| 거부 | `not_owner` | 삭제: 남이 올린 파일 (Employee), Manager 는 남의 팀 파일이면 `team_mismatch` | `reason` |
| 거부 | `downgrade_not_permitted` | 레벨 변경(5단계): 등급을 **낮추는** 변경(`admin-only`→`team`→`internal`→`public` 방향)은 Admin 만 (PRD 4장·5.3) | `reason` |

**5단계 추가 — 수정(PATCH) 판정 (2026-09-11)**
- `decideEdit(array $u, array $file): array` — 설명·레벨을 고칠 수 있는 기본 자격. `decideDelete` 와 같은 소유자·팀 규칙(PRD 5.3: Admin 모두 `admin_all`, Manager 본인 `owner`·자기 팀 `team_scope`, Employee 본인 `owner`, Guest 불가). 목록의 `can_edit` 도 이 함수로 계산한다.
- `decideLevelChange(array $u, array $file, string $newLevel, ?int $newTeamId): array` — `decideEdit` 통과가 전제. 새 등급이 현재보다 **낮으면**(순서 `admin-only` > `team` > `internal` > `public`) Admin 이 아닌 한 `downgrade_not_permitted`; 그 뒤 새 등급·팀을 지정할 자격은 `decideUploadAs($u, $newLevel, $newTeamId)` 와 같다(코드 재사용). 허용 코드는 `decideUploadAs` 의 것(`matrix`·`same_team`·`all_teams`).

핸들러는 이 코드를 **그대로** `Audit::allow(..., ['detail' => ['granted_by' => $code]])` 또는 `Audit::deny($action, $code, ...)` 에 넘긴다. 핸들러가 코드를 지어내거나 바꾸면 린트 대상이다. 거부 코드는 7.4 문장 표의 `reason` 과 같은 값이어야 한다(표에 없는 조합은 기록 실패 = 요청 실패).

> **초보자 메모**: "권한이 이상하다" 싶으면 볼 파일은 이거 하나다. 매트릭스(PRD 5장)와 이 파일이 1:1로 맞는지 코드 리뷰 때 항상 비교한다.

---

### 4.2 프로그램용 업로드 `POST /api/upload` — 정적 API 토큰 (PRD F-49, 사용자 결정 2026-09-15 A)

로컬 프로그램이 매번 2단계 인증 없이 파일을 올릴 수 있게, **한 창구에만 통하는 정적 토큰**을 둔다.
사람의 대화형 로그인(4.0)은 그대로다. 강사 2차 프롬프트의 계약(경로·필드·응답·`.env API_TOKEN`)을
그대로 따르되, v2 의 원칙 두 가지는 지킨다 — **업로드 로직은 하나**(웹과 같은 함수), **인가는 Authz 한 곳**.

**어디에 꽂히나.** 공통 관문 `[2] 인증` 에서 라우트가 `POST /api/upload` 이면 **토큰 경로**, 아니면 기존
세션 경로다. 다른 라우트에 `X-API-Token` 이 실려 와도 **무시**한다(토큰은 이 라우트 밖에서 아무것도 열지 못한다).

| 순서 | 토큰 경로가 하는 일 |
|---|---|
| 설정 확인 | `.env` `API_TOKEN` 이 없거나 빈 값·32자 미만이면 **503** `{"error":"API 토큰이 서버에 설정되지 않았습니다"}` + `auth.token deny not_configured` — 닫힌 실패. 앱은 `.env` 를 읽기만 하므로(0640, 그룹 읽기) 스스로 토큰을 만들 수 없다 |
| 헤더 확인 | `X-API-Token` 없음 → 401 「X-API-Token 헤더가 없습니다」(`missing_header`). 있으면 `hash_equals(API_TOKEN, 헤더)` — 다르면 401 「API 토큰이 올바르지 않습니다」(`bad_token`). 문자열 비교는 타이밍 안전한 `hash_equals` 만 쓴다 |
| 귀속 계정 | `.env` `API_TOKEN_USER` 의 계정을 `users` 에서 읽어 `$user` 로 세운다. 없음·비활성·강제절차 중(`nextStep !== null`)이면 503 `user_invalid`(설정 문제) — 요청자에게 계정 정보는 나가지 않는다 |
| 건너뛰는 것 | CSRF·강제절차 관문 `[3-1]`·재인증 관문 `[3-2]`. 토큰은 브라우저가 자동으로 싣지 않으므로 CSRF 대상이 아니다 |
| 그대로 타는 것 | `[4] Authz::authorize('file.upload', $user)` + 업로드 공통 함수 안의 등급 권한(`decideUploadAs`)·폴더 규칙(`fitsUnderFolder`)·형식 검사(`Upload::validate`)·일일 총량·민감정보 검출. **웹 업로드와 한 글자도 다르지 않은 판정**이다 |

**업로드 로직은 하나.** `files_upload.php` 의 본문을 공통 함수로 빼고(예: `src/handlers/_upload_common.php`),
웹(`POST /api/files`)과 API(`POST /api/upload`)가 **둘 다 그 함수를 부른다**. 차이는 세 가지 매핑뿐이다:
① 등급 필드 이름(`access_level` ↔ `level`, 없으면 `public`) ② 거부 문구(웹 = 통일 「잘못된 요청입니다」·「권한이
없습니다」 그대로, API = 사유 코드 → 사람이 읽는 이유) ③ 성공 응답(`{id,name}` ↔ `{id,name,level}`). 감사 행·사유
코드·트랜잭션은 같다. 사유 코드 목록은 `Upload::validate`·`checkDailyQuota`·`fitsUnderFolder`·핸들러의
`$rejectBadRequest` 호출을 **전부** 훑어 매핑하고, 모르는 코드는 「잘못된 요청입니다」로 떨어진다(빠짐 없음).

**오류 문구 정책(사용자 결정).** 400/401/403/413 은 이유를 알려 준다 — 부르는 쪽이 토큰을 아는 **우리 프로그램**이고,
이유를 모르면 고칠 수 없기 때문이다. **500 은 기존 일반 문구**(`{"error":"서버 오류"}`)를 유지한다(PRD S-09-7 —
예외·DB 오류 원문은 서버 로그의 `request_id` 로만). 100MB 초과는 nginx 가 PHP 앞에서 끊으므로
`deploy/sites/tbox-v2.conf` 에 `error_page 413` 을 **JSON** 으로 두어 계약(413 + `{"error"}`)을 지킨다.

**감사의 구분.** 토큰 경로는 요청 스코프에 인증 방식을 세우고(`TBOX_AUTH_METHOD = 'api_token'`), `Audit` 이
모든 행의 `detail.auth` 에 자동으로 붙인다 — 업로드 공통 함수는 누가 불렀는지 모른 채 같은 코드를 실행한다.
실패는 `auth.token deny(missing_header·bad_token·not_configured·user_invalid)`. 새 (action·result·reason) 조합과
상세 키 `auth` 는 `Audit.php` 허용표에 등록해야 기록이 통과한다(7장).

**토큰의 수명.** 생성·교체는 `scripts/api_token_rotate.sh`(무작위 48자, `.env` 의 `API_TOKEN=` 줄만 바꾸고 **값을
출력하지 않는다**). 확인은 서버에서 `cat .env`. 값은 저장소·문서·채팅·감사 로그 어디에도 적지 않는다.
한계는 SECURITY R-82 — 공용 정적 토큰이라 사용자별 회수·귀속이 없다(사용자별 토큰은 필요 시 후속 F-50).

### 4.3 토큰 라우트 확장 — 검색·다운로드 (PRD F-50, 사용자 요청 2026-09-15)

4.2 는 F-49 의 **한 라우트**(`POST /api/upload`)를 설명한다. F-50 에서 토큰이 통하는 라우트를 **셋**으로 넓혔다:
`POST /api/upload` · `GET /api/files` · `GET /api/files/{id}/download`. 상수는 `TBOX_API_TOKEN_ROUTE`(문자열 하나)에서
`TBOX_API_TOKEN_ROUTES`(집합)로 바뀌고, `[2-T]` 는 `in_array(TBOX_ROUTE, …, true)` 로 판단한다 — **어느 라우트인가**는 여전히
라우트 패턴으로만, 요청 값으로는 판단하지 않는다. 그 밖의 라우트는 헤더를 읽지 않는다(4.2 의 원칙 그대로).

**헤더 유무의 갈림길(구현 중 발견, 2026-09-15).** 넓힌 둘은 **화면도 쓰는 라우트**다. 라우트만으로 토큰 경로로 보내면 세션 쿠키를 든
브라우저의 목록·다운로드가 전부 「X-API-Token 헤더가 없습니다」 401 이 된다(Opus 실측). 그래서 조건이 하나 더 있다: `X-API-Token` 헤더가
**실제로 온** 요청만 토큰 경로로 가고, 없으면 세션 경로 그대로다. `POST /api/upload` 만 `TBOX_API_TOKEN_ONLY_ROUTES`(세션으로 부를 수 없는 창구)라
헤더가 없어도 토큰 경로에서 401 `missing_header` 를 낸다(F-49 불변). 요청 값이 관여하는 것은 헤더의 **유무**뿐이고 값의 옳고 그름은
`Auth::requireApiToken` 이 판정한다 — **틀린 토큰은 401 이고 세션으로 되돌아가지 않는다**(폴백 없음 → 권한 상승 경로가 생기지 않는다).

**새 판정 코드가 없다.** 넓힌 둘은 정책 `file.read` 이고, 인가는 세션 경로와 **같은 자리·같은 함수**다:
목록은 `Authz::visibleFilesWhere($user)` 가 SQL WHERE 에서 권한 없는 행을 돌려주지 않고(5.1, PRD S-02), 다운로드는
`Authz::decideRead` 가 저장소에 손대기 전 판정해 거부면 403(5.3). 토큰 요청의 `$user` 는 귀속 계정(`API_TOKEN_USER`)이므로
"토큰 주인의 역할로 볼 수 있는 것만" 이 자동으로 성립한다. IDOR 은 다운로드 핸들러가 이미 막고 있던 그대로다(남의 id → 403, 없는 id → 404).

**검색 응답 모양만 갈린다.** `files_list.php` 는 `defined('TBOX_AUTH_METHOD')` 일 때 간결 배열 `[{id,name,level,size,uploaded_at}]` 을
돌려주고 `limit`(1~100, 기본 20)을 페이지 크기로 쓴다. 세션(웹) 응답은 한 글자도 바뀌지 않는다. SQL 조각·바인딩·LIKE 이스케이프는
공통이다 — 갈리는 것은 **마지막 투영**뿐이다.

**남는 표면(R-83).** 토큰이 읽기까지 열리므로 유출된 토큰은 귀속 계정이 볼 수 있는 파일을 **검색·내려받을 수 있다**(업로드만이던 F-49 보다
넓다). 귀속 계정의 역할이 곧 노출 범위다 — 관리자 계정을 귀속하면 전부 보인다. 완화는 4.2 와 같다(회전·TLS·감사 `auth=api_token`), 그리고
귀속 계정을 **필요한 최소 역할**로 두는 것이 운영 권고다.

### 4.4 토큰 창구 넷 — `GET /api/whoami` (PRD F-56, 사용자 요청 2026-09-16)

F-50 의 셋에 **프로그램용 자기 확인** 창구 하나를 더해 넷이 됐다: `POST /api/upload` · `GET /api/files` · `GET /api/files/{id}/download` · **`GET /api/whoami`**.
상수는 `TBOX_API_TOKEN_ROUTES` 에 한 줄 추가, 그리고 `TBOX_API_TOKEN_ONLY_ROUTES` 에도 추가한다 — 화면이 쓰지 않는 **토큰 전용** 창구라 `POST /api/upload` 와 같은 갈래다:
헤더가 없으면 세션 쿠키가 있어도 401 `missing_header` 다(세션으로 되돌아가지 않는다). 정책은 `authenticated`(귀속 계정이 유효하면 누구든), 인가 [4] 는 그대로 탄다.

**새 판정 코드가 없다(F-50 과 같은 원칙).** 핸들러 `api_whoami.php` 는 `Auth::requireApiToken()` 이 앉힌 귀속 계정 `$user` 에서
`username`·`role`·`team_id` 를 읽고, `upload_levels` 는 `GET /api/me` 가 쓰는 **바로 그 함수** `Authz::uploadableLevels($user)` 를 부른다.
같은 계정이면 세션 `/api/me` 의 `upload_levels` 와 원소·순서까지 같아야 하며(T-68 (b)), 그것이 "중복 구현 금지" 의 검증 방법이다.

**넣지 않는 것.** `csrf_token`(세션이 없으니 뜻이 없다) · `can_*`·`next`·`acting*`(화면 편의 값) · 세션 정보 · 토큰 값. 프로그램이
"내가 무엇을 올릴 수 있나" 를 알기 위한 **최소한**만 준다 — 응답의 키는 정확히 넷이다. 성공은 감사 행을 남기지 않는다(조회, `/api/me` 와 같다);
실패는 `auth.token deny` 네 가지가 그대로다(새 문장 없음).

**남는 표면(R-89).** 유출된 토큰으로 귀속 계정의 아이디·역할·팀·업로드 가능 등급을 **한 번에** 알 수 있다 — F-50 까지는 검색 결과를 보고
추정해야 했던 것이 응답 하나로 확정된다. 데이터 자체가 새로 열리는 것은 아니다(R-83 범위 안). 완화는 4.2·4.3 과 같다(회전·TLS·최소 역할 귀속).

**뒤를 위한 모양.** 사용자별 토큰(`api_tokens` 표, 설계 B 후보)으로 가면 `requireApiToken` 이 앉히는 `$user` 가 「토큰을 쓴 진짜 사람」이 되고
이 창구의 응답은 그 사람을 가리킨다 — 핸들러도 클라이언트도 고치지 않는다. 다른 PC 로 넓힐 때의 순서가 그것이다.


### 4.5 토큰 창구 여덟 — 폴더 목록·생성·이름 변경·이동, 파일 이동 (PRD F-58, 사용자 요청 2026-09-16)

F-56 의 넷에 **폴더 관리 넷**을 더해 여덟이 됐다: `GET /api/folders` · `POST /api/folders` · `PATCH /api/folders/{num}` · `PATCH /api/files/{id}`.
넷 다 **화면도 쓰는 라우트**라 F-50 의 갈림길(4.3)을 그대로 탄다 — `X-API-Token` 헤더가 실제로 온 요청만 토큰 경로, 없으면 세션 경로(웹 불변),
틀린 값은 401 이고 세션으로 되돌아가지 않는다. `TBOX_API_TOKEN_ONLY_ROUTES` 에는 넣지 않는다. 상수 `TBOX_API_TOKEN_ROUTES` 에 넉 줄 추가가 라우터 쪽 변경의 전부다.

**새 판정 코드가 없다(4.3·4.4 와 같은 원칙).** 넷의 정책은 `file.read`·`file.upload`·`file.update` 이고 인가 `[4]` 는 그대로 탄다. 핸들러 안의 판정도
세션과 같은 함수다 — 트리 `decideRead(folder)` + `visibleFoldersWhere`(5.16), 만들기 `decideUploadAs` + 부모 `decideRead` + `fitsUnderFolder`,
폴더 이름·이동 `decideFolderChange` + `cycle`·`depth_exceeded`, 파일 이동 `decideRead`·`decideEdit` + 대상 폴더 `decideRead` + `fitsUnderFolder`.
토큰 요청의 `$user` 는 귀속 계정이므로 「귀속 계정이 만들었거나 그 역할로 고칠 수 있는 것만」이 자동으로 성립한다.

**토큰 요청은 허용 키가 더 좁다 — 유일한 새 코드.** 두 PATCH 핸들러의 첫머리 `$allowedFields` 를 `defined('TBOX_AUTH_METHOD')` 로 한 줄 가른다:

| 라우트 | 세션(웹) 허용 키 | 토큰 허용 키 |
|---|---|---|
| `PATCH /api/folders/{num}` | `name` `parent_id` `access_level` `team_id` `cascade` `reason_text` | **`name` `parent_id`** |
| `PATCH /api/files/{id}` | `description` `access_level` `team_id` `folder_id` | **`folder_id`** |

그 밖의 키가 오면 이미 있는 허용 키 검사(R-07 대량 할당 차단)가 400 `bad_request` 로 거부한다 — 새 거부 갈래·새 문구가 아니라 **허용 목록의 원소가 줄어든 것**뿐이다.
등급·팀 변경을 토큰에 열지 않는 이유: 넓히기는 Admin 만이고(`downgrade_not_permitted`) 그 결정은 15분 재인증 창 안에서만 된다(디버깅 묶음 S-8 부터 `files_update.php` 가 `Authz::levelChangeNeedsReauth` 로 묻는다 — 2026-09-26 보안 점검 전까지 이 문장은 **사실이 아니었다**: `file.update` 가 `REAUTH_POLICIES` 밖이라 Admin 의 내리기는 재인증 없이 됐다. 폴더 등급 넓히기는 지금도 묻지 않는다 — 폴더 등급은 폴더 이름의 노출이고 파일 등급은 따로라 범위 밖으로 남긴다)인데 토큰 경로는 그 창을 지나지 않는다(4.2) —
사람의 절차가 붙은 결정을 프로그램 창구로 우회시키지 않는다. `cascade`(하위 일괄 좁히기)도 같은 이유다. `folders_list.php`·`folders_create.php` 는 **한 글자도 바뀌지 않는다**.

**응답·문구는 세션과 같다.** F-50 의 검색은 정찰 재료(전체 건수·`can_*`·올린 사람)를 빼기 위해 투영을 갈랐지만, 폴더 트리 응답은 이미 그런 값이 없다(`id`·`name`·`depth`·`access_level`·`team_id`·`team_name`·`child_count`·`file_count`, 전부 호출자가 볼 수 있는 것만).
오류는 화면과 같은 문구(400·403·409)다 — **F-68(5.30, 2026-09-22)부터 400 은 화면과 같은 사유 문장**이 나가고(`depth_exceeded`·`cycle`·`parent_narrower`…), 허용 키 밖 `bad_request` 와 403·409 는 통일 문구 그대로다.

**남는 표면(R-90).** 토큰이 처음으로 **「고치기」 계열**에 닿는다. 유출된 토큰은 귀속 계정 권한으로 폴더를 만들고 이름을 바꾸고 파일·폴더를 옮길 수 있다 — 삭제는 아니지만
**복구 수단이 없다**(휴지통이 아니다, 옮긴 것은 감사 로그를 보고 사람이 되돌린다). 귀속이 Admin 이면 모든 폴더·파일이 대상이다. 완화는 4.2~4.4 와 같다(회전·TLS·감사 `auth=api_token`), 그리고
**귀속 계정을 Employee 로 두면** 고칠 수 있는 것이 자기가 만든 폴더·자기 파일로 좁혀진다(`decideFolderChange owner`·`decideEdit`). 지우기·등급 변경은 사용자별 토큰(설계 B) 결정 뒤 별건이다.

### 4.6 사용자별 프로그램 토큰 — 설계 B 와 토큰 삭제 (PRD F-59, 사용자 결정 2026-09-16)

F-49 는 「공용 정적 토큰 하나」(설계 A)로 갔고, 그 한계(회수·귀속 없음 — R-82)가 F-50·F-56·F-58 을 지나며 매번 「남는 위험」으로 적혔다.
지우기를 프로그램에 열려면 **누가 지웠는지가 사람 단위로 남아야** 하므로, 여기서 설계 B 로 간다. 4.2~4.5 의 원칙은 그대로다 — 인증만 갈리고 인가·판정·응답은 세션과 같은 코드다.

**표 `api_tokens`(6.2-5).** 계정별 개인 토큰. 토큰 원문은 발급 응답에 **한 번** 나가고 서버에는 **sha256 만** 남는다(`sessions`·`share_links` 와 같은 방식). 화면 식별용으로 앞 6자 `hint` 만 따로 둔다(48자 중 6자 — 남은 42자의 무작위성으로 충분하다). 회수는 `revoked_at`·`revoked_by` UPDATE 이고 행은 지우지 않는다 — 「그 토큰이 언제부터 언제까지 살아 있었나」 가 조사에 필요하다(앱 계정에 DELETE 권한 없음).

**발급·회수는 관리자의 세션 행동이다.** `GET/POST /api/users/{uid}/api-tokens`·`DELETE /api/users/{uid}/api-tokens/{tid}`, 정책 `user.manage`(Admin, 재인증 관문 `[3-2]` 자동). 발급은 `reason_text` 필수(S-07), 살아 있는 토큰 계정당 **5개**(`limit_exceeded`), 대상이 비활성·만료면 거부(`target_inactive`). 자기 자신도 대상이 된다 — 관리자 자신의 PC 프로그램이 첫 용례다(`decideUserChange` 의 `self_change` 는 여기 적용하지 않는다). 화면은 계정 관리(`/users`) 각 행의 「프로그램 토큰」 버튼이 여는 **토큰 창**: 목록(이름·hint·발급일·마지막 사용·상태), 발급(이름·사유 → 토큰을 **한 번** 보여 주고 복사), 회수. 전부 `textContent`. 처음엔 계정 편집 모달(F-40) 안의 구역으로 만들었으나 그 모달은 **자기 행에는 열리지 않아**(`is_self` → `(본인)`, 서버 `self_change` 와 같은 규칙) 관리자 자신의 토큰을 발급할 수 없었다 — 2026-09-17 사용자 실측으로 별도 버튼·창으로 보정.

**`Auth::requireApiToken` 의 판정 순서(4.2 표를 대신한다).**

| 순서 | 하는 일 |
|---|---|
| 헤더 | `X-API-Token` 없음 → 401 `missing_header` (불변) |
| 개인 토큰 | `sha256(헤더값)` 으로 `api_tokens` 를 조회(`revoked_at IS NULL`). 있으면 그 `user_id` 의 계정을 **로그인과 같은 열 목록**으로 읽어 4조건(존재·활성·미만료·강제절차 없음)을 본다. 실패 → **403** 「권한이 없습니다」 + `auth.token deny user_disabled`(계정을 비활성화하면 그 계정의 토큰이 자동으로 막히는 경로다 — 회수를 따로 하지 않아도 된다). 성공 → `$user` 로 앉히고 `TBOX_API_TOKEN_KIND='personal'`·`TBOX_API_TOKEN_ID=<id>`, `last_used_at` 갱신(NULL 이거나 60초 넘게 지났을 때만 — 요청마다 UPDATE 하지 않는다) |
| 공용 토큰 | 개인 토큰이 없을 때만. `.env` `API_TOKEN` 이 32자 이상이고 `hash_equals` → 기존 F-49 ④(귀속 계정 4조건, 실패 503 `user_invalid`) 그대로, `TBOX_API_TOKEN_KIND='shared'`(`TBOX_API_TOKEN_ID` 없음) |
| 둘 다 아님 | 401 「API 토큰이 올바르지 않습니다」 `bad_token`. 회수된 개인 토큰도 이 갈래다(회수됐다는 사실을 알리지 않는다) |
| 개인 전용 창구 | 라우터가 `TBOX_API_TOKEN_PERSONAL_ROUTES` 에 든 라우트면 `requireApiToken(true)` 로 부르고, 공용 토큰이면 **403** 「이 창구는 개인 토큰으로만 쓸 수 있습니다」 + `auth.token deny shared_token_not_permitted` |

`not_configured` 503 은 **더 나오지 않는다** — 개인 토큰이 있는 서버에서 공용 토큰 미설정은 정상 상태다(문장 틀은 표에 남되 쓰이지 않는다). 공용 토큰은 **이행용**이다: 사용자가 개인 토큰을 PC 프로그램에 심은 뒤 `.env` 두 줄을 지우면 공용 경로가 닫히고 R-82 가 ✅ 가 된다.

**감사 — 누가·어느 토큰으로.** `Audit::autoDetail` 이 토큰 경로의 모든 행에 `auth=api_token` 에 더해 `token_kind`(`personal`·`shared`)와 `token_id`(개인만)를 붙인다. 핸들러는 여전히 누가 불렀는지 모른다. 세 키는 이름에 `token` 이 들어 `FORBIDDEN_DETAIL_KEYS` 부분 일치에 걸리므로 `ALLOWED_DETAIL_KEYS` 에 **정확 일치 예외**로 등록한다(7.5) — 값은 번호·라벨·분류어이고 토큰이 아니다. 발급 행 `api_token.issue allow` 의 detail 은 `token_id`·`token_name`·`reason_text` 뿐, 토큰 원문·hint 도 남기지 않는다.

**토큰 창구 열 — 삭제 둘은 개인 토큰만.** `TBOX_API_TOKEN_ROUTES` 에 `DELETE /api/files/{id}`·`DELETE /api/folders/{num}` 을 더해 열이 되고, 그 둘만 `TBOX_API_TOKEN_PERSONAL_ROUTES` 다. 둘 다 화면도 쓰는 라우트라 헤더 유무 갈림길(4.3) 그대로다. 인가는 세션과 같은 함수(`decideDelete`·`decideFolderChange`), 파일 삭제는 30일 휴지통, 폴더 삭제는 세 갈래 400 + `?move_trashed=1`(5.16) 그대로다. 등급 변경·`cascade`·휴지통 복원(`/api/files/trash`·`restore`)은 여전히 닫힘.

**남는 표면(R-91).** 개인 토큰은 그 계정 권한으로 2단계 인증 없이 올리고·옮기고·지운다. 유출되면 그 계정의 파일이 휴지통으로 갈 수 있다(30일 복구). 완화: 회수 즉시 무효 · 계정 비활성화로 자동 차단 · 발급 1건 즉시 경보(AL-20) · 모든 행에 `token_id` · TLS · 살아 있는 토큰 5개 상한. 공용 토큰이 남아 있는 동안은 R-82 가 그대로 살아 있다.

### 4.7 프로그램 토큰 본인 발급 — 범위(scope)·만료 180일 (PRD F-66 · Q-24, 사용자 결정 2026-09-21)

**왜.** F-59 는 「관리자가 남의 토큰을 만든다」였다. 관리자 세션을 훔친 사람이 아무 계정의 자격을 만들 수 있고(R-91), 만든 토큰은 회수 전까지 영구였다. 사용자가 로컬 AI 비서에 admin01 토큰을 심어 쓰는 것을 계기로 「더 좋게」 — **토큰은 본인이, 비밀번호+OTP 를 치고, 필요한 만큼만, 180일까지**.

**세 가지 변화.**

| | F-59 (전) | F-66 (후) |
|---|---|---|
| 발급 | Admin 이 `/users` 에서 남의 것도(재인증 15분 창) | **본인만** `/settings` 에서 — 요청마다 비밀번호+OTP(`verifyCurrentPassword`·`verifyOtpOnce`, F-65 와 같은 helper, 세션에 창을 남기지 않는다). 2단계가 꺼진 계정은 발급 불가(403 `mfa_not_enabled`). `POST /api/users/{uid}/api-tokens` 는 **라우트에서 지운다** |
| 범위 | 없음(토큰 창구 열 전부) | `api_tokens.scope` ∈ `read` · `read+upload` · `read+upload+delete`. 선택지는 **역할이 정한다**(`Authz::tokenScopesFor`: Guest 는 `read` 만, 나머지 셋). 창구마다 필요한 범위는 **라우트 정책**이 정한다(아래 표) — 범위는 창구를 좁힐 뿐이고 파일별 판정(`decideRead`·`decideUploadAs`·`decideDelete`…)은 지금처럼 요청마다 한다. 토큰이 계정보다 커질 수 없다 |
| 만료 | 없음 | `api_tokens.expires_at` = 발급 + **180일** 고정. 지난 토큰은 401 `token_expired`(틀린 토큰 401 `bad_token` 과 문구가 다르다 — 토큰을 쥔 쪽은 우리 프로그램이라 이유를 준다, 4.2 오류 문구 정책). 목록의 `expires_soon`(14일 안)으로 화면이 안내한다 |

**창구 → 필요한 범위 (`Authz::tokenScopeAllows(scope, policy)`).** 정책 문자열 하나로 정한다 — 새 토큰 창구가 생겨도 표를 고칠 일이 없다.

| 라우트 정책 | 필요한 범위 | 창구 |
|---|---|---|
| `file.read` · `authenticated` | `read` 이상(전부) | `GET /api/files` · `GET /api/files/{id}/download` · `GET /api/folders` · `GET /api/whoami` |
| `file.upload` · `file.update` | `read+upload` 이상 | `POST /api/upload` · `POST /api/folders` · `PATCH /api/folders/{num}` · `PATCH /api/files/{id}` |
| `file.delete` | `read+upload+delete` | `DELETE /api/files/{id}` · `DELETE /api/folders/{num}` |

범위 밖 → **403** `MSG_TOKEN_SCOPE`(「이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요」) + `auth.token deny scope_not_permitted`(detail `token_id`·`token_scope`). 판정 자리는 `index.php` 토큰 가지에서 `requireApiToken` **직후, 정책 인가 앞** — 범위는 「이 토큰이 무엇을 할 수 있나」이고 정책 인가는 「이 계정이 무엇을 할 수 있나」다. 공용 토큰(`.env`, 이행용)은 범위 열이 없으니 `read+upload` 로 본다(삭제는 이미 개인 전용).

**`Auth::requireApiToken` 판정 순서(4.6 표에 두 줄이 늘어난다).** `SQL_FIND_API_TOKEN` 이 `scope`·`expires_at` 을 함께 읽고(`revoked_at IS NULL` 은 그대로), 행이 있는데 `expires_at <= now()` 면 401 `token_expired` 로 끝낸다 — 회수와 달리 만료는 알려 준다(회수는 관리자의 결정이라 숨기고, 만료는 시간의 결과라 숨길 이유가 없다). 통과하면 `TBOX_API_TOKEN_SCOPE` 상수를 세운다(공용은 `read+upload`). `Audit::autoDetail` 이 토큰 경로의 모든 행에 `token_scope` 를 붙인다(`token_kind`·`token_id` 와 같은 자리).

**본인 창구 셋 — `/api/me/api-tokens`(정책 `authenticated`, 세션·CSRF, 토큰 집합 밖).**
- `GET` — 내 것만(`WHERE user_id = 세션 본인`). 행마다 `scope`·`purpose`(=`reason_text`)·`expires_at`·`expires_soon`(`expires_at < now()+14일`)이 F-59 여섯 키에 더해진다.
- `POST` — 허용 키 **다섯**(`name`·`purpose`·`scope`·`password`·`otp`, R-07). 순서: 키·형식 → `scope ∈ Authz::tokenScopesFor($user)`(아니면 `bad_request` — 역할 힌트를 주지 않는다) → 2단계 켜짐(아니면 403 `mfa_not_enabled`, 실패로 세지 않음) → `verifyCurrentPassword`(실패 `bad_credentials` + `countFailureAndRevoke`) → `verifyOtpOnce`(`bad_code`·`code_reused` + 집계) → 살아 있는 5개(`limit_exceeded`) → INSERT(`created_by` = 본인, `scope`, `expires_at = now() + interval '180 days'`, `reason_text` = 용도) → `api_token.issue allow`(detail `token_id`·`token_name`·`token_scope`·`reason_text`·`otp_verified:true`). 비밀번호·코드는 읽은 즉시 지역 변수로만, 응답·감사·로그 0(PRD 7.7). 인증 실패 문구는 전부 「잘못된 요청입니다」(5.30 예외 목록).
- `DELETE /{tid}` — 내 것이 아니면 404, 이미 회수됨 400, 회수는 `revoked_at`·`revoked_by`(본인). 비밀번호·OTP 를 묻지 않는다 — 닫는 방향은 자격을 **줄이는** 일이고, 세션 탈취자가 회수해 봐야 얻는 것이 없다.

**자동 회수(디버깅 묶음 S-5, 2026-09-26 보안 점검 낮음 9).** 비밀번호 변경(본인 `POST /api/me/password`)·임시 비밀번호 재발급(`POST /api/users/{uid}/password/reset`)·2단계 인증 초기화(`…/mfa/reset`)는 그 계정의 **살아 있는 토큰 전부**를 같은 트랜잭션에서 회수한다(`Auth::revokeAllApiTokens` — `UPDATE … SET revoked_at = now(), revoked_by = <회수한 사람> WHERE user_id = :uid AND revoked_at IS NULL RETURNING id, name`). 토큰마다 감사 한 행 `api_token.revoke allow <password_change|password_reset|mfa_reset>`(detail `token_id`·`token_name` — 값·해시·hint 없음, 7.4). 본인 변경의 응답은 `{ok, sessions_revoked, tokens_revoked}`(개수만). 왜: 세 동작은 「이 계정 자격이 새었을 수 있다」는 신호인데, 전에는 세션만 끊고 토큰은 살아 있어(재발급·초기화는 강제 절차 동안만 403, 피해자가 절차를 마치면 다시 200) 훔친 사람이 미리 발급해 둔 토큰으로 계속 올리고 지울 수 있었다(R-91). 회수된 토큰은 「틀린 토큰」과 같은 401 이다(4.6 ④). 화면: 「내 계정」 비밀번호 칸 안내·도움말 한 줄 · 결과 문장에 취소한 토큰 수 · 같은 화면의 토큰 표를 다시 읽는다.

**관리자 창구.** 목록 `GET /api/users/{uid}/api-tokens`(행에 `scope`·`expires_at` 추가)·회수 `DELETE …/{tid}` 는 그대로(`user.manage`). **발급은 없다** — 핸들러 `api_tokens_issue.php` 는 지운다(옛 `POST` 는 같은 경로의 `GET` 이 남아 라우터 `[1]` 이 **405** 로 끝낸다 — 인증·인가에 닿지 않는다). 그와 함께 사유 코드 `target_inactive`(비활성 계정에 발급 불가)는 **닿을 수 없는 코드**가 된다 — 본인은 세션이 있으니 활성이다. 5.30 표와 `Http::REASON_MESSAGES` 에서 빼고, 7.4 의 문장 틀은 옛 감사 행이 그대로 보이도록 남긴다. 관리자가 남을 대신해 자격을 만드는 길이 사라지는 것이 R-91 의 핵심 완화다.

**표 `api_tokens`(6.2-5).** 열 둘: `scope TEXT NOT NULL CHECK (scope IN ('read','read+upload','read+upload+delete'))`, `expires_at TIMESTAMPTZ NOT NULL`. 마이그레이션 `2026-09-22_api_tokens_scope_expiry.sql` 은 기존 행을 **`read+upload+delete`**(그때는 범위가 없어 전부 열려 있었다 — 좁히면 사용자 프로그램이 멎는다)·`created_at + 180일`로 채운 뒤 NOT NULL 을 건다. 기존 토큰도 180일 규칙을 따른다는 뜻이다 — 사용자 비서의 admin01 토큰이 그 대상이며, 만료 전에 본인 창구에서 새로 발급한다.

**`GET /api/me`.** `token_scopes`(이 역할이 고를 수 있는 범위 배열)·`can_issue_tokens`(= `mfa_enabled`). 화면은 이 둘로만 폼을 그린다(역할 비교 0, DESIGN 0장 1).

**화면.** `/settings` 에 「프로그램 토큰」 절(패널 `span-all`) — 표(`.table-wrap`: 이름·용도·앞 6자·범위·만료·마지막 사용·상태·회수)·발급 폼(`.form--grid`: 이름·용도·범위 select·현재 비밀번호·2단계 코드 `.otp-box`·「토큰 발급」)·발급 직후 토큰 한 번 표시(F-59 `showIssuedToken` 과 같은 값 전용 칸)·만료 14일 안이면 `.notice--recommend` 한 줄. 2단계가 꺼져 있으면 폼 대신 `.notice--recommend` 「2단계 인증을 켜야 발급할 수 있습니다」. `/users` 의 토큰 모달은 **발급 폼을 빼고** 목록(범위·만료 열 추가)·회수만.

**남는 위험(R-97).** `read` 범위도 다운로드가 된다(정찰·유출은 범위로 못 막는다) · 만료 전 유출은 여전히 그 범위만큼 · 공용 토큰은 범위·만료가 없다(이행용, R-82 그대로) · 만료 뒤 갱신을 잊으면 프로그램이 멎는다(14일 안내로 완화).


## 5. 네 가지 흐름 — 순서대로, 권한 검사 위치 표시

표기: **[권한 ✔]** = 여기서 권한 검사 · **[DB]** = PostgreSQL · **[FS]** = 스토리지 디스크 · **[LOG]** = 감사 로그

### 5.1 파일 목록 / 검색 — `GET /api/files?q=&sort=&page=`

```
 브라우저                nginx              PHP (index.php → files_list.php)            PostgreSQL
 ───────                ─────              ──────────────────────────────────            ──────────
 GET /api/files?q=회의  ──▶  통과  ──▶  [1] 세션 → user_id → DB에서 role, team_id 조회 ─▶ SELECT users
                                        [권한 ✔] Authz::visibleFilesWhere($u)
                                              → "WHERE ... access_level IN (...)"
                                              ※ 권한 없는 행은 SQL 결과에 아예 안 나옴 (S-02)
                                        [2] 검색어: LIKE 값 바인딩, %·_ 이스케이프 (S-04)
                                        [3] sort: 허용 목록 ['name','created_at','size'] 에
                                              없으면 기본값으로 대체 (S-04)
                                        [4] SELECT ... WHERE deleted_at IS NULL
                                                 AND (권한 조건) AND (검색 조건)
                                                 ORDER BY <허용된 컬럼> LIMIT 20 OFFSET ? ─▶ [DB] files
                                        [5] COUNT(*) 도 같은 WHERE → 전체 건수 노출 안 함
                                        [6] JSON 응답 (Content-Type: application/json)
 ◀── {files:[...20건 — 행마다 can_delete(bool) 포함], total: n, page: 1}
 화면은 받은 것만 그림 (textContent 사용, innerHTML 금지) (S-03)
 ※ can_delete 는 삭제 버튼을 보일지 정하는 **화면 편의 값**이다 (4단계, PRD 10장 "버튼 표시는 권한의 근거가 아님").
   서버가 Authz::decideDelete 로 계산해 내려 주고, 실제 삭제 요청은 5.4 에서 서버가 다시 판단한다.
   같은 이유로 GET /api/me 는 업로드 가능 레벨 목록 upload_levels 를 내려 주어 업로드 폼의 선택지를 채운다.
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [1] | PHP | 요청에 실린 역할값 무시 → 프론트 권한 우회 |
| [권한 ✔] | **SQL WHERE** | 권한 없는 데이터 노출 (응답에 없음) |
| [2][3] | PHP | SQL 인젝션 (값 바인딩 + 허용 목록) |
| 화면 | JS | XSS (textContent) |

> 목록 조회는 양이 많아 감사 로그를 남기지 않는다(PRD S-07 참고). **다운로드는 반드시 남긴다.**

### 5.2 파일 업로드 — `POST /api/files` (multipart)

```
 브라우저              nginx                    PHP (files_upload.php)                    저장소
 ───────              ─────                    ──────────────────────                    ──────
 POST 파일+설명+레벨 ─▶ [크기 ✔] 100MB 초과 → 413 즉시 거부 (앱까지 안 옴)
                       통과 ─▶  [1] 세션 → user 조회
                                [2] CSRF 토큰 확인
                                [권한 ✔ A] Authz::canUploadAs($u, $level, $teamId)
                                      Guest → 403 / Employee가 admin-only → 403
                                      → [LOG] deny 기록 후 끝
                                [3] Upload::validate()  (S-06)
                                      · 크기 ≤ 100MB (PHP도 한 번 더)
                                      · 확장자 화이트리스트 (pdf, docx, ...)
                                      · finfo 로 실제 MIME 판별 → 확장자와 불일치 시 거부
                                      · 이중 확장자·경로문자·제어문자 제거
                                      · 일일 총량 2GB 초과 → 거부
                                [4] stored_name = UUIDv4 . '.' . 안전한확장자
                                    sha256 = hash_file(...)
                                [5] BEGIN
                                     INSERT INTO files (...) VALUES (:바인딩...)     ─▶ [DB] files
                                     Storage::put(tmp, stored_name)                  ─▶ [FS] /var/lib/tbox/storage/ab/cd/UUID.pdf
                                     Audit::allow('file.upload', file)               ─▶ [DB] audit_logs + [LOG] audit.log
                                    COMMIT   (하나라도 실패 → ROLLBACK + 파일 삭제)
 ◀── 201 {id, name}
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [크기 ✔] | nginx | 대용량 DoS |
| [권한 ✔ A] | PHP Authz | 권한 없는 레벨로 올리기 (프론트에서 select 값 조작) |
| [3] | PHP Upload | 웹셸 업로드, HTML/SVG 저장형 XSS, 위장 파일 |
| [4] | PHP | 경로 조작, 파일명 충돌, 원본명으로 인한 헤더 인젝션 |
| [5] | 트랜잭션 | DB엔 있는데 파일 없음 / 파일은 있는데 DB엔 없음(고아 파일) |

### 5.3 파일 다운로드 — `GET /api/files/{id}/download`

```
 브라우저                  nginx           PHP (files_download.php)                   저장소
 ───────                  ─────           ────────────────────────                   ──────
 GET /api/files/57/download ─▶ 통과 ─▶ [1] 세션 → user 조회
                                       [2] id 정수 검증 (아니면 404)
                                       [3] SELECT * FROM files
                                             WHERE id = :id AND deleted_at IS NULL  ─▶ [DB] files
                                           없으면 → 404
                                       [권한 ✔] Authz::canRead($u, $file)
                                             false → 403 {"error":"권한이 없습니다"} (파일명·레벨 등 아무 정보 없이)
                                                   → [LOG] deny 'file.download' 기록   ※ Q-6 결정: 과제 기준 403 통일
                                       [4] scan_status <> 'clean' → 403 (pending 「검사 중」 / infected 「격리」, F-54 부터 **필수** — 5.28)
                                       [5] 경로 = Storage::pathOf($file['stored_name'])
                                             · basename() 처리, UUID 형식 정규식 검사
                                             · realpath()가 storage 디렉터리 안인지 확인
                                       [6] 파일 sha256 재계산 ≠ DB 값 → 500 + Audit::error hash_mismatch (AL-6)
                                           ※ 6단계부터 **필수** (R-13, 2026-09-11). 100MB 도 0.3초 안팎
                                       [7] Audit::allow('file.download', file)        ─▶ [DB]+[LOG]
                                       [8] 헤더:
                                             Content-Type: application/octet-stream
                                             Content-Disposition: attachment; filename*=UTF-8''인코딩된원본명
                                             X-Content-Type-Options: nosniff
                                           readfile() (6단계 확정, X-Accel 미사용 — 6.3·R-34)   ─▶ [FS]
 ◀── 파일 바이트 스트림 (브라우저는 항상 "저장"으로 처리)
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [권한 ✔] | PHP Authz, **스토리지 접근 전** | ID만 알면 받는 문제(기존 t-box 취약점) |
| 403 동일 문구 + UUID 외부 ID | PHP | 응답 본문으로 파일 정보가 새는 것, 순차 ID 열거 |
| [5] | PHP Storage | 경로 조작(`../../etc/passwd`) |
| [8] | 응답 헤더 | 업로드된 HTML이 같은 도메인에서 실행되는 저장형 XSS |

> **X-Accel-Redirect란?** PHP가 "이 파일 내려줘"라고 nginx에 **내부적으로만** 알려주면 nginx가 전송한다.
> 큰 파일도 PHP 메모리를 안 쓰고, 그 내부 경로(`/protected/`)는 외부 URL로는 절대 열리지 않게 `internal;`로 잠근다.

### 5.4 파일 삭제 — `DELETE /api/files/{id}`

```
 브라우저                    nginx        PHP (files_delete.php)                         저장소
 ───────                    ─────        ──────────────────────                         ──────
 DELETE /api/files/57  ────▶ 통과 ─▶ [1] 세션 → user 조회
 (X-CSRF-Token 헤더 포함)              [2] CSRF 토큰 확인 → 불일치 403
                                      [3] SELECT * FROM files WHERE id=:id AND deleted_at IS NULL ─▶ [DB]
                                          없으면 404
                                      [권한 ✔ 1] Authz::canRead($u, $file)
                                          false → 403 (읽을 수 없는 파일은 삭제 시도도 403, 정보 없이)
                                      [권한 ✔ 2] Authz::canDelete($u, $file)
                                          false → 403 → [LOG] deny 'file.delete'
                                      [4] BEGIN
                                            UPDATE files SET deleted_at = now(), deleted_by = :uid
                                              WHERE id = :id                           ─▶ [DB] (논리 삭제)
                                            Audit::allow('file.delete', file)          ─▶ [DB]+[LOG]
                                          COMMIT
                                          ※ 실제 파일은 지우지 않음 → 30일 휴지통 (F-07, F-11)
 ◀── 200 {ok:true}

 (별도) 매일 04:00  scripts/purge_trash.php
     SELECT ... WHERE deleted_at < now() - interval '30 days'
     → Storage::remove(stored_name)  ─▶ [FS] 실제 삭제
     → Audit::system('file.purge')   ─▶ [DB]+[LOG]
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [2] | PHP | 다른 사이트에서 몰래 삭제 요청 보내기(CSRF) |
| [권한 ✔ 1·2] | PHP Authz | **기존 t-box 취약점 A**: 버튼만 숨기고 API는 누구나 |
| 논리 삭제 | DB | 실수·악의 삭제의 즉시 복구 불가 |

### 5.5 네 흐름 한눈에 비교

| 흐름 | 권한 검사 위치 | 판단 함수 | 실패 응답 | 감사 로그 |
|---|---|---|---|---|
| 목록/검색 | **SQL WHERE절** (조회 자체에 포함) | `visibleFilesWhere` | 401만 존재 | 기본 미기록 |
| 업로드 | DB에 쓰기 **전** | `canUploadAs` + `Upload::validate` | 403 / 400 / 413 | 성공·거부 모두 |
| 다운로드 | 파일 시스템 접근 **전** | `canRead` | 403 (동일 문구) / 없는 ID 404 | 성공·거부 모두 |
| 삭제 | UPDATE **전** | `canRead` → `canDelete` | 403 (동일 문구) | 성공·거부 모두 |
| URL 가져오기 | 외부 접속 **전** + 저장 **전** | `canFetch` → `Fetch::check` → `Upload::validate` | 403 / 400 / 429 (동일 문구) | 성공·거부 모두(사유 포함) |

### 5.6 URL로 파일 가져오기 — `POST /api/files/fetch` (PRD F-14, S-05)

기존 t-box 의 `fetch_url.php` 가 SSRF 로 뚫린 기능이다. 이번엔 "서버가 갈 수 있는 곳을 미리 정해두고, 검사한 곳과 실제 가는 곳이 같음을 보장하고, 가져온 것을 업로드와 똑같이 검사"한다.

```
 브라우저                 PHP (files_fetch.php → src/Fetch.php)                              외부
 ───────                 ─────────────────────────────────────                              ────
 POST url=https://…  ─▶ [1] 세션 → user, CSRF
                        [권한 ✔] Authz::canFetch($u)  → Manager 이상만 (아니면 403 + deny)
                        [2] 속도 제한: 사용자별 10회/시간 초과 → 429 + deny
                        [3] URL 정규화·검사 (PRD S-05 ①②④)
                              parse_url 실패 / scheme ≠ https / port ≠ 443(또는 없음)
                              / userinfo 있음 / 호스트가 IP·localhost·숫자표기 / 정규식 불통 → 400 + deny(사유)
                        [4] 화이트리스트 (③): host ∈ FETCH_ALLOWED_HOSTS 정확 일치. 아니면 → deny 'host_not_allowed'
                        [5] DNS 해석 → 모든 A/AAAA 가 공인 IP 인지 (⑤). 하나라도 내부 → deny 'private_ip'
                        [6] cURL 로 접속 — 검사한 IP 로 고정 (⑥⑦⑧)                            ─▶ https://host:443
                              CURLOPT_PROTOCOLS=HTTPS, CURLOPT_RESOLVE="host:443:검사한IP",
                              FOLLOWLOCATION=false, CONNECTTIMEOUT=3, TIMEOUT=10,
                              MAXFILESIZE=100MB, 응답은 임시파일(/var/lib/tbox/tmp)로 스트리밍
                              3xx → deny 'redirect' / 수신 바이트 초과 → deny 'too_large'
                        [7] 파일명 결정: Content-Disposition → URL 마지막 경로 → 정리(Upload 와 동일 규칙)
                        [8] Upload::validate(임시파일, 파일명)  ← 수동 업로드와 **같은 함수** (⑨)
                              확장자·MIME 불일치·크기 → deny 'invalid_file'
                        [9] 이후는 5.2 업로드 [4]~[5] 와 동일: UUID 저장명, sha256, 트랜잭션, Audit 'file.fetch' allow
 ◀── 201 {id, name}    (실패 시 본문은 항상 {"error":"가져올 수 없습니다"} — 사유는 감사 로그에만, ⑩)
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [3][4] | PHP Fetch | `file://`, 내부 포트, IP 직접 지정, 표기 우회, 허용되지 않은 출처 |
| [5][6] | PHP Fetch + cURL | 허용 도메인이 내부 IP 를 가리키는 경우, DNS 리바인딩, 리다이렉트로 내부 진입 |
| [8] | PHP Upload | URL 로 웹셸·HTML 반입 (업로드 하드닝을 우회하는 두 번째 입구가 되지 않게) |
| 응답 | PHP | 내부 서비스 응답을 읽어내는 것 (SSRF 의 진짜 목적) |
| 인프라 | php.ini · 방화벽 | `allow_url_fopen=Off`, 소켓 함수 차단, 아웃바운드 443 만 — 코드 실수의 안전망 |

```php
// src/Fetch.php — 외부 요청 코드는 이 파일에만 존재한다 (PRD S-05 ⑬)
final class Fetch
{
    private const PRIVATE_CIDRS = [
        '0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12',
        '192.0.0.0/24','192.168.0.0/16','198.18.0.0/15','224.0.0.0/4','240.0.0.0/4',
        '::1/128','::/128','fc00::/7','fe80::/10','::ffff:0:0/96',
    ];

    /** 검사 통과 시 [host, ip] 반환, 실패 시 FetchDenied(사유) 예외 */
    public static function check(string $url, array $allowedHosts): array
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https')            throw new FetchDenied('scheme');
        if (isset($p['port']) && $p['port'] !== 443)             throw new FetchDenied('port');
        if (isset($p['user']) || isset($p['pass']))              throw new FetchDenied('host_not_allowed');
        $host = strtolower(rtrim($p['host'] ?? '', '.'));
        if (!preg_match('/^[a-z0-9.-]{1,253}$/', $host))         throw new FetchDenied('host_not_allowed');
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || ctype_digit(str_replace('.', '', $host)))
                                                                  throw new FetchDenied('host_not_allowed');
        if (!in_array($host, $allowedHosts, true))               throw new FetchDenied('host_not_allowed');

        $records = array_merge(dns_get_record($host, DNS_A) ?: [], dns_get_record($host, DNS_AAAA) ?: []);
        if (!$records)                                            throw new FetchDenied('private_ip');
        foreach ($records as $r) {
            $ip = $r['ip'] ?? $r['ipv6'];
            if (self::isPrivate($ip))                             throw new FetchDenied('private_ip');
        }
        return [$host, $records[0]['ip'] ?? $records[0]['ipv6']];
    }

    public static function download(string $url, string $host, string $ip, string $tmpPath): void
    {
        $fh = fopen($tmpPath, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_RESOLVE         => ["$host:443:$ip"],          // 검사한 IP 로만 접속 (리바인딩 방지)
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_MAXFILESIZE     => 100 * 1024 * 1024,
            CURLOPT_FILE            => $fh,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch); fclose($fh);
        if (!$ok)                       throw new FetchDenied('fetch_failed');
        if ($code >= 300 && $code < 400) throw new FetchDenied('redirect');
        if ($code !== 200)              throw new FetchDenied('fetch_failed');
        if (filesize($tmpPath) > 100 * 1024 * 1024) throw new FetchDenied('too_large');
    }
    // isPrivate(): inet_pton + CIDR 비교 (생략)
}
```

> 위 코드는 설계 예시다. 구현 시 `isPrivate` 의 IPv6·IPv4 매핑 처리, 임시파일 정리(성공·실패 모두), 예외 → 감사 로그 사유 매핑을 T-6 로 검증한다.


---

### 5.7 설명·접근 레벨 변경 — `PATCH /api/files/{id}` (PRD F-08, 5.3, R-07 · 5단계, 2026-09-11)

```
 브라우저                      PHP (files_update.php)                                         PostgreSQL
 ───────                      ───────────────────────                                        ──────────
 PATCH {description?, access_level?, team_id?}  (JSON 만, X-CSRF-Token)
                        ─▶ [1] 세션 → user, CSRF (라우터). 정책 file.update (Guest 는 라우터에서 403)
                           [2] 본문 필드 **화이트리스트**: description · access_level · team_id 만.
                               모르는 키가 하나라도 있으면 400 + deny bad_request (R-07 대량 할당 차단 —
                               uploaded_by·sha256·stored_name 같은 컬럼은 요청으로 절대 바뀌지 않는다)
                           [3] SELECT 파일 행 (deleted_at IS NULL) 없으면 404
                           [권한 ✔ 1] decideRead   → 실패 403 + deny file.update|role_not_permitted
                           [권한 ✔ 2] decideEdit   → 실패 403 + deny file.update|not_owner / team_mismatch
                           [4] 값 검증 (업로드와 같은 규칙): 설명 500자·UTF-8·제어문자 제거, 레벨 4종, team 은 team_id 필수·존재
                               바뀌는 값이 하나도 없으면 400 + deny no_change
                           [권한 ✔ 3] 레벨·팀이 바뀌면 decideLevelChange → 실패 403 + deny file.level_change|…
                           [5] BEGIN → UPDATE files SET description/access_level/team_id (바인딩) WHERE id
                               → Audit::allow('file.level_change', detail.before/after) 또는 ('file.update') → COMMIT
 ◀── 200 {ok:true}
```

| 단계 | 무엇을 막나 |
|---|---|
| [2] | 요청 JSON 을 그대로 UPDATE 하는 대량 할당(R-07) — 허용 3필드 밖은 400 |
| [권한 ✔ 2·3] | 남의 파일 수정, 등급 하향으로 노출 범위 확대(R-20, AL-5), 남의 팀으로 옮기기 |
| detail.before/after | "이전값 → 새값" 이 없으면 하향 변경을 조사할 수 없다 (PRD 7.7 접근 레벨 변경) |

> 화면: 목록 행의 `can_edit`(서버 계산) 가 참일 때만 "수정" 버튼을 만든다. 버튼은 편의이며, PATCH 는 위 순서대로 서버가 다시 판단한다.


### 5.8 미리보기 — `GET /api/files/{id}/preview` · `/preview/raw` (PRD F-18, S-03 · 12-2, 2026-09-12)

"내려받지 않고 무슨 파일인지 본다". 권한은 **다운로드(5.3)와 한 글자도 다르지 않다** — 같은 `Authz::decideRead`, 같은 403 문구, 같은 sha256 대조. 다른 것은 **응답의 모양**뿐이다.

| 순서 | 무엇을 | 왜 |
|---|---|---|
| [1] | UUID 형식 확인 → `files` 조회(`deleted_at IS NULL`) → 없으면 404 | 5.3 과 동일 |
| [2] | `Authz::decideRead` → 거부면 `file.preview deny` 기록 + 403 | 권한은 서버, 다운로드와 같은 표 |
| [3] | `Preview::kindFor($ext)` 로 갈래 결정 — **요청 파라미터가 아니라 DB `files.ext`** (업로드 때 화이트리스트·finfo 를 통과한 값) | 요청값으로 형식을 고르면 `.zip` 을 `image` 라고 속여 `inline` 전송을 유도할 수 있다 |
| [4] | `Storage::assertExists` + sha256 대조 → 어긋나면 `error hash_mismatch` + 500 | 변조 파일을 화면에 띄우지 않는다(R-13) |
| [5-JSON] | 갈래별 본문을 만들어 JSON 으로 응답. `image`·`pdf` 는 본문 대신 `src: "/api/files/{id}/preview/raw"` | 화면이 그리는 방법이 갈래마다 다르다 |
| [5-raw] | `image`·`pdf` 만 파일을 `readfile` 로 흘린다. 헤더: `Content-Type: <DB mime>` · `Content-Disposition: inline; filename*=…` · **`Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'self'`** · **`X-Frame-Options: SAMEORIGIN`**(bootstrap 의 DENY 를 교체) · `X-Content-Type-Options: nosniff` · `Cache-Control: no-store` · `Referrer-Policy: no-referrer`. 다른 갈래는 400 `preview_not_available`. **nginx**: 이 경로만 `location ~ "^/api/files/[0-9a-f-]{36}/preview/raw$"` 로 받아 전역 `frame-ancestors 'none'`·`X-Frame-Options DENY` 를 물려주지 않는다(같은 출처 모달의 `<iframe>` 이 끼울 수 있어야 하므로) — 그 밖의 모든 응답은 전역 헤더 그대로(12-2 검토에서 실측·보정, 2026-09-12) | `sandbox` 가 붙은 응답은 브라우저가 **불투명 출처(opaque origin)** 로 다룬다 — PDF 안의 스크립트·폼이 t-box 출처·쿠키·DOM 에 닿을 수 없다. `nosniff` 는 형식 재해석을 막는다 |
| [6] | `file.preview allow` 기록 (`detail.preview_kind`, JSON 은 `part: meta`, raw 는 `part: raw`) | 누가 무엇을 봤는지 — 다운로드와 같은 무게 |

**갈래와 상한** (`src/Preview.php` 상수 하나에 모은다 — 상한은 "서버를 멈추게 하는 파일"을 막기 위한 것이다, R-42)

| 갈래 `kind` | 확장자 | 서버가 하는 일 | 상한 |
|---|---|---|---|
| `image` | png jpg jpeg gif | raw 로 inline 전송 | 파일 자체 100MB 이하(업로드 상한) |
| `pdf` | pdf | raw 로 inline 전송 | 같음 |
| `text` | txt md | 앞 1MiB 만 읽어 UTF-8 검사(`iconv` — 아니면 CP949→UTF-8 시도, 둘 다 실패면 `encoding: unknown` 으로 치환문자) → 앞 **64KiB** 로 자름, `truncated` | 1MiB 읽기 · 64KiB 응답 |
| `table` | csv | 앞 1MiB 를 `str_getcsv` 로 행 분해 → **200행 × 50열** | 200×50 |
| `archive` | zip | `ZipArchive` 로 **중앙 디렉터리만** 읽어 항목 이름·크기·압축 크기·디렉터리 여부. 내용은 절대 풀지 않는다 | 항목 200개 표시, 전체 개수는 `total_entries` |
| `office` | docx pptx xlsx hwpx | `ZipArchive` 에서 **정해진 멤버만** 꺼내 정규식으로 텍스트 노드만 모은다 — docx `word/document.xml` `<w:t>`, pptx `ppt/slides/slide*.xml` `<a:t>`(번호순), xlsx `xl/sharedStrings.xml` `<t>`("문자열 목록 발췌" 로 표기), hwpx `Contents/section*.xml` `<hp:t>`. **XML 파서를 쓰지 않는다**(XXE·엔티티 확장 자체가 없다). `html_entity_decode` 후 공백 정리 | 멤버 하나 8MiB 이하만(`statName` 으로 먼저 확인), 발췌 **20,000자** |
| `none` | hwp, 그 외 | 기본 정보(이름·크기·형식·sha256 앞 12자·올린 사람·시각)만 | — |

- `ZipArchive` 는 `php8.1-zip`(Ubuntu 22.04 배포판 패키지)이 필요하다 → `deploy/DEPENDENCIES.md` 3장에 실측 기록(PRD S-10-C 심사: 배포판 패키지·보안 패치 경로 동일·EOL 은 PHP 와 같음).
- 목록 응답(5.1)에 행마다 `preview_kind` 를 넣는다 — 화면은 이 값이 `none` 이어도 「미리보기」를 열 수 있되 정보 카드만 나온다. 값은 서버 계산(`Preview::kindFor`), 화면은 확장자를 보지 않는다.
- 화면(모달 `<dialog>`): `image` 는 `<img>`, `pdf` 는 `<iframe sandbox referrerpolicy="no-referrer">`(토큰 없는 `sandbox` = 스크립트·폼·팝업·같은 출처 전부 금지) — 둘 다 `src` 는 서버가 준 값이 `/api/files/` 로 **시작할 때만** 넣는다. `text`·`office` 는 `<pre>` 의 `textContent`, `table` 은 `<td>` 마다 `textContent`, `archive` 는 `<li>` 마다 `textContent`. innerHTML 은 어디에도 없다(린트 S-03). 브라우저가 sandbox iframe 안 PDF 렌더를 막는 경우를 위해 「새 탭에서 열기」(raw 를 최상위로 — 응답 CSP `sandbox` 가 그대로 적용된다)를 함께 둔다. **PDF 렌더 가능 여부는 브라우저 실측(관문 ③)** 이다.
- 페이지 CSP 는 그대로다: `default-src 'self'` 가 `frame-src`·`img-src` 에 적용되어 같은 출처 raw 를 허용하고, `frame-ancestors 'none'` 은 t-box 화면이 남의 사이트에 끼워지는 것을 막는 별개 규칙이다.

### 5.9 조직·권한 관리 — `/api/org/*` (PRD F-19, 5.4, S-07 · 12-3, 2026-09-12)

Admin 전용. 정책 `org.manage`(`Authz::canManageOrg` = Admin) 를 `KNOWN_POLICIES` 에 추가한다 — `user.manage` 를 재사용하지 않는 이유는 감사 로그와 라우트 표에서 "무엇을 관리했나" 가 이름만으로 갈리게 하기 위해서다.

| 요청 | 하는 일 | 검사 순서 |
|---|---|---|
| `GET /api/org/teams` | `teams` + 구성원 수(`users.is_active`)·살아있는 파일 수(`deleted_at IS NULL`) | 정책 → 조회 |
| `POST /api/org/teams` `{name, reason_text}` | 팀 생성 | 정책 → 형식(`name` trim 1~50자, 제어문자 없음; `reason_text` 1~500자) → 중복(`lower(name)` 비교 → 409 `already_exists`) → INSERT → `team.create allow` |
| `PATCH /api/org/teams/{num}` `{name?, is_active?, reason_text}` | 이름 변경 · 활성/비활성 | 정책 → 대상 존재(404) → 형식 → 비활성화 요청이면 **활성 구성원 수 0 확인**(아니면 400 `team_has_members`) → 중복 → UPDATE(`updated_at=now()`) → `team.update allow` (`detail.before/after` = 바뀐 필드만, `reason_text`) |
| `GET /api/org/teams/{num}/members` | 구성원 표 | 정책 → 존재 → `users WHERE team_id = …` (비활성 계정 포함, `is_active` 로 표시) |
| `GET /api/org/permissions` | `permissions` 16행 그대로 + 5.3 규칙 문구(상수) | 정책 → SELECT. **UPDATE 경로 없음** — DB 계정 권한도 SELECT 만(10장) |

- **팀은 지우지 않는다.** `files.team_id`·`users.team_id`·감사 기록이 참조한다. 대신 `teams.is_active=false`(마이그레이션 `db/migrations/2026-09-12_teams_active.sql`: `is_active BOOLEAN NOT NULL DEFAULT true`, `created_at`, `updated_at`, `CHECK (length(name) BETWEEN 1 AND 50)`).
- 비활성 팀의 효과(코드 위치): `GET /api/teams` 는 활성만 돌려준다(선택지). `users_update`·`users_create` 의 `team_id` 가 비활성 팀이면 400 `team_inactive`. `files_upload`·`files_fetch`·`files_update` 에서 팀 존재를 확인하는 자리에서 **활성**도 확인 → 400 `team_inactive`. 이미 그 팀에 있는 파일·계정은 그대로다(읽기 판정은 `team_id` 일치만 본다 — 4.1 불변).
- 구성원 배정은 새 API 를 만들지 않는다. `/org` 화면이 기존 `PATCH /api/users/{uid}` `{team_id, reason_text}` 를 부른다 — 한 규칙(자기 자신 금지·마지막 Admin 보호·세션 종료 T-22)이 두 곳에 갈라지지 않게.
- 감사 문장(7.4)에 `team.create|allow`·`team.create|deny|bad_request`·`team.create|deny|already_exists`·`team.update|allow`·`team.update|deny|bad_request`·`team.update|deny|not_found`·`team.update|deny|team_has_members`·`team.update|deny|already_exists` 를 더한다. `target_type=team`, `target_name` 은 팀 이름.

### 5.10 위키 — `GET /wiki` · `/wiki/{book}` · `/wiki/{book}/{slug}` (PRD F-21, 10장 「위키」, S-03 · 14단계 2026-09-12 · 책 셋 2026-09-23)

**무엇인가.** 네 문서(PRD·ARCHITECTURE·SECURITY·STAGES)를 초보자 말로 다시 쓴 **해설 글**을 앱 안에서 보여 주는 화면이다. 원문 md 를 파싱해 보여 주지 않는다 — 마크다운 파서(HTML 통과 위험, 의존성 0 원칙 S-10-C)를 만들지 않기 위해서고, 원문은 기술 문서라 그대로는 초보자용이 아니기 때문이다.

**어디에 두나 — 책 셋 (2026-09-23, 사용자 지시 「위키 접속 시 Qaredi · T-box · Community 셋을 보여 주고 골라 들어가게」).** 저장소 루트 `wiki/`(웹 루트 `public/` 밖 — URL 로 직접 닿지 않는다) 아래에 **책**마다 디렉터리 하나: `wiki/qaredi/`(사이트 공통) · `wiki/tbox/`(파일 공유) · `wiki/community/`(커뮤니티). 책마다 `index.json`(차례) + `<slug>.json`(글 한 편). 책 이름은 **고정 목록**(`Wiki::BOOKS` — 순서가 곧 책 고르기 화면의 순서, 이름·설명도 코드 상수)과 정확히 같을 때만 받고(`Wiki::bookKey`), 경로에는 요청 문자열이 아니라 **목록 쪽 문자열**이 들어간다. `slug` 는 `^[a-z0-9][a-z0-9-]{0,39}$`(`index` 는 예약). 라우터 자리표시자 `{book}`(책 키 셋과 같은 글자 — 늘리려면 `BOOKS` 와 함께 고친다, 어긋나면 새 책이 404 로 닫힌다)·`{slug}` 를 `TBOX_PATH_PATTERNS` 에 둔다 — 그 밖의 문자는 라우트 매칭에서 404 다. 파일 경로는 `wiki/` + 책 키 + 검증된 slug + `.json` 만으로 만들고, 책 디렉터리와 글 파일 둘 다 `realpath` 로 풀어 `wiki/` · `wiki/<책>/` 안인지 한 번 더 본다(5.3 [5] 와 같은 방식). 책이 나뉜 뒤로 `wiki/` 바로 아래의 `*.json` 은 어느 주소로도 닿지 않으므로 `wiki_check` 가 문제로 잡는다.

**글 구조 (`wiki/<책>/<slug>.json`)** — 본문에는 HTML 도 URL 도 없다. 서버(`src/Wiki.php`)가 모든 문자열을 `htmlspecialchars(ENT_QUOTES)` 로 그리고, 인라인 표기는 **백틱 한 가지**(`` `코드` `` → `<code>`)만 인정한다(이스케이프 **뒤**에 백틱을 나눈다).

```json
{ "slug": "stage-04", "title": "4단계 — 서버가 권한을 판단한다", "summary": "한 줄 요약",
  "group": "stage",  "stage": 4,  "updated": "2026-09-12",
  "sections": [ { "id": "why", "heading": "왜 필요한가", "blocks": [
      { "type": "p",       "text": "문단. `코드`는 백틱." },
      { "type": "list",    "items": ["항목", "항목"] },
      { "type": "steps",   "items": ["1번째", "2번째"] },
      { "type": "table",   "header": ["열", "열"], "rows": [["값", "값"]] },
      { "type": "code",    "text": "curl -s ..." },
      { "type": "callout", "kind": "why|how|check|risk|try|term", "title": "제목", "text": "본문" },
      { "type": "terms",   "items": [ { "term": "인가", "easy": "쉬운 풀이" } ] },
      { "type": "refs",    "items": [ { "doc": "PRD", "where": "7.1 S-01", "what": "무엇이 적혀 있나" } ] },
      { "type": "link",    "slug": "stage-05", "label": "다음 단계" },
      { "type": "checklist", "rounds": ["1차 …", "5차 …"],
        "items": [ { "label": "검토 항목", "marks": ["ok", "open"],
                     "how": ["어떻게 확인했나"], "code": "명령과 응답 원문(선택)" } ] } ] } ] }
```
`group` 은 `guide`(안내) 또는 `stage`(단계). 단계 번호의 모양은 **책마다** 다르다(`Wiki::validStage`): t-box 는 정수 1~28(`Wiki::STAGE_MAX` — PRD 13장 로드맵 끝, 2026-09-13 에 13→23, 이후 28) · 커뮤니티는 문자열 `3`·`3-B`·`4-A`·`4+`(숫자 + 선택 조각 글자 하나, 또는 그 단계 뒤 보정 묶음 `+` — `COMMUNITY_STAGE_RE`) · 사이트는 `Q-0`·`Q-1`(`QAREDI_STAGE_RE`). `refs.doc` 은 `PRD`·`ARCH`·`SECURITY`·`STAGES`·`README`·`CLAUDE`·`DIRECTIVE`·`PLAN` 여덟 값만(뒤의 둘은 2026-09-23 — 커뮤니티·사이트 글이 지시서·통합 계획을 근거로 들어서). 어느 모듈의 문서인지는 `where` 에 경로로 적는다(예: `community/PRD.md 4.1`). `checklist`(2026-09-13 추가, 보안 시험 기록)는 행 = 검토 항목·열 = 차수이며 칸 값은 `ok`·`open`·`todo`·`na` 넷뿐이다 — **기호·툴팁 문구·색은 코드**(`Wiki::CHECK_MARKS`)가 정하고 글은 키만 고른다(감사 요약 문장을 `Audit` 상수 표 하나에 두는 것과 같은 이유, 7.4). 한 줄은 `<details>` 로 그려져 눌러서 펼치며 **자바스크립트를 쓰지 않는다**(CSP `script-src 'self'` 와 무관, 스크립트가 막혀도 내용이 남는다). 차수 개수는 클래스 `wiki-check--N` 으로 나간다 — 인라인 `style` 은 CSP `style-src 'self'` 가 막기 때문이다. `link.slug` 는 (가는 책의) 차례에 있는 slug 만 그린다(없으면 글자만). 모르는 `type`·빠진 필드·범위 밖 값은 **그 글 전체를 500** 으로 끝낸다(조용히 건너뛰면 검토에서 놓친다) — 그래서 커밋 전에 `scripts/wiki_check.php`(CLI, 세 책 전 글 구조 검사)를 돌린다. 2026-09-23 부터 이 검사는 **빠진 글**도 본다: 세 STAGES 파일(`tbox-v2/STAGES.md` · `community/STAGES.md` · 사이트 `STAGES.md`)의 0장 표에서 상태 칸에 ✅ 가 있는 단계마다 그 책에 같은 번호의 단계 글이 있어야 하고, 없으면 실패다(예외는 `WIKI_MISSING_EXCEPTIONS` 에 이유와 함께). 같은 책에 같은 단계 번호의 글이 둘이어도 실패다. 전에는 있는 글의 형식만 봐서 끝난 단계의 글이 빠진 것(t-box 26·28, 커뮤니티 3~4-D)을 잡지 못했다(사이트 STAGES 2장 2026-09-23 「이건 왜 위키에 기록이 안 되니」).

**요청 순서 (4장 공통 관문 그대로).** 라우트 정책은 네 경로(`/wiki` · `/wiki/{book}` · `/wiki/{book}/{slug}` · 옛 `/wiki/{slug}`) 모두 `role:Admin` (PRD F-21, SECURITY R-56). 라우트 표에서 `/wiki/{book}` 줄이 옛 주소 줄보다 **위**에 있어야 `/wiki/tbox` 가 차례로 간다(`tbox` 도 slug 정규식에 맞으므로). `Auth::requireLogin` → CSRF(GET 이라 해당 없음) → 강제 절차 관문 → `Authz::authorize('role:Admin')` → 핸들러. `/api/me` 는 `can_view_wiki`(`Authz::canReadWiki`, Admin) 를 내려 주고 셸의 「위키」 항목은 그 값이 참일 때만 열린다 — 다른 `can_*` 와 같은 **화면 편의 값**이며 막는 것은 라우트 정책이다. 감사 기록은 남기지 않는다(PRD F-21).

**화면.** `/wiki` = **책 고르기**: 타일 셋(Qaredi · t-box · 커뮤니티 — 런처와 같은 부품 `.card-grid` > `.card.card--link`, 이름·설명은 `Wiki::BOOKS`, 글 수는 차례에서 센 숫자, `Wiki::renderBooks`). `/wiki/{book}` = 그 책의 차례: 안내 글 목록 + 단계 카드(번호·제목·요약·`updated`). `/wiki/{book}/{slug}`: 제목·요약·절 차례(`id` 앵커)·본문·이전/다음 단계 링크(그 책의 차례 순). **옛 주소** `/wiki/{slug}`(책이 나뉘기 전 — 그때 글은 전부 t-box 것)는 t-box 책에 그 글 파일이 있을 때만 `/wiki/tbox/{slug}` 로 **308**, 없으면 바로 404(`wiki_legacy.php`). Location 은 고정 접두 + 책 상수(`Wiki::LEGACY_BOOK`) + 검증된 slug 로 코드가 만들고 요청의 쿼리·헤더는 쓰지 않는다. 재인증 뒤 돌아오는 곳은 키 `wiki` 하나이고 책 고르기(`/wiki`)로 온다. 공통 셸(`src/views/shell_*.php`) 안에 그리며, 새 JS 는 필요 없다(서버 렌더). CSS 는 `app.css` 토큰을 쓴다.

**말의 기준(내용 작성 규칙, PRD F-21).** ① 새 용어는 처음 나올 때 괄호로 풀이하고 `terms` 블록에도 넣는다 ② 영어 약어는 한글 뜻을 함께(예: CSRF — 남의 이름으로 요청 보내기) ③ 한 문장에 새 개념 하나 ④ 단계 글의 절 순서는 「왜 필요한가 → 무엇을 만들었나 → 어떻게 확인했나 → 남은 위험 → 직접 해보기 → 원문 위치」 ⑤ 사실은 원문에 있는 것만, 검토 결과는 SECURITY 7장 실측 값만 쓴다(추정 금지) ⑥ 비밀값(비밀번호·키·IP·계정 비밀)은 넣지 않는다.

---

### 5.11 내 활동 — `GET /activity` · `GET /api/me/activity` (PRD F-12 · 15-1, 2026-09-12)

감사 로그(7장)를 **본인 행만** 잘라 보여 주는 화면이다. 정책은 `authenticated`. 핸들러는 요청에서 대상을 받지 않는다 — SQL 의 `WHERE actor_id = :me` 에 `Auth::currentUser()['id']` 를 넣고, `action` 은 PRD F-12 의 고정 목록(`IN (...)` 상수)으로 좁힌다. 돌려주는 칸은 `happened_at`·`action`·`result`·`reason`·`summary`·`target_name` 만 — `ip`·`user_agent`·`detail` 은 SELECT 자체에 넣지 않는다(응답에 없으면 새지 않는다, S-02 의 같은 원리). 20건 페이지(`Http::queryInt`). 이 조회는 감사에 남기지 않는다(PRD F-12). 셸 내비 「내 활동」은 모든 로그인 사용자에게 보인다.

**거부 행 가림(디버깅 묶음 S-1, 2026-09-26 보안 점검 중간 1 · 검토 반영).** 가리는 것은 사유가 **권한 계열**(`role_not_permitted`·`team_mismatch`·`not_owner`·`not_found`·`not_found_in_trash`)인 거부 행뿐이다 — 그 밖의 사유(검사 중·격리·형식 오류 …)는 권한 판정을 지난 뒤라 본인이 볼 수 있는 대상이다. 거부 행의 대상은 그 사람이 **볼 수 없는** 것일 수 있다 — Guest 가 남의 UUID(내려받기·미리보기)·링크 번호(회수)를 찔러 보면 거부 감사에 admin-only 파일 이름이 남고, 전에는 그 이름이 이 화면으로 되돌아 나왔다. 그래서 `result = deny` 행은 `target_name` 을 `Audit::MASKED_TARGET`(「(볼 수 없는 대상)」)으로, 요약 문장은 `Audit::maskedSummary()` — **같은 문장 틀**(7.4)에 `{target}`=「(볼 수 없는 대상)」·`{level}`=「(가림)」을 넣어 다시 만든 것 — 으로 내보낸다(SELECT 에 `actor_name` 을 더해 문장의 `{actor}` 만 채운다 · 응답 칸은 여섯 그대로). 예외는 `file.upload`·`file.fetch` 의 거부 행 — 대상 이름이 요청자가 **보낸** 파일 이름이라 가릴 까닭이 없고 가리면 본인이 까닭을 모른다. 허용·오류 행은 그대로. 감사 원본(DB·파일 사본·관리자 감사 화면)은 바뀌지 않는다. 한계: 틀을 나중에 고치면 가린 문장은 지금 틀로 만들어진다 · 틀이 표에서 빠진 옛 조합은 고정 문장.

### 5.12 공유 링크 — `POST /api/files/{id}/share` · `GET /s/{token}` (PRD F-13 · Q-5 기본안 · 15-2, 2026-09-12)

**만들기.** 라우트 정책 `file.update`(Guest 는 여기서 끝) → 핸들러가 파일 행을 읽어 `Authz::decideRead` → `Authz::decideEdit`(5.3 규칙: Admin 전부 · Manager 본인·자기 팀 · Employee 본인) 를 순서대로 통과해야 한다. 본문은 허용 키 3개(`expires_hours` 1~168 기본 24, `max_downloads` 1~10 기본 1, `reason_text` 선택 500자)만, 그 밖의 키는 400(R-07 과 같은 화이트리스트). 토큰은 `random_bytes(32)` 를 base64url 로(43자), DB `share_links.token_hash` 에는 `hash('sha256', 토큰)` 만 넣는다(세션 6.2-2 와 같은 방식). 응답 `{id, url, expires_at, max_downloads}` 의 `url` 은 `APP_URL . '/s/' . 토큰` 이며 **이 응답에만** 존재한다. 감사 `share.create allow`(target 파일, detail `share_id`·`expires_at`·`max_downloads`·`reason_text`; 토큰·해시는 기록하지 않는다).

**받기.** `GET /s/{token}` 은 정책 `public` 이다 — 로그인 검사·CSRF·강제 절차 관문을 지나지 않는다(로그인 API 와 함께 public 인 둘째 라우트). `{token}` 자리표시자는 `[A-Za-z0-9_-]{43}` 이고 그 밖은 라우터 404. 핸들러 순서 ① `hash('sha256', 토큰)` 으로 행 조회 ② `revoked_at IS NULL` ③ `expires_at > now()` ④ `download_count < max_downloads` ⑤ 파일 행 `deleted_at IS NULL`·`purged_at IS NULL` ⑥ **한 트랜잭션**에서 `UPDATE … SET download_count = download_count + 1 WHERE id = :id AND download_count < max_downloads` 가 1행이어야 진행(동시 요청으로 횟수를 넘기지 못하게) ⑦ `Storage::stream`(sha256 대조 포함, 5.3 [6]) — 다운로드와 같은 코드 경로, 항상 `attachment`. ②~⑤ 어느 단계에서 막혀도 응답은 **404 `찾을 수 없습니다`** 하나이고, 사유는 감사에만(`share.download deny not_found|revoked|expired|exhausted|file_gone`). 성공은 `share.download allow` — actor 는 없고(로그인 전 문장 틀) detail 에 `share_id`·`issued_by`(발급자 id·이름)·`remaining`. ~~접근 등급 판정(`decideRead`)은 하지 않는다 — 그 판단은 만들 때 끝났다~~ → **디버깅 묶음 S-2(2026-09-26) 부터 받을 때마다 다시 판정한다**: 링크는 만든 사람의 권한을 빌려 주는 것이라, ⑤(파일 살아 있음) 뒤·횟수 소비(⑥) 앞의 **⑤-A** 에서 ① 만든 사람이 활성(`is_active`)이고 만료 전(`expires_at < now()` 아님, DB 시계)인가 → 아니면 `issuer_inactive` ② `Authz::decideRead(만든 사람의 지금 역할·팀, 파일의 지금 등급·팀)` → 거부면 그 코드(`role_not_permitted`·`team_mismatch`) ③ `Authz::decideEdit(만든 사람, 파일)` — 만들 때와 같은 둘째 판정(검토 L-2) → 거부면 `not_owner` 등. 판정 코드는 새로 쓰지 않고 기존 함수를 부른다. 막히면 다른 실패와 **같은 404** + `share.download deny <코드>`(detail `share_id`·`issued_by`) — 횟수는 쓰지 않는다. 링크 행은 바꾸지 않으므로(회수가 아니다) 등급을 되돌리거나 계정을 다시 켜면 같은 링크가 다시 된다. 전에는 파일 등급을 올리거나 만든 사람을 꺼도 링크가 200 이었다(보안 점검 중간 2). 파일 등급이 `admin-only` 인 링크는 여전히 Admin 만 만들 수 있고(decideEdit), Admin 권한(`admin_all`)으로 만드는 링크는 15분 재인증 창 안에서만(S-8, 4.0).

**회수·목록.** `DELETE /api/shares/{num}`(`authenticated`) — `Authz::decideShareRevoke(actor, link)`: 만든 사람 또는 Admin, 그 밖은 `not_owner` — **디버깅 묶음 S-1 부터 응답은 없는 링크와 같은 404**(상태·머리·본문이 같다 — 전에는 403·404 가 갈려 순차 번호로 링크 존재를 훑을 수 있었다)이고 감사는 **대상 없는** `share.revoke deny not_owner`(target_type `share`·target_id = 요청한 번호 · detail `share_id` — 파일 이름·등급·파일 번호 없음). **없는 번호도 같은 감사**를 남긴다(검토 L-1 — 전에는 없는 번호만 감사가 없어 「내 활동」 전체 건수·응답 시간으로 번호 존재가 갈렸다). UPDATE `revoked_at = now(), revoked_by = :me`(행 삭제 없음). `GET /api/me/shares` 는 내가 만든 링크(토큰·해시 제외) 목록. 앱 DB 계정은 `share_links` 에 SELECT·INSERT·UPDATE 만(10장, `db/grants.sql`).

**속도 제한.** nginx `location ~ "^/s/[A-Za-z0-9_-]{43}$"` 에 별도 존 `tbox_share`(`limit_req_zone $binary_remote_addr zone=tbox_share:1m rate=60r/m`, burst 10, 429) — 토큰 열거를 IP 단위로 늦춘다(43자 무작위라 열거 자체는 비현실적이지만 한 겹 더).

**위험과 경계(SECURITY R-57).** 링크 원문을 받은 사람은 누구나 받을 수 있다 — ~~사내망 안에서 · 사내망 밖 공개는 NG-4 그대로(네트워크가 막는다)~~ → **2026-09-26 d67 정정: 사이트는 강사 프록시를 거쳐 인터넷에 공개돼 있다**(공인 주소 · 사용자 인지). 링크를 받은 사람은 **인터넷 어디서든** 받는다 — 메신저·메일 전달 경로가 곧 공격면이다. 그래서 기본 1회·24시간, 회수, 전부 감사, 받을 때마다 만든 사람 권한 재판정(S-2). 외부 전달 정책은 PRD Q-5 사용자 확정 대기.

### 5.13 민감정보 패턴 검출 — 업로드·URL 가져오기 공통 (PRD F-22 · 15-3, 2026-09-12)

**어디서.** `src/Sensitive.php` 한 파일. 업로드(5.2)와 URL 가져오기(5.6) 모두 `Upload::validate/validatePath` 가 끝난 **직후, 저장 트랜잭션 전**에 `Sensitive::scan(임시 경로, ext)` 를 한 번 부른다 — 두 핸들러가 같은 함수를 부르므로 한쪽만 빠지는 일이 없다. 등급 변경(5.7)은 DB 의 `files.sensitive_flags` 만 본다(다시 읽지 않는다).

**무엇을.** `Preview::kindFor(ext)` 가 `text`·`table`·`office` 인 것만 — `Preview::text/table/office` 가 뽑은 글자(상한은 Preview 상수 그대로: 1MiB·200×50·8MiB 멤버·20,000자 발췌)를 대상으로 ① 주민등록번호: `\d{6}-?\d{7}` 후보 → 뒷자리 첫 숫자 1~4·검증 숫자 계산 통과만 ② 카드 번호: 13~19자리(공백·붙임표 허용) → Luhn 통과만 ③ 키워드: `Sensitive::KEYWORDS` 상수(`급여`·`연봉`·`주민번호`·`계좌번호`·`여권번호`). 결과는 `['rrn' => 건수, 'card' => 건수, 'keyword' => 건수]` 중 0 이 아닌 것 — **값(숫자 원문)은 어디에도 돌려주지 않는다**. `image`·`pdf`·`archive`·`none` 은 검사하지 않고 `[]`(한계, R-58). 추출 실패(`parse_failed`)도 `[]` 로 두고 업로드를 막지 않는다 — 검출은 보조이고 업로드 가용성을 해치지 않는다.

**규칙.** 결과가 비어 있지 않고 요청 등급이 `public`·`internal` 이면 `Http::error(400, MSG_SENSITIVE_LEVEL_REQUIRED)` + 감사 `file.upload deny sensitive_level_required`(detail `sensitive` = 패턴 이름 목록·건수). `team`·`admin-only` 면 저장하되 `files.sensitive_flags`(`text[]`, 패턴 이름만)를 같은 INSERT 에 넣고 `file.upload allow` detail 에 `sensitive` 를 함께 남긴다. 5.7 등급 변경에서 새 등급이 `public`·`internal` 이고 `sensitive_flags` 가 비어 있지 않으면 `decideLevelChange` 뒤에 400 `sensitive_level_required`(`file.level_change deny`). 목록(5.1)은 `sensitive` 불리언만 내려 배지를 그린다(패턴 이름은 목록에 내리지 않는다).

**한계를 문서에 남긴다(R-58).** 이미지·PDF·암호 zip·스캔 문서는 검사하지 못하고, 오탐(우연히 검증 숫자가 맞는 숫자열)도 있다. 그래서 「민감」 배지는 참고이고, 등급 판단의 책임은 올리는 사람에게 있다는 문구를 업로드 화면에 둔다.

---

### 5.14 관리자 영역 — `GET /admin` · `/api/admin/*` (PRD F-24 · 17-1, 2026-09-12)

**무엇인가.** 관리 기능의 **단일 입구** 허브다. 화면 `/admin`(카드: 계정 관리 `/users` · 조직·권한 `/org` · **권한 매트릭스 편집 `/admin/permissions`** · 감사 로그 `/audit` · 휴지통 `/trash` · 공유 링크 전체 · 최근 경보 · 위키 `/wiki`, 재인증 남은 시간)과 API `GET /api/admin/shares`(전체 링크, 토큰·해시 없음) · `GET /api/admin/alerts`(`alerts.log` 마지막 50줄 — root 0644 파일을 읽기만, JSON 줄을 그대로 돌려주되 `evidence.command` 는 200자). 정책은 전부 `role:Admin` 이며 `Authz::REAUTH_POLICIES` 에 들어 **재인증(4.0) 관문을 지난 세션만** 쓴다.

**세 겹.** ① 서버 인가 `role:Admin`(비관리자는 `authz.route deny` 403, 화면도 403) ② 재인증 15분 창(F-23) ③ 네트워크 — nginx 두 사이트 파일에 `location ^~ /admin` · `location ^~ /api/admin` 블록을 **주석으로 준비**해 두고(`allow <관리망>; deny all;` + 기존 fastcgi 지시), README 에 켜는 법과 **함정**(현재 접속 출처 확인 → 허용 대역에 자기 출처가 없으면 잠김, hardening.sh 8.4-16 과 같은 실수)을 적는다. 기본은 꺼짐(SECURITY R-61).

**일반 셸.** `shell_top.php` 의 관리 그룹(계정·조직·감사·휴지통·위키)을 없애고 Admin 에게만 「관리자 페이지」(`/admin`) 한 항목을 `can_admin`(`/api/me`, = `Authz::canReadWiki` 와 같은 Admin 판정 함수 `canAdmin`)로 연다. 비관리자 HTML 에는 항목 자체가 없다(기본 `hidden` 은 지금처럼 — 막는 것은 정책). 기존 관리 화면 경로는 그대로 두되 허브에서만 연결한다 — 위키·문서가 그 경로를 가리키고, 이미 재인증 대상이다.

**경보 목록의 출처 배지 (F-69 ③, 2026-09-24 — 판정 표는 11.4).** `GET /api/admin/alerts` 는 줄의 `origin`·`title` 을 **그대로** 내려 준다 — 판정은 배치가 `src/Origin.php` 로 이미 했고 이 창구는 다시 판정하지 않는다. `origin` 은 넷(`Origin::VALUES`) 중 하나일 때만 내려 보내고, 그 밖(2026-09-24 전의 옛 줄 — 키 없음 — 또는 모르는 글자)은 `null` 이다. `title` 은 300자까지(`iconv_substr`), `evidence.origin_hint` 는 근거 안에 그대로다. 화면(`app.js` `alertOriginBadgeFor`)은 이 두 값만 보고 규칙 번호 옆에 배지를, 그 옆에 제목을 둔다:

| 줄의 값 | 배지 | 클래스(DESIGN 2.2 — 새 클래스·색 0) |
|---|---|---|
| `origin` 없음(`null` — 옛 줄) | 「출처 표기 전」 | `.badge--off` |
| `system` | 「시스템」 | `.badge--team` 재사용 — **색은 사용자 확인 대기** |
| `claude-review` · `claude-session` | 「Claude」 | `.badge--internal` (감사 화면 F-71 과 같은 모양) |
| `human` + `origin_hint: partial` | 「확인 필요」 | `.badge--scan-pending` |
| `human` | 없음 | — |

제목의 `[Claude] ` 앞머리도 서버가 붙인 글자다 — 화면이 판정해 덧붙이지 않는다. 배지·제목은 전부 `textContent` 로 넣고, 배지는 표시일 뿐 경보를 거르거나 숨기지 않는다(지시서 B 의 선택 항목 「사람·시스템만」 거르개는 넣지 않았다). 옛 줄은 고치지 않는다 — `alerts.log` 는 증거다.

### 5.15 권한 매트릭스 편집 — `GET/PATCH /api/admin/permissions` (PRD F-25 · Q-12 결정 · 17-2, 2026-09-12)

**결정 배경.** 6.2-1 의 "앱 계정은 SELECT 만" 은 권한 상승 경로 차단의 핵심이었다. 사용자 결정(Q-12, "보안만 확보된다면 페이지에서")으로 편집을 허용하되 **그 핵심은 유지**한다 — 런타임 롤 `tbox_app` 은 여전히 SELECT 만이고, 쓰기는 **별도 롤 `tbox_perm`** 으로 **한 핸들러**에서만 한다. 다른 핸들러의 SQL 인젝션·결함으로는 여전히 표를 바꿀 수 없다(SECURITY R-60).

**DB 롤 `tbox_perm`** (10장): `CONNECT`, `USAGE ON SCHEMA public`, `permissions` 에 `SELECT, UPDATE` 만. 다른 표 권한 0(INSERT·DELETE 도 없음 — 16행은 고정, 행을 늘리거나 지울 수 없다). 비밀번호는 `install.sh` 가 만들어 `.env` `DB_PERM_USER`·`DB_PERM_PASS` 로(예시는 `.env.example` 주석만). `Db::perm()` 은 이 자격으로 **두 번째 PDO 연결**을 지연 생성하며, `handlers/admin_permissions_update.php` 만 부른다(린트: `Db::perm(` 가 그 파일 밖에 나오면 반려 — `security_lint.sh` 에 규칙 추가).

**요청 순서(`PATCH /api/admin/permissions`).** 라우터: `role:Admin` → 재인증 관문(4.0) → CSRF → 핸들러 ① 허용 키 `changes`(배열 1~16, 각 `{role, access_level, can_read, can_upload}` bool)·`reason_text`(필수 1~500자)·`otp`(6자리) 밖은 400 ② TOTP — `Totp::verifyStep(users.mfa_secret, otp)` + `mfa_last_step` 1회성(13단계 규칙, 실패는 `countFailureAndRevoke`) → 거부 `permission.change deny bad_code|code_reused` ③ 현재 16행을 `tbox_app` 으로 읽어 변경 후보 표를 만들고 **불변 규칙** 검사 → 위반은 `deny invariant`(detail 에 어느 규칙): (a) `role='Admin'` 행은 네 셀 전부 `true` 고정 (b) `can_upload=true ⇒ can_read=true` (c) `role='Guest' AND access_level='admin-only'` 는 둘 다 `false` 고정 (d) 역할·등급은 4×4 밖 거부 (e) 값이 실제로 바뀌는 셀이 0개면 400 ④ `Db::perm()` 트랜잭션에서 바뀌는 셀마다 `UPDATE permissions SET can_read=:r, can_upload=:u WHERE role=:role AND access_level=:lvl`(rowCount 1 확인) ⑤ 셀마다 `Audit::allow('permission.change', target_type=system, target_name="{role}|{level}", detail before/after·reason_text·`otp_verified:true`)` — 감사 INSERT 는 `tbox_app` 연결(별도 트랜잭션; 감사 실패 시 perm 트랜잭션도 롤백하도록 순서: perm 트랜잭션 안에서 UPDATE → 감사 기록(자동 커밋) → perm COMMIT; 감사가 예외를 내면 perm ROLLBACK) ⑥ 응답 `{ok, changed:[…], matrix:16행}`. **효과는 다음 요청부터**(4.1 매트릭스는 요청마다 읽음 — 살아 있는 세션도 즉시 영향).

**`GET /api/admin/permissions`** 는 16행 + `locked` 표시(규칙 a·c 셀)를 준다. 화면 `/admin/permissions` 는 4×4 토글 표, 잠긴 셀 비활성, 변경 미리보기(before → after 목록), 사유·코드 칸, 「적용」. 실패 문구는 서버 문구 그대로.

**경보 AL-13.** `alerts.php` 가 5분 창의 `permission.change allow` 를 세어 1건 이상이면 `AL-13|actor`(evidence: 바뀐 셀·사유·행위자) — 정상 작업이어도 알린다(PRD 7.11). 같이 보강: AL-2 세는 행에 `auth.reauth deny bad_credentials` 추가(16단계 창구), AL-9 명령 가리기에 `PASSWORD\s+'[^']*'`·`PASSWORD\s+"[^"]*"` 추가.

---

### 5.16 폴더 — `GET/POST /api/folders` · `PATCH/DELETE /api/folders/{num}` (PRD F-26 · 18-1, 2026-09-13, 사용자 결정 「폴더 자체도 권한」)

**원칙.** ① 폴더는 **DB 의 분류**다 — 저장 경로(6.3)는 바뀌지 않는다. ② 폴더는 **파일과 같은 등급 체계**(`access_level` 4종 + `team_id`)를 갖고, 읽기 판정은 **같은 함수** `Authz::decideRead(user, row)` 다(행에 `access_level`·`team_id` 만 있으면 파일이든 폴더든 같은 코드). 못 읽는 폴더는 트리 응답에 없고(S-02), 그 폴더의 목록·자식 요청은 403 동일 문구다.

**두 겹을 한 번의 검사로 — 불변 규칙.** "폴더에 접근해도 권한이 없으면 파일을 못 본다" 를 판정마다 폴더·파일을 두 번 검사하는 대신 **쓰기 시점**에 보장한다.
- ① **하위는 부모보다 넓을 수 없다**: 폴더 안 파일·하위 폴더의 `LEVEL_RANK[level] ≥ LEVEL_RANK[parent.level]`, 부모가 `team` **이고 하위도 `team` 이면** `team_id` 가 같아야 한다(`Authz::fitsUnderFolder(childLevel, childTeam, folder)`) — 하위가 `admin-only` 면 팀을 보지 않는다(더 좁고, 그것을 읽는 Admin 은 어느 팀 폴더든 읽으므로 단조성이 유지된다, 18-1 검토 결정 2026-09-13). 업로드·URL 가져오기·파일 이동·파일 등급 변경·폴더 생성·폴더 이동·폴더 등급 변경 **전부** 이 함수를 지난다(거부 `parent_narrower`).
- ② 폴더 등급을 **좁히면**(rank 증가) 하위 폴더·살아 있는 파일 전부가 새 등급에 대해 ①을 만족해야 한다(재귀 CTE 로 검사, 위반 시 400 `children_exceed`, detail 에 위반 개수만).
- ③ 폴더 등급을 **넓히면**(rank 감소) Admin 만(`decideLevelChange` 와 같은 `downgrade_not_permitted`). 넓혀도 하위는 자기 등급을 유지하므로 ①은 깨지지 않는다.
- **구글 드라이브와 비교(사용자 지시 「구글드라이브의 권한 구조를 생각하면 된다」)**: 같은 점 — 권한을 **폴더에 걸고**, 그 아래로 내려가며 적용되고, 새로 올리는 파일은 폴더의 등급을 **기본값**으로 물려받는다(화면 기본 선택 = 폴더 등급; 서버는 여전히 명시값을 요구하고 ①로 검사). 다른 점 — 드라이브는 폴더 접근자가 안의 것을 모두 보지만, 여기서는 사용자 결정대로 **파일은 폴더보다 더 좁을 수 있다**(폴더에 들어와도 파일 등급이 없으면 못 본다). 즉 폴더 권한은 「안으로 들어올 수 있는 최소 자격」이고 파일 권한은 그 위에 한 번 더 좁힌다. ② 의 「좁히기」에는 드라이브의 「하위에도 적용」과 같은 선택지 `cascade:true` 를 둔다 — 하위 폴더·파일의 등급을 새 등급으로 **함께 좁힌다**(한 트랜잭션, 항목마다 `file.level_change`·`folder.level_change` 감사; 행위자가 고칠 수 없는 항목이 하나라도 있으면 전체 거부 `not_owner`). `cascade` 없이 위반이 있으면 지금처럼 `children_exceed`.
매트릭스(6.2-1)는 등급 순서에 대해 **단조**다 — 어떤 역할이 읽을 수 있는 등급 집합은 넓은 쪽부터 연속(Guest {public}, Employee·Manager {public, internal, team(같은 팀)}, Admin 전부). 그래서 ①이 지켜지면 **"파일이 보이면 그 폴더도 보인다"** 가 성립하고, 목록·검색 SQL 은 지금의 `visibleFilesWhere`(파일 조건만) 그대로다. F-25 로 매트릭스를 편집할 때 이 단조성이 깨질 수 있으므로(예: Guest 에 `team` 만 열기) **F-25 불변 규칙 (f)** 를 추가한다: 역할마다 `can_read` 는 rank 순서로 앞에서부터 연속이어야 한다(위반 시 `invariant rule=f`).

**표 `folders`** (6.2-4): `id`, `parent_id NULL → folders ON DELETE RESTRICT`, `name`, `depth 1..5`, `access_level`(파일과 같은 CHECK), `team_id NULL → teams`(team 이면 NOT NULL — 파일과 같은 CHECK), `created_by`, `created_at`, `updated_at`, `UNIQUE(parent_id, name)` + 루트용 부분 UNIQUE. `files.folder_id NULL → folders ON DELETE RESTRICT`.

**하위 개수(디버깅 묶음 S-7, 2026-09-26).** 트리 응답의 `child_count` 는 `file_count` 와 같이 **호출자가 볼 수 있는** 하위 폴더 수다 — 같은 조건 함수 `visibleWhere(user, 'd')`(세는 쪽이 `d`, 이 층이 `p`)로 한 문장에 센다. 전에는 조건 없이 세어 못 보는 admin-only 하위의 존재·개수가 숫자로 새었다. 그 폴더를 펼쳤을 때 돌아오는 수와 같다. 남는 것: 폴더 **만들기**의 이름 중복 409 로 못 보는 루트 폴더 이름의 존재를 확인할 수 있다(DB 유일 제약과 얽혀 이번엔 고치지 않음 — SECURITY R-106).

**권한 판정(`Authz`).** 트리·목록: 라우트 `file.read` → 핸들러가 부모 폴더 행을 읽어 `decideRead`(실패 403 + `folder.read deny`), 자식은 `visibleFoldersWhere(user)`(= `visibleFilesWhere` 와 같은 조건을 별칭 `d` 로 — 한 함수 `visibleWhere(user, alias)` 로 일반화하고 파일 쪽은 그것을 부른다) 로 SQL 에서 거른다. 만들기: 라우트 `file.upload` → `decideUploadAs(user, level, team)`(파일과 같은 자격) → 부모가 있으면 `decideRead(parent)` + `fitsUnderFolder`. 이름 변경·이동·등급 변경·삭제: `decideFolderChange(actor, folder)` — Admin `admin_all` · Manager 본인 `owner` / 폴더 `team_id` = 자기 팀 `team_scope` / 그 밖 `team_mismatch`·`not_owner` · Employee 본인 `owner` / `not_owner` · Guest `role_not_permitted`. 등급 변경은 그 뒤 ②③. 이동은 새 부모 `decideRead` + `fitsUnderFolder` + 자기 자신·자손 금지(`cycle`) + 하위 깊이 재계산(`depth_exceeded`).

**파일 쪽 변경(5.2·5.6·5.7).** 업로드·가져오기 본문에 `folder_id`(선택) — 폴더 `decideRead` + `fitsUnderFolder(level, team, folder)` 를 등급 검사 바로 뒤에서. `PATCH /api/files/{id}` 허용 키에 `folder_id`(이동: 대상 폴더 `decideRead` + ①) ; 파일 등급 변경(`access_level`)도 폴더 안에 있으면 ① 검사(폴더보다 넓게 못 바꿈, `parent_narrower`).

**삭제(26단계 — PRD F-41).** 거부를 **세 갈래**로 가른다. ① 하위 폴더 있음 → 400 `folder_has_children` ② **살아 있는** 파일 있음(`deleted_at IS NULL`) → 400 `folder_not_empty` ③ 휴지통 파일만 남음 → 400 `folder_has_trashed` + **볼 수 있는** 휴지통 파일의 이름·삭제일 목록(최대 50, 안 보이는 것이 섞이면 `others: true` 만 — R-63), `?move_trashed=1` 로 확인하면 **같은 트랜잭션**에서 `UPDATE files SET folder_id = NULL WHERE folder_id=:id AND deleted_at IS NOT NULL AND purged_at IS NULL` 뒤 폴더 삭제(감사 `moved_trashed`·`moved_file_ids`). 살아 있는 파일은 **한 행도** 옮기지 않고, `deleted_at`·`purged_at` 도 손대지 않는다 — 휴지통의 「30일 안에 되살릴 수 있다」는 약속을 깨지 않기 위해서다(복구하면 루트로 살아난다). 18-1 에는 갈래가 하나여서, 휴지통 파일이 있으면 배치가 지울 때까지 폴더도 30일간 지울 수 없었다.

**화면.** 트리는 읽을 수 있는 폴더만(등급 배지), 「새 폴더」 등급 선택지는 `upload_levels` ∩ (부모 등급 이상), 파일 이동 모달은 대상 폴더 등급 이상인 파일만 허용(서버가 다시 검사). 전부 `textContent`.

### 5.17 일괄 업로드 — 화면 순차 요청 (PRD F-27 · 18-2, 2026-09-13)

**서버는 바뀌지 않는다.** `POST /api/files` 는 지금처럼 **요청당 파일 1개**(php-fpm `max_file_uploads=1`, `Upload::validate`)이며, 파일마다 확장자·MIME·크기·일일 총량·민감정보 검출·감사 행이 그대로다. 화면(app.js)이 `<input multiple>`·끌어 놓기로 받은 파일 목록을 **한 번에 하나씩 순서대로** 보낸다(동시 1) — 한 사용자가 워커 5개를 점유하지 못하게 하고, 일일 총량 검사가 파일 순서대로 누적되게 한다. 파일별 상태(대기·올리는 중·완료·거부 사유 문구)를 표로 그린다. 하나가 400 이어도 다음 파일로 계속하고, 401/403(세션·권한)이면 멈춘다. 선택 상한 50개(화면 상수), 공통 값은 등급·팀·폴더·설명(선택). 서버 쪽 유일한 변경은 없음 — 그래서 18-2 의 검토(T-41)는 "요청이 파일 수만큼 순서대로 남는가" 와 "한 요청에 두 파일이면 400 인가" 다.

---

### 5.18 (비었음) — 구매·재고 모듈이 있던 자리

2026-09-21 에 F-64 로 제거했다. 설계는 태그 `v1.13.0-with-purchase` 의 이 절과 `docs/purchase/` 에 있다.
절 번호는 5.19 이후가 흔들리지 않게 남긴다.

---

### 5.19 「사용 방법」 도움말 — 화면별 정적 글 (PRD F-38 · 화면 문구 정리 2단계, 사용자 결정 2026-09-15)

화면 문구 정리 1단계에서 화면에 늘 펼쳐져 있던 안내 68곳을 지웠다. 지운 것은 **자리**이지 **내용**이 아니다 —
업무 기준("폴더 이름에 사람 이름을 넣지 마세요", "요청은 미확인일 때만 고칠 수 있습니다")은 그 화면을 쓰는 사람에게
여전히 필요하다. 그래서 **단추 뒤**로 옮겼다.

| 무엇 | 어디 |
|---|---|
| 글 (내용) | `src/help/screens.php` — `'<키>' => ['title' => …, 'sections' => [['h' => …, 'items' => [ … ]], …]]` 인 **정적 PHP 배열 한 벌**. 값은 **순수 문자열**이고 HTML 조각(`<`)이 한 글자도 없다 |
| 키를 정하는 곳 | 화면 handler 한 줄 — `$pageHelp = 'files';` (`$pageKey` 옆). 로직은 건드리지 않는다 |
| 그리는 곳 | `src/views/shell_bottom.php` — 키가 배열에 있으면 `<dialog id="help-dialog" class="modal help-dialog">` 를 **한 번** 출력한다. 모든 문자열이 `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` 를 거친다 (PRD S-03) |
| 여는 곳 | 화면의 `.page-head__actions` **첫 자리**에 `data-help-open` 단추 하나(아이콘 `help` + 「사용 방법」). 모양·자리는 9화면이 모두 같다 |
| 여닫는 코드 | `public/assets/app.js` 의 `initHelpDialog()` — `document` 에 붙인 **위임 하나**. `[data-help-open]` → `showModal()`, `[data-help-close]` → `close()`. ESC 는 `<dialog>` 가 스스로 닫는다. `innerHTML` 계열을 쓰지 않는다 |
| 폭·스크롤 | `app.css` 끝의 `.help-dialog` — 폭 `min(640px, 92vw)`, 본문 `max-height: 70vh`(머리·바닥은 늘 보인다). 인라인 `style` 은 CSP(`style-src 'self'`)가 금지한다 |

**왜 라우트가 아닌가.** 도움말에 `/api/help/{key}` 같은 창구를 내면 **새 입구가 하나 늘고**, 그 입구마다
정책·인가·감사·속도 제한을 다시 정해야 한다. 그런데 이 글에는 지킬 비밀이 없다 — **그 화면을 열 수 있는 사람은
그 화면의 쓰는 법을 봐도 된다**. 화면 요청이 이미 라우트 정책(`authenticated`·`role:Admin`·`user.manage` …)과 재인증
관문을 통과했으므로, 도움말은 그 통과의 **부산물**로 같은 응답 안에 실려 나간다. 새 창구 0 · 새 표 0 · 새 권한 판단 0.

**왜 파일 하나인가.** 같은 설명이 화면 스무 곳의 마크업에 흩어져 있으면 언젠가 한 화면만 고쳐진다(25단계의
CSS 다섯 벌이 남긴 교훈과 같다). 글을 한곳에 모으면 "무엇을 안내하고 있나" 를 한 번에 읽고 고칠 수 있다.

**키가 틀리면 어떻게 되나.** `shell_bottom.php` 는 배열에 **없는 키면 아무것도 출력하지 않는다.** 오타가 화면을
깨뜨리지 않는다. 키는 파일 이름이 아니라 **배열의 첨자**라서 경로 조립이 한 군데도 없다(S-06 과 무관하다).

**무엇을 쓰지 않나.** 개발 진행 단계 번호(「N단계」)·요구사항 ID(S-xx·R-xx·T-xx)·내부 구조 설명
(「서버가 …」·「감사 로그에 남습니다」)을 도움말 문장에 쓰지 않는다. 읽는 사람은 그 화면을 쓰는 **업무 담당자**이고,
서비스가 **어떻게 만들어졌는지**는 종전대로 위키(PRD F-21, `/wiki`)가 맡는다. 두 글이 같은 것을 두 번 설명하면
언젠가 한쪽만 고쳐진다.

**대상이 아닌 화면.** 로그인·재인증·2단계 인증 등록·위키는 단추를 달지 않는다 — 앞의 셋은 화면 자체가 한 가지
동작만 시키는 관문이고, 위키는 그 자체가 해설이다.


### 5.20 「역할로 보기」 — 세션 안의 임시 역할 (PRD F-39 · 26단계, 사용자 결정 2026-09-15 A안)

관리자가 「Employee 에게는 이 화면이 어떻게 보이나」를 **직접 확인**하는 장치다. 계정을 하나 더 만들어
로그인하는 방식은 감사 로그에서 사람이 갈라지지 않고, 화면만 흉내 내는 방식은 서버 판정과 어긋나
「화면엔 안 보이는데 API 는 되는」 상태를 만든다. 그래서 **유효 역할 자체를 세션 안에서 바꾼다.**

**어디에 두나 — `sessions` 의 네 열** (`db/migrations/2026-09-16_sessions_act_as.sql`)

| 열 | 뜻 |
|---|---|
| `act_role` | 보는 t-box 역할. NULL 이면 보는 중이 아니다. CHECK 로 `Manager`·`Employee`·`Guest` 만 |
| `act_team_id` | 보는 팀 (NULL 이면 팀 없음) |
| `act_started_at` | 시작 시각 — 표시·감사용이고 **만료 판정에 쓰지 않는다** |
| `act_last_seen_at` | 보는 중 마지막 요청 시각 — **만료 판정의 기준** |

`users` 가 아니라 `sessions` 인 이유는 `reauth_at`(16-1)과 같다: 확인은 「이 기기, 이 창」의 일이고,
`users.role` 을 바꾸면 **실제 권한**이 바뀌어 되돌릴 사람이 없어질 수 있다(브라우저를 닫거나 세션이
끊기면 그 계정은 Employee 인 채로 남는다). 세션 행에 두면 로그아웃·세션 만료와 함께 저절로 사라진다.

**어디서 바뀌나 — `Auth::currentUser()` 한 곳**

```
SQL_LOOKUP  →  act_role, act_* 값 + DB 가 계산한 두 불린
                 act_role_ok = (act_role IS NOT NULL AND u.role = 'Admin')     ← 강등되면 거짓
                 act_fresh   = (act_last_seen_at > now() - 30분)               ← 활동 없는 창
셋 다 참이면  →  반환 배열의 role · team_id 를 act_* 값으로 바꾼다
                 real_role · real_team_id 는 언제나 실제 값
```

그 한 곳이 모든 핸들러·`Authz` 가 읽는 `$u` 를 만들므로 **판정 코드는 한 줄도
바뀌지 않는다** — 목록 WHERE·라우트 정책이 전부 그 역할로 동작한다(흉내가 아니다).
역할 문자열을 견주는 자리는 위 SQL 한 줄뿐이고, PHP 쪽에는 역할 비교가 생기지 않는다.
`scripts/security_lint.sh` 의 규칙 (f) 가 `act_role`·`real_role` 값 비교를 `Auth`·`Audit`·`me_act_*`
밖에서 찾으면 반려한다(본체 역할 문자열 비교 규칙과 같은 방식).

**아래로만 — 권한이 늘어나는 조합이 없다**

1. 켜기 라우트의 정책이 `role:Admin` 이다. **보는 중에는 유효 역할이 Admin 이 아니므로 그 라우트가
   스스로 403** 이다 — 겹쳐 켜기(Employee 로 보다가 Manager 로 갈아타기)가 불가능하고, 먼저 꺼야 한다.
2. 고를 수 있는 값이 Manager·Employee·Guest 뿐이다(핸들러 허용 목록 + DB CHECK 두 겹).
3. `role:Admin` 은 이미 `Authz::REAUTH_POLICIES` 에 있어 **재인증 관문이 자동으로** 따라붙는다.

**끄기는 `authenticated`.** 보는 중의 유효 역할로도 닿아야 원래대로 돌아올 수 있다. 끄기는 권한을
만들지 않는다 — 임시로 좁혀 둔 역할을 `users.role` 로 되돌릴 뿐이고, `users` 는 손대지 않는다.

**만료는 「활동 없는 30분」.** 요청마다 `act_last_seen_at` 을 미는 문장은 기존
`UPDATE sessions SET last_seen_at = now()` 에 한 열을 더한 것이라 **쿼리 수가 늘지 않는다**.
**Q:F-4 P-3(2026-09-24)** — 이 문장은 이제 **60초에 한 번만** 쓴다(`WHERE … AND last_seen_at < now() - interval '60 seconds'`).
요청마다 UPDATE 하던 것을 줄인 것이고, 두 열(`last_seen_at`·`act_last_seen_at`)은 같은 문장이라 함께 움직인다.
대가로 기록이 실제 마지막 활동보다 최대 60초 늦을 수 있어, 무활동 만료·역할로 보기 만료가 실제 무활동보다 **최대 60초 일찍** 닫힐 수 있다(늦게 닫히는 길은 없다 — SECURITY 7장 수용 근거). **Q:F-4 U-3** — 자동 요청(`X-Qaredi-Background: 1`, 알림 폴링·편집 heartbeat)은 이 문장을 부르지 않는다(두 열 다 밀지 않음 — 4.0 표). 그래서 보는 중 창도 사람이 누른 요청으로만 늘어난다. 쓰던 글 임시저장(B1-2)과 같은 커밋이다.
만료·강등을 발견한 요청이 그 자리에서 여섯 열을 NULL 로 되돌리고 `act.expire allow`(reason `idle`·
`role_lost`)를 한 줄 남긴다 — 배치를 두지 않는 이유는 reauth 창과 같다(지났는데 아직 열려 있는 틈을
만들지 않는다). 「시작 뒤 30분」이 아니다: 쓰는 중에 끊기면 관리자는 자기가 무엇을 보는지 알 수 없다.

**감사.** `actor_role` 열은 **실제 역할**이다(「누가」는 사람이다). 무슨 역할로 보였는지는
`Audit::autoDetail()` 이 붙이는 `detail.acting_as {role, team_id}` 와
`detail.real_role` 에 남는다 — 자동 키라 핸들러가 빠뜨릴 수도 덮어쓸 수도 없다.

**셸이 서버에서 띠를 그린다.** 보는 중에는 `can_admin` 이 거짓이라 사이드바 「관리」 묶음이 닫힌다.
그러면 복귀 경로가 띠의 「원래대로」 하나뿐인데, 그 하나를 스크립트가 못 그리는 상황에 잃으면
사용자는 30분을 기다려야 한다. 그래서 `shell_top.php` 가 `$user['acting']` 으로 직접 그린다
(이 파일에도 역할 비교는 없다 — 판정 결과인 불린 하나만 본다).


### 5.21 생성기의 시각 맞추기 — `GET /api/time` (PRD F-42 · F-46, 사용자 결정 2026-09-15)

2단계 코드 생성기(`public/assets/totp-generator.html`)가 내는 코드는 **시각의 함수**다
(스텝 = `시각 ÷ 30`). 그래서 그 PC 의 시계가 몇십 초만 어긋나도 화면의 코드가 서버에서 거부된다.
전에는 생성기가 `Date.now()`(PC 시계)를 그대로 썼고, 화면의 「남은 초」와 실제 인증 초가 갈렸다.

**창구는 하나, 내주는 것도 하나.** `GET /api/time` 은 `{"now": <유닉스 초>, "now_ms": <유닉스 밀리초>, "period": 30}` 만 돌려준다
(`src/handlers/time.php`, 정책 `POLICY_PUBLIC`). `now_ms` 는 보안 넷(2026-09-26, SECURITY R-79)이 더했고 `now` 는 그 몫(초)
그대로다 — 이미 내려받아 둔 옛 생성기 사본은 `now` 만 읽는다. 버전·서버 이름·설정 값은 담지 않는다 — `/api/health` 와 같은
원칙이다(PRD S-09-5 취지). 로그인·CSRF·감사 기록이 없다: 읽기만 하고 대상이 없으며, 같은 값이 응답 헤더
`Date` 로도 이미 나간다. `Cache-Control: no-store` 는 중간 캐시가 지난 시각을 되돌려 주지 못하게 한다.

**CORS 는 이 창구에만.** 생성기는 PC 에 내려받아 `file://` 로 여는 것이 전제라 출처가 달라진다. 여기서
나가는 것은 시각 하나뿐이라 다른 출처가 읽어도 잃을 것이 없고, 그래서 `Access-Control-Allow-Origin: *`·
`Access-Control-Allow-Methods: GET` 을 **이 핸들러 안에서만** 붙인다. 세션 쿠키로 내용이 갈리는 응답에
같은 헤더가 붙으면 곧 정보 유출이므로, 다른 라우트에는 없다(T-55 (a) 가 `/api/health`·`/login` 으로 확인한다).

**보정 공식.** 생성기는 보낸 때 `t0`, 받은 때 `t1`(둘 다 PC 시계)을 재고

```
offset = now_ms + (t1 − t0) ÷ 2 − t1        (밀리초, 「서버 − PC」 · now_ms 가 없는 옛 서버면 now × 1000)
serverNow() = Date.now() + offset
```

왕복 지연의 절반을 더해 **답이 만들어진 순간**을 `t1` 에 맞춘다. `tick()` 과 코드 계산은 `serverNow()` 만
쓴다 — 화면의 남은 초와 서버의 스텝 경계가 같아진다. **화면 갱신은 초 경계에 맞춘다**(보안 넷 R-79): `tick()` 은
1초 간격 반복이 아니라 `serverNow()` 의 다음 초 경계 + 20ms 에 잡은 `setTimeout` 으로 돈다(30초 스텝 경계도 초 경계다).
전에는 `now` 가 정수 초라 보정값이 0~1초 뒤처지고 갱신 박자도 경계와 무관해, 경계 직후 1~2초 동안 이전 스텝 코드가
보였다 — 서버 창이 0 이라 그 코드는 401 이었다. 서버의 판정(창 0)은 바꾸지 않았다.

**오프라인.** `{offset, at, base}` 를 `localStorage['tbox-totp-offset']` 에 남겨, 다음에 서버에 닿지 못해도
마지막으로 맞춘 차이로 계산한다(비밀 키는 이 값에 들어가지 않는다). 상태 줄은 세 가지 —
「서버와 동기화됨 · 서버 시각 HH:MM:SS · PC 와 ±N초」 / 「오프라인 · 저장된 보정값 사용(마지막 동기화 M분 전)」 /
「오프라인 · PC 시각 사용」. 차이가 ±60초를 넘으면 경고색이다. `file://` 로 열렸고 적어 둔 주소가 없으면
t-box 주소 입력칸을 한 번 보여 준다(`https?://` 로 시작하는 값만 받는다).

**CSP.** 생성기 전용 location 의 `default-src 'none'` 은 어떤 연결도 막으므로 `connect-src 'self'` 를 더한다
(`deploy/sites/tbox-v2.conf`, 파일 안 `<meta>` 도 같다). `'self'` 라서 **이 서버 말고는 어디로도 보낼 수 없다** —
생성기가 비밀 키를 밖으로 내보내는 경로를 만들지 않는 것이 이 한 칸의 요점이다. 적용 전에는 fetch 가 막혀
상태 줄이 「오프라인」 이 되며, 그 상태에서도 코드는 PC 시계로 계속 나온다(기능이 멈추지 않는다).

**이름 (F-46).** 화면·문서의 이름은 「t-box 2단계 코드 생성기」다. 「오프라인」을 뺀 이유는
그것이 **계산 방식**(키를 보내지 않는다)을 가리키는 말인데 **접근 경로**(인터넷으로 열린다)로
읽혔기 때문이다(사용자 관찰 2026-09-15). 상태 줄의 「오프라인 · …」 세 문구는 **그대로 둔다** —
거기서는 정확히 「서버 시각을 못 받았다」는 동기화 상태를 뜻한다.

**휴대폰에서 쓰기 (F-46).** 인증 앱이 광고·결제를 요구해 못 쓰는 사람을 위해, 생성기를
**휴대폰 브라우저**에서 쓸 수 있게 한다. 만드는 것은 앱(APK)이 아니라 **브라우저의 바로가기**다.

| 무엇 | 어디 | 왜 |
|---|---|---|
| `@media (max-width: 480px)` | 생성기 `<style>` | 입력칸·버튼이 화면 폭을 다 쓰고, 누르는 것은 44px 이상, 코드 글자는 40px. 가로 스크롤이 생기지 않아야 한 손으로 쓴다 |
| `totp-generator.webmanifest` | `public/assets/` | 「홈 화면에 추가」의 이름·아이콘·시작 주소·`display: standalone` 를 정한다. **아이콘은 `data:` SVG** 라 파일 밖에서 받아오는 것이 하나도 없다 |
| `<meta name="theme-color">` · `apple-touch-icon` | 생성기 `<head>` | 홈 화면 아이콘과 주소 표시줄 색. iOS 가 SVG 아이콘을 못 읽으면 그냥 무시된다 |
| `manifest-src 'self'` | 생성기 location 의 CSP · 파일 안 `<meta>` | `default-src 'none'` 아래에서는 매니페스트도 막힌다. 한 칸만 연다 — **여전히 이 서버 말고는 어디에도 닿지 못한다** |
| `location = /assets/totp-generator.webmanifest` | `deploy/sites/tbox-v2.conf` | 정적 파일 하나. 로그인이 필요 없고(생성기와 같은 성격), `types { application/manifest+json webmanifest; }` 로 형식을 정한다 |

생성기가 **보내는 것은 여전히 없다**. 휴대폰에서도 비밀 키는 그 브라우저 밖으로 나가지 않으며,
받는 것은 `GET /api/time` 의 시각 하나뿐이다(5.21 위). 다만 **그 휴대폰이 두 번째 겹**이므로
로그인하는 PC 와 다른 기기여야 의미가 있다 — R-40 의 조건이 여기서도 그대로다.


### 5.22 URL 가져오기 — 허용 사이트 표 · 공개 링크 변환 · 통제된 리다이렉트 (PRD F-43, 사용자 결정 2026-09-15)

7단계(F-14)가 만든 SSRF 방어의 뼈대 셋은 그대로다 — ① 갈 수 있는 곳을 미리 정해 둔다 ② 검사한 곳과
실제 가는 곳이 같다 ③ 가져온 것을 업로드와 똑같이 검사한다 (5.6). F-43 이 바꾼 것은 **①의 값이 어디서
오느냐**와 **3xx 를 어떻게 다루느냐** 둘뿐이다.

**허용 목록의 자리.** `.env` 의 `FETCH_ALLOWED_HOSTS` 한 줄에서 표 `fetch_hosts` 로 옮겼다.

| 어디 | 무엇 | 왜 |
|---|---|---|
| `fetch_hosts` (표) | `host`(UNIQUE, 소문자, 점 하나 이상, `*` 금지 — DB CHECK) · `note` · `is_active` · `created_by` · 시각 | 서버에 들어갈 수 있는 사람만 고칠 수 있고 **고친 기록이 남지 않던** 값을, 누가·언제·왜 바꿨는지가 감사 로그에 남는 값으로 바꾼다 |
| `.env` (그대로 둠) | 같은 형식의 비상 통로. 지금은 비어 있다 | DB 가 이상해 화면으로 못 고치는 상황에서도 열 수 있어야 한다 |
| `Fetch::allowedHosts()` | 위 둘의 합집합. 표 쪽 값도 읽는 자리에서 `HOST_RE`·`*` 검사를 **다시** 받는다 | DB 를 직접 고친 행이 있어도 와일드카드가 목록에 들어가지 않는다(닫힌 방향으로 실패) |

**목록을 바꾸는 창구는 하나다** — `POST /api/fetch-hosts`(`src/handlers/fetch_hosts_update.php`). 조건은 권한
매트릭스 편집(5.15)과 같은 급이다: ① `role:Admin` ② 재인증 15분 창(정책이 `REAUTH_POLICIES` 에 있어
관문 [3-2] 가 자동으로 붙는다) ③ CSRF ④ 요청마다 인증 앱 코드(`Totp::verifyStep` + `mfa_last_step` 1회성)
⑤ 사유 1~500자 ⑥ 호스트 형식 규칙 ⑦ 한 트랜잭션 + 호스트마다 감사 `fetch_host.change allow` + 경보 AL-18.

권한 매트릭스와 **다른 점 하나**: 별도 DB 롤(`Db::perm`)을 쓰지 않는다. 그 롤은 `permissions` 표 하나를
위한 것이고(R-60), 여기에 자격을 더하면 그 롤이 닿는 표가 늘어난다. 이 표의 방어는 「앱 롤에 DELETE 가
없다 + 창구가 하나 + 코드·사유·재인증·감사·경보」다.

**삭제가 없다.** `DELETE` 라우트도, 앱 DB 계정의 DELETE 권한도 없다(`\dp fetch_hosts` = `tbox_app=arw`).
허용에서 뺀 호스트도 "언제부터 언제까지 열려 있었나" 가 조사에 필요하기 때문이며, `teams.is_active`·
`share_links.revoked_at` 과 같은 방식이다.

**화면이 보는 값.** `/api/me` 에 둘이 늘었다.

| 키 | 뜻 | 누구에게 |
|---|---|---|
| `fetch_enabled` | 활성 호스트가 하나라도 있나 | 모두 |
| `fetch_hosts_public` | 활성 호스트 이름 배열 | `can_fetch` 인 사람에게만(Manager 이상). Employee·Guest 응답에는 **키가 없다** |

홈의 「URL 로 가져오기」 단추는 `can_fetch && fetch_enabled` 일 때만 열린다 — 목록이 비면 어떤 주소도
`host_not_allowed` 이므로 **눌러도 반드시 실패하는 단추**를 보여 주지 않는다. 「사용 방법」 도움말(files)은
`fetch_hosts_public` 으로 `<ul data-fetch-hosts>` 를 채운다(app.js 가 만들고 textContent 로만 넣는다).
셋 다 화면 편의 값이며 권한이 아니다 — 무엇이 허용인지는 요청마다 `Fetch::check` 가 다시 판단한다.

**공개 링크 변환 — `src/CloudLink.php`.** 사람이 손에 쥐는 주소는 **보는 주소**(`…/file/d/<ID>/view`)라
그대로 가져오면 파일이 아니라 웹 페이지가 저장된다. 규칙표는 이렇다(호스트는 정확 일치, 경로·ID 는 정규식).

| 들어온 주소 | 나가는 주소 | provider |
|---|---|---|
| `drive.google.com/file/d/<ID>/…` · `drive.google.com/open?id=<ID>` | `https://drive.google.com/uc?export=download&id=<ID>` | google |
| `docs.google.com/{document\|spreadsheets\|presentation}/d/<ID>/…` | `…/export?format={docx\|xlsx\|pptx}` | google |
| `onedrive.live.com/…` | 원 주소 + `download=1` | microsoft |
| `1drv.ms/…` | 그대로. `onedrive.live.com` 으로 302 한 **다음 단계**에 `download=1` 을 붙인다(`CloudLink::followUp`) | microsoft |
| `<tenant>.sharepoint.com` · `<tenant>-my.sharepoint.com` | 원 주소 + `download=1` (**F-46 에서 바뀜** — 전에는 변환 없이 `needs_login = true` 였다) | microsoft |
| 그 밖 | 그대로 | null |

`CloudLink` 는 **순수 함수**다 — cURL·DNS 가 한 줄도 없고, 허용 여부를 판단하지 않는다. 바꾼 주소도
`Fetch::check` 를 똑같이 지나므로, 관리자가 `drive.google.com` 을 목록에 넣지 않았으면 변환해도 거부된다.
**변환은 편의이지 관문이 아니다** (S-01 과 같은 원칙). 파일 ID 는 base64url 글자만 받고, 그 밖이면 변환하지
않는다(애매한 문자열을 억지로 해석해 만든 주소로 나가지 않는다).

**통제된 리다이렉트.** 드라이브의 `uc?export=download` 는 `drive.usercontent.google.com` 으로, `1drv.ms` 는
`onedrive.live.com` 으로 302 한다. 7단계의 "3xx 는 전부 거부" 로는 둘 다 가져올 수 없다. 그래서 따라가되
**따라가는 주체를 우리로 둔다**.

```
[1] check(첫 주소)  ── https · 443 · 호스트 표기 · 허용 목록 · DNS → 사설 IP 차단
[2] request()       ── CURLOPT_FOLLOWLOCATION = false, CURLOPT_RESOLVE 로 IP 고정
     │  200 → [5]
     │  3xx → Location 을 읽는다 (없으면 redirect 거부)
[3]  ├─ absolute()        상대 주소를 지금 주소 기준 절대 주소로 (호스트는 못 바꾼다)
     ├─ CloudLink::followUp()  1drv.ms → onedrive.live.com 이면 download=1 (질의만 더함)
     ├─ redirectStep()    횟수(≤3) · https · 443 · 호스트 표기 · **같은 허용 목록** — 네트워크 0
     │                    (F-46) 공급자의 내려받기 호스트면 허용 목록 대신 통과 — 아래 표
     ├─ check(다음 주소)  ★ 처음부터 다시. DNS 해석 뒤 사설 IP 차단까지 첫 주소와 똑같이
     └─ cookieHeader()    (F-46) 다음 호스트가 **직전과 같을 때만** 직전 응답의 쿠키를 싣는다
        → [2] 로 (hops 에 호스트를 하나 더 적는다)
[4] 4번째 단계 · Location 없음 · 검사 실패 → FetchDenied(redirect|scheme|port|host_not_allowed)
[5] Content-Type 이 text/html 이고 provider 가 있으면 → FetchDenied(needs_login)
[6] 크기 확인 → Upload::validatePath (5.6 [8] 과 같은 함수)
```

`redirectStep()` 이 `check()` 와 **따로 있는** 이유는 둘이다. ① 시험용 https 서버를 세우지 않고도
`php -r` 로 단계 판단을 확인할 수 있어야 한다(T-56 (f)). ② 같은 검사를 두 번 하므로 한쪽만 고쳐지는 날에도
다른 한쪽이 남는다. `CURLOPT_FOLLOWLOCATION` 이 `true` 이거나 `curl_exec` 가 있는 파일에 `check()` 가 없으면
`scripts/security_lint.sh` 규칙 (g)가 반려한다.

**`needs_login`.** 이제 **한 자리**에서만 난다 — 공급자 링크인데 200 응답의 `Content-Type` 이 `text/html`
일 때(로그인 화면)다. F-46 전에는 `CloudLink` 가 SharePoint 주소를 보자마자 요청 전에 거부했는데, 그것이
**공개 링크까지 막고 있었다**(사용자 실측 2026-09-15). 판단을 「주소 모양」이 아니라 「돌아온 것」에 맡긴다 —
`download=1` 을 붙여 실제로 요청해 보고, 파일이 오면 파일이고 로그인 화면이 오면 거부다.
이 거부만 **다른 가져오기 실패와 문구가 다르다**:
요청자가 고쳐서 다시 할 수 있는 일(공유 설정을 바꾸거나 내려받아 올리기)이고 내부 상태를 한 글자도 알려
주지 않는다 — 민감정보 거부(5.13)와 같은 판단이다. 서버가 대신 로그인하는 길(OAuth)은 **만들지 않는다**
(사용자 결정 2026-09-15: 「전체 공유된 링크만 지원하면 인증이 필요 없다」).

**F-46 ① 리다이렉트 사이의 쿠키 — 같은 호스트로 가는 다음 단계에만.**
SharePoint 의 공개 링크는 `download=1` 로 302 한 뒤 **같은 호스트**의 파일 주소로 보내면서
`Set-Cookie: FedAuth=…` 를 준다. 쿠키 없이 따라가면 `403`(text/plain)이고, 그 쿠키를 실으면 200 에
355KB 엑셀이 온다(사용자 실측 2026-09-15). 그래서 단계 사이에 쿠키를 넘기되 규칙을 좁게 둔다.

| 규칙 | 값 | 왜 |
|---|---|---|
| 언제 싣나 | **다음 단계 호스트 == 직전 호스트** 일 때만 | 호스트가 바뀌면 버린다. 쿠키가 다른 사이트로 새는 길을 만들지 않는다 |
| 무엇을 싣나 | 직전 응답 `Set-Cookie` 의 **이름=값만** (첫 `;` 앞까지) | `Path`·`Domain`·`Secure`·`HttpOnly` 는 브라우저가 **저장할 때** 쓰는 값이지 보낼 때 쓰는 값이 아니다 |
| 상한 | 20개 · 합계 8KB. 넘으면 **자르지 않고 쿠키 없이** | 반쪽짜리 쿠키는 상대 서버에 무엇이 갈지 예측할 수 없는 상태를 만든다 |
| 버리는 줄 | 이름이 토큰 글자가 아니거나 값에 제어문자·공백·`;`·`,` | 헤더 주입 |
| 기록 | 감사 `detail.cookie_hops`(쿠키를 실은 **단계 수**)만. **값은 어디에도 없다** | 조사에 필요한 것은 「썼나·몇 번」이지 값이 아니다 |

구현은 `Fetch::cookieHeader()`(순수 함수, 네트워크 0)와 `request()` 의 `CURLOPT_COOKIE` 한 줄뿐이다.
`CURLOPT_COOKIEJAR`·`COOKIEFILE` 은 쓰지 않는다 — 파일에 쌓이면 요청 사이에 남고, 남으면 언젠가 다른
호스트로 간다.

**F-46 ② 공급자의 내려받기 호스트 — 코드 상수, 리다이렉트 단계에서만.**
구글 시트의 `…/export?format=xlsx` 는 307 로 `doc-08-9g-sheets.googleusercontent.com` 처럼 **매번 바뀌는**
이름으로 넘긴다(사용자 실측 2026-09-15, 감사 15:00). 정확 일치 허용 목록으로는 담을 수 없다.

| | |
|---|---|
| 어디에 | `Fetch::PROVIDER_DOWNLOAD_SUFFIXES` — **코드 상수**. 관리자가 화면에서 못 바꾼다 |
| 값 | google → `.googleusercontent.com` · `drive.usercontent.google.com` / microsoft → `.files.1drv.com` · `.sharepoint.com` |
| 언제 | **리다이렉트 다음 단계에서만**. 첫 요청은 언제나 관리자 허용 목록의 **정확 일치**다 |
| 조건 | 첫 주소의 공급자가 그 칸의 공급자일 때만. `provider` 가 null 이면 이 곁길이 아예 없다 |
| 모양 | `.` 로 시작하면 접미(앞에 라벨이 하나 이상), 아니면 정확 일치 → `evil.googleusercontent.com.attacker.tld` 는 맞지 않는다 |
| 그대로인 것 | https · 443 · 호스트 표기 · DNS 뒤 사설 IP · 최대 3단계 · `FOLLOWLOCATION` false · 감사 `hops` 에 실제 호스트 |

화면에서 넣는 허용 목록의 **와일드카드 금지 규칙은 그대로다**. 회전 도메인을 관리자가 손으로 넣을 수
없으니 코드에 적어 두고 검토를 받는 것이며, 이 값을 늘리는 일은 커밋으로만 가능하다.


### 5.23 버전과 변경 이력 — `VERSION` · `CHANGELOG.json` · 「변경 이력」 모달 (PRD F-47, 사용자 결정 2026-09-15)

**번호 규칙.** `X.Y.Z` 세 자리. X 는 **대규모**(뽐낼 만한 업데이트), Y 는 **중규모**(공치사 정도), Z 는
**버그 수정 등 작은 것**. 위 자리가 오르면 아래 자리는 0 으로 돌아간다. 등급은 발행하는 사람(Fable, 관문 ④)이
정하되 기본은 patch, 새 화면·새 기능(F-xx·N단계)은 minor, **major 는 사용자가 말했을 때만** 올린다.

**진실은 한 곳.** 현재 버전은 저장소 루트 `VERSION` 한 줄, 이력은 `CHANGELOG.json` 한 벌(최신이 첫 항목).
`CHANGELOG.md` 는 `scripts/changelog_md.php` 가 JSON 에서 **생성**하는 파일이라 손으로 고치지 않는다
(T-59 (a) 가 byte 동일을 검사한다). 세 파일이 어긋나는 일은 `scripts/release.sh` 만 쓰면 생기지 않는다.

```
CHANGELOG.json 항목
{ "version": "1.2.0",              ^\d+\.\d+\.\d+$
  "level":   "minor",              major | minor | patch
  "released_at": "2026-09-15 18:30",  서버 시각(KST) — 「몇 날 몇 시에 올렸나」
  "title":   "한 줄 제목",
  "changes": ["무엇이 변했나 …", "…"] }   사용자 말로. T-xx·R-xx·파일 이름 없음(5.19 「사용 방법」 규칙과 같다)
```

**발행 한 번 = 커밋 하나 + 태그 하나.**

```
scripts/release.sh <major|minor|patch> "제목" "변경 1" ["변경 2" …] [--no-push] [--dry-run]
  0) git status --porcelain 에 수정·스테이징 파일이 있으면 거부(untracked 는 허용) — 기능 커밋을 먼저 하고 발행한다
  1) VERSION 읽어 다음 번호 계산 · 같은 이름의 태그가 이미 있으면 거부
  2) CHANGELOG.json 맨 앞에 항목 추가(released_at = 지금, php json_encode UNESCAPED_UNICODE|PRETTY_PRINT)
  3) VERSION 갱신 · scripts/changelog_md.php > CHANGELOG.md
  4) git add VERSION CHANGELOG.json CHANGELOG.md  (-A 금지) · commit "vX.Y.Z: 제목" · tag -a vX.Y.Z
  5) git push origin main && git push origin vX.Y.Z   (--no-push 면 생략)
```

**화면.** `src/Changelog.php` 가 두 파일을 읽어 검증(형식이 틀린 항목은 버리고 `error_log`)한 배열을 돌려주고,
`shell_top.php` 가 사이드바 아래(사용자 칩 위)에 `v1.0.0` 단추(`data-changelog-open`)를, `shell_bottom.php` 가
`<main>` 밖에 `<dialog id="changelog-dialog" class="modal">` 를 그린다 — 「사용 방법」 모달(5.19)과 **같은 뼈대·같은
CSS·같은 열고 닫는 JS 방식**이다. 내용은 서버가 그린 정적 문자열이고 **모든 값이 `htmlspecialchars` 를 거친다**
(S-03). 새 라우트·API 는 없다(`public/index.php` 는 손대지 않는다).

**보이지 않는 곳.** 로그인 전 화면(`login_page.php`)·`/api/health`·`/api/time` 에는 버전을 내지 않는다 —
버전은 「어떤 코드가 돌고 있나」를 알려 주는 정찰 재료이고, 로그인한 사람에게만 보이면 목적(무엇이 바뀌었나
알리기)은 충분하다. 자산 주소의 `?v=filemtime` 은 캐시용이며 이 버전과 무관하다.

**깃.** 태그 `vX.Y.Z` 는 릴리스 커밋을 가리키고 원격(`origin`)에도 올라간다. `git tag -l`·`git describe --tags` 로
어느 코드가 어느 버전인지 알 수 있고, 되돌릴 때는 `git checkout v1.0.0` 처럼 태그 이름으로 간다.

### 5.24 상태 확인 — `GET /api/health` 의 DB 확인 (PRD F-48, 사용자 요청 2026-09-15)

**무엇을 하나.** 1단계부터 있던 `GET /api/health`(정책 `POLICY_PUBLIC`, `src/handlers/health.php`)는 `{"ok":true}` 만 돌려줬다 —
PHP 까지 살아 있다는 뜻일 뿐, 2026-09-15 16:45 사고(디스크 0 → PostgreSQL PANIC → 사이트 503, SECURITY R-80)처럼 **DB 만 죽은 상태**를
구분하지 못했다. F-48 부터는 `Db::instance()->run('SELECT 1')` 한 번을 해 보고 답한다.

| 상황 | 상태 | 본문 | 비고 |
|---|---|---|---|
| DB 가 답함 | 200 | `{"status":"ok","db":"ok","time":"2026-09-15T19:02:11+09:00"}` | `time` 은 `DATE_ATOM`(ISO 8601, 서버 시간대 Asia/Seoul). 시각은 `/api/time`·`Date` 헤더로 이미 나가는 값 |
| 연결 자체가 안 됨 | 503 | `{"status":"fail","db":"connect_failed"}` | 연결이 실패한 경우(Q:F-4 P-5c 부터 연결은 부팅이 아니라 health 의 `Db::ready()` 에서 연다 — 5.31) |
| 연결은 됐는데 질의 실패 | 503 | `{"status":"fail","db":"query_failed"}` | `run('SELECT 1')` 이 던진 경우 |

**부팅 순서의 변화 — `Db::boot` 는 실패를 삼키고 기억한다.** 전에는 bootstrap 5) 의 `Db::boot` 가 `new PDO` 실패로 예외를 던져
**모든 요청**이 예외 처리기의 500 `{"error":"서버 오류"}` 로 끝났다 — health 핸들러까지 오지도 못하므로 503 을 낼 수 없었다.
이제 `Db::boot` 는 `PDOException` 을 잡아 서버 로그(`request_id` + 예외 문구)에만 적고 `연결 실패` 표식을 남긴다. 그 뒤 `Db::instance()` 는
표식이 있으면 `RuntimeException` 을 던진다 → health 를 뺀 나머지 창구는 **전과 똑같이** 500 이다(`Auth::requireLogin` 의 첫 질의에서).
DB 없이 렌더되는 것은 `/login` 화면·`/api/time`·404/405 정도이고, 이들은 DB 를 읽지 않으므로 달라지는 것이 없다.
예외 처리기의 `Db::instance()->rollback()` 은 표식이 있으면 부르지 않는다(`Db::ready()`) — 로그에 「rollback failed」 잡음을 남기지 않기 위해서다.
**(Q:F-4 P-5c, 2026-09-24)** 연결 시도가 부팅에서 **첫 질의**로 옮겨졌다(5.31). 표식은 그대로 「이 요청에서 연결 실패」이고(`connectFailed`),
실패 뒤의 결과(health 503 · 나머지 500 · 원문은 서버 로그만)도 같다. 달라진 것은 두 가지다 — `Db::ready()` 는 아직 연결하지 않았으면 **연결을 시도**하고,
예외 처리기는 연결을 **열지 않고** 묻는 `Db::connected()` 로 롤백 여부를 가른다(롤백하려고 새로 연결하지 않는다).

**왜 `db` 가 고정 분류어인가 (S-09-7).** PDO 의 오류 문구는 `connection to server at "127.0.0.1", port 5432 failed: FATAL: password
authentication failed for user "tbox_app"` 같은 형태다 — 호스트·포트·계정 이름이 들어 있다. 사용자 요청의 「에러 요약」은 응답에는
두 분류어 중 하나로만 나가고, 원문은 `error_log` 에 `request_id` 와 함께 남는다(5.23 의 「보이지 않는 곳」 원칙과 같다).
정찰 재료가 되는 값(버전·서버 이름·설정)은 F-47 ④ 그대로 넣지 않고, CORS 헤더도 F-42 그대로 없다.

**남는 위험(SECURITY R-81).** 로그인 없이 부를 수 있는 창구가 요청마다 DB 질의 하나를 하게 됐다. `SELECT 1` 은 표를 읽지 않고
연결 풀도 없으므로(요청당 연결 1 — Q:F-4 P-2 뒤로는 **워커당** 연결 1, 5.31) 비용은 연결 한 번과 같고, 이미 모든 로그인 화면 요청이 같은 비용을 낸다. IP 속도 제한 존을 따로
붙이지 않았다 — 필요해지면 `tbox_login` 과 같은 방식으로 `location = /api/health` 에 붙인다.

**API 문서 `API.md`.** 저장소 루트에 둔다(README 와 같은 자리). `public/` 이 nginx 루트라 그 안의 `.md` 는 로그인 없이 내려받힌다 —
문서가 곧 정찰 재료가 되므로 웹 루트 **밖**이다. 외부 프로그램이 부를 창구(상태 확인·로그인·내 정보·업로드·로그아웃)의 주소·요청·응답
예시를 적고, API 가 늘 때마다 이어 적는다(PRD 8장 표가 요구사항, `API.md` 는 사용 설명이다 — 둘이 어긋나면 8장이 옳다).

### 5.25 2단계 인증 등록 화면의 QR 코드 — `src/Qr.php` (PRD F-51, 사용자 결정 2026-09-16)

**무엇이 바뀌나.** 10단계부터 등록 화면은 비밀 키(32자)와 `otpauth://` 주소를 **글자로만** 보여 줬다 — QR 라이브러리를 넣지 않기 위해서였다(`src/Totp.php` 머리말). 인증 앱(Google Authenticator 등)의 정상 경로는 「QR 코드 스캔」이고, 「설정 키 입력」은 32자를 손으로 옮겨 적는 예외 경로다. F-51 부터 같은 `otpauth` 문자열을 **QR 로도** 보여 준다. 텍스트·링크는 그대로 남는다(카메라가 없는 PC 생성기 사용자, 링크로 바로 넣는 휴대폰 사용자).

**어디서 만드나 — 서버(PHP), 그림은 화면(canvas).**

| 단계 | 누가 | 무엇 |
|---|---|---|
| ① | `src/handlers/mfa_enroll.php` | 비밀을 만들어 저장한 뒤 `Qr::encode(otpauth)` 를 부른다. 결과 `{version,size,rows}` 를 응답 `qr` 에 넣는다. 던지면 `qr:null` — 텍스트 등록은 그대로 된다 |
| ② | `src/Qr.php` (새 파일, 외부 의존 0) | ISO/IEC 18004 인코더 — 바이트 모드, 오류정정 **M**, 버전 **1~10 자동**(들어가는 가장 작은 것), 리드-솔로몬(GF(2⁸), 원시다항식 0x11D), 마스크 8종 벌점(N1=3·N2=3·N3=40·N4=10) 선택, 형식 정보 BCH(15,5)+0x5412, 버전 7 이상은 버전 정보 BCH(18,6). 출력은 **모듈 행렬**(`rows`: `'0'`/`'1'` 문자열 배열, 여백 없음). 이미지·SVG·파일을 만들지 않는다 |
| ③ | `public/assets/app.js` `initAccount` | 응답 `qr` 가 모양 검사를 통과하면(`size` 정수, `rows` 길이 = size, 각 행 길이 = size, `0`/`1` 만) `<canvas id="mfa-qr">` 에 `fillRect` 로 그린다. 모듈 4px, 여백 4모듈(규격의 quiet zone). 통과하지 못하면 canvas 를 숨긴다. 확정·재등록·페이지 이동 시 지운다 |

**왜 서버에서 만드나.** ⓐ `php -r` 로 **단위 시험**이 되고 파이썬 `qrcode` 와 행렬을 비트 단위로 대조할 수 있다(PRD T-63 (a)) — 브라우저 JS 인코더는 같은 대조를 Playwright 안에서 해야 해 시험이 무겁다. ⓑ `app.js` 에 300줄을 더하지 않는다(모든 화면이 내려받는 파일). ⓒ 등록 슬롯이 둘(`/settings`·강제 절차 `/`)이지만 응답 하나로 둘 다 같은 그림을 얻는다.

**왜 이미지가 아니라 행렬인가.** PNG 는 GD 확장(현재 없음, `deploy/DEPENDENCIES.md` 3장)이 필요하고, SVG 문자열은 화면에 **마크업으로 주입**해야 해 S-03(innerHTML 금지)과 충돌한다. `0/1` 행렬은 다른 모든 값과 같이 **데이터로만** 다뤄지고, 그리는 쪽은 `fillRect` 좌표 계산뿐이다. `data:` URL 도 만들지 않으므로 CSP(`img-src 'self' data:`)와 무관하다.

**비밀은 어디까지 가나.** 전과 같이 **enroll 응답 한 곳**이다(`Cache-Control: no-store`). `rows` 는 `otpauth` 의 다른 표현이라 같은 등급의 비밀이며, 감사 로그 `detail` 에 넣지 않는다(`FORBIDDEN_DETAIL_KEYS` 의 `secret`·`mfa` 가 이름으로 막고, 핸들러는 애초에 감사를 남기지 않는다 — 켠 사실은 `mfa_confirm` 이 남긴다). `Qr.php` 는 로그를 쓰지 않는다.

**`Qr.php` 가 받는 입력.** 사용자 입력이 아니다 — 서버가 `random_bytes` 로 만든 비밀과 `Auth::USERNAME_RE`(`^[a-z0-9_.-]{3,64}$`) 를 통과한 아이디를 `Totp::otpauthUri` 가 `rawurlencode` 한 문자열이다. 최대 길이는 172바이트(아이디 64자)이고 버전 10-M 의 용량은 213바이트라 **항상 들어간다**. 그래도 던질 수 있게 두는 이유는 다음 사람이 `ISSUER` 를 길게 바꾸거나 다른 곳에서 `Qr::encode` 를 재사용할 때 **조용히 깨진 그림**을 내지 않기 위해서다.

**남는 위험(SECURITY R-84).** 등록 순간의 화면을 찍으면 텍스트 비밀 키와 똑같이 비밀이 새어 나간다 — 창구·순간이 같으므로 노출 면적이 **늘지는 않는다**. 시험용 파이썬 `qrcode` 는 `~/.local` 에만 있고 앱·배포·`install.sh` 어디에도 닿지 않는다.

### 5.26 2단계 인증 필수 범위 — Admin 만 필수, 나머지는 권장 (PRD F-52, 사용자 결정 2026-09-16)

**무엇이 바뀌나.** 11단계(F-16 ③)는 「모든 역할 필수」였다. 사용자 결정 2026-09-16: 「다른 사이트는 OTP 가 선택인데 우리는 과하다 — Admin 권한자만 필수, 게스트·직원은 선택, 다만 권장하는 절차는 필요」. 그래서 **필수는 Admin 만**, 나머지는 켜지 않아도 모든 기능을 쓰되 켜라고 **권한다**.

**판단은 한 곳 — `Auth::nextStep`.** 전과 같이 로그인 응답 `next`·`/api/me`·라우터 관문이 이 함수 하나를 본다. 바뀐 것은 조건 하나다.

| 함수 | 조건 | 쓰는 곳 |
|---|---|---|
| `Auth::MFA_REQUIRED_ROLES` | `['Admin']` — 상수. Manager 를 필수로 하려면 여기 한 단어(문서·R-85 먼저) | 아래 둘 |
| `Auth::mfaRequired($user)` | **실제 역할**(`real_role` 이 있으면 그것, 없으면 `role`)이 목록에 있는가. 「역할로 보기」(5.20)로 Employee 를 흉내 내는 Admin 도 실제 역할로 판정한다 — 흉내로 관문을 빠져나갈 수 없다 | `nextStep`·`mfa_confirm`(감사 `forced`)·`/api/me` |
| `Auth::nextStep($user)` | `must_change_password` → `password_change`(역할 무관, F-16 ② 그대로) → `mfaRequired && mfa_enabled === false` → `mfa_setup` → 그 밖 `null` | 로그인·`/api/me`·라우터 관문 [2-G] |
| `Auth::mfaRecommended($user)` | `!mfaRequired && mfa_enabled === false` | 로그인 응답·`/api/me` 의 `mfa_recommended` |

관문이 `mfa_setup_required` 플래그가 아니라 **`mfa_enabled`** 를 보는 이유: 플래그는 생성·해제·초기화가 켜고 확정이 끄는 값이라 보통 `!mfa_enabled` 와 같지만, 어긋난 순간(마이그레이션·수동 수정)에 관문이 열리면 안 된다. 켜져 있는지는 `mfa_enabled` 가 진실이다. 플래그는 그대로 남아 감사 `forced` 와 「내 계정」 안내에 쓰인다.

**역할이 바뀌면.** Admin 으로 승격된 계정이 2단계 인증을 안 켰다면 **다음 요청부터** 관문에 걸린다 — 계산이 매 요청 DB 값(`Auth::currentUser`)이라 별도 처리가 없다. 반대로 Admin 에서 내려간 계정은 관문이 사라지고 권장 카드를 본다(켜 둔 것은 그대로 켜져 있다).

**권장 절차 — 서버는 두 값만, 화면이 카드를 그린다.**
- 로그인 200 → `{ok, next, mfa_required, mfa_recommended}`; `/api/me` 도 같은 두 값. 둘 다 `Auth::mfaRequired`·`mfaRecommended` 에서 나오며 요청 값은 섞이지 않는다(S-01).
- 홈(`/`)의 `app.js`: `next === null && me.mfa_recommended` 이면 `#mfa-recommend` 카드(`src/handlers/home.php` 에 마크업, 기본 `hidden`)를 연다. 「지금 켜기」는 `/settings` 링크, 「나중에」는 `sessionStorage['tbox-mfa-later']='1'` 을 적고 닫는다 — `try/catch` 로 감싸 저장이 안 되는 브라우저에서도 닫힌다. **서버는 「나중에」를 저장하지 않는다**: 탭을 닫고 다시 로그인하면 다시 보인다. 이것이 「권장」의 강도다 — 막지 않되 매 로그인마다 상기시킨다.
- 「내 계정」: `me.mfa_required` 면 「이 역할(Admin)은 2단계 인증이 필수입니다」, 아니면 「선택이지만 켜 두기를 권장합니다」 문구. 켜기·끄기 UI 는 F-51 그대로.
- Admin 이 계정 목록에서 누가 켰는지 보는 것은 기존 `mfa_enabled` 열이 있으면 그대로(없으면 이번 범위 밖).

**바뀌지 않는 것.** 순차 2단계 로그인(켠 계정은 코드 필요, 4.0) · `enroll/confirm/disable` 세 창구 · 코드 1회성(R-45) · Admin 의 재발급·초기화(F-16 ⑤) · DB 스키마 · 관문 [2-G] 의 허용 목록과 403 모양.

**남는 위험(SECURITY R-85).** Manager·Employee·Guest 는 비밀번호 하나로 지켜질 수 있다 — 사용자 수용. Manager 는 「URL 로 가져오기」 권한이 있어 상수 한 단어로 필수에 넣을 수 있게 해 두었다.

### 5.27 업로드 내용 검사 강화 — zip 내부·매크로·PDF 능동 내용·HWP 서명 (PRD F-53, 사용자 결정 2026-09-16)

**무엇을 더 보나.** 6단계 검사(확장자 화이트리스트·이중 확장자·finfo MIME 짝·OOXML 구조)는 「겉」을 본다. F-53 은 **속을 풀지 않고** 속을 본다 — 전부 `Upload::validatePath` 안, MIME 짝 검사 **뒤**에 붙는다(F-14 가져오기도 같은 함수를 타므로 자동).

| 검사 | 대상 | 방법 (추출 0) | 거부 사유 코드 |
|---|---|---|---|
| zip 내부 이름 | `zip`·`hwpx`·`docx/xlsx/pptx` | `ZipArchive` 중앙 디렉터리의 **이름만** 훑는다. 마지막 확장자 ∈ `BLOCKED_EXT` **또는 `ENTRY_BLOCKED_EXT`**(디버깅 묶음 S-10 — 윈도에서 열면 실행되는 형식 lnk·hta·jar·wsf·vbe·jse·sct·msc·cpl·scf·chm·msp·psm1·ps1xml·msh… · 디스크 이미지 iso·img·vhd · reg·url·inf·one · Access 실행형·ClickOnce 등 Outlook 1단계 차단 형식. **zip 항목의 마지막 확장자에서만** 본다 — 처음 BLOCKED_EXT 에 넣었다가 이중 확장자 검사가 맨 앞 조각까지 보므로 `Gadget.png`·`HTA.보고서.pdf` 같은 정상 겉 이름이 400 이 되어(독립 검토 M-1) 옮겼다. 겉 파일은 화이트리스트가 막는다. `BLOCKED_EXT` 는 기준 판 그대로), 확장자는 **정규화**해서 본다(끝의 점·공백·제어 문자를 떼고 · `:` 앞 부분(NTFS 스트림)도 후보 — `x.exe.`·`x.EXE `·`x.exe::$DATA` 가 빠져나가지 못함), `..` 세그먼트, 절대 경로(`/`·`\`·드라이브), 항목 > 10,000, `.zip` 항목이 3단 이상 | `zip_blocked_entry` · `zip_bad_path` · `nested_zip` |
| 압축 폭탄 | 같은 대상 | 항목 > 10,000 · `statIndex` 의 `size`(원본) 합 > 1GB · 합 ÷ 파일 크기 > 100 **이면서 합 > 32MB**(작은 zip 의 반복 텍스트 오탐 방지, 검토 보정 2026-09-16) | `zip_bomb` |
| 오피스 매크로 | `docx/xlsx/pptx` | 항목 이름이 `vbaProject.bin` 으로 끝나거나 `*/vbaData.xml` | `macro_found` |
| PDF 능동 내용 | `pdf` | 파일 앞 4MB + 뒤 4MB 를 읽어 `/JavaScript` `/JS` `/OpenAction` `/AA` `/Launch` `/EmbeddedFile` `/RichMedia` 키를 대소문자 그대로 찾는다(PDF 이름 객체는 대소문자 구분) | `pdf_active_content` |
| HWP 서명 | `hwp` 이고 머리 8바이트가 OLE 서명(`D0 CF 11 E0 A1 B1 1A E1`) | **휴리스틱**(OLE 디렉터리·미니 FAT 를 해석하지 않는다): 파일을 1MB 조각(64바이트 겹침)으로 읽어 디렉터리 항목 이름 UTF-16LE `FileHeader` 와 ASCII 서명 `HWP Document File` 이 **둘 다** 있어야 통과. 목적은 `.doc/.xls/.msi` 를 `.hwp` 로 바꿔 올린 것을 잡는 것 — 두 문자열을 갖춘 OLE 를 일부러 만든 공격자는 ClamAV(F-54) 몫 | `hwp_not_hwp` |

**왜 풀지 않나.** 추출은 그 자체가 공격면이다(경로 탈출·폭탄·심볼릭 링크). 이름과 크기 표만 읽으면 100MB zip 도 0.1초이고 디스크를 쓰지 않는다.

**오탐과 한계(SECURITY R-86).** `/OpenAction` 은 정상 PDF 에도 「첫 페이지로 이동」 용도로 있다 — 거부하고 사유를 관리자가 감사에서 볼 수 있게 한다. 사용자가 「정상인데 막힌다」고 하면 그 파일은 zip 으로 싸서 올리는 우회가 있다(zip 속 pdf 는 이름만 본다) — 이것이 곧 이 검사의 한계이며 F-54 ClamAV 가 그 자리를 맡는다. 암호 zip 은 이름은 보이지만 내용은 못 본다(ClamAV 도 못 본다) — 그대로 통과하되 R-86 에 적는다.

**바뀌지 않는 것.** ~~응답은 동일 문구 400, 사유는 감사 `file.upload deny <코드>` 에만~~ → **F-68(5.30, 2026-09-22)부터 8종 사유마다 문장이 나간다**(감사 `file.upload deny <코드>` 는 그대로, 7.4 표에 다섯 줄 추가). `ALLOWED_EXT`·`BLOCKED_EXT`·MIME 표 불변.

### 5.28 악성코드 검사 파이프라인 — `pending` → `scripts/scan_pending.php` → `clean`/`infected` (PRD F-54, 사용자 결정 2026-09-16)

**한 줄.** 업로드는 **검사 대기**로 저장되고, 1분마다 도는 배치가 ClamAV 로 검사해 **깨끗함/격리** 로 바꾼다. 깨끗해지기 전에는 아무도 내려받지 못한다.

**왜 앱 밖 배치인가.** ① 앱 코드에 소켓·`exec` 를 넣지 않는다(CLAUDE.md 금지 패턴, php-fpm `disable_functions`) — `scripts/` 는 CLI 이고 린트 대상 밖이다(`purge_trash.php`·`alerts.php` 와 같은 자리). ② `clamd` 상주 데몬은 시그니처를 올리면 1GB 이상을 쥔다 — 이 VM(3.8GB, 가용 1.1GB)에서는 PostgreSQL 과 다툰다. 그래서 **`clamscan` 을 대기 파일이 있을 때만** 띄운다(없으면 배치는 DB 한 번 보고 끝). ③ 업로드 응답이 느려지지 않는다.

**흐름.**
```
[업로드]  Upload::validatePath ✔ → INSERT files (scan_status = Upload::initialScanStatus())
              · SCAN_ENABLED=1 → 'pending'   · 아니면 'clean' (지금까지의 동작)
              · 두 곳 전부: _upload_common · files_fetch
[cron * * * * *  tbox-php]  php scripts/scan_pending.php
   [1] 잠금 dirname(STORAGE_DIR)/scan.lock = 운영 /var/lib/tbox-v2/scan.lock (flock, 있으면 즉시 종료 — 격리 폴더와 같은 「저장소 형제 경로」 규칙, 대기열 2e)
       --dry-run 은 복구·실패 기록 갈래까지 DB·감사에 쓰지 않고 「[예정·dry-run]」만 찍는다(대기열 2e)
   [2] SELECT id, stored_name FROM files WHERE scan_status='pending' AND purged_at IS NULL ORDER BY created_at LIMIT 200
       0건 → 종료 (clamscan 실행 없음)
   [3] proc_open clamscan --no-summary --infected --stdout --max-filesize=100M --max-scansize=1G <경로…>
       종료 코드 0 = 전부 깨끗 · 1 = 감염 있음(stdout 에 "<경로>: <시그니처> FOUND") · 2 = 오류
   [4] 건마다 트랜잭션: clean → UPDATE scan_status='clean' + Audit::system('file.scan' allow)
                       infected → rename(storage → /var/lib/tbox-v2/quarantine/<stored_name>) → UPDATE 'infected' + Audit::system('file.scan' deny infected, detail.signature)
                       오류 → 상태 유지 + Audit::system('file.scan' error scan_failed) → 다음 실행이 다시 시도
[다운로드 · 미리보기 · 공유 링크 · 토큰 다운로드]
   [권한 ✔] 뒤 [4] scan_status<>'clean' → pending: **423 + Retry-After: 30** 「검사 중입니다. 잠시 뒤 다시 시도하세요」 (F-57) / infected: **403** 「악성코드가 발견되어 격리된 파일입니다」
             + Audit deny 'file.download' scan_pending | scan_infected
[목록]     응답에 scan_status → 화면 배지 「검사 중」(회색) · 「격리됨」(빨강), 다운로드 단추 비활성
[경보]     alerts.php AL-19: file.scan deny infected 1건 → 즉시, evidence {name, uploader, signature}
```

**일시 상태와 영구 거부는 상태 코드로 갈린다 (F-57, 2026-09-16).** `pending` 은 곧 풀리는 상태라 **423 Locked** + `Retry-After: 30` + 본문 `retry_after`(초)로 답하고, `infected`(격리)·권한 없음은 **403** 그대로다. 사용자 프로그램이 403 을 전부 영구 실패로 처리하다 올린 직후 다운로드에 실패한 실측 보고가 계기다 — 한국어 문구로만 구분되는 상태는 문구가 바뀌면 조용히 깨진다. 넷(다운로드·미리보기·raw·공유 링크)이 `Http::locked()` 한 함수를 부르므로 코드가 어긋날 자리가 없다. 감사 사유 `scan_pending` 과 문장은 그대로다. 검사 소요 실측(감사 로그 5건): 21~76초, 중앙값 25초(배치 매분 + `clamscan` 약 22초). 다른 코드를 고르지 않은 이유는 PRD F-57 행.

**격리는 삭제가 아니다.** 바이트는 `quarantine/` 로 옮기고(`0640 tbox-php`, 웹 루트 밖, `open_basedir` 안) 행은 `deleted_at` 없이 남는다 — 누가 언제 무엇을 올렸는지가 증거다(S-07). 되살리는 창구는 없다(원하면 관리자가 서버에서 수동). 30일 뒤 `purge_trash` 는 격리 파일을 건드리지 않는다.

**시그니처 갱신.** `clamav-freshclam` 데몬(배포판 패키지)이 `database.clamav.net` 에서 HTTPS 로 받는다 — 이 서버가 밖으로 내는 통신은 이것과 패키지 미러·NTP·백업뿐이고 PRD 10장 아웃바운드 표에 이미 예외로 적혀 있다(ufw 는 현재 비활성, 켤 때 이 예외를 넣는다). `check_baseline.sh` 가 `daily.cld/cvd` 갱신 시각이 24시간을 넘으면 경고한다.

**남는 위험(SECURITY R-86).** 검사 창(업로드 뒤 최대 1분 + clamscan 시간) 동안 다운로드 불가 · `clamscan` 1회 실측 RSS 991MB·22초(스왑 2GB 있음, 대기 있을 때만) · 알려진 시그니처만 잡는다(직원 PC 백신 대체 불가) · 암호 zip 내부 미검사 · 오탐 시 격리 해제는 수동. 이 문제들은 운영 전환 때 `clamd` + 전용 메모리로 바꾸면 창이 초 단위로 준다 — 배치 [3] 만 `clamdscan` 으로 바꾸면 된다.

**바뀌지 않는 것.** `Upload::validatePath`(F-53 포함)는 그대로 먼저 돈다 — ClamAV 는 그 뒤의 두 번째 겹이다. 권한 판정(`decideRead`)이 상태 검사보다 **앞**이라 403 의 의미(IDOR)는 그대로다.

### 5.29 실행 파일이 든 압축본 — 관리자 확인 업로드·확인 다운로드 (PRD F-65 · Q-23, 사용자 결정 2026-09-21)

**무엇을 바꾸나.** F-53(5.27)은 zip 안에 차단 확장자(`.php`·`.sh`·`.js`·`.html` …)가 하나라도 있으면 `zip_blocked_entry` 로 거부한다. 이 절은 그 한 갈래에 **예외가 아니라 더 무거운 조건**을 둔다: 내용 검사를 건너뛰는 대신 「누가(Admin·admin-only)」·「진짜 그 사람인가(요청마다 비밀번호+OTP)」·「알고 올리나(확인 문구)」·「알고 받나(경고 배지 + 받을 때도 요청마다 비밀번호+OTP)」·「나중에 추적(감사·경보)」 다섯 겹으로 바꾼다. F-53 의 나머지 검사(경로 탈출·중첩·압축 폭탄·매크로·PDF·HWP)와 F-54 ClamAV 는 **그대로** 돈다.

**왜 이렇게 하나 (사용자 문답 2026-09-21, STAGES 27단계 학습 기록).** 서버는 저장 구조(3장·6장)가 지키므로 zip 속 `.php` 가 서버에 해가 되지 않는다. F-53 이 막는 것은 **직원 PC 로의 배달**이다. 게시자 신원만으로는 「훔친 세션」·「게시자가 속은 파일」·「판단 못 하는 독자」를 못 막는다 — 그래서 요청마다 OTP(훔친 세션 차단), 확인 문구(모르고 올림 차단), 받는 쪽 OTP·경고(모르고 받음 차단)를 겹친다. 「알고도」 하는 경우와 게시자 PC 자체의 감염은 이 설계로도 못 막는다(SECURITY R-94, 받아들인 위험).

**표.** `files.exec_content BOOLEAN NOT NULL DEFAULT false` 한 열(6.2, 마이그레이션 `db/migrations/2026-09-21_files_exec_content.sql`). 참이면 「실행 파일 포함」 파일이다. 이 값은 업로드 트랜잭션에서만 참이 되고 **다시 거짓이 되지 않는다.**

**올리기 — `POST /api/files` (웹 세션만).** 5.2 의 흐름에 한 갈래가 붙는다. `Upload::validate` 가 `zip_blocked_entry` 를 던졌을 때:

| 순서 | 조건 | 결과 |
|---|---|---|
| ① | 토큰 입구(`POST /api/upload`, `TBOX_AUTH_METHOD` 정의됨) · URL 가져오기(`files_fetch`) | 400 — 토큰 입구는 `zip_blocked_entry` 문장(F-68, 5.30), URL 가져오기는 「가져올 수 없습니다」 그대로(S-05 ⑩); 감사 `file.upload deny zip_blocked_entry` — 이 갈래는 **웹 세션에만** 있다 |
| ② | `$user['real_role'] !== 'Admin'` 또는 「역할로 보기」 중(`acting`) 또는 등급이 `admin-only` 가 아님 | 400 `zip_blocked_entry` 문장(F-68, 5.30 — 2026-09-22 전에는 통일 문구). **응답에 `next` 를 넣지 않는다** — Employee 에게 「실행 파일이 들어 있다」는 힌트를 주지 않는다 |
| ③ | Admin · admin-only · 세션인데 `exec_ack` 필드가 `"1"` 이 아님 | 400 `{error: MSG_EXEC_ACK_REQUIRED, next:"exec_ack", entries:[차단 항목 이름 ≤ 20]}`, 감사 `file.upload_exec deny exec_ack_required`(detail `exec_entry_count`). 화면이 이 응답으로 확인 창을 연다 |
| ④ | `exec_ack="1"` | `Auth::verifyCurrentPassword($user, $password)` → 실패면 `file.upload_exec deny bad_credentials` + `countFailureAndRevoke` + 400 통일 문구. 이어서 `Auth::verifyOtpOnce($user, $otp)` → `mfa_not_enabled`(403) · `bad_code`·`code_reused`(400 + `countFailureAndRevoke`). **둘 다 요청마다**, 세션에 창을 남기지 않는다(`reauth_at` 을 놓지 않는다) |
| ⑤ | 통과 | `Upload::validate($file, allowBlockedEntries: true)` 로 **다시** 검사 — 차단 이름은 모으기만 하고(`exec_entries`) 나머지 검사는 그대로 던진다. 이어 민감정보 검사(5.13) → INSERT(`exec_content = true`) → 감사 `file.upload_exec allow`(detail `exec_ack:true`·`otp_verified:true`·`exec_entry_count`·`exec_entries`(≤ 20 이름)) — 한 트랜잭션(5.2 [5]) |

비밀번호·코드는 `$_POST` 에서 읽은 직후 `unset` 하고 응답·감사·`app.log` 어디에도 남기지 않는다(PRD 7.7). `password`·`otp`·`exec_ack` 는 multipart 필드이고 다른 필드(`access_level`·`description`·`folder_id`)와 같은 자리에서 읽는다.

**받기.** `exec_content = true` 인 파일은 네 창구(5.3 다운로드·5.7 미리보기 둘·5.12 공유)가 전부 막히고, **새 창구 하나**만 연다.

| 창구 | 동작 |
|---|---|
| `GET /api/files/{id}/download` (세션) | `decideRead` 뒤 · `scan_status` 관문 앞에서 403 `{error: MSG_EXEC_ACK_REQUIRED, next:"exec_ack"}`, 감사 `file.download deny exec_ack_required` |
| `GET /api/files/{id}/download` (`X-API-Token`) | 403 `MSG_FORBIDDEN`, 감사 `file.download deny exec_content_token` — 토큰은 OTP 를 칠 수 없다 |
| `GET …/preview` · `…/preview/raw` | 403 같은 사유(`exec_ack_required`) — zip 은 미리보기 종류가 없지만 관문을 같은 모양으로 둔다 |
| `POST /api/files/{id}/share` | 400 `exec_content` 문장(F-68, 5.30), 감사 `share.create deny exec_content` — 링크는 OTP 없이 받는 길이다 |
| `GET /s/{token}` | 만들 수 없으니 닿을 일이 없지만, 5.12 의 검사 순서에 `exec_content` 를 넣어 404 (겹 방어) |
| `PATCH /api/files/{id}` `access_level` | 400 `exec_content` 문장(F-68, 5.30), 감사 `file.update deny exec_content` — 등급을 내리면 「Admin 만·OTP 필수」 전제가 깨진다. `description`·`folder_id` 는 그대로 |
| **`POST /api/files/{id}/exec-download`** (정책 `file.read`, 세션만, CSRF) | JSON `{password, otp}` 허용 키 둘. 5.3 과 같은 순서(uuid → 행 → `decideRead` → ) 뒤 `exec_content` 가 거짓이면 400 · `verifyCurrentPassword` → `verifyOtpOnce`(위 ④ 와 같은 사유·집계) → `scan_status` 관문(5.28) → 무결성 → **본문으로 파일 전송**(`Content-Disposition: attachment`, 5.3 과 같은 헤더). 감사 `file.download_exec allow`(detail `otp_verified:true` + 5.3 의 키) |

`decideRead` 가 앞에 있으므로 받는 사람은 **언제나 Admin** 이다(admin-only). Admin 은 F-52 로 2단계 인증이 필수라 `mfa_not_enabled` 갈래는 실제로는 닿지 않지만, 정책이 바뀌어도 코드가 지키도록 둔다.

**목록·화면.** `GET /api/files` 항목에 `exec_content`(bool) 추가. 화면은 그 값 **하나만** 보고 「실행 파일 포함」 배지를 붙이고(`textContent`), 다운로드 단추가 **확인 창**(올린 사람·올린 날짜·경고 문장·비밀번호·코드)을 연 뒤 `exec-download` 를 `fetch` 로 불러 blob 으로 저장한다. 공유 단추는 감추고 등급 셀렉트는 잠근다 — 표시는 편의이고 근거는 위 서버 표다(PRD S-01). 올리기 창은 400 `next:"exec_ack"` 를 받으면 그 파일 줄 아래에 항목 이름(`entries`, textContent)·확인 문구 체크·비밀번호·코드 칸을 펴고, 체크 없이는 보내지 않는다(서버가 다시 400).

**감사·경보.** 새 action 둘 — `file.upload_exec`·`file.download_exec`(7.4 문장 표·7.5 키 `exec_ack`·`exec_entry_count`·`exec_entries`·`otp_verified`). 기존 action 의 새 사유 — `file.download deny exec_ack_required|exec_content_token`, `share.create deny exec_content`, `file.update deny exec_content`. **AL-21**: `file.upload_exec allow` 1건이라도 즉시(PRD 7.11), evidence 파일명·올린 사람·항목 수.

**검증 helper 두 개 (`src/Auth.php`).** `verifyCurrentPassword(array $user, string $password): bool` — `reauth()` 의 앞부분(`SQL_LOAD_BY_ID` + `password_verify`)과 같고 세션에 `reauth_at` 을 **놓지 않는다**. `verifyOtpOnce(array $user, string $otp): string` — `admin_permissions_update.php` ②의 규칙(`mfa_enabled` 필수 · `Totp::verifyStep` · `mfa_last_step` 1회성)과 같으며 `'ok'|'mfa_not_enabled'|'bad_code'|'code_reused'` 를 돌려주고 실패 집계는 호출자가 한다. `admin_permissions_update.php` 는 이번에 손대지 않는다 — 같은 규칙이 두 벌이 되는 것은 알고 있으며 다음 정리 대상이다(SECURITY R-94 비고).

**바뀌지 않는 것.** `ALLOWED_EXT`·`BLOCKED_EXT`·이름 검사 자체 · 토큰·가져오기 입구의 거부 · ClamAV · 저장 구조 · admin-only 가 아닌 모든 업로드.


### 5.30 400 거부의 이유 문장 — `Http::reasonMessage()` 한 표 (PRD F-68 · Q-27 · S-09-12, 사용자 요청 2026-09-21)

**왜.** 「영업팀」 비활성화가 「잘못된 요청입니다」로 막혔다(2026-09-21). 서버는 이유(`team_has_members`, R-44)를 알고 감사에도 남겼지만 화면에는 숨겼다 — S-09-5 「동일 문구」를 400 에도 그대로 적용해 온 결과다. 그런데 400 은 대부분 **요청자 본인의 입력·업무 규칙 위반**이라, 이유를 말해도 공격자가 얻는 것이 없다(자기가 보낸 값이 왜 안 되는지는 자기가 가장 잘 안다). 실제로 예외가 이미 여럿이었다(`MSG_SENSITIVE_LEVEL_REQUIRED`·`MSG_FOLDER_*` 셋·`MSG_EXEC_ACK_REQUIRED`·F-49 의 프로그램 입구 표). 이번에 원칙을 뒤집는다 — **400 은 이유를 말하는 것이 기본**, 통일 문구는 아래 예외 목록으로 좁힌다.

**어디서.** `Http::reasonMessage(string $reason): string` — 사유 코드 → 문장 표 **하나**. 표에 없는 코드는 `MSG_BAD_REQUEST`. 표와 별도로 **절대 문장을 붙이지 않는 코드 목록** `Http::REASON_UNIFORM`(`bad_request`·`bad_credentials`·`bad_code`·`code_reused`·`same_password`·`weak_password`)을 두고, 이 목록의 코드는 누가 표에 넣어도 `MSG_BAD_REQUEST` 로 떨어진다(오라클 차단이 실수로 풀리지 않게). 핸들러의 `$reject` 닫힘 함수는 `Http::error(400, Http::MSG_BAD_REQUEST)` 자리를 `Http::error(400, Http::reasonMessage($reason))` 로 바꿀 뿐이다 — **상태 코드·감사 `Audit::deny(action, reason, …)`·응답 모양 `{"error":…}` 은 바뀌지 않는다.** 403 자리는 그대로 `MSG_FORBIDDEN` 이다(같은 코드 `not_owner` 가 403 으로 나가는 자리는 건드리지 않는다). F-49 의 `api_upload.php` `$reasonTable` 은 **문장 열을 이 표로 넘기고 상태 코드(413·403) 열만** 남긴다 — 웹과 프로그램이 같은 문장을 쓴다. 403 두 줄(`role_not_permitted`·`team_mismatch` 「이 계정은 그 등급(팀)으로 올릴 권한이 없습니다」)은 `api_upload.php` 에 남는다(T-61 계약).

**문장 규칙(S-09-12).** ① 「요청자가 방금 보낸 값의 어디가 규칙에 맞지 않나」만 ② 대상 이름·다른 사람·**개수**·서버 경로·SQL·예외 문구 금지(R-63 — 개수도 정보다) ③ 목록 값(허용 확장자)은 코드에서 만든다(`Upload::ALLOWED_EXT`), 손으로 베끼지 않는다 ④ **이 표가 원본**이고 `Http.php` 는 이 표를 옮긴 것이다 — 둘이 다르면 표를 먼저 고친다(T-81 이 글자 대조를 한다).

| 사유 코드 | 나오는 자리 | 문장 |
|---|---|---|
| `team_has_members` | 팀 비활성화 (`org_team_update`) | 활성 구성원이 있는 팀은 비활성화할 수 없습니다 — 먼저 구성원을 다른 팀으로 옮기세요 |
| `already_exists` | 계정 생성 (`users_create`, 400 그대로) | 이미 있는 아이디입니다 |
| `self_change` | 계정 역할·상태 변경 · 비밀번호/2단계 초기화 (`users_update`·`users_mfa_reset`·`users_password_reset`) | 자기 자신에게는 쓸 수 없습니다 — 내 계정 화면을 이용하세요 |
| `last_admin` | 계정 강등·비활성화 (`users_update`) | 마지막 활성 관리자는 강등하거나 비활성화할 수 없습니다 |
| `depth_exceeded` | 폴더 생성·이동 (`folders_create`·`folders_update`) | 폴더는 5단계까지만 만들 수 있습니다 |
| `cycle` | 폴더 이동 (`folders_update`) | 폴더를 자기 자신이나 그 하위 폴더 안으로 옮길 수 없습니다 |
| `parent_narrower` | 폴더 생성·이동·등급, 파일 업로드·정보 변경 | 파일이나 폴더의 등급이 상위 폴더의 등급보다 넓을 수 없습니다 |
| `children_exceed` | 폴더 등급 좁히기 (`folders_update`, cascade 없음 · cascade 상한 초과 둘 다) | 하위에 이 등급보다 넓은 폴더나 파일이 있습니다 — 「하위 항목도 함께」를 켜거나 먼저 정리하세요 |
| `not_owner` (**400 자리만**) | 폴더 등급 cascade 중 내가 못 바꾸는 하위 (`folders_update`) | 하위에 내가 바꿀 수 없는 폴더나 파일이 있어 함께 좁힐 수 없습니다 |
| `bad_encoding` | 업로드·파일 정보 | 파일 이름 또는 설명이 올바른 문자(UTF-8)가 아닙니다 |
| `description_too_long` | 업로드·파일 정보 | 설명은 500자까지입니다 |
| `bad_access_level` | 업로드·파일 정보 | level 값이 올바르지 않습니다 (public·internal·team·admin-only) |
| `bad_team` | 업로드·파일 정보 | team 등급은 team_id(활성 팀 번호)가 필요합니다 |
| `bad_folder` | 업로드·파일 정보 | folder_id 가 없거나 접근할 수 없는 폴더입니다 |
| `no_change` | 파일 정보 (`files_update`) | 바뀐 내용이 없습니다 |
| `exec_content` | 공유 링크 · 등급 변경 (`share_create`·`files_update`) | 실행 파일이 든 압축본은 공유 링크를 만들거나 등급을 바꿀 수 없습니다 |
| `no_file` | 업로드 | file 필드에 파일이 없습니다 |
| `upload_error` | 업로드 | 업로드 요청의 형식이 올바르지 않습니다 (multipart/form-data 로 file 하나를 보냅니다) |
| `not_uploaded_file` | 업로드 | 정상적인 HTTP 업로드가 아닙니다 |
| `empty_file` | 업로드 | 빈 파일(0바이트)은 올릴 수 없습니다 |
| `size_unreadable` | 업로드 | 파일 크기를 읽을 수 없습니다 |
| `hash_failed` | 업로드 | 파일의 무결성 값을 계산할 수 없습니다 |
| `ext_not_allowed` | 업로드 | 허용되지 않는 확장자입니다 (허용: `implode(' ', Upload::ALLOWED_EXT)`) — 목록은 코드가 만든다 |
| `double_extension` | 업로드 | 이중 확장자 파일은 올릴 수 없습니다 |
| `mime_mismatch` | 업로드 | 파일 내용이 확장자와 다릅니다 |
| `too_large` | 업로드 (웹 400 · 프로그램 413) | 파일이 100MB 를 넘습니다 |
| `quota_exceeded` | 업로드 (웹 400 · 프로그램 413) | 오늘 업로드 총량(2GB)을 넘었습니다 |
| `zip_blocked_entry` | 업로드 내용 검사 (F-53; F-65 갈래가 아닌 사람) | 압축본 안에 실행 파일이나 스크립트가 있어 올릴 수 없습니다 |
| `zip_bad_path` | 업로드 내용 검사 | 압축본 안에 올바르지 않은 경로(상위 폴더로 나가는 경로·절대 경로)가 있습니다 |
| `zip_bomb` | 업로드 내용 검사 | 압축본을 풀었을 때의 크기나 항목 수가 한도를 넘습니다 |
| `zip_unreadable` | 업로드 내용 검사 | 압축본을 읽을 수 없습니다 — 손상됐거나 지원하지 않는 형식입니다 |
| `nested_zip` | 업로드 내용 검사 | 압축본 안에 다른 압축본이 있어 올릴 수 없습니다 |
| `macro_found` | 업로드 내용 검사 | 문서에 매크로가 들어 있어 올릴 수 없습니다 |
| `pdf_active_content` | 업로드 내용 검사 | PDF 에 실행되는 내용(자바스크립트·자동 실행)이 있어 올릴 수 없습니다 |
| `hwp_not_hwp` | 업로드 내용 검사 | HWP 형식이 아닌 파일입니다 |
| `limit_exceeded` | 개인 토큰 본인 발급 (`me_tokens_issue`, F-66) | 살아 있는 토큰이 상한에 이르렀습니다 — 먼저 하나를 회수하세요 |
| `invariant` | 권한 매트릭스 · 허용 사이트 (`admin_permissions_update`·`fetch_hosts_update`) | 바꿀 수 없는 항목이거나 규칙에 어긋나는 값입니다 |
| `already_enabled` | 2단계 등록 시작 (`mfa_enroll`) | 2단계 인증이 이미 켜져 있습니다 |
| `sensitive_level_required` · `folder_has_children` · `folder_not_empty` · `folder_has_trashed` · `exec_ack_required` | (기존 상수) | 기존 `MSG_*` 그대로 — 표에서도 찾을 수 있게 같은 상수를 가리킨다 |

**그대로 통일 문구 — 예외 목록(표에 넣지 않는다, `REASON_UNIFORM` 이 지킨다).**

| 자리 | 문구 | 왜 |
|---|---|---|
| `bad_request` — 허용 키 밖·값의 형식·`Http::jsonBody` 파싱 | 잘못된 요청입니다 | 프로그램(또는 개발자도구)의 실수다. 무엇이 허용되는지는 `API.md` 가 말한다 |
| `bad_credentials`·`bad_code`·`code_reused`·`same_password`·`weak_password` — `login`·`reauth`·`password_change`·`mfa_confirm`·`mfa_disable`·F-65 확인(`_upload_common` ④·`files_exec_download`)·매트릭스/허용 사이트 OTP | 잘못된 요청입니다 (로그인은 `MSG_LOGIN_FAILED`) | 「비밀번호는 맞았나」「코드는 맞았나」 오라클 차단 (R-48·R-45). `weak_password` 를 가르면 현재 비밀번호가 맞았다는 뜻이 된다 |
| 403 전부 (`role_not_permitted`·`team_mismatch`·`not_owner`·`mfa_not_enabled`·`exec_content_token`…) | 권한이 없습니다 | S-09-5 — 대상 정보 미포함 |
| 404 · `/s/{token}` (`not_found`·`revoked`·`expired`·`exhausted`·`file_gone`·`exec_content`) | 찾을 수 없습니다 | 존재 여부 오라클, 5.12 |
| URL 가져오기 (`files_fetch` 전부, 400·403·429) | 가져올 수 없습니다 | S-05 ⑩ — 내부망 정찰 재료 |
| 423 검사 중 · 429 속도 제한 | 그대로 | 5.28 · S-05 ⑪ |

**바뀌지 않는 것.** 상태 코드 · 감사 사유 코드와 7.4 문장 · 응답 모양 · `Authz`·`Auth`·`Upload`·`Audit`·`index.php` · 화면 JS(`errorMessage()` 가 `error` 를 그대로 보인다) · 프로그램 입구의 403 두 문장.

**5.27·5.29 의 「400 통일 문구」 문장은 이 절로 바뀐다.** 5.27 「응답은 동일 문구 400」 → F-53 8종은 위 표의 문장. 5.29 표 ①(토큰 입구)·②(Admin 이 아니거나 admin-only 가 아닌 사람)의 「400 통일 문구」 → `zip_blocked_entry` 문장 — 단 **`next` 는 여전히 없다**(확인 창은 열 수 있는 사람에게만). 5.29 받기 표의 `POST …/share`·`PATCH access_level` → `exec_content` 문장. 5.29 ④ 와 `exec-download` 의 `bad_credentials`·`bad_code`·`code_reused` 는 예외 목록대로 그대로.


### 5.31 DB 연결 — 첫 질의 때 열고, 워커마다 다시 쓴다 (Q:F-4 P-2 · P-5c · P-4b, 2026-09-24)

**왜.** 요청마다 새 PostgreSQL 연결(루프백 TLS + SCRAM, 실측 약 21ms)을 열었다 — 8.3일 461,027 세션, 평균 수명 75ms 중 쿼리는 1.1ms
(`pg_stat_database`, 리서치 P-2). 앞단 헬스체크는 2초마다 `/`(DB 가 필요 없는 런처)를 부르는데 그때도 연결했다(P-5c).

| | 전 | 후 |
|---|---|---|
| 연결을 여는 때 | bootstrap 5) `Db::boot` — **모든 요청**(런처·308·404 포함) | **첫 `run()`·`begin()`**(health 는 `Db::ready()`) — DB 가 필요 없는 요청은 0 |
| 연결의 수명 | 요청 하나(끝나면 닫힘) | **FPM 워커 하나**(`PDO::ATTR_PERSISTENT`, 앱 롤 `tbox_app` 만) |
| `Db::perm()`(`tbox_perm`, 5.15) | 지연 · 비영속 | **그대로**(부르는 순간 연결 · 요청이 끝나면 닫힘) — 쓰기 자격이 붙은 연결이 워커에 남지 않게 |
| DB 가 죽었을 때 쿠키 없는 런처 | 200(부팅 실패를 삼켰다, 5.24) | 200 — 연결을 시도하지도 않는다 |

**요청 사이 상태 누수 막기(SECURITY R-101).** 영속 연결은 요청이 끝나도 닫히지 않으므로, 앞 요청이 연결에 남긴 것이 다음 요청(다른 사람)에게
이어질 수 있다. 가장 위험한 것은 **열린 트랜잭션**이다 — 다음 사람의 쓰기가 앞 사람의 트랜잭션 안에서 커밋·롤백되거나 잠금이 풀리지 않는다.
그래서 연결을 **돌려줄 때**와 **넘겨받을 때** 두 번 정리한다.

| 자리 | 하는 일 | 비용 |
|---|---|---|
| 요청 끝 — `Db::endRequest()`(bootstrap 의 종료 함수, 치명적 오류 은닉 함수 **뒤**에 등록) | 이 요청이 연 연결에 트랜잭션이 남았으면 되돌리고 서버 로그에 한 줄(`open transaction rolled back at request end (depth=n)` — 값 없음). 트랜잭션 안의 `Http::error()`·`exit`, 예외 처리기가 돌지 않는 치명적 오류, `Db::begin` 을 거치지 않은 트랜잭션까지. 전에는 연결이 닫히며 저절로 되던 일이다 | 트랜잭션이 남았을 때만 왕복 1 |
| 넘겨받을 때 — `adopt()`(영속 연결을 이 요청이 처음 쓸 때) | ① 남은 트랜잭션이 있으면 되돌린다(요청 끝 정리가 못 돈 경우의 그물 — `inTransaction()` 은 libpq 상태를 읽을 뿐 왕복 없음) ② **`DISCARD ALL`** — 세션 설정·`SET ROLE`·임시 표·세션 advisory lock·`LISTEN`·커서·서버 prepare 를 비워 **처음 상태**로 돌리고(역할 기본값 `ALTER ROLE … SET` 은 처음 상태에 들어 있어 그대로), 같은 왕복으로 살아 있는지 확인한다. 실패하면 **한 번만** 다시 연결 — 실제 질의는 다시 보내지 않는다(이미 반영됐는지 알 수 없다). `DISCARD ALL` 은 트랜잭션 안에서 돌 수 없어 ① 뒤다 | 요청마다 왕복 1(이름 없는 문장 — `PGSQL_ATTR_DISABLE_PREPARES`, 값 없음. 실측 p50 약 0.24ms — `SELECT 1` 과 같다) |
| PHP 자체 | PHP 8.1 PDO 는 PDO 객체가 풀릴 때 열린 트랜잭션을 되돌린다(SQL `BEGIN`·치명적 오류 포함 — 2026-09-24 실측). 위 둘은 그 동작에 기대지 않고 **명시하고 기록**하는 그물이다 | — |

- **연결에 세션 상태를 남기는 SQL 0**: `SET`(세션)·`SET ROLE`·`set_config`·세션 advisory lock·임시 표·`LISTEN`·`WITH HOLD` 커서를 쓰지 않는다
  (`tests/qf4b1.sh` 가 grep). advisory lock 은 `pg_advisory_xact_lock` 두 곳(`embed_domains_add`·`templates_create`)뿐이고 **트랜잭션 범위**라
  커밋·롤백과 함께 풀린다. 쓰더라도 다음 요청은 `DISCARD ALL` 뒤라 물려받지 않지만, 요청과 요청 **사이**(워커가 쉬는 동안) 세션 잠금·임시 표가
  남아 있게 되므로 이 목록을 새로 쓰는 코드는 이 절과 R-101 을 먼저 고친다.
- **트랜잭션 제어는 `Db::begin/commit/rollback` 만**: SQL 문자열로 `BEGIN`·`COMMIT`·`ROLLBACK`·`SAVEPOINT` 를 보내는 곳 0, PDO 의
  `beginTransaction`·`commit`·`rollBack` 을 부르는 파일은 `src/Db.php` 하나(`tests/qf4b1.sh` P-2 ③ 이 grep 으로 확인).
- **서버 prepare**: 이름은 연결마다 늘어나는 번호(`pdo_stmt_…`)라 요청 사이에 겹치지 않고, 문장이 풀릴 때 `DEALLOCATE` 된다. 트랜잭션 안에서
  SQL 오류가 난 뒤에는 그 `DEALLOCATE` 가 거부돼 문장이 세션에 남는데(실측), 다음 요청이 넘겨받을 때의 `DISCARD ALL` 이 지운다 — 늘어나지 않는다.
- **끊긴 연결**: DB 가 재시작되거나 연결이 끊기면 PDO 의 자체 확인(PQconsumeInput)은 끊김을 늘 알아채지 못해, 확인이 없으면 워커마다 첫 요청 하나가
  500 이었다(`pg_terminate_backend` 실측). `adopt()` ② 의 `DISCARD ALL` 이 실패하면 그 요청을 다시 연결로 돌린다. **실제 PostgreSQL 재시작은 미검증**(시험 DB 가 운영과 같은
  클러스터) — 운영 반영 뒤 첫 DB 재시작 때 확인한다(사이트 STAGES).
- **연결 수**: 워커 수만큼 연결이 **늘 열려 있다** + `perm` 을 쓰는 요청 동안 1. P-7(9.1) 뒤 풀은 `pm = dynamic` — 쉬는 워커 2~4 · 최대 8 이라 상시 연결 2~8
  (전: `ondemand` · 최대 5 · 유휴 30초에 워커 종료 → 연결도 닫힘). 트랜잭션이 남는 경우의 DB 쪽 안전망은 `tbox_app` 역할의 `idle_in_transaction_session_timeout = 60s`(10장).
  서버가 그 시간에 연결을 끊으면(요청 안에서 트랜잭션을 연 채 60초 넘게 쉬는 경우) 그 요청은 다음 질의에서 500 으로 끝나고(질의가 없으면 요청 끝 정리의 롤백이
  실패를 로그 한 줄 `request-end rollback failed` 로만 남긴다), **다음 요청은 200** 이다 — PHP 의 영속 연결 확인이 끊긴 핸들(CONNECTION_BAD)을 다시 연결하고
  (PQreset — 2026-09-24 실측 경로), 그것이 놓친 경우는 `adopt()` 의 `DISCARD ALL` 실패가 비영속 재연결로 돌린다. 트랜잭션 안에서 문장이 `statement_timeout` 에
  취소돼도 연결은 끊기지 않고 다음 요청이 같은 연결을 그대로 쓴다(`tests/qf4_p7_timeouts.sh` ④ — php-fpm 워커 1개, 임시 DB 에서만 3초).

**health 와 연결 실패(5.24).** `Db::ready()` 가 아직 연결하지 않았으면 연결을 **시도**한다 — 연결 실패는 `connect_failed`, 질의 실패는
`query_failed` 로 전과 같이 갈린다. bootstrap 예외 처리기는 연결을 열지 않는 `Db::connected()` 로 롤백 여부를 본다. 연결 실패의 원문(호스트·포트·계정)은
전처럼 서버 로그에만 `request_id` 와 함께 남는다(S-09-7). 한 요청 안에서 연결을 두 번 시도하지 않는다.

**편집 중 신호는 본문을 읽지 않는다(P-4b).** `POST /community/api/pages/{id}/heartbeat` 는 30초마다 오고 판정만 한다. 판정용 행은
`community_page_row_nodoc()` — `community_page_row()` 와 **같은 SQL 한 벌**(`community_page_row_sql($withDoc)`)에서 본문 칸 `p.doc` 하나만 뺀다
(실측 최대 99KB). 판정 칸을 따로 고르지 않으므로 판정 함수(`CommunityAuthz`, 본문을 읽지 않는다)가 받는 칸은 `doc` 을 뺀 전부가 같고, 행에는 `doc` 키가
없다(실수로 읽으면 경고 → 예외 → 500). 쿼리 계획에 `p.doc` 이 없는 것과 전 사용자 × 전 페이지 행·판정 동일을 `tests/qf4b1.sh` 가 본다.

**시험.** `tests/qf4b1.sh` — 임시 DB(`tbox_qf4b1_test`) · 전(`BEFORE_REF`)·후 두 인스턴스를 같은 포트에서 차례로(PHP 내장 서버 = 워커 1).
P-5c(쿠키 없는 `/`·308·404 세션 증가 0 · 쿠키 런처 동일 · DB 없을 때 런처 200·health 503) · P-2(100 요청 세션 증가 ≤ 1 · 트랜잭션 누수 여섯 갈래 ·
세션 상태(설정·임시 표·advisory lock)·서버 prepare 를 물려받지 않음 · grep · 끊긴 연결) · P-4b(HTTP 전수 · 행 전수 · 쿼리 계획 · 편집기 브라우저) · 전후 p50. 시험 전용 경로 `/__qf4b/*` 는 그 시험이 만드는 임시 인스턴스의 router 에만 있다.

**운영 규칙 세 가지 (독립 검토 L-1·L-4·M-1, 2026-09-24)**
- **자격을 바꾸면 php-fpm 을 reload 한다.** 영속 연결의 풀 키에 비밀번호가 들어 있어, `.env` 의 `DB_PASS` 를 바꿔도 옛 연결은 워커가 재활용(유휴·요청 수 한도)될 때까지 남는다. 자격 회전 절차의 마지막 줄은 `systemctl reload php8.1-fpm` 이다.
- **첫 질의 전에 부작용을 두지 않는다.** 연결 실패는 이제 부팅이 아니라 첫 질의에서 드러난다. 그래서 핸들러는 DB 를 처음 읽기 전에 파일 쓰기·외부 요청 같은 되돌릴 수 없는 일을 하지 않는다(2026-09-24 현재 그런 창구 없음 — 새 창구도 이 순서를 지킨다).
- **재접속은 비영속으로 연다(M-1).** 넘겨받은 영속 연결의 정리(`ROLLBACK`·`DISCARD ALL`)가 실패하면, 이 요청은 풀 핸들 대신 이 요청만 쓰는 비영속 연결을 새로 연다.

### 5.32 휴대폰 경보 — 설치형 웹 앱 + 웹 푸시 (PRD F-70 · Q-28 ⓐ, 2026-09-24~25)

지시서 `docs/f70-push-alerts-directive.md`(확정) · 구현 기록 `docs/f70-push-alerts-implementation.md`(다르게 간 곳과 이유) · SECURITY R-99.

```
[경보가 생김] cli_raise_alert → alerts.log 한 줄(F-69 origin·title)          alerts.php(root, 5분) ── AL-22 판정(받기 표 fail_since)
        │ (읽기만 — tbox-push 는 폴더 통과 x 뿐)
[보내는 배치] scripts/alert_push.php  (cron 매분 · OS 계정 tbox-push · DB 역할 tbox_push · .env 를 읽지 않는다 — push.conf)
        ├ human · system · origin 없는 줄 → 곧바로(받기마다 시간당 10건, 넘치면 다음 정시 「외 n건」)
        ├ claude-* · DIGEST_RULES(기본 AL-9) → 하루 요약 칸(개수만) → 18시 첫 실행에 한 번(0건이면 안 보냄)
        └ 대기표 alert_push_outbox 의 「시험 알림」
              → 내용 JSON ≤ 1KB {v, kind, rule, name, origin, at, n} → RFC 8291 aes128gcm(받기의 p256dh·auth) + VAPID JWT(ES256, 12시간)
              → src/Fetch.php pushSend() → 푸시 서버(코드 상수 PUSH_HOSTS 셋 — S-05 ①~⑧ 그대로)
[관리자 휴대폰] 「Qaredi 경보」(홈 화면 앱) ← 서비스 워커 push → 알림 → 누르면 고정 주소 /tbox/admin
[웹] 받기 등록·해지·목록·시험(대기표 한 행)·공개 키 — **밖으로 한 번도 나가지 않는다**(새 핸들러에 Fetch:: 0)
```

| 창구 (전부 `role:Admin` — 재인증 관문 자동) | 하는 일 |
|---|---|
| `GET /alerts-app` | 화면(공통 셸 · 셸 키 `admin` · `$pageApp='alerts'` 로 매니페스트·아이폰 메타·`alerts-app.js`). 재인증은 `/reauth?to=alerts` 로 가서 이 화면으로 돌아온다 |
| `GET /alerts-sw.js` · `GET /alerts-app.webmanifest` | 서비스 워커(원본 `src/push/alerts-sw.js`, public/ 밖)·매니페스트 — **PHP 라우트**라 등록 범위 `/tbox/alerts-app` 에 Service-Worker-Allowed 가 필요 없고 nginx 변경 0. 보안 머리는 다른 PHP 응답과 같다 · `Cache-Control: no-cache` |
| `GET /api/admin/push/key` | VAPID 공개 키(`/etc/tbox-v2/vapid-public.txt`, root 0644 — `.env` 아님). 없으면 503 |
| `GET /api/admin/push/subscriptions` | 내 살아 있는 받기 `{id, host, device_hint, created_at, last_ok_at}` — 주소·키 원문 없음 |
| `POST /api/admin/push/subscriptions` (+ CSRF) | 허용 키 셋만 · 주소는 `Fetch::pushEndpoint`(check 의 ①②④ + PUSH_HOSTS 정확 일치, **DNS 없음** — `WebPush::checkEndpoint` 가 감싼다) · p256dh 65바이트·0x04·곡선 위의 점 · auth 16바이트 · 사람 행 잠금 뒤 3대 · 같은 주소는 200 · 감사 `alert.push_subscribe` `{host, device_hint}` |
| `DELETE /api/admin/push/subscriptions/{num}` (+ CSRF) | 내 것만(남의 것·없는 것 404 같은 문구) · `revoked_reason='user'` · 행은 지우지 않는다 |
| `POST /api/admin/push/test` (+ CSRF) | 대기표 한 행(보내지 않음) · 사람당 10분 1회(429 · Retry-After) · 받기 0 이면 400 |

**표** (`db/migrations/2026-09-30_f70_push.sql`, 소유자 tbox_owner)

| 표 | 칸 | 규칙 |
|---|---|---|
| `alert_push_subscriptions` | id · user_id · endpoint(≤1024, https, 살아 있는 줄에서 UNIQUE) · host(PUSH_HOSTS 셋 CHECK · 주소의 호스트와 같음) · p256dh(BYTEA 65, 0x04) · auth(BYTEA 16) · device_hint(고정 글자 ≤ 40) · created_at · last_ok_at · fail_count · **fail_since**(연속 실패 시작 — 성공하면 NULL) · revoked_at · revoked_reason(user·not_admin·gone) | 지우지 않는다(해지 = revoked_at). tbox_app: SELECT · INSERT(모양 칸) · UPDATE(해지 두 칸). tbox_push: SELECT · UPDATE(전달 결과·해지 칸) |
| `alert_push_outbox` | id · user_id · requested_at · sent_at | tbox_app: SELECT · INSERT(user_id). tbox_push: SELECT · UPDATE(sent_at) |

**보내는 배치** `scripts/alert_push.php` — 시작점 `scripts/lib/push_bootstrap.php`(Env·Db·Http·Auth·Audit·Fetch·WebPush·Origin·log_tail 만 명시 목록),
설정 `/etc/tbox-v2/push.conf`(비밀 없음) · DB 비밀번호 `/etc/tbox-v2/push.pgpass`(tbox-push 0600 — libpq 가 PGPASSFILE 로 읽는다) · VAPID 비밀 키
`/etc/tbox-v2/vapid-private.pem`(tbox-push 0600) · 상태 `/var/lib/tbox-push/alert-push-state.json`(0600 — 읽기 위치·대기·개수, 주소·키 없음) ·
자기 감사 사본 `/var/log/tbox-push/audit.log`(Audit 클래스 그대로, `AUDIT_LOG_FILE` 만 push.conf 값).

| 단계 | 규칙 |
|---|---|
| 읽기 | `scripts/lib/log_tail.php`(alerts.php 와 한 벌 — inode 로 회전, 크기로 잘림, 줄 단위). **처음 켤 때 위치 = 파일 끝** |
| 가르기 | origin 없는 줄 = `human`(좁은 쪽) · 모르는 origin = `human` · claude-* → 요약 · `DIGEST_RULES` 규칙 → 요약 |
| 받는 사람 | 매 실행 `JOIN users … (role = 'Admin' AND is_active)` — 아니면 보내지 않고 `not_admin` 해지 + `alert.push_revoke` |
| 만들기 | `name` 은 배치의 코드 표(`PUSH_RULE_NAMES`) — 근거 글자를 옮기지 않는다 · 보낼 때마다 새 임시 키·salt · JWT `aud` = 스킴+호스트, `exp` = +12시간, `sub` = `https://changkwon.mso.so` |
| 보내기 | TTL 3600(즉시)·86400(요약) · Urgency high(즉시·외 n건·시험) · Content-Encoding aes128gcm · Authorization `vapid t=…, k=…` |
| 결과 | 2xx → last_ok_at·fail_count 0·fail_since NULL · 404/410 → `gone` 해지 · 413 → 기록만 · 429·5xx·연결 실패·사설 IP·응답 8KB 초과·키 없음 → fail_count+1, 50초 뒤 다음 실행에 다시(최대 3회 — 푸시 서버가 `Retry-After` 를 주면 그만큼 뒤, 초·HTTP 날짜 둘 다, 상한 3600초 `Fetch::PUSH_MAX_RETRY_AFTER` — `pushSend` 가 `retry_after` 정수로 돌려준다) · 3xx·그 밖 4xx → fail_count+1(다시 안 함) |
| 기록 | `alert.push_send allow|error` detail `{host, status, rule, kind}` 만(7.4 · 7.5) · 실패해도 exit 0 |
| dry-run | 대상 수·호스트·내용 크기만 찍는다 — 오프셋·상태·잠금 파일·받기 표·대기표·감사 0, Fetch 0 |

**서비스 워커** — 처리기는 `push`·`notificationclick` **둘뿐**(fetch 처리기 0 — 로그인 화면을 기기에 남기지 않는다). 제목 「[사람] AL-1 권한 우회 시도」 ·
「[시스템] AL-8 백업 실패」 · 「[Claude] 오늘 경보 n건」 · 「[요약] 오늘 경보 n건」(사람 AL-9 가 섞인 요약) · 「[경보] 지난 한 시간 외 n건」 · 「[시험] …」.
내용 모양을 다시 좁히고(규칙 번호 `AL-n` · 이름 40자 · 개수 정수), 모르는 내용이 와도 고정 제목 하나를 띄운다. 누르면 **고정 주소** `/tbox/admin`.

**나가는 통로의 잠금** (S-05 — SECURITY R-99 · 6장): ① https ② 443 ③ `PUSH_HOSTS` 정확 일치(fetch_hosts 표와 따로 — 화면으로 넓힐 수 없다) ④ 표기 정규화
⑤ 보낼 때마다 A/AAAA 전부 공인 ⑥ CURLOPT_RESOLVE ⑦ 3xx 실패 ⑧ 연결 3초·전체 10초·보내는 본문 4KB·응답 8KB 에서 끊음 ⑩ 상태 코드만 ⑬ curl 은 Fetch.php 에만.
⑫ 인프라 잠금(아웃바운드 방화벽)은 **없다**(L-8 — R-22 와 따로 계획).

### 5.33 즐겨찾기 · 최근 — `/api/files/favorites` · `/api/folders/favorites` · `/api/files/recent` (개발 대기열 6 · Q:F-3a 라운드 A, 2026-09-25)

지시서 `../docs/qf3a-favorites-recent-bulk-directive.md` 1.1 · 1.2 · 결정 M-1~M-3 · M-8 · 구현 기록 `docs/qf3a-favorites-recent-implementation.md`.
**새 판정 코드 0** — 넣을 때는 그 순간의 읽기 판정, 목록은 지금의 읽기 조건절. 커뮤니티 쪽은 커뮤니티 ARCH 21.24.

| 창구 | 정책 | 판정 · 조건 | 응답 |
|---|---|---|---|
| `GET /api/files/favorites` | file.read | `file_favorites` ⋈ `files` + `Authz::visibleFilesWhere`(논리 삭제 제외 포함) · 넣은 때 오름차순 · 200 | 파일 목록과 같은 줄 모양 + `favorited_at` · **전체 개수 칸 없음** |
| `PUT·DELETE /api/files/{id}/favorite` | file.read (+ CSRF) | PUT: 행(`deleted_at IS NULL`) + `Authz::decideRead` — 없음·지움·못 읽음은 **같은 404 문구** · 사람당 200(가득 차면 못 보는 내 줄을 먼저 걷음 → 그래도 409) · DELETE: 내 줄만(있든 없든 200) | `{ok, favorite}` |
| `GET /api/folders/favorites` | file.read | `folder_favorites` ⋈ `folders` + `Authz::visibleWhere($user, 'd')`(폴더 트리와 같은 조건절) · 50 | `{folders: [{id, name, access_level, team_id, team_name, path}]}` |
| `PUT·DELETE /api/folders/{num}/favorite` | file.read (+ CSRF) | PUT: `Authz::decideRead`(트리 [권한 ✔ 1]과 같은 함수) — 없음·못 읽음 같은 404 · 사람당 50 | `{ok, favorite}` |
| `GET /api/files/recent` | file.read | **새로 쓰는 행 0** — `audit_logs` 에서 `actor_id = 나 · action IN ('file.download','file.preview') · result='allow' · target_type='file'` 최근 30일을 파일마다 마지막 시각으로 묶고(`audit_actor_idx`), 그 뒤에 `visibleFilesWhere` · 20 | 파일 줄 + `last_used_at` · 대상 인자 없음(세션 본인 고정) |

- **표**: `file_favorites(user_id, file_id, created_at)` · `folder_favorites(user_id, folder_id, created_at)` — 기본키 (사람, 대상). 대상 외래 키 `ON DELETE CASCADE`
  (빈 폴더는 행째 지워진다 — 즐겨찾기가 삭제를 막지 않는다) · 사람 외래 키는 ON DELETE 절 없음(계정은 지우지 않는다). 앱 계정 SELECT · 칸 단위 INSERT · DELETE(10장).
- **감사 없음**(결정 M-2 — 개인 화면 설정 · 거부도 — 구현 기록 D-4). 목록 조회도 남기지 않는다(5.1 마지막 줄과 같다).
- **토큰 창구 아님** — `TBOX_API_TOKEN_ROUTES` 불변. 화면 편의 창구라 세션으로만.
- **화면**(`home.php` · `app.js`): 목록 위 보기 셋 「파일」「즐겨찾기」「최근」(`.btn--quiet.btn--small` + 고르지 않은 것 `.btn--plain` + `aria-pressed`) ·
  파일 줄 이름 앞 ☆(`.btn--icon` — `aria-label` 「즐겨찾기에 넣기/빼기」) · 「즐겨찾기」 보기의 「즐겨찾기 폴더」 묶음(누르면 그 폴더를 「파일」 보기로) ·
  폴더 동작 줄의 「즐겨찾기에 넣기/빼기」 · 「최근」 보기의 시각 칸은 「마지막으로 연 때」. 별이 찬지는 창구가 준 목록에 있나 없나만 본다(화면은 판단하지 않는다).
- 런처에는 보이지 않는다(결정 M-8 — 런처는 데이터 0).

### 5.34 zip 으로 받기 — `POST /api/files/zip` (개발 대기열 6 · Q:F-3a 라운드 C, 2026-09-26)

지시서 `../docs/qf3a-favorites-recent-bulk-directive.md` 1.4 · 3장 QF3A-T8 · 결정 M-5 · 구현 기록 `docs/qf3a-favorites-recent-implementation.md` 12장.
**새 판정 코드 0** — 파일마다 한 건 받기(5.3)와 **같은 함수**를 부른다. `files_download.php` 안에 있던 행 읽기 · 세 관문 · 무결성은
`src/handlers/_download_common.php` 로 글자 그대로 옮겼고(`tbox_download_find` · `tbox_download_gate` · `tbox_download_integrity`) 두 창구가 함께 쓴다.

| 순서 | 무엇 | 끝나면 |
|---|---|---|
| [1] | 본문 `{ids:[UUIDv4 …]}` — 키는 `ids` 하나 · 목록 · 1~100 · 모두 UUIDv4 · 같은 id 는 하나로 | 형식 오류 400 통일 문구 · 101개 이상 400 「한 번에 100개까지 …」 |
| [2] | 사람마다 한 번에 하나 — `Storage::tmpDir()/.zip-user-<id>.lock` `flock` (기다리지 않음) | 429 + `Retry-After: 10` · `file.zip deny busy`(kind `user`) |
| [3] | 파일마다 `tbox_download_find` → `tbox_download_gate`(decideRead → F-65 → F-54) — 거부 행은 관문이 남긴다(detail `via: "zip"`) · 없는 id·지운 파일은 한 건 받기처럼 **감사 없이** 뺀다 | 그 파일만 뺀다(`skipped` +1) |
| [4] | 통과한 게 없으면 · **통과한 파일만**의 DB 크기 합계가 500MB(524,288,000 B)를 넘으면 | 403 `{error, skipped}` · `file.zip deny nothing_allowed` / 400 「합계가 500MB 를 넘습니다 …」 · `file.zip deny too_large` |
| [5] | 사이트 전체 슬롯 둘(`.zip-slot-0/1.lock`) · 10분 지난 `zip-*` 치우기 · 잡은 슬롯 파일에 **내 최악 크기 2 × 합계**를 적고 · 남은 공간 ≥ 2 × 합계 + **쥐어져 있는 다른 슬롯의 적힌 몫**(비었거나 숫자가 아니면 최악 2 × 500MB · 안 쥔 슬롯의 옛 값은 무시) + 1GB(zip 사본 + nginx 가 응답을 받아 두는 임시 파일, 그 뒤에도 1GB — R-80) — 검토 L-C1 | 429(kind `site`) · 503 「임시 공간이 모자라 …」 · `file.zip error no_space`(detail `reserved_bytes`) |
| [6] | 파일마다 `tbox_download_integrity`(바이트 있나 · sha256 = DB) | 실패 파일만 뺀다 · `file.download error file_missing·hash_mismatch`(via zip — AL-6) |
| [7] | `tempnam(Storage::tmpDir(), 'zip-')`(0600 · 폴더 0700 tbox-php) → `ZipArchive` 에 **압축 없이 담기**(`CM_STORE` — 시간이 크기에 비례) · 안 이름은 파일 이름만(아래) · 닫은 뒤 0600 다시 · 항목 수 확인 | 실패 500 · `file.zip error zip_failed`(detail `why`) · 임시 파일 지움 |
| [8] | 먼저 시간 한도 — 요청 시작부터 `TBOX_ZIP_TIME_BUDGET`(50초)을 넘었으면 allow 행 없이 끝(nginx 60초 504 뒤 「받음」이 남지 않게 — 검토 L-C2). 그다음 감사 — 파일마다 `file.download allow`(한 건 받기와 같은 칸 + `via: "zip"`) · 묶음 한 줄 `file.zip allow`(requested·added·skipped·size_bytes) | 넘으면 503 「시간이 너무 걸려 …」 · 임시 파일 지움 · `file.zip error zip_failed`(why `time` · `elapsed_ms`) |
| [9] | 전송 — `application/zip` · `Content-Disposition: attachment; filename="t-box-YYYYMMDD-HHMMSS.zip"; filename*=UTF-8''…`(한 건 받기와 같은 모양 · 서버가 만든 ASCII 이름) · `X-Zip-Count` · `X-Zip-Skipped` · `readfile` 뒤 지움 | 어디서 끝나든(오류·예외·손님이 끊음) shutdown 함수가 한 번 더 지운다 |

- **안 이름**: F-05 정리 규칙(`\` → `/` 의 마지막 조각 · 제어 문자 지움 · 앞뒤 공백 · 255자) + zip 한 겹(`:` → `_` — Windows 드라이브·대체 데이터 흐름 ·
  빈 이름·`.`·`..` → `file`). 같은 이름(대소문자 무시)은 `이름 (2).확장자`. 폴더 구조·절대 경로·`..` 조각은 zip 에 들어가지 않는다.
- **응답에 나가는 것**: 넣은 수·뺀 수뿐. 뺀 파일의 이름·까닭·있고 없음은 응답에 없다(못 받으면 403 본문도 `{error, skipped}`) — 감사에만.
  합계 한도는 **통과한 파일**로만 재므로 못 보는 파일의 크기가 400/200 갈림으로 새지 않는다.
- **토큰 창구 아님**(결정 M-5) — `TBOX_API_TOKEN_ROUTES` 불변. `X-API-Token` 은 읽히지도 않고 세션 경로(없으면 401).
- **시간·자원**: 실측(임시 인스턴스 · php -S) 100MB × 5 = 500MB — 머리까지 18.7초(sha256 500MB + 담기) · 끝까지 21.8초 · 최대 RSS 그대로(약 100MB —
  크기에 따라 늘지 않는다). FPM `request_terminate_timeout 90s` · nginx `fastcgi_read_timeout 60s`(첫 바이트까지) 안이다. 동시 두 개가 겹치면
  디스크를 나눠 쓰므로 머리까지 두 배 가까이(약 40초) 될 수 있다 — 60초와의 여유가 좁아지는 자리(구현 기록 12장 C-U 표).
  nginx 는 응답을 `fastcgi_max_temp_file_size`(기본 1024m) 안에서 임시 파일로 받아 두므로 느린 손님이 PHP 워커를 붙잡지 않는다.
  FPM 이 워커를 죽이면(90초) shutdown 함수도 돌지 않아 `zip-*` 가 남는다 — 다음 zip 요청이 10분 지난 것을 치운다.
- **화면**(`home.php` · `app.js`): 일괄 막대 맨 앞 「zip 으로 받기」(`.btn--icon` · 아이콘 download · 받기 = 파랑 — `app.css` 뜻 색 선택자에 한 줄) →
  fetch + blob + `a[download]`(F-65 확인 다운로드와 같은 `saveBlob`) · 만드는 동안 단추 잠김 + 막대 아래 한 줄 · 결과 한 줄 `#files-ok`(뺀 개수 포함) ·
  거부 문장은 막대 바로 아래 `#files-bulk-msg`(`.msg`) · 고른 것은 그대로.

### 5.35 휴대폰 지문·얼굴로 로그인 — 패스키(WebAuthn) (PRD T:F-72 · 지시서 `/var/www/qaredi/docs/passkey-mobile-directive.md` · 2026-09-27, 복제본 · 검토 전)

**한 줄**: 휴대폰 안의 이 사이트 전용 열쇠(공개 키 방식)로 로그인한다. 꺼낼 때마다 휴대폰이 지문·얼굴·화면 잠금 PIN 으로 주인을 확인(UV)하므로
「가진 것 + 본인 확인」 두 요소 — **2단계 충족**(PRD F-52 ⑦). 서버에는 **공개 키만** 있다. 비밀번호·2단계 코드 길은 그대로 남는다.

| 창구(`/tbox` 아래) | 정책 · 겹 | 하는 일 |
|---|---|---|
| `POST /api/login/passkey/options` | public · JSON `{}` 만 · nginx `tbox_login` | 로그인 챌린지(32바이트) + 로그인 전 쿠키 `__Host-tbox_pk`(64 hex · HttpOnly · Secure · **SameSite=Strict** · Max-Age 600 · 있으면 재사용). `allowCredentials: []`(로그인 전 사람에게 열쇠 목록 0). 옵션마다 만료 정리 · 살아 있는 로그인 챌린지 5,000 넘으면 503. 감사 없음 |
| `POST /api/login/passkey` | public · JSON 만 · nginx `tbox_login` | `Auth::loginWithPasskey` — 아래 순서. 응답은 `/api/login` 과 같은 모양(`ok`·`next`·`mfa_required`·`mfa_recommended`) |
| `GET /api/me/passkeys` | authenticated | 내 살아 있는 열쇠(해제 제외) — 번호·이름·기기 요약·등록일·마지막 사용·`state`(active·stale)·`synced`. 열쇠 id·공개 키 0 |
| `POST /api/me/passkeys/options` | authenticated + CSRF · nginx `tbox_login` | **비밀번호(+켠 계정은 코드)를 먼저 확인**(PK-D3 · `verifyCurrentPassword`·`verifyOtpOnce` · 실패는 `countFailureAndRevoke`) → 등록 옵션(ES256 · platform · resident · UV required · attestation none · `excludeCredentials` = 내 살아 있는 열쇠 · `user.id` = 사람마다 고정 무작위 32바이트) |
| `POST /api/me/passkeys` | authenticated + CSRF | 등록 검증(지시서 1.4 「등록」 1~7) → 한 트랜잭션(사용자 행 `FOR UPDATE` → 살아 있는 < 5 → INSERT → 감사) · 201 |
| `DELETE /api/me/passkeys/{num}` | authenticated + CSRF | 내 것만 해제(`revoked_at`) — 남의 번호·없는 번호는 같은 404 |
| `POST /api/me/reauth/passkey/options` · `POST /api/me/reauth/passkey` | authenticated + CSRF · nginx `tbox_login` | 재인증(라운드 C) — `allowCredentials` = 지금 사람의 살아 있는 열쇠 · 챌린지는 이 세션+이 사람 · 성공이면 `SQL_SET_REAUTH`(지금 세션만, 15분) |
| `GET /app.webmanifest` | public · 데이터 0 | 홈 화면 앱 「Qaredi」 — 시작 `/community/` · 범위 `/` · standalone · PNG 192·512 · **서비스 워커 없음 · 바로가기 없음**(PK-D5) |

**설정(`Passkey::config()`, 부팅 때 한 번)** — RP ID·origin 은 **`.env` `APP_URL` 에서만**(요청 머리 Host·Origin·Referer·X-Forwarded-* 0 — 공유 링크와 같은 한 값).
`https://<호스트 이름>`(경로·쿼리·사용자정보 없음 · 포트 없거나 443) 또는 시험용 `http://localhost:<포트>` 만 켠다. IP·그 밖은 **꺼짐**(창구 503 · `/api/me`
`passkey_available:false` → 단추 숨김). 표가 없어도(마이그레이션 전) 꺼짐. RP ID 는 호스트 그대로 — `mso.so` 로 넓히지 않는다(다른 `*.mso.so` 는 남의 사이트).

**로그인 검증 순서(`Auth::loginWithPasskey`)** — ① 모양(허용 키만 · 엄격 base64url · 상한 clientDataJSON 2KB · authData 4KB · 서명 72 · rawId 16~1023 · `id = rawId`) ·
로그인 전 쿠키 → ② clientDataJSON `type = webauthn.get` → 챌린지 **한 문장 소비**(`purpose login` · 쿠키 sha256 일치 · 미사용 · 만료 전) → `origin` 정확 일치 · `crossOrigin` 거짓 →
③ 열쇠 찾기(`credential_id` 바이트 → `passkeys` → 사용자 열은 **`SQL_LOAD_USER` 와 같은 열 목록**(F-59 규칙) — 없으면 `passkey_unknown`) →
④ `userHandle` 이 있고 행의 `user_handle` 과 `hash_equals` → ⑤ authData 정확히 37바이트(ED 면 끝까지) · rpIdHash = sha256(RP ID) · UP · UV · AT 없음 · BE 가 등록 때와 같음 →
⑥ `openssl_verify(authData ‖ sha256(clientDataJSON)) === 1` **만** → ⑦ 서명 횟수: 둘 다 0 이면 통과 · 아니면 늘어야(역행 `passkey_counter` + AL-23) →
⑧ 여기까지 = 열쇠 주인. 해제·90일 → 401 「꺼진 열쇠」 문장 + `passkey_off`(유일한 다른 문구) · 잠금 423 · 비활성 · Guest 만료 · `must_change_password` → `forced_step`(R-39 우회 금지) →
⑨ **`Auth::issueSession`**(비밀번호 로그인 8)을 뽑아 둔 한 함수 — 세션 발급 두 벌 0) + 같은 트랜잭션에서 서명 횟수·마지막 사용·BS 갱신 · 로그인 전 쿠키 지움.
실패는 전부 401 한 문구(`MSG_LOGIN_FAILED` — 「없는 열쇠」와 「서명 틀림」 본문이 바이트까지 같다). ⑤~⑦ 실패는 그 계정 실패 1회(`countFailure` — **잠긴 동안은 세지 않는다**, 비밀번호 길과 같다).
모양 거부(①)는 400 이고 세지 않는다(잘못 만든 화면이 계정을 잠그지 않게).
**서명 횟수 갱신은 조건부**(`SQL_TOUCH_PASSKEY … AND sign_count = <③에서 읽은 값>` — 독립 검토 RV-PK-1, 2026-09-27): 같은 저장값을 읽은 두 요청 가운데 뒤쪽은 0 행 → 로그인은 세션 발급 트랜잭션을 되돌리고 401 한 문구 · 감사 `passkey_counter`(실패 1회) · 재인증은 `passkey_invalid`(창 안 열림).

**서버 검증은 직접 구현** — `src/Passkey.php`(★ 보안 핵심 · 외부 코드 0 · `openssl`·`hash` 만): CBOR 부분집합 해석기(입력 ≤ 4KB · 주 형식 0~5 와 7 의 false/true/null ·
무한 길이·태그·실수·8바이트 길이 거부 · 깊이 ≤ 4 · 맵 키 정수·글자 · 중복 키 거부 · UTF-8 검사 · 남는 바이트 0 · 실패는 `PasskeyError` 하나) · authData 자르기 ·
COSE EC2(kty 2 · alg -7 · crv 1 · x·y 32) → `0x04‖x‖y` → `WebPush::publicKeyFromRaw`(곡선 위 점 — **고치지 않고 부른다**) → SPKI DER 91바이트 저장 ·
증명은 `fmt none` + 빈 `attStmt` 만(검사 흉내 0). 교차 확인은 파이썬 `cryptography` 가 만든 표본 + 크로미움 가상 인증기 표본(PK-T1).

**챌린지 표 `passkey_challenges`** — 원문 저장 0(sha256 만) · 한 문장 소비 `UPDATE … SET used_at = now() WHERE challenge_hash = :h AND purpose = :p AND used_at IS NULL AND expires_at > now() AND <묶음> RETURNING` ·
수명 로그인 5분(자동 완성은 화면이 4분마다 새로 받음) · 등록·재인증 2분 · 묶음: 로그인 = 로그인 전 쿠키 sha256 · 등록·재인증 = `sessions.id` + 사람 ·
옵션 창구마다 `expires_at < now() - 10분` 정리.

**자동 해제(`Auth::revokeAllPasskeys`)** — S 의 `revokeAllApiTokens` 를 부르는 세 곳 바로 옆(본인 비밀번호 변경 `changePassword` · 임시 비밀번호 재발급 · 2단계 초기화) +
`users_update.php` 의 세션 끊는 조건(역할 변경 · 비활성화) 안 — **같은 트랜잭션**, 열쇠마다 `auth.passkey_revoke allow <사유>`. 팀 변경·2단계 끄기는 해제하지 않는다.
90일 = 마지막 사용(없으면 등록일) 기준 찾는 SQL 조건(배치 없음 — 목록에 「90일 안 써서 꺼짐」).

**화면(`public/assets/passkey.js` — 로그인·내 계정·재인증·권장 카드 공용)** — 휴대폰 판정(`userAgentData.mobile` · UA · 터치 맥) + `isUserVerifyingPlatformAuthenticatorAvailable()`
둘 다 참일 때만 단추(PK-D1 — **화면 편의, 권한 아님**, 서버로 보내지 않는다). 「이 기기 표시」 localStorage `qaredi.passkey-here = 1`(계정 정보 0 · 서버 판단에 안 씀):
있으면 로그인 화면이 열리자마자 지문 창(ⓒ) + 검정 「지문·얼굴로 로그인」 + 「비밀번호로 로그인」 · 없으면 폼 그대로 + 테두리 단추 + 아이디 칸 `autocomplete="username webauthn"`
자동 완성(ⓑ — `AbortController` 로 ⓒ 가 끝난 뒤). ⓒ 를 하지 않는 때: 방금 로그아웃(`sessionStorage` 표식) · 이 탭에서 한 번 취소 · 연달아 두 번 취소(표시 꺼짐 — 0.3초 안의 거절은
브라우저가 누름 없는 호출을 막은 것으로 보고 세지 않음) · 「꺼진 열쇠」 답(표시 꺼짐). 로그인 뒤 이동은 비밀번호 로그인과 **한 함수**(`app.js` `window.qarediLoginGo` — Q:F-7 B1-1).

**nginx** — `tbox_login`(30r/m · burst 10)을 로그인 둘 · 등록 옵션 · 재인증 둘 = 다섯 창구 × 끝 슬래시 변형 = 10 자리, `tbox-v2.conf`·`tbox-v2-local.conf` 둘 다(8장).
**DB** — 표 둘(`db/migrations/2026-10-06_passkeys.sql` · 10장). **감사** — 7.4 문장 · 7.5 새 키 다섯. **경보** — AL-23(11.4). **위험** — SECURITY R-109.

## 6. 저장 위치 — 파일 정보 vs 실제 파일

### 6.1 두 곳에 나눠 저장하는 이유

| 저장 대상 | 어디에 | 왜 |
|---|---|---|
| 파일 **정보** (이름, 크기, 레벨, 올린 사람, 해시) | PostgreSQL `files` 테이블 | 검색·권한 필터를 SQL로 하려면 DB에 있어야 함 |
| 파일 **내용** (바이트) | `/var/lib/tbox/storage/` | DB에 큰 바이너리를 넣으면 백업·성능이 나빠짐. 웹 루트 밖이라 URL 노출 없음 |

### 6.2 `files` 테이블

```sql
CREATE TABLE files (
    id            BIGSERIAL PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE DEFAULT gen_random_uuid(),  -- URL 에 노출되는 ID (순차 열거 방지, PRD S-09-5)
    original_name TEXT        NOT NULL,                 -- 사용자가 올린 이름 (화면 표시용, 출력 시 이스케이프)
    stored_name   TEXT        NOT NULL UNIQUE,          -- 서버가 만든 이름: UUID.ext  (경로 없음)
    ext           TEXT        NOT NULL,                 -- 화이트리스트 통과한 확장자
    mime          TEXT        NOT NULL,                 -- finfo 로 판별한 실제 MIME
    size_bytes    BIGINT      NOT NULL CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256        CHAR(64)    NOT NULL,                 -- 무결성 검사용
    description   TEXT        NOT NULL DEFAULT '' CHECK (length(description) <= 500),
    access_level  TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    team_id       BIGINT      REFERENCES teams(id),
    uploaded_by   BIGINT      NOT NULL REFERENCES users(id),
    scan_status   TEXT        NOT NULL DEFAULT 'clean' CHECK (scan_status IN ('pending','clean','infected')),
    exec_content  BOOLEAN     NOT NULL DEFAULT false,   -- F-65 (5.29): 실행 파일이 든 압축본을 관리자가 확인하고 올렸나. 참이면 공유·토큰·미리보기·등급 변경이 막히고 받을 때도 요청마다 비밀번호+OTP (업로드 때만 참이 되고 되돌아가지 않는다)
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ,                          -- NULL 이면 살아있는 파일
    deleted_by    BIGINT      REFERENCES users(id),
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;
```

### 6.2-1 `permissions` 테이블 — 권한 매트릭스를 데이터로

PRD 5.1·5.2 의 역할 × 레벨 표를 16행으로 저장한다. `Authz` 는 부팅 시 이 표를 읽어 캐시하고, 소유자·팀 규칙(5.3)만 코드로 판단한다.

```sql
CREATE TABLE permissions (
    role         TEXT NOT NULL CHECK (role IN ('Admin','Manager','Employee','Guest')),
    access_level TEXT NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    can_read     BOOLEAN NOT NULL DEFAULT false,   -- 목록·다운로드 (team 은 "같은 팀이면" 의 전제)
    can_upload   BOOLEAN NOT NULL DEFAULT false,   -- 이 레벨로 올릴 수 있나
    PRIMARY KEY (role, access_level)
);
-- 시드: PRD 5.1 / 5.2 그대로
INSERT INTO permissions VALUES
('Admin','public',true,true),   ('Admin','internal',true,true),   ('Admin','team',true,true),   ('Admin','admin-only',true,true),
('Manager','public',true,true), ('Manager','internal',true,true), ('Manager','team',true,true), ('Manager','admin-only',false,false),
('Employee','public',true,true),('Employee','internal',true,true),('Employee','team',true,true),('Employee','admin-only',false,false),
('Guest','public',true,false),  ('Guest','internal',false,false), ('Guest','team',false,false), ('Guest','admin-only',false,false);
```

| 규칙 | 이유 |
|---|---|
| 앱 계정(`tbox_app`)은 이 표에 **SELECT 만** | 화면·API 어디서도 권한 표를 바꿀 수 없어야 한다 (바꿀 수 있으면 그게 곧 권한 상승 경로) |
| 변경은 마이그레이션 파일 + 코드 리뷰 + `SECURITY.md` 이력 | 권한 표 변경은 곧 보안 정책 변경 |
| **17단계(Q-12 결정 2026-09-12)**: 화면 편집은 허용하되 **별도 롤 `tbox_perm`**(`permissions` SELECT·UPDATE 만)으로 한 핸들러에서만, 재인증·TOTP·사유·불변 규칙·감사 before/after·AL-13 과 함께 (5.15) | 앱 롤 `tbox_app` 은 SELECT 유지 — 다른 결함이 표를 못 바꾸는 성질은 그대로 |
| `team` 의 `can_read=true` 는 "같은 팀일 때" 라는 뜻 | 팀 일치 여부는 `Authz::canRead` 가 `users.team_id` 와 비교 |

> **`teams` 표 (12-3, 2026-09-12)** — `id, name(UNIQUE, 1~50자), is_active, created_at, updated_at`. 팀은 지우지 않고 `is_active=false` 로 내린다(5.9). `permissions` 는 12-3 뒤에도 **앱 계정 SELECT 만** — 조직·권한 화면의 매트릭스는 읽기 전용이다(편집은 17단계 `/admin/permissions` 에서만 — 5.15)(PRD Q-12).

### 6.2-3 `share_links` 테이블 — 공유 링크 (15-2, PRD F-13, ARCH 5.12)

| 컬럼 | 뜻 |
|---|---|
| `id BIGSERIAL` | 회수·목록에 쓰는 순차 id(`{num}`) |
| `token_hash CHAR(64) UNIQUE` | 토큰의 sha256. **원문은 저장하지 않는다** |
| `file_id BIGINT → files.id` | 어느 파일 |
| `created_by BIGINT → users.id` | 발급자. 받을 때 감사 detail 의 `issued_by` |
| `expires_at TIMESTAMPTZ NOT NULL` | 만료. `CHECK (expires_at <= created_at + interval '7 days')` |
| `max_downloads INT NOT NULL` | 1~10 (`CHECK`) |
| `download_count INT NOT NULL DEFAULT 0` | 받을 때마다 +1 (트랜잭션 안 조건부 UPDATE) |
| `revoked_at TIMESTAMPTZ`, `revoked_by BIGINT` | 회수. 행은 지우지 않는다 |
| `reason_text TEXT` | 선택 사유(≤500자) |
| `created_at TIMESTAMPTZ NOT NULL DEFAULT now()` | |

앱 계정 권한: `SELECT, INSERT, UPDATE` (DELETE 없음). `files` 에는 `sensitive_flags TEXT[] NOT NULL DEFAULT '{}'`(15-3, PRD F-22) 가 추가된다 — 값은 패턴 이름(`rrn`·`card`·`keyword`)만.


### 6.2-4 `folders` 테이블 — 논리 분류 (18-1, PRD F-26, ARCH 5.16)

| 컬럼 | 뜻 |
|---|---|
| `id BIGSERIAL` | `{num}` |
| `parent_id BIGINT NULL → folders.id ON DELETE RESTRICT` | NULL = 루트 바로 아래 |
| `name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 50)` | 같은 부모 안 중복 금지(`UNIQUE(parent_id, name)` + 루트용 부분 UNIQUE) |
| `depth INT NOT NULL CHECK (depth BETWEEN 1 AND 5)` | 부모 depth + 1 |
| `access_level TEXT NOT NULL CHECK (IN 4종)`, `team_id BIGINT NULL → teams.id`, `CHECK (access_level <> 'team' OR team_id IS NOT NULL)` | 폴더의 등급·팀 — 파일과 같은 제약(5.16 불변 규칙 ①은 앱이 지킨다) |
| `created_by BIGINT NOT NULL → users.id` | 관리 범위 판정(5.16 `decideFolderChange`) |
| `created_at`, `updated_at TIMESTAMPTZ NOT NULL DEFAULT now()` | |

`files.folder_id BIGINT NULL REFERENCES folders(id) ON DELETE RESTRICT`. 앱 계정 권한: `folders` SELECT·INSERT·UPDATE·DELETE(빈 폴더 삭제 — 파일이 참조하면 FK 가 막는다). 저장 경로(6.3)와 무관하다.


### 6.2-2 `sessions` 테이블 — DB 세션

PHP 기본 파일 세션을 쓰지 않고 DB 에 둔다. 비활성화·비밀번호 변경 시 그 사용자의 행을 지우면 **모든 기기에서 즉시 로그아웃**된다 (SECURITY R-10).

```sql
CREATE TABLE sessions (
    id           CHAR(64)    PRIMARY KEY,           -- 쿠키 토큰(32바이트 난수)의 SHA-256. 토큰 원문은 저장하지 않음
    user_id      BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at   TIMESTAMPTZ NOT NULL,              -- 유휴 30분 / 절대 8시간 (PRD S-09-2)
    ip           INET,
    user_agent   TEXT
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
```

흐름: 로그인 성공 → `random_bytes(32)` → 쿠키에 hex 토큰(HttpOnly·Secure·SameSite=Lax) → DB 에는 `hash('sha256', 토큰)`. 요청마다 해시로 조회 → `expires_at` 확인 → `last_seen_at` 갱신(Q:F-4 P-3 부터 60초보다 오래됐을 때만). 로그아웃은 행 삭제.
DB 가 유출돼도 해시만 있으므로 세션을 위조할 수 없다.

### 6.2-5 `api_tokens` 테이블 — 사용자별 프로그램 토큰 (F-59, PRD F-59, ARCH 4.6)

```sql
CREATE TABLE api_tokens (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users(id),
    name         TEXT        NOT NULL CHECK (length(name) BETWEEN 1 AND 50),   -- 라벨 「사무실 PC」
    token_hash   CHAR(64)    NOT NULL UNIQUE,   -- 48자 토큰의 sha256. 원문은 저장하지 않음
    hint         CHAR(6)     NOT NULL,          -- 토큰 앞 6자 — 화면에서 어느 토큰인지 알아보는 용도
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    revoked_by   BIGINT      REFERENCES users(id),
    reason_text  TEXT        CHECK (reason_text IS NULL OR length(reason_text) <= 500),
    -- F-66 (2026-09-22): 범위·만료. 마이그레이션 2026-09-22_api_tokens_scope_expiry.sql (기존 행은 read+upload+delete · created_at+180일)
    scope        TEXT        NOT NULL CHECK (scope IN ('read','read+upload','read+upload+delete')),
    expires_at   TIMESTAMPTZ NOT NULL           -- 발급 + 180일 고정 (ARCH 4.7)
);
CREATE INDEX api_tokens_user_idx ON api_tokens (user_id);
-- 권한: tbox_app 에 SELECT, INSERT, UPDATE 만 (DELETE 없음 — 회수는 revoked_at). 시퀀스 USAGE, SELECT.
```

`token_hash` 의 UNIQUE 가 조회 인덱스를 겸한다(`WHERE token_hash = … AND revoked_at IS NULL` 이 유일한 조회 경로). 살아 있는 토큰 5개 상한은 앱이 본다(DB CHECK 로 표현하기 어려운 조건). 기존 DB 는 `db/migrations/2026-09-16_api_tokens.sql` 로 같은 모양이 된다.

### 6.3 실제 파일의 위치와 이름

```
/var/lib/tbox/storage/
└── 3f/
    └── 9a/
        └── 3f9a7c1e-2b4d-4f0e-9b1a-7c8d2e5f6a01.pdf     ← stored_name
```

| 규칙 | 이유 |
|---|---|
| 이름은 서버가 만든 **UUID**만 사용 | 사용자 파일명(`../../x`)이 경로에 절대 들어가지 않음 |
| 앞 2글자/다음 2글자로 하위 폴더 분산 | 한 폴더에 수십만 개가 쌓이는 것 방지 |
| 최상위 `storage/` 와 하위 폴더 모두 `0750`, 파일 `0640` — setgid 없음 | ~~setgid(2750)+그룹 www-data 상속~~ 은 X-Accel 전제였음. **6단계 결정(2026-09-11, R-34)**: 다운로드는 PHP `readfile()` 로 확정 → nginx 가 저장소를 읽을 필요가 없으므로 그룹 상속도 필요 없다 |
| 디렉터리 `0750`, 파일 `0640`, 소유자·**그룹 `tbox-php`** (6단계부터) | 다른 계정·웹 서버(`www-data`)가 직접 읽지 못한다. 옛 취약판 v1 이 같은 VM 에서 `www-data` 로 돌기 때문에 그룹 `www-data` 읽기는 곧 v1 웹셸의 읽기 권한이었다(R-34) → `install.sh` 가 `/var/lib/tbox-v2`·`storage` 그룹을 `tbox-php` 로 회수. nginx `/protected/` `internal` 블록은 404 보장용으로만 남긴다 |
| 디렉터리에 실행 권한·PHP 핸들러 없음 | 어떤 파일이 들어와도 실행되지 않음 |
| 원본 파일명은 DB에만, 다운로드 헤더에 인코딩해서 사용 | 헤더 인젝션(CR/LF) 방지 |

---

## 7. 감사 로그 — 어디에, 어떻게 남기나

### 7.1 두 곳에 동시에 기록

```
 Audit::allow() / Audit::deny()
        │
        ├──▶ [DB] audit_logs  INSERT  (같은 트랜잭션 안. 실패하면 본 작업도 ROLLBACK)
        │
        └──▶ [파일] /var/log/tbox/audit.log  한 줄 JSON 추가 (append)
                    → logrotate 로 일 단위 회전, 1년 보존, 원격으로 복사
```

| 저장소 | 장점 | 단점 | 역할 |
|---|---|---|---|
| DB `audit_logs` | 검색·필터 쉬움, 관리 화면에서 조회 | DB가 침해되면 함께 위험 | **원본** (조회용) |
| 파일 `audit.log` | DB와 독립, 외부 SIEM으로 전송 쉬움 | 검색 불편 | **사본** (증거 보전용) |

**파일 사본을 "추가만 되게" 만드는 방법 (2026-09-10 추가, PRD S-07 "파일 사본 보호", R-11)**

| 무엇 | 어떻게 | 어디에 구현 | 확인 명령 |
|---|---|---|---|
| 앱이 지우거나 고칠 수 없게 | `chattr +a /var/log/tbox-v2/audit.log` — 파일 소유자(`tbox-php`)여도 덧붙이기만 된다. 해제는 root 만 | `deploy/install.sh` (파일 생성 직후) | `lsattr` 에 `a` |
| 커지지 않게 | `logrotate`: `daily`, `rotate 365`, `compress`, `delaycompress`, `dateext`, `missingok`, `notifempty`, `create 0640 tbox-php adm`, `prerotate` 에서 `chattr -a`, `postrotate` 에서 새 파일에 `chattr +a` | `deploy/logrotate.d/tbox-v2` → `/etc/logrotate.d/tbox-v2` (install.sh 가 복사) | `logrotate -d /etc/logrotate.d/tbox-v2` 오류 0 |
| 서버가 털려도 남게 | rsyslog(omfile 감시 → TLS 전송) 로 원격 로그 서버에 실시간 사본 | 9단계 (ARCH 11.4) | 원격에서 줄 수 대조 |
| 지워졌는지 알 수 있게 | 일별 sha256 을 직전 날의 값과 엮어 따로 기록(해시 체인) | 9단계 `scripts/audit_chain.php` | 체인 검증 스크립트 |

**체인을 언제 봉인하나 — 봉인 시점 (13단계 보정 2026-09-12, SECURITY R-50, PRD T-29)**

| 무엇 | 규칙 |
|---|---|
| cron 00:05 | `audit_chain.php --yesterday` — **어제** 회전 파일(`audit.log-YYYYMMDD`)을 봉인한다. 인자가 없어도 어제다 |
| 왜 오늘이 아닌가 | logrotate 가 00:00 에 회전하므로 00:05 의 "오늘 파일" 은 방금 만들어진 **빈 파일**이다. 전에는 그 0줄짜리 해시(`e3b0c4…`)가 체인에 적히고 **어제는 영영 봉인되지 않았다**. 오늘 파일은 하루 종일 자라니 다음 `--verify` 는 반드시 실패했고, 매일 나는 AL-6 헛경보가 진짜 변조를 덮었다(2026-09-12 실측: 09-11·09-12 두 날 `hash_changed` — 변조가 아니라 설계 결함. 09-11 기록 807줄의 해시가 회전 파일 앞 807줄과 정확히 일치) |
| `--verify` 와 열린 날 | 체인에 **오늘** 날짜 항목이 있으면 해시를 비교하지 않고 「열린 날 — 검산 제외」로 표기한다. **실패로 세지 않으며** 검산 건수(`detail.checked`)에서도 뺀다. 연결(`prev`)은 그대로 확인한다 |
| 닫힌 날이 달라지면 | 지금처럼 `audit.integrity error mismatch` + **AL-6**. 체인은 고치지 않는다 — 그것이 변조 신호다 |
| 잘못 적힌 항목 바로잡기 | 일회성 `--reseal YYYY-MM-DD`(사람이 그 날 파일을 확인한 뒤 부른다). 옛 해시·줄수·바이트수를 `audit.integrity allow` 의 `detail.resealed_from_hash`·`resealed_from_lines`·`resealed_from_bytes` 에 남기고 항목을 갱신한 뒤 **뒤따르는 항목의 `prev` 를 다시 잇는다**(`detail.relinked` = 다시 이은 개수). 감사 로그에 "무엇이 무엇으로 바뀌었나" 가 없으면 재봉인은 증거 조작과 구분되지 않는다. 오늘·미래 날짜에는 쓸 수 없다 |

> DB 쪽은 `db/grants.sql`(아래) 로 이미 추가 전용이다 — 2026-09-10 실측 `\dp audit_logs` → `tbox_app=ar`. 파일 쪽 두 줄(속성·회전)이 없었던 것을 같은 날 발견해 4단계 운영 보강으로 넣는다.

### 7.2 `audit_logs` 테이블과 "지울 수 없게" 만드는 방법

```sql
CREATE TABLE audit_logs (
    id           BIGSERIAL PRIMARY KEY,
    happened_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    request_id   UUID        NOT NULL,
    actor_id     BIGINT,                 -- NULL = 시스템(배치)
    actor_name   TEXT,
    actor_role   TEXT,                   -- 행동 당시 역할 (나중에 바뀌어도 남김)
    action       TEXT        NOT NULL,   -- auth.login, file.download, file.delete, user.role_change ...
    target_type  TEXT,                   -- file / user / system
    target_id    BIGINT,
    target_name  TEXT,
    access_level TEXT,
    result       TEXT        NOT NULL CHECK (result IN ('allow','deny','error')),
    reason       TEXT,                   -- role_not_permitted / not_owner / mime_mismatch ...
    ip           INET,
    user_agent   TEXT,
    detail       JSONB,                  -- 이전값→새값 등. 비밀번호·토큰·파일내용 금지
    summary      TEXT        NOT NULL    -- 초보자용 한국어 요약 한 문장. 기록 시점에 7.4 문장 표로 생성 (2026-09-10)
);
CREATE INDEX audit_time_idx  ON audit_logs (happened_at DESC);
CREATE INDEX audit_actor_idx ON audit_logs (actor_id, happened_at DESC);
```

```sql
-- db/grants.sql : 앱 계정은 감사 로그를 "추가"만 할 수 있다 (PRD S-07 Append-only)
REVOKE ALL ON audit_logs FROM tbox_app;
GRANT  SELECT, INSERT ON audit_logs TO tbox_app;
GRANT  USAGE, SELECT  ON SEQUENCE audit_logs_id_seq TO tbox_app;
-- UPDATE / DELETE / TRUNCATE 권한 없음 → 코드에 버그가 있어도, 침입자가 앱 계정을 얻어도 못 지움
```

### 7.3 기록 예시 (audit.log 한 줄)

```json
{"happened_at":"2026-09-08T14:03:11+09:00","request_id":"8f3a...","actor_id":12,"actor_name":"employee01",
 "actor_role":"Employee","action":"file.download","target_type":"file","target_id":57,
 "target_name":"급여명세_2026.pdf","access_level":"admin-only","result":"deny",
 "reason":"role_not_permitted","ip":"10.0.1.23","user_agent":"Mozilla/5.0 ...",
 "summary":"employee01 이(가) admin-only 등급 파일 「급여명세_2026.pdf」 을(를) 내려받으려 했지만 권한이 없어 막았습니다"}
```

> 위 한 줄이 바로 "실습에서 뚫렸던 시도"가 이번엔 **막히고 기록된** 모습이다. 마지막 `summary` 만 읽어도 무슨 일인지 알 수 있다.

> **로그인 행의 "누가"**: `auth.login` 은 세션이 만들어지기 전이라 `actor_*` 가 NULL 이고, 대상 사용자는 `target_type=user`, `target_id`, `target_name=username` 에 남는다. 실패(`deny`)도 같다 — 입력된 아이디가 `target_name` 이다. 조회할 때 `actor_name` 대신 `target_name` 을 본다 (2단계 결정).

### 7.4 요약 문장 표 — `summary` 는 이 표로만 만든다 (2026-09-10 결정, PRD S-07)

**왜**: 원본 항목(`action`·`result`·`reason`)은 코드 언어라 초보자가 읽기 어렵다. 그래서 기록 시점에 한국어 한 문장을 함께 저장한다. 조회 시점에 번역하지 않고 **기록 시점에 저장**하는 이유는 ① `tail -f audit.log` 만으로 읽히고 ② 나중에 문장 틀을 바꿔도 과거 기록의 문장은 당시 그대로 남아 증거로서 흔들리지 않기 때문이다.

**규칙**
- 문장 틀은 `src/Audit.php` 의 상수 표 하나에만 둔다. 키는 `action|result|reason` (allow 는 reason 이 없으므로 `action|allow`).
- 표에 없는 조합은 **예외 → 기록 실패 → 요청 실패(500)**. 새 행동·새 사유를 추가하는 사람이 문장 틀을 빠뜨리면 첫 요청에서 바로 드러난다. 조용한 기본 문장으로 넘어가지 않는다.
- 자리표시자는 세 개뿐: `{actor}` = `actor_name`(없으면 `(로그인 전)`), `{target}` = `target_name`, `{level}` = `access_level`. 요청 값·`detail`·`user_agent` 는 문장에 넣지 않는다.
- 치환은 **한 번에**(`strtr` 배열 방식) 한다. 순차 치환(`str_replace` 배열)을 쓰면 파일명 안의 `{level}` 같은 문자열이 다시 치환되어 기록이 왜곡된다 — 2026-09-10 검토에서 파일명 `{level}{target}{actor}.txt` 가 `internal{target}{actor}.txt` 로 기록된 것을 실측해 추가한 규칙. 값 안의 문자는 어떤 경우에도 자리표시자로 해석하지 않는다.
- 파일명은 「 」 로 감싼다. 문장 길이는 1,000자에서 자른다(`target_name` 은 이미 500자 제한).
- 아래 표가 곧 **사유 코드 등록부**다. 4단계 이후 핸들러가 쓰는 `reason` 은 이 표에 있는 값만 쓴다.
- `summary` 는 **사람이 읽기 위한 보조 필드**다. 파일명은 사용자가 정한 자유 텍스트라 `「x」 을(를) 삭제했습니다.txt` 같은 이름으로 문장을 흉내 낼 수 있다(2026-09-10 실측). 조사·분쟁 때의 판단 근거는 `action`·`result`·`reason`·`target_id` 구조화 컬럼이며, 8단계 조회 화면은 `summary` 를 다른 사용자 입력과 똑같이 이스케이프(`textContent`)해 출력한다.

| 키 (`action|result|reason`) | 문장 틀 | 쓰는 곳 |
|---|---|---|
| `auth.login|allow` | `{target} 이(가) 로그인했습니다` | Auth (actor 없음 → target) |
| `auth.login|deny|bad_credentials` | `{target} 계정으로 로그인을 시도했지만 아이디 또는 비밀번호가 틀렸습니다` | Auth |
| `auth.login|deny|locked` | `{target} 계정으로 로그인을 시도했지만 실패 5회로 10분 잠긴 계정입니다` | Auth |
| `auth.login|deny|inactive` | `{target} 계정으로 로그인을 시도했지만 비활성화된 계정입니다` | Auth |
| `auth.lock|deny|failed_limit` | `{target} 계정이 인증 실패 5회로 10분 동안 잠겼습니다` | Auth::countFailure — 카운터가 한도에 **막 닿은** 실패 한 번에만(잠긴 동안의 추가 실패는 다시 남지 않음). 로그인 전이면 actor 없음. `detail.lock_minutes`. 경보 AL-2 가 「잠금 발생」으로 센다 (디버깅 묶음 S2) |
| `auth.login|deny|temp_expired` | `{target} 계정으로 로그인을 시도했지만 임시 비밀번호의 유효기간(발급 뒤 72시간)이 지났습니다` | Auth::login (보안 넷 ④ — SECURITY R-39 · `users.temp_password_issued_at`) |
| `auth.logout|allow` | `{actor} 이(가) 로그아웃했습니다` | Auth |
| `auth.required|deny|not_authenticated` | `로그인하지 않은 채 {target} 에 접근하려 해서 막았습니다` | Auth::requireLogin |
| `csrf|deny|token_mismatch` | `{actor} 이(가) {target} 요청을 보냈지만 CSRF 토큰이 없거나 달라서 막았습니다 (다른 사이트에서 몰래 보낸 요청일 수 있음)` | Http::requireCsrf |
| `authz.route|deny|role_not_permitted` | `{actor} 이(가) 권한이 없는 기능({target})을 쓰려 해서 막았습니다` | index.php |
| `file.upload|allow` | `{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 올렸습니다` | files_upload |
| `file.upload|deny|role_not_permitted` | `{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 올리려 했지만 그 등급으로 올릴 권한이 없어 막았습니다` | 4단계 canUploadAs |
| `file.upload|deny|team_mismatch` | `{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 올리려 해서 막았습니다` | 4단계 canUploadAs |
| `file.upload|deny|no_file` | `{actor} 이(가) 파일 없이 업로드를 요청해서 거부했습니다` | files_upload |
| `file.upload|deny|empty_file` | `{actor} 이(가) 0바이트 파일 「{target}」 을(를) 올리려 해서 거부했습니다` | Upload |
| `file.upload|deny|too_large` | `{actor} 이(가) 100MB 를 넘는 파일 「{target}」 을(를) 올리려 해서 거부했습니다` | Upload |
| `file.upload|deny|bad_access_level` | `{actor} 이(가) 파일 「{target}」 을(를) 존재하지 않는 접근 등급으로 올리려 해서 거부했습니다` | files_upload |
| `file.upload|deny|bad_team` | `{actor} 이(가) 파일 「{target}」 을(를) 없는 팀 또는 팀 지정 없이 team 등급으로 올리려 해서 거부했습니다` | files_upload |
| `file.upload|deny|description_too_long` | `{actor} 이(가) 올린 파일 「{target}」 의 설명이 500자를 넘어 거부했습니다` | files_upload |
| `file.upload|deny|bad_encoding` | `{actor} 이(가) 올린 파일의 이름 또는 설명이 올바른 문자(UTF-8)가 아니어서 거부했습니다` | files_upload |
| `file.upload|deny|upload_error` | `{actor} 이(가) 보낸 업로드 요청의 형식이 잘못되어(파일 여러 개 등) 거부했습니다` | Upload |
| `file.upload|deny|not_uploaded_file` | `{actor} 이(가) 보낸 업로드가 정상적인 HTTP 업로드가 아니어서 거부했습니다` | Upload |
| `file.upload|deny|size_unreadable` | `{actor} 이(가) 올린 파일 「{target}」 의 크기를 읽을 수 없어 거부했습니다` | Upload |
| `file.upload|deny|hash_failed` | `{actor} 이(가) 올린 파일 「{target}」 의 무결성 값을 계산할 수 없어 거부했습니다` | Upload |
| `file.upload|deny|ext_not_allowed` | `{actor} 이(가) 허용되지 않는 종류의 파일 「{target}」 을(를) 올리려 해서 거부했습니다` | 6단계 Upload |
| `file.upload|deny|mime_mismatch` | `{actor} 이(가) 올린 파일 「{target}」 의 실제 내용이 확장자와 달라(위장 파일 의심) 거부했습니다` | 6단계 Upload |
| `file.upload|error|upload_failed` | `{actor} 이(가) 파일 「{target}」 을(를) 올리는 중 서버 오류가 나서 되돌렸습니다` | files_upload |
| `file.upload|deny|double_extension` | `{actor} 이(가) 이중 확장자 파일 「{target}」 을(를) 올리려 해서 거부했습니다 (실행 파일 위장 의심)` | 6단계 Upload (`a.pdf.php`, `shell.php.txt`) |
| `file.upload|deny|quota_exceeded` | `{actor} 이(가) 파일 「{target}」 을(를) 올리려 했지만 하루 업로드 한도(2GB)를 넘어 거부했습니다` | 6단계 Upload (PRD S-06 일일 총량) |
| `file.fetch|allow` | `{actor} 이(가) 허용된 외부 주소에서 파일 「{target}」 을(를) 가져와 {level} 등급으로 저장했습니다` | 7단계 files_fetch (target = 저장된 파일명, detail.source_host) |
| `file.fetch|deny|scheme` | `{actor} 이(가) https 가 아닌 주소로 파일 가져오기를 요청해서 막았습니다` | 7단계 Fetch::check (S-05 ①) |
| `file.fetch|deny|port` | `{actor} 이(가) 443 이 아닌 포트로 파일 가져오기를 요청해서 막았습니다 (내부 포트 탐색 의심)` | 7단계 Fetch::check (S-05 ②) |
| `file.fetch|deny|host_not_allowed` | `{actor} 이(가) 허용 목록에 없는 주소(또는 IP·localhost·잘못된 표기)로 파일 가져오기를 요청해서 막았습니다` | 7단계 Fetch::check (S-05 ③④) |
| `file.fetch|deny|private_ip` | `{actor} 이(가) 요청한 주소가 내부망 IP 로 해석되어 파일 가져오기를 막았습니다 (SSRF 의심)` | 7단계 Fetch::check (S-05 ⑤) |
| `file.fetch|deny|redirect` | `{actor} 이(가) 요청한 주소가 다른 곳으로 넘기는(리다이렉트) 응답을 보내 파일 가져오기를 막았습니다` | 7단계 Fetch::download (S-05 ⑦) |
| `file.fetch|deny|too_large` | `{actor} 이(가) 요청한 주소의 파일이 100MB 를 넘어 가져오기를 막았습니다` | 7단계 Fetch::download (S-05 ⑧) |
| `file.fetch|deny|fetch_failed` | `{actor} 이(가) 요청한 주소에서 파일을 받을 수 없었습니다 (연결 실패·시간 초과·200 이 아닌 응답)` | 7단계 Fetch::download |
| `file.fetch|deny|invalid_file` | `{actor} 이(가) 외부 주소에서 가져온 파일 「{target}」 이(가) 업로드 규칙(확장자·내용·크기)에 맞지 않아 저장하지 않았습니다` | 7단계 Upload::validate 재사용 (S-05 ⑨) |
| `file.fetch|deny|rate_limited` | `{actor} 이(가) 한 시간에 10회를 넘게 파일 가져오기를 요청해서 막았습니다` | 7단계 files_fetch (S-05 ⑪) |
| `file.fetch|deny|bad_request` | `{actor} 이(가) 파일 가져오기 요청을 잘못된 형식(URL 없음·등급 오류·팀 오류)으로 보내 거부했습니다` | 7단계 files_fetch |
| `file.fetch|deny|role_not_permitted` | `{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 가져오려 했지만 그 등급으로 올릴 권한이 없어 막았습니다` | 7단계 decideUploadAs 재사용 |
| `file.fetch|deny|team_mismatch` | `{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 가져오려 해서 막았습니다` | 7단계 decideUploadAs 재사용 |
| `file.fetch|error|fetch_failed` | `{actor} 이(가) 외부 주소에서 가져온 파일 「{target}」 을(를) 저장하는 중 서버 오류가 나서 되돌렸습니다` | 7단계 files_fetch 트랜잭션 실패 |
| `audit.view|allow` | `{actor} 이(가) 감사 로그를 조회했습니다 (필터는 상세 기록)` | 8단계 audit_list (조회 자체를 기록, PRD 7.7) |
| `file.restore|allow` | `{actor} 이(가) 휴지통의 {level} 등급 파일 「{target}」 을(를) 복구했습니다` | 10단계 files_restore (Admin). **Q:F-4 U-2b(2026-09-24)** — 올린 사람 본인도 복구한다. 허용 근거는 `detail.granted_by`: `admin_all`(Admin, 모든 파일) · `owner`(본인이 올리고 본인이 지운 파일). 코드는 `Authz::decideRestore` 가 준 값 그대로 |
| `file.restore|deny|not_found_in_trash` | `{actor} 이(가) 휴지통에 없는 파일을 복구하려 해서 거부했습니다` | 10단계 files_restore. **Q:F-4 U-2b** — 남의 파일(남이 올렸거나 남이 지운 것)·올릴 수 없는 역할의 복구 시도도 **이 문장 하나**다: 응답은 없는 파일과 같은 404 문구, 기록도 같은 `reason`·같은 대상 칸(`target_name` 없음). 「내 활동」(F-12)이 본인 행의 `target_name`·`summary` 를 보여 주므로 여기서 이름·사유를 가르면 그것이 곧 「그 UUID 의 파일이 휴지통에 있다」는 신호가 된다 — 있다·없다를 가르지 않는다. 역할 판정은 `Authz::decideRestore` 가 이 코드를 돌려준다 |
| `file.purge|allow` | `휴지통에 30일 넘게 있던 {level} 등급 파일 「{target}」 을(를) 배치가 영구 삭제했습니다` | 9단계 scripts/purge_trash.php (actor 없음 = 시스템) |
| `file.purge|error|purge_failed` | `휴지통 파일 「{target}」 영구 삭제 중 오류가 나서 건너뛰었습니다 (점검 필요)` | 9단계 purge_trash |
| `file.download|deny|scan_pending` | `{actor} 이(가) 아직 검사 중인 파일 「{target}」 을(를) 내려받으려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `file.download|deny|scan_infected` | `{actor} 이(가) 악성코드로 격리된 파일 「{target}」 을(를) 내려받으려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `file.preview|deny|scan_pending` | `{actor} 이(가) 아직 검사 중인 파일 「{target}」 을(를) 미리 보려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `file.preview|deny|scan_infected` | `{actor} 이(가) 악성코드로 격리된 파일 「{target}」 을(를) 미리 보려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `share.download|deny|scan_pending` | `공유 링크로 아직 검사 중인 파일 「{target}」 을(를) 내려받으려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `share.download|deny|scan_infected` | `공유 링크로 악성코드가 격리된 파일 「{target}」 을(를) 내려받으려 해서 막았습니다` | F-54 읽기 창구 [4] (ARCH 5.28) |
| `share.download|deny|issuer_inactive` | `공유 링크를 만든 사람의 계정이 꺼졌거나 만료되어 파일 「{target}」 을(를) 링크로 받지 못하게 막았습니다` | 디버깅 묶음 S-2 (5.12 받기 ⑤-A) |
| `share.download|deny|role_not_permitted` | `공유 링크를 만든 사람이 지금은 {level} 등급 파일 「{target}」 에 대한 권한이 없어 링크로 받지 못하게 막았습니다` | 디버깅 묶음 S-2 (5.12 받기 ⑤-A · `{level}` = 파일의 지금 등급 · decideRead·decideEdit 공통) |
| `share.download|deny|team_mismatch` | `공유 링크를 만든 사람이 지금은 다른 팀의 파일 「{target}」 에 대한 권한이 없어 링크로 받지 못하게 막았습니다` | 디버깅 묶음 S-2 (5.12 받기 ⑤-A · decideRead·decideEdit 공통) |
| `share.download|deny|not_owner` | `공유 링크를 만든 사람이 지금은 파일 「{target}」 을(를) 고칠 수 없어 링크로 받지 못하게 막았습니다` | 디버깅 묶음 S 검토 L-2 (5.12 받기 ⑤-A — decideEdit) |
| `file.scan|allow` | `배치가 파일 「{target}」 을(를) 검사했습니다 — 이상 없음` | scripts/scan_pending.php (ARCH 5.28) |
| `file.scan|deny|infected` | `배치가 파일 「{target}」 에서 악성코드를 발견해 격리했습니다` | scripts/scan_pending.php (ARCH 5.28) |
| `file.scan|error|scan_failed` | `파일 「{target}」 검사 중 오류가 나서 다음 실행에 다시 시도합니다` | scripts/scan_pending.php (ARCH 5.28) |
| `backup.run|allow` | `백업이 완료되었습니다 (대상·산출물·검증값은 상세 기록)` | 9단계 scripts/backup_*.sh → audit_cli.php (시스템) |
| `backup.run|error|backup_failed` | `백업이 실패했습니다 (사유는 상세 기록·app.log) — 2회 연속이면 경보 AL-8` | 9단계 |
| `backup.restore|allow` | `{actor} 이(가) 백업에서 복구를 실행했습니다 (대상 시점·범위는 상세 기록)` | 9단계 scripts/restore.sh (운영자, actor = 실행 계정명) |
| `backup.restore|error|restore_failed` | `백업 복구가 실패해 되돌렸습니다 (단계는 상세 기록)` | 16-2 scripts/restore.sh 의 중단 경로(die·EXIT trap·정합성 검사 실패). `detail.step` 에 멈춘 단계. **성공 기록(allow)은 성공을 확인한 뒤에만** 남긴다 (SECURITY 8.4-10) |
| `audit.integrity|allow` | `정합성·무결성 점검을 마쳤습니다 (검사 건수·불일치 0)` | 9단계 consistency_check.php · audit_chain.php |
| `audit.integrity|error|mismatch` | `정합성·무결성 점검에서 불일치가 발견되었습니다 (목록은 상세 기록) — 경보 AL-6` | 9단계 |
| `alert.raised|allow` | `경보 {target} 조건이 충족되어 알림을 보냈습니다 (근거는 상세 기록)` | 9단계 scripts/alerts.php (target = AL-1 등 규칙 번호) |
| `alert.push_subscribe|allow` | `{actor} 이(가) 휴대폰 경보 받기 기기를 등록했습니다 (푸시 서버 {target} — 기기 요약은 상세 기록)` | F-70 받기 등록 `POST /api/admin/push/subscriptions` (Admin · 재인증 · CSRF). target = 푸시 서버 호스트, detail `{host, device_hint}` — 주소(endpoint)·기기 키·auth 는 남기지 않는다 |
| `alert.push_subscribe|deny|bad_request` | `{actor} 이(가) 휴대폰 경보 받기를 등록하려 했지만 받기 정보의 모양이 맞지 않아 거부했습니다` | F-70 — 허용 키 셋(`endpoint`·`keys.p256dh`·`keys.auth`) 밖 · 문자열 아님 · 1,024자 초과 |
| `alert.push_subscribe|deny|bad_endpoint` | `{actor} 이(가) 휴대폰 경보 받기를 등록하려 했지만 받기 주소가 https·443·호스트 표기 규칙에 맞지 않아 거부했습니다` | F-70 — `Fetch::pushEndpoint` ①②④(http · `:8443` · IP 리터럴 · `user@host` …). target 없음(정규화에 실패한 글자를 남기지 않는다) |
| `alert.push_subscribe|deny|host_not_allowed` | `{actor} 이(가) 허용 목록 밖의 푸시 서버 {target} 로 휴대폰 경보 받기를 등록하려 해서 거부했습니다` | F-70 — 호스트가 `Fetch::PUSH_HOSTS` 정확 일치가 아님(SSRF 시도의 흔적 — R-99 ①). target = 정규화한 호스트 |
| `alert.push_subscribe|deny|bad_key` | `{actor} 이(가) 휴대폰 경보 받기를 등록하려 했지만 기기 키가 올바른 P-256 공개 키가 아니거나 auth 길이가 맞지 않아 거부했습니다` | F-70 — p256dh 65바이트·0x04·곡선 위의 점, auth 16바이트(`WebPush::checkSubscriptionKeys`) |
| `alert.push_subscribe|deny|too_many` | `{actor} 이(가) 휴대폰 경보 받기 기기를 네 대째 등록하려 해서 거부했습니다 (한 사람 3대까지)` | F-70 L-6 — 살아 있는 받기 COUNT(사람 행 잠금 뒤) |
| `alert.push_subscribe|deny|duplicate` | `{actor} 이(가) 다른 사람이 이미 등록한 받기 주소로 휴대폰 경보 받기를 등록하려 해서 거부했습니다` | F-70 — 같은 주소의 살아 있는 받기가 다른 계정 것(한 브라우저를 두 관리자가 나눠 쓸 때) |
| `alert.push_unsubscribe|allow` | `{actor} 이(가) 휴대폰 경보 받기 기기 하나를 해지했습니다 (푸시 서버 {target})` | F-70 `DELETE /api/admin/push/subscriptions/{num}` — 자기 것만, `revoked_reason='user'`(행은 지우지 않는다) |
| `alert.push_unsubscribe|deny|not_found` | `{actor} 이(가) 없거나 자기 것이 아닌 휴대폰 경보 받기를 해지하려 해서 막았습니다` | F-70 — 남의 받기·이미 해지한 받기·없는 번호는 같은 404 문구 |
| `alert.push_revoke|allow` | `배치가 휴대폰 경보 받기 하나를 해지했습니다 (푸시 서버 {target} — 사유는 상세 기록)` | F-70 scripts/alert_push.php — detail `{host, revoked_reason}`: `not_admin`(받는 사람이 지금 활성 Admin 이 아님) · `gone`(푸시 서버 404/410) |
| `alert.push_test|allow` | `{actor} 이(가) 휴대폰 시험 알림을 요청했습니다 (1분 안에 배치가 보냅니다)` | F-70 `POST /api/admin/push/test` — 대기표에 한 행만 넣는다(웹은 보내지 않는다) |
| `alert.push_test|deny|rate_limited` | `{actor} 이(가) 10분 안에 휴대폰 시험 알림을 다시 요청해서 막았습니다` | F-70 — 사람당 10분에 1회(429) |
| `alert.push_test|deny|no_subscription` | `{actor} 이(가) 휴대폰 시험 알림을 요청했지만 등록한 기기가 없어 거부했습니다` | F-70 — 살아 있는 받기 0 |
| `alert.push_send|allow` | `배치가 휴대폰 경보 알림 한 건을 푸시 서버 {target} 에 넘겼습니다 (규칙·종류·상태 코드는 상세 기록)` | F-70 scripts/alert_push.php (시스템 — actor 없음). detail `{host, status, rule, kind}` 만 — 주소·키·내용 원문 없음 |
| `alert.push_send|error|http_status` | `배치가 휴대폰 경보 알림을 푸시 서버 {target} 에 넘기지 못했습니다 — 푸시 서버가 받지 않았습니다 (상태 코드는 상세 기록)` | F-70 — 2xx 가 아닌 응답(404/410 → 해지 · 413 → 기록만 · 429/5xx → 다음 실행에 다시, 최대 3회) |
| `alert.push_send|error|fetch_failed` | `배치가 휴대폰 경보 알림을 푸시 서버 {target} 에 보내지 못했습니다 — 연결·TLS·시간 초과 (다음 실행에 다시)` | F-70 — `Fetch::pushSend` fetch_failed(연결 3초·전체 10초) |
| `alert.push_send|error|redirect` | `배치가 휴대폰 경보 알림을 보내려 했지만 푸시 서버 {target} 가 다른 주소로 넘겨서(3xx) 따라가지 않았습니다` | F-70 — S-05 ⑦(리다이렉트 = 실패) |
| `alert.push_send|error|private_ip` | `배치가 휴대폰 경보 알림을 보내려 했지만 푸시 서버 {target} 의 이름이 내부망 주소로 풀려 보내지 않았습니다` | F-70 — S-05 ⑤(보낼 때마다 DNS 를 다시 풀어 본다) |
| `alert.push_send|error|host_not_allowed` | `배치가 허용 목록 밖 푸시 서버로 가는 받기 {target} 에 보내지 않았습니다` | F-70 — 저장된 받기의 호스트가 지금 `PUSH_HOSTS` 에 없음(코드 상수를 줄인 경우) |
| `alert.push_send|error|bad_endpoint` | `배치가 모양이 맞지 않는 받기 주소에 휴대폰 경보 알림을 보내지 않았습니다` | F-70 — 저장된 주소가 `Fetch::pushEndpoint` 를 통과하지 못함 |
| `alert.push_send|error|too_large` | `배치가 휴대폰 경보 알림을 보냈지만 푸시 서버 {target} 의 응답 본문이 8KB 를 넘어 끊었습니다` | F-70 — S-05 ⑧(응답 본문은 읽어 버린다, 최대 8KB) |
| `alert.push_send|error|bad_key` | `배치가 받기의 기기 키로 휴대폰 경보 알림을 암호화하지 못해 보내지 않았습니다 (푸시 서버 {target})` | F-70 — RFC 8291 암호화 실패(`WebPush::encrypt`) |
| `alert.push_send|error|no_key` | `배치가 VAPID 서명 키를 읽지 못해 휴대폰 경보 알림을 보내지 않았습니다 (키 파일·권한 확인 — 계속되면 경보 AL-22)` | F-70 — `/etc/tbox-v2/vapid-private.pem`(tbox-push 0600)을 못 읽거나 공개 키 파일과 짝이 아님 |
| `alert.push_send|error|state_corrupt` | `배치의 상태 파일이 깨져 읽기 위치를 잃었습니다 — 경보 로그의 지금 끝부터 다시 읽습니다 (그 사이 경보는 휴대폰으로 보내지 않았습니다)` | F-70 — `alert-push-state.json` 이 비었거나 JSON 이 아님. 방향은 「처음 켬」과 같다(옛 줄을 몰아 보내지 않는다) — 조용히 넘어가지 않고 한 줄. target_type `push_state`, detail `{kind: state}` |
| `user.create|allow` | `{actor} 이(가) 계정 「{target}」 을(를) 만들었습니다 (역할·팀·사유는 상세 기록)` | 10단계 users (Admin) |
| `user.create|deny|bad_request` | `{actor} 이(가) 계정 생성 요청을 잘못된 형식(아이디·비밀번호·역할·팀·사유)으로 보내 거부했습니다` | 10단계 |
| `user.create|deny|already_exists` | `{actor} 이(가) 이미 있는 아이디 「{target}」 로 계정을 만들려 해서 거부했습니다` | 10단계 |
| `user.create|deny|reserved_prefix` | `{actor} 이(가) 검토·시험 계정 전용 접두로 시작하는 아이디 「{target}」 로 계정을 만들려 해서 거부했습니다 (검토 계정은 seed_users.php 로만 만듭니다)` | 대기열 2b (2026-09-24, PRD F-71 ③) — `qa_`·`claude_` 접두(`Origin::isReviewAccount()` 한 곳). `already_exists` 검사 **뒤**라 이미 있는 아이디(검토 계정 포함)는 전처럼 `already_exists` |
| `user.role_change|allow` | `{actor} 이(가) 계정 「{target}」 의 역할을 바꿨습니다 (이전 → 새 역할·사유는 상세 기록)` | 10단계 (detail.before/after/reason_text) |
| `user.role_change|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 의 역할 변경을 잘못된 형식(역할·팀·사유 없음)으로 요청해서 거부했습니다` | 10단계 |
| `user.role_change|deny|self_change` | `{actor} 이(가) 자기 자신의 역할을 바꾸려 해서 거부했습니다 (관리자 권한 자기 부여·자기 강등 방지)` | 10단계 |
| `user.role_change|deny|last_admin` | `{actor} 이(가) 마지막 활성 관리자 계정 「{target}」 의 역할을 낮추려 해서 거부했습니다 (관리자 0명 방지)` | 10단계 |
| `user.deactivate|deny|last_admin` | `{actor} 이(가) 마지막 활성 관리자 계정 「{target}」 을(를) 비활성화하려 해서 거부했습니다 (관리자 0명 방지)` | 10단계 |
| `user.deactivate|allow` | `{actor} 이(가) 계정 「{target}」 을(를) 비활성화하고 모든 세션을 끊었습니다 (사유는 상세 기록)` | 10단계 (R-10 revokeAllSessions) |
| `user.deactivate|deny|self_change` | `{actor} 이(가) 자기 자신을 비활성화하려 해서 거부했습니다` | 10단계 |
| `user.deactivate|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 비활성화를 사유 없이 요청해서 거부했습니다` | 10단계 |
| `user.activate|allow` | `{actor} 이(가) 계정 「{target}」 을(를) 다시 활성화했습니다 (사유는 상세 기록)` | 10단계 |
| `user.activate|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 재활성화를 잘못된 형식(사유 없음 등)으로 요청해서 거부했습니다` | 10단계 (검토 보완 A) |
| `user.expiry_change|allow` | `{actor} 이(가) 계정 「{target}」 의 만료일을 바꿨습니다 (이전 → 새 값·사유는 상세 기록)` | 10단계 — 역할·활성이 아니라 `expires_at` 만 바뀐 경우 (검토 보완 C) |
| `user.update|allow` | `{actor} 이(가) 계정 「{target}」 의 이름·아이디를 바꿨습니다 (바뀐 칸·사유는 상세 기록)` | 5-A″ — 이름(`display_name`)만 바뀐 경우. **이름의 값은 남기지 않는다**(`changed_fields` 만). d74 — 로그인 아이디(`username`)도 이 행: `{target}` 은 바꾸기 **전** 아이디, 새 아이디는 문장·상세 어디에도 없다 |
| `user.update|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 의 이름·아이디를 잘못된 형식(길이·글자·제어 문자·사유 없음)으로 바꾸려 해서 거부했습니다` | 5-A″ — 41자 이상·제어·서식 문자·사유 없음. d74 — 아이디 형식(`Auth::USERNAME_RE` — 대문자 포함)·권한 칸과 섞은 요청 |
| `user.update|deny|self_change` | `{actor} 이(가) 자기 자신의 계정 이름·아이디를 바꾸려 해서 거부했습니다 (본인은 보기만)` | 5-A″ — 역할과 같은 `self_change` 규칙(d74 아이디도) |
| `user.update|deny|already_exists` | `{actor} 이(가) 계정 「{target}」 의 아이디를 이미 있는 아이디로 바꾸려 해서 거부했습니다` | d74 — 계정 만들기와 같은 함수(`tbox_username_unavailable`, 비교는 `lower()`) · 400 · **시도한 이름은 남기지 않는다** |
| `user.update|deny|reserved_prefix` | `{actor} 이(가) 계정 「{target}」 의 아이디를 검토·시험 계정 전용 접두로 시작하는 이름으로 바꾸려 해서 거부했습니다` | d74 — `qa_`·`claude_`(`Origin::isReviewAccount()` 한 곳) · 400 |
| `user.update|deny|review_account` | `{actor} 이(가) 검토·시험 계정 「{target}」 의 아이디를 바꾸려 해서 거부했습니다 (검토 표식이 깨지지 않게)` | d74 — 지금 이름이 검토 접두인 계정은 아이디를 바꾸지 않는다 · 400 |
| `user.update|deny|shared_token_user` | `{actor} 이(가) 계정 「{target}」 의 아이디를 바꾸려 했지만 공용 프로그램 토큰의 귀속 아이디에 닿아 거부했습니다 (공용 토큰을 먼저 정리)` | d74 — 공용 토큰이 켜져 있고(`API_TOKEN` 32자 이상) **지금 이름**이 `API_TOKEN_USER` 와 같다(그 경로는 **글자**로 계정을 찾는다 — 4.2) · 409 · 닫힌 실패. 새 이름 쪽은 막지 않는다(결정 d76 ② — 귀속 줄을 먼저 새 아이디로 바꾸고 이 창구를 부른다) |
| `user.expired|deny|session_expired` | `{actor} 의 Guest 계정이 만료되어 살아 있던 세션을 끊었습니다` | 10단계 재지시 — `Auth::currentUser()` 가 만료된 Guest 의 세션을 즉시 파기 (R-12) |
| `auth.mfa_enroll|deny|bad_code` | `{actor} 이(가) 2단계 인증을 켜려 했지만 확인 코드가 틀렸습니다` | 10단계 (검토 보완 B) |
| `auth.gate|deny|password_change_required` | `{target} 이(가) 비밀번호를 아직 바꾸지 않은 상태로 다른 기능을 요청해서 거부했습니다` | 11단계 관문 (target = 본인, route 패턴은 상세) |
| `auth.gate|deny|mfa_setup_required` | `{target} 이(가) 2단계 인증을 아직 설정하지 않은 상태로 다른 기능을 요청해서 거부했습니다` | 11단계 관문 |
| `auth.gate|deny|reauth_required` | `{target} 이(가) 비밀번호를 다시 확인하지 않은 채 관리 기능을 요청해서 거부했습니다` | 16-1 관문 [3-2] (target = 본인, route 패턴·`next`·`policy` 는 상세). **Q:F-4 U-2b** — 휴지통 셋(`GET /trash` · `GET /api/files/trash` · `POST /api/files/{id}/restore`)은 라우트 정책이 `authenticated` 라 관문 [3-2] 를 타지 않는다. 대신 **핸들러**가 복구 범위(`Authz::restorableFilesWhere` 의 셋째 값)가 `admin_all` 일 때만 같은 문장·같은 모양(target = 본인, `next`, `policy` = 라우트 정책 `authenticated`)으로 남기고 403 `next:"reauth"`(화면은 302 `/reauth?to=trash`) — Admin 의 전체 휴지통은 전과 같이 재인증, 본인 휴지통은 묻지 않는다 |
| `auth.reauth|allow` | `{actor} 이(가) 관리 기능을 쓰려고 비밀번호를 다시 확인했습니다` | 16-1 `POST /api/me/reauth` (Auth::reauth, `detail.window_minutes`) |
| `act.start|allow` | `{actor} 이(가) 다른 역할로 보기를 시작했습니다 (역할·팀은 상세 기록)` | 26단계 `POST /api/me/act` (F-39). `detail` 에 고른 role·team_id |
| `act.start|deny|bad_request` | `{actor} 이(가) 역할로 보기를 허용되지 않는 형식(모르는 항목·역할·팀)으로 요청해서 거부했습니다` | 26단계. Admin 으로 보기·없는 팀·모르는 키 |
| `act.start|deny|role_not_permitted` | `{actor} 이(가) 역할로 보기를 시작할 자격 없이 요청해서 막았습니다` | 26단계. 실제 역할이 Admin 이 아니거나 이미 보는 중 |
| `act.stop|allow` | `{actor} 이(가) 역할로 보기를 끝내고 원래 역할로 돌아왔습니다` | 26단계 `DELETE /api/me/act`. 끄기 **전** 상태에서 기록해 `detail.acting_as` 가 함께 남는다 |
| `act.expire|allow` | `{actor} 의 역할로 보기가 자동으로 풀렸습니다 (사유는 상세 기록)` | 26단계 `Auth::currentUser()`. `detail.reason` = `idle`(활동 없는 30분) · `role_lost`(강등). 거부가 아니라 **서버가 판정한 사실**이라 allow |
| `auth.reauth|deny|bad_credentials` | `{actor} 이(가) 관리 기능을 쓰려고 비밀번호를 다시 확인했지만 비밀번호가 틀렸습니다` | 16-1 (실패는 로그인 뒤 창구와 같은 카운터 — R-48) |
| `auth.login|allow|passkey` | `{target} 이(가) 지문·얼굴로 로그인했습니다` | F-72 `POST /api/login/passkey` (5.35). `detail.credential_no` |
| `auth.login|deny|passkey_unknown` | `등록되지 않은 열쇠로 지문·얼굴 로그인을 시도했습니다 (대상 {target})` | F-72. target 「(알 수 없는 열쇠)」 |
| `auth.login|deny|passkey_invalid` | `{target} 계정의 열쇠로 로그인을 시도했지만 서명·주소·확인 표시가 맞지 않았습니다` | F-72. 열쇠 주인이 정해지기 전(모양·origin)이면 target 「(알 수 없는 열쇠)」 · 뒤(⑤⑥)면 실패 1회 |
| `auth.login|deny|passkey_challenge` | `지문·얼굴 로그인의 확인 문제가 없거나 시간이 지났거나 이미 쓰였습니다 (대상 {target})` | F-72. 로그인 전 쿠키 없음·다른 값·재사용·만료 |
| `auth.login|deny|passkey_counter` | `{target} 계정의 열쇠 서명 횟수가 뒤로 갔습니다 — 열쇠 복제가 의심됩니다` | F-72 · **AL-23** · 실패 1회 |
| `auth.login|deny|passkey_revoked` | `{target} 계정의 해제되었거나 90일 동안 쓰지 않아 꺼진 열쇠로 로그인을 시도했습니다` | F-72. 응답만 「꺼진 열쇠」 문장(서명이 맞은 사람에게만) |
| `auth.login|deny|forced_step` | `{target} 계정은 비밀번호 변경이 먼저라 지문·얼굴 로그인을 받지 않았습니다` | F-72. `must_change_password`(R-39 우회 금지) |
| `auth.reauth|allow|passkey` | `{actor} 이(가) 관리 기능을 쓰려고 지문·얼굴로 다시 확인했습니다` | F-72 라운드 C `POST /api/me/reauth/passkey` |
| `auth.reauth|deny|passkey_invalid` | `{actor} 이(가) 관리 기능을 쓰려고 지문·얼굴로 다시 확인했지만 열쇠·서명·주소가 맞지 않았습니다` | F-72 라운드 C. 실패는 `countFailureAndRevoke`(R-48) · AL-2 로그인 뒤 목록 |
| `auth.reauth|deny|passkey_challenge` | `{actor} 이(가) 지문·얼굴로 다시 확인하려 했지만 확인 문제가 없거나 시간이 지났거나 이미 쓰였습니다` | F-72 라운드 C. 실패는 `countFailureAndRevoke`(R-48) · AL-2 로그인 뒤 목록 |
| `auth.reauth|deny|passkey_counter` | `{actor} 이(가) 지문·얼굴로 다시 확인했지만 열쇠 서명 횟수가 뒤로 갔습니다 — 열쇠 복제가 의심됩니다` | F-72 라운드 C. 실패는 `countFailureAndRevoke`(R-48) · AL-2 로그인 뒤 목록 |
| `auth.passkey_register|allow` | `{actor} 이(가) 지문·얼굴로 로그인할 기기를 등록했습니다 (기기 이름은 상세 기록)` | F-72 `POST /api/me/passkeys` · **AL-23** · `detail` credential_no·credential_label·device_hint·backed_up |
| `auth.passkey_register|deny|bad_request` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 요청 모양(이름·칸)이 맞지 않았습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` |
| `auth.passkey_register|deny|bad_credentials` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 현재 비밀번호가 틀렸습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` · AL-2 로그인 뒤 목록 |
| `auth.passkey_register|deny|bad_code` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 2단계 인증 코드가 틀렸습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` · AL-2 로그인 뒤 목록 |
| `auth.passkey_register|deny|code_reused` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 이미 한 번 쓴 2단계 인증 코드를 다시 냈습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` · AL-2 로그인 뒤 목록 |
| `auth.passkey_register|deny|limit_exceeded` | `{actor} 이(가) 지문·얼굴 로그인 기기를 여섯 번째로 등록하려 해서 거부했습니다 (한 사람 5개까지)` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` |
| `auth.passkey_register|deny|passkey_invalid` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 열쇠 정보·주소·확인 표시가 맞지 않았습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` |
| `auth.passkey_register|deny|passkey_challenge` | `{actor} 이(가) 지문·얼굴 로그인 기기를 등록하려 했지만 확인 문제가 없거나 시간이 지났거나 이미 쓰였습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` |
| `auth.passkey_register|deny|passkey_duplicate` | `{actor} 이(가) 이미 등록된 열쇠를 다시 등록하려 해서 거부했습니다` | F-72 `POST /api/me/passkeys/options`·`POST /api/me/passkeys` |
| `auth.passkey_revoke|allow|self` | `{actor} 이(가) 자기 계정의 지문·얼굴 로그인 기기 하나를 해제했습니다 (기기 이름은 상세 기록)` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|allow|password_change` | `{actor} 이(가) 비밀번호를 바꿔 계정 「{target}」 의 지문·얼굴 로그인 기기를 해제했습니다` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|allow|password_reset` | `{actor} 이(가) 계정 「{target}」 의 임시 비밀번호를 재발급해 그 계정의 지문·얼굴 로그인 기기를 해제했습니다` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|allow|mfa_reset` | `{actor} 이(가) 계정 「{target}」 의 2단계 인증을 초기화해 그 계정의 지문·얼굴 로그인 기기를 해제했습니다` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|allow|role_change` | `{actor} 이(가) 계정 「{target}」 의 역할을 바꿔 그 계정의 지문·얼굴 로그인 기기를 해제했습니다` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|allow|deactivate` | `{actor} 이(가) 계정 「{target}」 을(를) 비활성화해 그 계정의 지문·얼굴 로그인 기기를 해제했습니다` | F-72. 열쇠 하나에 한 행 · `detail` credential_no·credential_label |
| `auth.passkey_revoke|deny|not_found` | `{actor} 이(가) 없는 지문·얼굴 로그인 기기를 해제하려 했습니다` | F-72. 남의 번호도 같은 행·같은 404 |
| `auth.password_change|deny|same_password` | `{actor} 이(가) 비밀번호를 현재와 같은 값으로 바꾸려 해서 거부했습니다` | 11단계 |
| `user.password_reset|allow` | `{actor} 이(가) 계정 「{target}」 의 임시 비밀번호를 재발급하고 모든 세션을 끊었습니다 (사유는 상세 기록)` | 11단계 users (Admin) |
| `user.password_reset|deny|self_change` | `{actor} 이(가) 자기 자신의 임시 비밀번호를 재발급하려 해서 거부했습니다` | 11단계 |
| `user.password_reset|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 임시 비밀번호 재발급을 사유 없이 요청해서 거부했습니다` | 11단계 |
| `user.mfa_reset|allow` | `{actor} 이(가) 계정 「{target}」 의 2단계 인증을 초기화하고 모든 세션을 끊었습니다 (사유는 상세 기록)` | 11단계 |
| `user.mfa_reset|deny|self_change` | `{actor} 이(가) 자기 자신의 2단계 인증을 초기화하려 해서 거부했습니다` | 11단계 |
| `user.mfa_reset|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 2단계 인증 초기화를 사유 없이 요청해서 거부했습니다` | 11단계 |
| `auth.mfa_enroll|deny|already_enabled` | `{actor} 이(가) 이미 켜져 있는 2단계 인증을 다시 등록하려 해서 거부했습니다` | 10단계 (검토 보완 B) |
| `auth.mfa_disable|deny|bad_code` | `{actor} 이(가) 2단계 인증을 끄려 했지만 인증 코드가 틀렸습니다` | 10단계 (검토 보완 B) |
| `auth.mfa_disable|deny|bad_credentials` | `{actor} 이(가) 2단계 인증을 끄려 했지만 현재 비밀번호가 틀렸습니다` | 10단계 (검토 보완 B) |
| `user.expired|deny|guest_expired` | `{target} 계정으로 로그인을 시도했지만 만료된 Guest 계정입니다` | 10단계 Auth::login (R-12 expires_at) |
| `auth.password_change|allow` | `{actor} 이(가) 비밀번호를 바꾸고 다른 기기의 세션을 모두 끊었습니다` | 10단계 password (본인) |
| `auth.password_change|deny|bad_credentials` | `{actor} 이(가) 비밀번호를 바꾸려 했지만 현재 비밀번호가 틀렸습니다` | 10단계 |
| `auth.password_change|deny|weak_password` | `{actor} 이(가) 비밀번호를 바꾸려 했지만 새 비밀번호가 규칙(10자 이상)에 맞지 않습니다` | 10단계 (S-09-4) |
| `auth.mfa_enroll|allow` | `{actor} 이(가) 2단계 인증(TOTP)을 켰습니다` | 10단계 (R-05) |
| `auth.mfa_disable|allow` | `{actor} 이(가) 2단계 인증(TOTP)을 껐습니다` | 10단계 |
| `auth.mfa|deny|bad_code` | `{target} 계정으로 로그인하면서 2단계 인증 코드를 틀렸습니다` | 10단계 Auth::login |
| `auth.mfa|deny|code_required` | `{target} 계정은 2단계 인증이 켜져 있는데 코드 없이 로그인을 시도해 막았습니다` | 10단계 Auth::login |
| `auth.mfa|deny|code_reused` | `{target} 계정으로 로그인하면서 이미 한 번 쓴 2단계 인증 코드를 다시 냈습니다` | 13단계 Auth::login (R-45) |
| `auth.mfa_disable|deny|code_reused` | `{actor} 이(가) 2단계 인증을 끄려 했지만 이미 한 번 쓴 인증 코드를 다시 냈습니다` | 13단계 mfa_disable (R-45) |
| `auth.mfa_enroll|deny|code_reused` | `{actor} 이(가) 2단계 인증을 켜려 했지만 이미 한 번 쓴 확인 코드를 다시 냈습니다` | 13단계 mfa_confirm (R-45) |
| `authz.route|deny|role_not_permitted` (재사용) | — | 8·10단계 Employee 의 `/api/audit`·`/api/users` 호출 (T-13) |
| `file.download|error|hash_mismatch` | `{actor} 이(가) 파일 「{target}」 을(를) 내려받으려 했지만 저장된 파일의 내용이 기록과 달라 전송을 막았습니다 (변조 의심, 점검 필요)` | 6단계 files_download (R-13 sha256 대조 필수, AL-6) |
| `file.download|allow` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 내려받았습니다` | files_download |
| `file.download|deny|role_not_permitted` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 내려받으려 했지만 권한이 없어 막았습니다` | 4단계 canRead |
| `file.download|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 team 등급 파일 「{target}」 을(를) 내려받으려 해서 막았습니다` | 4단계 canRead |
| `file.download|error|file_missing` | `{actor} 이(가) 파일 「{target}」 을(를) 내려받으려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)` | files_download |
| `file.delete|allow` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 삭제했습니다 (30일 휴지통)` | files_delete |
| `file.delete|deny|role_not_permitted` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 삭제하려 했지만 볼 권한조차 없어 막았습니다` | 4단계 canRead 실패 |
| `file.delete|deny|not_owner` | `{actor} 이(가) 다른 사람이 올린 파일 「{target}」 을(를) 삭제하려 해서 막았습니다` | 4단계 canDelete |
| `file.delete|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 파일 「{target}」 을(를) 삭제하려 해서 막았습니다` | 4단계 canDelete(Manager) |
| `file.update|allow` | `{actor} 이(가) 파일 「{target}」 을(를) 수정했습니다 (바뀐 항목은 상세 기록: description·access_level·team_id·folder_id)` | 5단계 files_update (설명만 바뀜) |
| `file.update|deny|role_not_permitted` | `{actor} 이(가) 파일 「{target}」 을(를) 수정하려 했지만 볼 권한조차 없어 막았습니다` | 5단계 decideRead 실패 |
| `file.update|deny|not_owner` | `{actor} 이(가) 다른 사람이 올린 파일 「{target}」 을(를) 수정하려 해서 막았습니다` | 5단계 decideEdit |
| `file.update|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 파일 「{target}」 을(를) 수정하려 해서 막았습니다` | 5단계 decideEdit |
| `file.update|deny|bad_request` | `{actor} 이(가) 파일 「{target}」 수정 요청을 허용되지 않는 형식(모르는 항목·JSON 아님)으로 보내 거부했습니다` | 5단계 files_update (R-07 필드 화이트리스트) |
| `file.update|deny|bad_access_level` | `{actor} 이(가) 파일 「{target}」 을(를) 존재하지 않는 접근 등급으로 바꾸려 해서 거부했습니다` | 5단계 files_update |
| `file.update|deny|bad_team` | `{actor} 이(가) 파일 「{target}」 을(를) 없는 팀 또는 팀 지정 없이 team 등급으로 바꾸려 해서 거부했습니다` | 5단계 files_update |
| `file.update|deny|description_too_long` | `{actor} 이(가) 파일 「{target}」 의 설명을 500자 넘게 바꾸려 해서 거부했습니다` | 5단계 files_update |
| `file.update|deny|bad_encoding` | `{actor} 이(가) 파일 「{target}」 의 설명을 올바르지 않은 문자(UTF-8)로 바꾸려 해서 거부했습니다` | 5단계 files_update |
| `file.update|deny|no_change` | `{actor} 이(가) 파일 「{target}」 수정을 요청했지만 바뀌는 값이 없어 거부했습니다` | 5단계 files_update |
| `file.level_change|allow` | `{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 바꿨습니다 (이전 등급은 상세 기록)` | 5단계 files_update (레벨·팀이 바뀜, detail.before/after 필수) |
| `file.level_change|deny|downgrade_not_permitted` | `{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 낮추려 했지만 등급을 낮추는 것은 관리자만 할 수 있어 막았습니다` | 5단계 decideLevelChange (PRD 4장·5.3) |
| `file.level_change|deny|role_not_permitted` | `{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 바꾸려 했지만 그 등급을 지정할 권한이 없어 막았습니다` | 5단계 decideLevelChange(decideUploadAs 재사용) |
| `file.level_change|deny|team_mismatch` | `{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 바꾸려 해서 막았습니다` | 5단계 decideLevelChange |

| `file.preview|allow` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열었습니다` | 12-2 files_preview · files_preview_raw (`detail.preview_kind`·`part`) |
| `file.preview|deny|role_not_permitted` | `{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열려 했지만 권한이 없어 막았습니다` | 12-2 decideRead |
| `file.preview|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 team 등급 파일 「{target}」 을(를) 미리보기로 열려 해서 막았습니다` | 12-2 decideRead |
| `file.preview|deny|preview_not_available` | `{actor} 이(가) 미리보기를 지원하지 않는 형식의 파일 「{target}」 을(를) 원본 표시로 열려 해서 거부했습니다` | 12-2 raw 에 image·pdf 외 형식 |
| `file.preview|error|file_missing` | `{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)` | 12-2 |
| `file.preview|error|hash_mismatch` | `{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장된 파일의 내용이 기록과 달라 표시를 막았습니다 (변조 의심, 점검 필요)` | 12-2 (R-13, AL-6) |
| `file.preview|error|parse_failed` | `{actor} 이(가) 파일 「{target}」 의 미리보기를 요청했지만 내용을 읽어 내지 못했습니다 (손상 또는 지원 범위 밖)` | 12-2 zip/office 추출 실패 — 화면에는 정보 카드만 |
| `team.create|allow` | `{actor} 이(가) 팀 「{target}」 을(를) 만들었습니다 (사유는 상세 기록)` | 12-3 org_team_create |
| `team.create|deny|bad_request` | `{actor} 이(가) 팀 생성 요청을 잘못된 형식(이름·사유)으로 보내 거부했습니다` | 12-3 |
| `team.create|deny|already_exists` | `{actor} 이(가) 이미 있는 이름 「{target}」 으로 팀을 만들려 해서 거부했습니다` | 12-3 |
| `team.update|allow` | `{actor} 이(가) 팀 「{target}」 의 정보를 바꿨습니다 (이전값→새값·사유는 상세 기록)` | 12-3 org_team_update (`detail.before/after`) |
| `team.update|deny|bad_request` | `{actor} 이(가) 팀 「{target}」 변경 요청을 잘못된 형식으로 보내 거부했습니다` | 12-3 |
| `team.update|deny|not_found` | `{actor} 이(가) 없는 팀을 바꾸려 해서 거부했습니다` | 12-3 |
| `team.update|deny|team_has_members` | `{actor} 이(가) 구성원이 남아 있는 팀 「{target}」 을(를) 비활성화하려 해서 거부했습니다 (먼저 재배정)` | 12-3 |
| `team.update|deny|already_exists` | `{actor} 이(가) 팀 「{target}」 의 이름을 이미 있는 이름으로 바꾸려 해서 거부했습니다` | 12-3 |

| `share.create|allow` | `{actor} 이(가) 파일 「{target}」 의 공유 링크를 만들었습니다 (만료·횟수·사유는 상세 기록)` | 15-2 share_create (detail `share_id`·`expires_at`·`max_downloads`·`reason_text`. **토큰·해시는 기록하지 않는다**) |
| `share.create|deny|role_not_permitted` | `{actor} 이(가) 파일 「{target}」 의 공유 링크를 만들려 했지만 볼 권한조차 없어 막았습니다` | 15-2 decideRead 실패 |
| `share.create|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 파일 「{target}」 의 공유 링크를 만들려 해서 막았습니다` | 15-2 decideRead·decideEdit |
| `share.create|deny|not_owner` | `{actor} 이(가) 다른 사람이 올린 파일 「{target}」 의 공유 링크를 만들려 해서 막았습니다` | 15-2 decideEdit |
| `share.create|deny|bad_request` | `{actor} 이(가) 공유 링크 만들기를 허용되지 않는 형식(모르는 항목·만료·횟수·사유)으로 요청해서 거부했습니다` | 15-2 허용 키 3개 밖·범위 밖 (R-07 과 같은 화이트리스트) |
| `share.revoke|allow` | `{actor} 이(가) 파일 「{target}」 의 공유 링크를 회수했습니다 (이후 그 링크로는 받을 수 없습니다)` | 15-2 share_revoke (`decideShareRevoke`) |
| `share.revoke|deny|not_owner` | `{actor} 이(가) 자기가 만들지 않은 공유 링크를 회수하려 해서 막았습니다` | 15-2 decideShareRevoke |
| `share.revoke|deny|bad_request` | `{actor} 이(가) 이미 회수된 공유 링크를 다시 회수하려 해서 거부했습니다` | 15-2 (행은 지우지 않으므로 두 번째 회수는 400) |
| `share.download|allow` | `공유 링크로 파일 「{target}」 을(를) 내려받았습니다 (발급자·링크 id·남은 횟수는 상세 기록)` | 15-2 share_download — **actor 없음**(로그인 전 문장 틀). detail `share_id`·`issued_by`·`remaining` |
| `share.download|deny|not_found` | `없는 공유 링크로 파일을 받으려는 요청이 있어 막았습니다` | 15-2 (target = system. **시도된 토큰은 기록하지 않는다**) |
| `share.download|deny|revoked` | `회수된 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다` | 15-2 ② |
| `share.download|deny|expired` | `만료된 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다` | 15-2 ③ |
| `share.download|deny|exhausted` | `허용 횟수를 다 쓴 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다` | 15-2 ④ · ⑥ 조건부 UPDATE 가 0행일 때 |
| `share.download|deny|file_gone` | `이미 삭제된 파일 「{target}」 을(를) 가리키는 공유 링크로 받으려 해서 막았습니다` | 15-2 ⑤ (`deleted_at`·`purged_at`) |
| `share.download|error|file_missing` | `공유 링크로 파일 「{target}」 을(를) 보내려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)` | 15-2 ⑤-2 (다운로드의 같은 행과 짝) |
| `share.download|error|hash_mismatch` | `공유 링크로 파일 「{target}」 을(를) 보내려 했지만 저장된 파일의 내용이 기록과 달라 전송을 막았습니다 (변조 의심, 점검 필요)` | 15-2 ⑤-2 (R-13, AL-6). 응답은 다른 실패와 같은 404 |
| `file.upload|deny|sensitive_level_required` | `{actor} 이(가) 민감정보로 보이는 내용이 든 파일 「{target}」 을(를) {level} 등급으로 올리려 해서 거부했습니다 (검출된 패턴 이름·건수는 상세 기록)` | 15-3 files_upload·files_fetch (같은 행동 이름을 쓴다 — detail `sensitive`, **값은 기록 금지**) |
| `file.level_change|deny|sensitive_level_required` | `{actor} 이(가) 민감정보로 보이는 내용이 든 파일 「{target}」 의 접근 등급을 {level} 등급으로 낮추려 해서 거부했습니다` | 15-3 files_update (`sensitive_flags` 가 비어 있지 않고 새 등급이 public·internal. Admin 도 같은 400) |
| `permission.change|allow` | `{actor} 이(가) 권한 매트릭스의 {target} 칸을 바꿨습니다 (이전값→새값·사유·코드 확인은 상세 기록)` | 17-2 admin_permissions_update — **칸 하나에 한 줄**. `{target}` 은 `역할\|등급`(예 `Guest\|internal`). detail `before`·`after`·`reason_text`·`otp_verified` |
| `permission.change|deny|bad_request` | `{actor} 이(가) 권한 매트릭스 변경을 허용되지 않는 형식(모르는 항목·사유·코드)으로 요청해서 거부했습니다` | 17-2 허용 키 3개 밖·사유 없음·코드 형식 밖 (R-07 과 같은 화이트리스트) |
| `permission.change|deny|bad_code` | `{actor} 이(가) 권한 매트릭스를 바꾸려 했지만 2단계 인증 코드가 맞지 않아 거부했습니다` | 17-2 `Totp::verifyStep` 실패 — **실패 집계**(countFailureAndRevoke) |
| `permission.change|deny|code_reused` | `{actor} 이(가) 권한 매트릭스를 바꾸려 했지만 이미 쓴 2단계 인증 코드라 거부했습니다` | 17-2 `mfa_last_step` 1회성 (R-45) — 실패 집계 |
| `permission.change|deny|invariant` | `{actor} 이(가) 권한 매트릭스의 바꿀 수 없는 규칙을 어기는 변경을 요청해서 거부했습니다 (어긴 규칙은 상세 기록)` | 17-2 불변 규칙 (a)~(e), detail `rule` (5.15). 실패로 세지 않는다(비밀값 추측이 아니다) |
| `folder.read|deny|role_not_permitted` | `{actor} 이(가) 볼 권한이 없는 {level} 등급 폴더를 열어 보려 해서 막았습니다` | 18-1 folders_list · files_list (허용은 기록하지 않는다 — 목록과 같은 취급) |
| `folder.read|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 폴더를 열어 보려 해서 막았습니다` | 18-1 |
| `folder.read|deny|not_found` | `{actor} 이(가) 없는 폴더를 열어 보려 해서 막았습니다` | 18-1 (없는 폴더도 응답은 403 동일 문구 — id 열거 방지) |
| `folder.create|allow` | `{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 만들었습니다 (부모·깊이는 상세 기록)` | 18-1 folders_create |
| `folder.create|deny|bad_request` | `{actor} 이(가) 폴더 만들기를 허용되지 않는 형식(모르는 항목·이름 규칙·등급·팀·없는 부모)으로 요청해서 거부했습니다` | 18-1 |
| `folder.create|deny|conflict` | `{actor} 이(가) 같은 위치에 이미 있는 이름 「{target}」 으로 폴더를 만들려 해서 거부했습니다` | 18-1 (409) |
| `folder.create|deny|depth_exceeded` | `{actor} 이(가) 폴더 「{target}」 을(를) 최대 깊이(5단계)보다 깊은 곳에 만들려 해서 거부했습니다` | 18-1 |
| `folder.create|deny|role_not_permitted` | `{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 만들려 했지만 그 등급을 지정할 권한이 없어 막았습니다` | 18-1 `decideUploadAs` 재사용 |
| `folder.create|deny|team_mismatch` | `{actor} 이(가) 폴더 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 만들려 해서 막았습니다` | 18-1 `decideUploadAs` 재사용 |
| `folder.create|deny|parent_narrower` | `{actor} 이(가) 폴더 「{target}」 을(를) 부모 폴더보다 넓은 {level} 등급으로 만들려 해서 거부했습니다` | 18-1 불변 규칙 ① (`fitsUnderFolder`) |
| `folder.update|allow` | `{actor} 이(가) 폴더 「{target}」 의 이름 또는 위치를 바꿨습니다 (이전값→새값·사유는 상세 기록)` | 18-1 folders_update |
| `folder.update|deny|not_found` | `{actor} 이(가) 없는 폴더를 바꾸려 해서 거부했습니다` | 18-1 (403 동일 문구) |
| `folder.update|deny|bad_request` | `{actor} 이(가) 폴더 「{target}」 변경을 허용되지 않는 형식(모르는 항목·이름 규칙·없는 부모·바뀌는 값 없음)으로 요청해서 거부했습니다` | 18-1 |
| `folder.update|deny|conflict` | `{actor} 이(가) 폴더 「{target}」 의 이름을 같은 위치에 이미 있는 이름으로 바꾸려 해서 거부했습니다` | 18-1 (409) |
| `folder.update|deny|cycle` | `{actor} 이(가) 폴더 「{target}」 을(를) 자기 자신 또는 자기 하위 폴더 아래로 옮기려 해서 거부했습니다` | 18-1 재귀 CTE |
| `folder.update|deny|depth_exceeded` | `{actor} 이(가) 폴더 「{target}」 을(를) 옮기면 하위 폴더가 최대 깊이(5단계)를 넘게 되어 거부했습니다` | 18-1 하위 포함 깊이 재계산 |
| `folder.update|deny|parent_narrower` | `{actor} 이(가) 폴더 「{target}」 을(를) 자기보다 좁은 등급의 폴더 아래로 옮기려 해서 거부했습니다` | 18-1 불변 규칙 ① |
| `folder.update|deny|not_owner` | `{actor} 이(가) 다른 사람이 만든 폴더 「{target}」 을(를) 바꾸려 해서 막았습니다` | 18-1 `decideFolderChange` |
| `folder.update|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 폴더 「{target}」 을(를) 바꾸려 해서 막았습니다` | 18-1 `decideFolderChange` |
| `folder.update|deny|role_not_permitted` | `{actor} 이(가) 폴더 「{target}」 을(를) 바꾸려 했지만 볼 권한조차 없어 막았습니다` | 18-1 `decideRead` 먼저 |
| `folder.level_change|allow` | `{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 바꿨습니다 (이전 등급·하위 적용 여부는 상세 기록)` | 18-1 (cascade 는 `detail.cascade`·`cascaded`) |
| `folder.level_change|deny|downgrade_not_permitted` | `{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 넓히려 했지만 등급을 넓히는 것은 관리자만 할 수 있어 막았습니다` | 18-1 ③ `decideLevelChange` 재사용 |
| `folder.level_change|deny|role_not_permitted` | `{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 바꾸려 했지만 그 등급을 지정할 권한이 없어 막았습니다` | 18-1 |
| `folder.level_change|deny|team_mismatch` | `{actor} 이(가) 폴더 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 바꾸려 해서 막았습니다` | 18-1 |
| `folder.level_change|deny|parent_narrower` | `{actor} 이(가) 폴더 「{target}」 의 등급을 부모 폴더보다 넓은 {level} 등급으로 바꾸려 해서 거부했습니다` | 18-1 불변 규칙 ① |
| `folder.level_change|deny|children_exceed` | `{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 좁히려 했지만 그보다 넓은 하위 폴더·파일이 남아 있어 거부했습니다 (개수는 상세 기록)` | 18-1 불변 규칙 ② (`detail.violations` 는 개수만) |
| `folder.level_change|deny|not_owner` | `{actor} 이(가) 폴더 「{target}」 의 등급을 하위까지 함께 좁히려 했지만 자기가 고칠 수 없는 항목이 있어 전부 되돌렸습니다 (개수는 상세 기록)` | 18-1 `cascade:true` 전체 롤백 |
| `folder.delete|allow` | `{actor} 이(가) 빈 폴더 「{target}」 을(를) 지웠습니다 (부모·깊이는 상세 기록)` | 18-1 folders_delete |
| `folder.delete|deny|not_found` | `{actor} 이(가) 없는 폴더를 지우려 해서 거부했습니다` | 18-1 (403 동일 문구) |
| `folder.delete|deny|folder_not_empty` | `{actor} 이(가) 살아 있는 파일이 남아 있는 폴더 「{target}」 을(를) 지우려 해서 거부했습니다` | 18-1 (400, FK 가 마지막 그물). 26단계에서 「살아 있는 파일」로 좁혔다 |
| `folder.delete|deny|folder_has_children` | `{actor} 이(가) 하위 폴더가 남아 있는 폴더 「{target}」 을(를) 지우려 해서 거부했습니다` | 26단계 (400) |
| `folder.delete|deny|folder_has_trashed` | `{actor} 이(가) 휴지통 파일만 남은 폴더 「{target}」 을(를) 확인 없이 지우려 해서 되물었습니다` | 26단계 (400 + 목록). `?move_trashed=1` 로 다시 오면 삭제된다 |
| `folder.delete|deny|not_owner` | `{actor} 이(가) 다른 사람이 만든 폴더 「{target}」 을(를) 지우려 해서 막았습니다` | 18-1 |
| `folder.delete|deny|team_mismatch` | `{actor} 이(가) 다른 팀의 폴더 「{target}」 을(를) 지우려 해서 막았습니다` | 18-1 |
| `folder.delete|deny|role_not_permitted` | `{actor} 이(가) 폴더 「{target}」 을(를) 지우려 했지만 볼 권한조차 없어 막았습니다` | 18-1 |
| `file.upload|deny|bad_folder` | `{actor} 이(가) 파일 「{target}」 을(를) 없는 폴더 또는 볼 수 없는 폴더에 올리려 해서 거부했습니다` | 18-1 files_upload |
| `file.upload|deny|parent_narrower` | `{actor} 이(가) 파일 「{target}」 을(를) 폴더보다 넓은 {level} 등급으로 올리려 해서 거부했습니다` | 18-1 불변 규칙 ① |
| `file.upload|deny|zip_unreadable` | `{actor} 이(가) 올린 「{target}」 의 압축 파일 구조를 읽을 수 없어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|zip_bad_path` | `{actor} 이(가) 올린 압축 파일 「{target}」 안에 경로를 벗어나는 항목이 있어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|zip_blocked_entry` | `{actor} 이(가) 올린 압축 파일 「{target}」 안에 실행 파일이 들어 있어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload_exec|allow` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 내용을 확인했다고 밝히고 비밀번호·2단계 코드를 다시 확인한 뒤 admin-only 등급으로 올렸습니다 (항목 수는 상세 기록)` | F-65 ⑤ (5.29) |
| `file.upload_exec|deny|exec_ack_required` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 확인 절차 없이 올리려 해서 확인을 요청했습니다` | F-65 ③ — 화면이 확인 창을 여는 갈래 |
| `file.upload_exec|deny|bad_credentials` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 비밀번호를 틀려 거부했습니다` | F-65 ④ (실패 집계) |
| `file.upload_exec|deny|bad_code` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 2단계 인증 코드를 틀려 거부했습니다` | F-65 ④ (실패 집계) |
| `file.upload_exec|deny|code_reused` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 이미 쓴 2단계 인증 코드를 다시 보내 거부했습니다` | F-65 ④ (R-45) |
| `file.upload_exec|deny|mfa_not_enabled` | `{actor} 이(가) 2단계 인증이 꺼진 상태로 실행 파일이 든 압축본 「{target}」 을(를) 올리려 해서 거부했습니다` | F-65 ④ |
| `file.download_exec|allow` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 비밀번호·2단계 코드를 다시 확인한 뒤 내려받았습니다` | F-65 (5.29 받기) |
| `file.download_exec|deny|bad_credentials` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 비밀번호를 틀려 거부했습니다` | F-65 (실패 집계) |
| `file.download_exec|deny|bad_code` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 2단계 인증 코드를 틀려 거부했습니다` | F-65 (실패 집계) |
| `file.download_exec|deny|code_reused` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 이미 쓴 2단계 인증 코드를 다시 보내 거부했습니다` | F-65 (R-45) |
| `file.download_exec|deny|mfa_not_enabled` | `{actor} 이(가) 2단계 인증이 꺼진 상태로 실행 파일이 든 압축본 「{target}」 을(를) 받으려 해서 거부했습니다` | F-65 |
| `file.download_exec|deny|bad_request` | `{actor} 이(가) 실행 파일 확인 절차를 허용되지 않는 형식으로 요청하거나 실행 파일이 아닌 파일에 요청해서 거부했습니다` | F-65 |
| `file.download|deny|exec_ack_required` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 확인 절차 없이 받으려 해서 확인을 요청했습니다` | F-65 — 미리보기 두 창구(`/preview`·`/preview/raw`)도 **action 까지 `file.download`** 로 같은 행을 쓴다(문장은 `action|result|reason` 으로 고르므로). 어느 창구였나는 `detail.route` 가 답한다. `exec-download` 창구의 권한·검사·무결성 거부도 5.3 과 같은 `file.download` 행이다 |
| `file.download|deny|exec_content_token` | `{actor} 이(가) 프로그램 토큰으로 실행 파일이 든 압축본 「{target}」 을(를) 받으려 해서 거부했습니다 (이 파일은 사람이 2단계 코드를 넣어야 받을 수 있습니다)` | F-65 |
| `file.zip|allow` | `{actor} 이(가) 파일 여러 개를 zip 「{target}」 하나로 묶어 내려받았습니다 (넣은 수·뺀 수는 상세 기록, 파일마다 내려받기 행이 따로 남습니다)` | Q:F-3a 라운드 C (5.34 [8]) — **묶음 한 줄**. {target} = 서버가 만든 zip 이름. 파일마다의 행은 위 `file.download` 행 그대로(detail `via: "zip"` — 새 사유 0. 실행 파일 압축본은 `exec_ack_required` 행이 「zip 에서 뺐다」는 뜻이 된다 — 어느 창구였나는 `via`·`route`) |
| `file.zip|deny|nothing_allowed` | `{actor} 이(가) zip 으로 받으려 한 파일이 모두 받을 수 없는 파일이어서(권한·검사 중·실행 파일이 든 압축본·없는 파일) 만들지 않았습니다` | 5.34 [4] |
| `file.zip|deny|too_large` | `{actor} 이(가) 합계 500MB 를 넘는 파일들을 zip 하나로 받으려 해서 거부했습니다` | 5.34 [4] |
| `file.zip|deny|busy` | `{actor} 이(가) zip 을 받으려 했지만 다른 zip 을 만드는 중이어서 잠시 뒤 다시 하도록 했습니다` | 5.34 [2]·[5] (detail `kind` = `user`·`site`) |
| `file.zip|error|no_space` | `{actor} 이(가) zip 을 받으려 했지만 서버의 임시 공간이 모자라 만들지 않았습니다 (점검 필요)` | 5.34 [5] |
| `file.zip|error|zip_failed` | `{actor} 이(가) zip 을 받으려 했지만 묶는 중 서버 오류가 나서 임시 파일을 지웠습니다 (점검 필요)` | 5.34 [7]·[8] (detail `why` = `temp`·`open`·`add`·`close`·`count`·`size` · `time`(시간 한도 — `elapsed_ms`)) |
| `share.download|deny|exec_content` | `실행 파일이 든 압축본 「{target}」 이(가) 공유 링크로 요청되어 거부했습니다 (이 파일은 링크로 나갈 수 없습니다)` | F-65 겹 방어 — 링크는 만들 수 없어 보통 닿지 않지만, 닿으면 404 와 함께 기록한다(actor 없음) |
| `share.create|deny|exec_content` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 의 공유 링크를 만들려 해서 거부했습니다 (이 파일은 링크로 나갈 수 없습니다)` | F-65 |
| `file.update|deny|exec_content` | `{actor} 이(가) 실행 파일이 든 압축본 「{target}」 의 접근 등급을 바꾸려 해서 거부했습니다 (admin-only 로 고정됩니다)` | F-65 |
| `file.upload|deny|nested_zip` | `{actor} 이(가) 올린 「{target}」 안에 압축 파일이 여러 겹 겹쳐 있어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|zip_bomb` | `{actor} 이(가) 올린 「{target}」 은(는) 압축을 풀면 지나치게 커지거나 항목이 너무 많아(압축 폭탄 의심) 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|macro_found` | `{actor} 이(가) 올린 문서 「{target}」 에 매크로가 들어 있어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|pdf_active_content` | `{actor} 이(가) 올린 PDF 「{target}」 에 열리면 실행되는 내용(스크립트·자동 실행)이 들어 있어 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.upload|deny|hwp_not_hwp` | `{actor} 이(가) 올린 파일 「{target}」 이(가) 한글 문서 형식이 아닌데 .hwp 이름이라(위장 의심) 거부했습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|zip_unreadable` | `{actor} 이(가) 가져온 「{target}」 의 압축 파일 구조를 읽을 수 없어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|zip_bad_path` | `{actor} 이(가) 가져온 압축 파일 「{target}」 안에 경로를 벗어나는 항목이 있어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|zip_blocked_entry` | `{actor} 이(가) 가져온 압축 파일 「{target}」 안에 실행 파일이 들어 있어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|nested_zip` | `{actor} 이(가) 가져온 「{target}」 안에 압축 파일이 여러 겹 겹쳐 있어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|zip_bomb` | `{actor} 이(가) 가져온 「{target}」 은(는) 압축을 풀면 지나치게 커지거나 항목이 너무 많아(압축 폭탄 의심) 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|macro_found` | `{actor} 이(가) 가져온 문서 「{target}」 에 매크로가 들어 있어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|pdf_active_content` | `{actor} 이(가) 가져온 PDF 「{target}」 에 열리면 실행되는 내용(스크립트·자동 실행)이 들어 있어 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|hwp_not_hwp` | `{actor} 이(가) 가져온 파일 「{target}」 이(가) 한글 문서 형식이 아닌데 .hwp 이름이라(위장 의심) 저장하지 않았습니다` | F-53 `Upload::inspectContent` (ARCH 5.27) |
| `file.fetch|deny|bad_folder` | `{actor} 이(가) 가져온 파일을 없는 폴더 또는 볼 수 없는 폴더에 저장하려 해서 거부했습니다` | 18-1 files_fetch (응답은 기존 fetch 문구 하나) |
| `file.fetch|deny|parent_narrower` | `{actor} 이(가) 가져온 파일을 폴더보다 넓은 {level} 등급으로 저장하려 해서 거부했습니다` | 18-1 |
| `file.fetch|deny|needs_login` | `{actor} 이(가) 로그인이 필요한 클라우드 문서를 가져오려 해서 막았습니다 (공개 링크만 가져올 수 있습니다)` | 7단계 files_fetch — 공개 링크가 아닌 클라우드 문서(F-43, 5.22) |
| `file.update|deny|bad_folder` | `{actor} 이(가) 파일 「{target}」 을(를) 없는 폴더 또는 볼 수 없는 폴더로 옮기려 해서 거부했습니다` | 18-1 files_update |
| `file.update|deny|parent_narrower` | `{actor} 이(가) 파일 「{target}」 을(를) 자기보다 좁은 등급의 폴더로 옮기려 해서 거부했습니다` | 18-1 |
| `file.level_change|deny|parent_narrower` | `{actor} 이(가) 파일 「{target}」 의 접근 등급을 그 파일이 든 폴더보다 넓은 {level} 등급으로 바꾸려 해서 거부했습니다` | 18-1 |
| `api_token.issue|allow` | `{actor} 이(가) 계정 「{target}」 의 프로그램 토큰을 발급했습니다 (이름·사유는 상세 기록, 토큰 값은 남기지 않습니다)` | F-59 |
| `api_token.issue|deny|bad_request` | `{actor} 이(가) 계정 「{target}」 의 프로그램 토큰 발급을 허용되지 않는 형식(모르는 항목·이름 규칙·사유 없음)으로 요청해서 거부했습니다` | F-59 |
| `api_token.issue|deny|limit_exceeded` | `{actor} 이(가) 계정 「{target}」 에 프로그램 토큰을 더 발급하려 했지만 살아 있는 토큰이 이미 5개여서 거부했습니다` | F-59 |
| `api_token.issue|deny|target_inactive` | `{actor} 이(가) 비활성·만료된 계정 「{target}」 에 프로그램 토큰을 발급하려 해서 거부했습니다` | F-59 |
| `api_token.revoke|allow` | `{actor} 이(가) 계정 「{target}」 의 프로그램 토큰을 회수했습니다 (이후 그 토큰은 쓸 수 없습니다)` | F-59 |
| `api_token.revoke|deny|not_found` | `{actor} 이(가) 계정 「{target}」 에 없는 프로그램 토큰을 회수하려 해서 거부했습니다` | F-59 |
| `api_token.revoke|deny|bad_request` | `{actor} 이(가) 이미 회수된 프로그램 토큰을 다시 회수하려 해서 거부했습니다` | F-59 |
| `api_token.revoke|allow|password_change` | `{actor} 이(가) 비밀번호를 바꿔 계정 「{target}」 의 프로그램 토큰을 회수했습니다 (이후 그 토큰은 쓸 수 없습니다)` | 디버깅 묶음 S-5 (4.7 「자동 회수」 — 토큰마다 한 행) |
| `api_token.revoke|allow|password_reset` | `{actor} 이(가) 계정 「{target}」 의 임시 비밀번호를 재발급해 그 계정의 프로그램 토큰을 회수했습니다 (이후 그 토큰은 쓸 수 없습니다)` | 디버깅 묶음 S-5 |
| `api_token.revoke|allow|mfa_reset` | `{actor} 이(가) 계정 「{target}」 의 2단계 인증을 초기화해 그 계정의 프로그램 토큰을 회수했습니다 (이후 그 토큰은 쓸 수 없습니다)` | 디버깅 묶음 S-5 |
| `api_token.issue|deny|mfa_not_enabled` | `{actor} 이(가) 2단계 인증이 꺼진 채 프로그램 토큰을 발급하려 해서 거부했습니다 (먼저 2단계 인증을 켜야 합니다)` | F-66 |
| `api_token.issue|deny|bad_credentials` | `{actor} 이(가) 프로그램 토큰을 발급하려 했지만 비밀번호가 틀려서 거부했습니다` | F-66 |
| `api_token.issue|deny|bad_code` | `{actor} 이(가) 프로그램 토큰을 발급하려 했지만 2단계 인증 코드가 틀려서 거부했습니다` | F-66 |
| `api_token.issue|deny|code_reused` | `{actor} 이(가) 프로그램 토큰을 발급하려 했지만 이미 쓴 2단계 인증 코드여서 거부했습니다` | F-66 |
| `auth.token|deny|token_expired` | `만료된 개인 토큰으로 {target} 을(를) 부르려 해서 막았습니다 (내 계정에서 새로 발급해야 합니다)` | F-66 |
| `auth.token|deny|scope_not_permitted` | `개인 토큰의 범위에 없는 창구 {target} 을(를) 부르려 해서 막았습니다` | F-66 |
| `auth.token|deny|user_disabled` | `{target} 의 개인 토큰은 맞았지만 그 계정이 비활성·만료·절차 중이어서 막았습니다` | F-59 |
| `auth.token|deny|shared_token_not_permitted` | `공용 토큰으로 개인 토큰 전용 창구 {target} 을(를) 부르려 해서 막았습니다` | F-59 |
| `auth.token|deny|not_configured` | `{target} 을(를) 불렀지만 이 서버에 프로그램용 API 토큰이 설정되어 있지 않아 막았습니다 (설정 전에는 아무도 쓸 수 없습니다)` | 프로그램용 API 토큰 검사 requireApiToken (4장 API 토큰 절) |
| `auth.token|deny|missing_header` | `API 토큰 없이 {target} 을(를) 부르려 해서 막았습니다` | 프로그램용 API 토큰 검사 requireApiToken (4장 API 토큰 절) |
| `auth.token|deny|bad_token` | `틀린 API 토큰으로 {target} 을(를) 부르려 해서 막았습니다` | 프로그램용 API 토큰 검사 requireApiToken (4장 API 토큰 절) |
| `auth.token|deny|user_invalid` | `{target} 의 API 토큰은 맞았지만 그 토큰에 연결된 계정이 없거나 쓸 수 없는 상태여서 막았습니다 (서버 설정 점검 필요)` | 프로그램용 API 토큰 검사 requireApiToken (4장 API 토큰 절) |

> 7·8·9단계(URL 가져오기·감사 로그 조회·백업)의 행동은 그 단계 지시서에서 이 표에 행을 먼저 추가한 뒤 구현한다.

**기존 DB 반영**: `deploy/install.sh` 는 표가 이미 있으면 `schema.sql` 을 건너뛴다. 따라서 컬럼 추가는 `db/migrations/2026-09-10_audit_summary.sql` 로 하고, 소유자 계정으로 한 번 실행한다(기존 행은 같은 문장 표로 채운 뒤 `NOT NULL` 을 건다). 실행: `sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-10_audit_summary.sql`. 새로 설치하는 DB 는 `schema.sql` 에 컬럼이 이미 들어 있으므로 마이그레이션이 필요 없다.

### 7.5 `detail` 표준 키 등록부 — 육하원칙을 채우는 나머지 칸 (2026-09-10 추가, PRD S-07 "기록 원칙")

7.2 의 고정 컬럼만으로는 "왜 허용됐나", "같은 로그인에서 이어진 행동인가", "어떤 내용의 파일인가"에 답할 수 없다. 스키마를 다시 바꾸지 않고 `detail`(JSONB) 에 **정해진 키**로 채운다. 아래 표에 없는 키를 새로 쓰려면 이 표를 먼저 고친다. 값은 전부 `Audit::scrub()` 을 거치며(비밀 키 이름은 `[제거됨]`), 문자열은 500자에서 잘린다.

| 키 | 육하원칙 | 값 | 누가 채우나 | 어느 행에 |
|---|---|---|---|---|
| `route` | 어디서·어떻게 | `메서드 라우트패턴` 예 `GET /api/files/{id}/download`. **실제 UUID 가 아니라 라우트 표의 패턴** — 로그가 파일 ID 목록이 되지 않게 | `Audit::write()` 자동 (`TBOX_ROUTE` 상수, 라우터가 매칭 직후 정의) | 모든 행. CLI(배치)는 `cli:스크립트명` |
| `temp_password_issued` | 어떻게 | `true` — 임시 비밀번호를 발급했다는 사실만. **값은 절대 기록하지 않는다** | 핸들러 | `user.create`·`user.password_reset` |
| `forced` | 왜 | `true` — 최초 로그인 강제 절차 중에 한 변경 | 핸들러 | `auth.password_change allow`·`auth.mfa_enroll allow` |
| `next` | 어떻게 | 관문이 요구한 다음 단계 `password_change`·`mfa_setup` | 라우터 | `auth.gate deny` |
| `actor_team` | 누가 | 행동 당시 사용자의 `team_id` (정수 또는 null) | `Audit::write()` 자동 (`Auth::currentUser()`) | 로그인된 모든 행 |
| `acting_as` | 어떻게 | 「역할로 보기」 중이었다면 그때의 유효 역할 `{role, team_id}` (26단계 F-39). `actor_role` 열은 **실제 역할**이고 이 키가 「어떤 권한으로 판정됐나」다 | `Audit::write()` 자동 (`autoDetail`) | 보는 중의 **모든** 행 |
| `real_role` | 누가 | 보는 중일 때의 실제 역할(언제나 `Admin`). `acting_as` 와 짝으로만 나온다 | `Audit::write()` 자동 | 보는 중의 모든 행 |
| `moved_trashed` | 무엇을 | 폴더를 지우면서 루트로 옮긴 **휴지통 파일의 개수** (26단계 F-41) | 핸들러 | `folder.delete allow` (확인 삭제일 때만) |
| `moved_file_ids` | 무엇을 | 위에서 옮긴 파일들의 `public_id` 목록 — 되짚어 볼 수 있게. 이름은 넣지 않는다 | 핸들러 | 위와 같음 |
| `login_ref` | 누가 | 로그인 1회당 고정되는 12자 16진수. `substr(hash('sha256', 'audit-login-ref:' . $session_id), 0, 12)` — 세션 id(토큰의 해시)에서 **한 번 더 단방향으로 파생**하므로 이 값으로 쿠키도 세션 행도 찾을 수 없다. 키 이름에 `session`·`hash` 를 쓰지 않는 이유는 `FORBIDDEN_DETAIL_KEYS` 부분 일치 때문 | `Audit::write()` 자동 | 로그인된 모든 행 |
| `granted_by` | 왜 | 4.1 판정 코드 중 허용 코드 하나 | 핸들러가 `Authz::decide*()` 결과를 그대로 | 인가를 거친 `allow` 행 (`file.download`·`file.delete`·`file.upload`) |
| `sha256` | 무엇을 | 파일 내용 해시 64자 (DB `files.sha256`) | 핸들러 | `file.download`·`file.delete`·`file.upload`(allow) |
| `size_bytes` | 무엇을 | 파일 크기 | 핸들러 | 위와 같음 |
| `mode` | 어떻게 | `soft_delete` / `purge` / `restore` | 핸들러 | `file.delete`·`file.purge`·`file.restore` |
| `preview_kind` | 무엇을 | `image`·`pdf`·`text`·`table`·`archive`·`office`·`none` — 서버가 DB `ext` 로 판정한 갈래 (12-2) | 핸들러 | `file.preview` |
| `part` | 어떻게 | `meta`(JSON 본문) / `raw`(inline 원본) — 한 미리보기가 두 행으로 남을 수 있음을 구분 (12-2) | 핸들러 | `file.preview` |
| `before` · `after` | 어떻게 | 이전값 → 새값 (레벨 변경·역할 변경). 레벨 변경은 `{"access_level":…, "team_id":…}` 객체 두 개 | 핸들러 | `file.level_change`·`user.role_change` (5·10단계) |
| `changed` | 어떻게 | 이번 요청으로 바뀐 필드 이름 목록 (예 `["description"]`) | 핸들러 | `file.update`·`file.level_change` (5단계) |
| `source_host` · `source_ip` | 어디서(출처) | F-14 로 가져온 파일의 허용 도메인과 검사·고정한 IP. **URL 전체는 남기지 않는다**(경로에 토큰·개인정보가 있을 수 있음). 거부 행에도 host 는 남긴다(정규화에 실패했으면 `?`) | 핸들러 | `file.fetch` (7단계) |
| `hops` | 어디서 | 리다이렉트로 **지나온 호스트 목록**(첫 주소 → 마지막). 단계마다 정말 검사를 받았는지를 로그만으로 되짚는 값이다. 호스트 이름뿐이고 경로는 없다 | 핸들러 | `file.fetch` (F-43) |
| `source_url` | 어디서 | 사람이 넣은 주소와 서버가 바꾼 주소(`원 → 변환`, 512바이트로 자름). 7단계의 「URL 전체는 남기지 않는다」를 F-43 이 **이 한 키에 한해** 열었다 — 변환이 맞았는지·어떤 링크로 나갔는지는 그 문자열이 없으면 알 수 없고, 값은 요청자가 직접 적어 넣은 **공개 문서 주소**이며 감사 로그를 볼 수 있는 사람은 Admin 뿐이다. 헤더·쿠키·토큰은 여전히 한 글자도 남기지 않는다 | 핸들러 | `file.fetch` (F-43) · 디버깅 묶음 S-6(2026-09-26): 기록 전에 **사용자 정보(`user:pass@`)를 `***@` 로 가린다** — 권한부(스킴 뒤 `//`, 스킴 없는 맨 앞, 쿼리 속 주소)의 마지막 `@` 까지. 거부·허용 행 모두 같은 함수(`files_fetch.php` `$shortUrl`) |
| `provider` | 무엇을 | 드라이브·OneDrive·SharePoint 링크였나 (`google`·`microsoft`·null) | 핸들러 | `file.fetch` (F-43) |
| `cookie_hops` | 무엇을 | 리다이렉트 중 직전 응답의 쿠키를 **같은 호스트**로 실어 보낸 **단계 수**(정수. 보통 0 또는 1). **쿠키 값도 이름도 남기지 않는다** — 조사에 필요한 것은 「썼나·몇 번」뿐이다. 이름에 `cookie` 가 들어가 `Audit` 의 부분 일치 금지 목록에 걸리므로 `ALLOWED_DETAIL_KEYS` 에 **정확 일치 예외**로 등록했다(`sessions_revoked`·`chain_hash` 와 같은 자리) | 핸들러 | `file.fetch` (F-46) |
| `needs_login` | 왜 | 공개 링크가 아니어서 거부했나(true). 값이 아니라 판정 사실이다 | 핸들러 | `file.fetch` (F-43) |
| `http_status` | 왜 | 외부 응답 상태 코드 (거부 사유 `redirect`·`fetch_failed` 의 근거). 사용자에게는 보이지 않는다 (S-05 ⑩) | 핸들러 | `file.fetch` deny |
| `upload_reason` | 왜 | `invalid_file` 거부의 세부 사유(`ext_not_allowed`·`mime_mismatch`·`too_large`·`quota_exceeded`…) — Upload 검증 코드 그대로 | 핸들러 | `file.fetch` deny `invalid_file` (7단계 검토에서 등록, 8단계 이후 반영) |
| `filters` | 어떻게 | 감사 로그 조회에 적용한 필터(키·값 객체). 값은 500자 절단 | 핸들러 | `audit.view` (8단계) |
| `artifact` · `checksum` · `bytes` | 무엇을 | 백업 산출물 파일명·sha256·크기 | 배치 | `backup.run`·`backup.restore` (9단계) |
| `checked` · `mismatches` | 왜 | 정합성 검사 건수와 불일치 목록(`stored_name`·종류, 최대 50건). 종류는 `missing`·`hash_mismatch`·`unreadable`·`orphan`·`orphan_purged` + F-69(2026-09-24) 감염 행 갈래 `infected_in_storage`·`quarantine_missing`·`quarantine_hash_mismatch`·`quarantine_unreadable`(11.4) | 배치 | `audit.integrity` (9단계) |
| `quarantined` · `quarantine_orphan` · `trash_quarantine_missing` · `quarantine_readable` | 무엇을 | 정합성 검사의 격리 폴더 대조(F-69 C, 11.4): 격리 폴더에 제대로 있는 살아 있는 감염 행 수(정상) · 행이 없는 격리 파일 수(보고만 — 증거 보관소라 지우지 않는다) · 휴지통 감염 행인데 격리 폴더에 없는 수(보고만) · 격리 폴더를 읽었나(`true`/`false` — `false` 면 감염 행이 전부 `quarantine_missing` 이 된 이유다). **개수·참거짓만** — 파일 이름은 `mismatches` 목록에만 | 배치 | `audit.integrity` allow·error (consistency_check, F-69) |
| `rule` · `evidence` | 왜 | 경보 규칙 번호와 근거(대상 계정·건수·기간) | 배치 | `alert.raised` (9단계) |
| `alerts_log_failed` | 왜 | `true` 하나 — 이 경보 줄을 경보 로그(alerts.log)에 쓰지 못했다(링크·주인이 다름 등 · 디버깅 묶음 S 검토 M-3). 감사 행은 그대로 남는다 | 배치(cli_raise_alert) | `alert.raised` |
| `rule_text` | 왜 | 경보 규칙의 사람 문장(규칙마다 코드 상수) — 경보 줄 `title` 의 재료. `scripts/alerts.php` 의 규칙 13개(`$raise` 자리 전부)에는 전부터 있었고, F-69(2026-09-24)로 AL-6(`consistency_check.php` · `audit_chain.php` 두 곳)·AL-8(`verify_backup.sh`)에도 붙었다 | 배치 | `alert.raised` 의 `evidence` 안 · `alerts.log` 줄의 `evidence` 안 |
| `origin` | 누가 | 경보를 누가 일으켰나 — `human`·`system`·`claude-review`·`claude-session` 넷 중 하나(판정 표 11.4, `src/Origin.php`). `alerts.log` 줄의 최상위 `origin` 과 **같은 값**. 보여 주기·분류(F-70 즉시/하루 요약) 전용 — 권한·차단·기록 여부에 쓰지 않는다(SECURITY R-100) | 배치(`cli_raise_alert()` · `verify_backup.sh` 는 `"system"` 고정) | `alert.raised` detail **최상위**(`{rule, evidence, origin}`) · `alerts.log` 줄 최상위 (F-69) |
| `title` (경보 줄) | 무엇을 | 사람이 읽는 경보 제목 — `Origin::title()`: `claude-*` 면 `[Claude] ` + `rule_text`, 아니면 `rule_text`(없으면 규칙 번호). **`alerts.log` 줄에만** 있고 감사 detail 에는 넣지 않는다(같은 값을 `rule_text`·`origin` 에서 다시 만들 수 있다) | `cli_raise_alert()` · `verify_backup.sh` | `alerts.log` 줄 최상위 (F-69) |
| `origin_hint` | 누가 | `partial` 하나 — 계정(`qa_`·`claude_`)과 검토 표식 UA(`tbox-review/`) 중 **하나만** 맞음(화면 「확인 필요」). 해당 없으면 키가 없다 | 배치(`cli_raise_alert()` 가 evidence 에 넣는다) | `evidence` 안 — `alerts.log` 줄과 `alert.raised` detail 둘 다 (F-69) |
| `host` | 어디로 | 푸시 서버 호스트(`fcm.googleapis.com`·`web.push.apple.com`·`updates.push.services.mozilla.com`) — **받기 주소(endpoint) 전체가 아니다**(주소에는 받기 토큰이 있다) | 핸들러 · 배치 | `alert.push_subscribe`·`alert.push_unsubscribe`·`alert.push_revoke`·`alert.push_send` (F-70) |
| `status` | 어떻게 | 푸시 서버 응답 상태 코드(정수) · 응답 전에 끊겼으면 null. 응답 본문·머리는 남기지 않는다 | 배치 | `alert.push_send` (F-70) |
| `kind` | 무엇을 | 알림 종류 `alert`·`digest`·`test`·`more`(「외 n건」) | 배치 | `alert.push_send` (F-70) |
| `device_hint` | 무엇으로 | 기기 요약 고정 글자(「Android Chrome」 등) — UA 원문 아님 | 핸들러 | `alert.push_subscribe`·`alert.push_unsubscribe` (F-70) · `auth.passkey_register` (F-72) |
| `credential_no` | 무엇을 | 지문·얼굴 로그인 열쇠의 **행 번호**(`passkeys.id`) — 열쇠 id 원문·공개 키가 아니다 | Auth · 핸들러 | `auth.login`(passkey*)·`auth.reauth`(passkey*)·`auth.passkey_register`·`auth.passkey_revoke` (F-72) |
| `credential_label` | 무엇을 | 사람이 붙인 기기 이름(1~40자) | 핸들러 · Auth | `auth.passkey_register`·`auth.passkey_revoke` (F-72) |
| `backed_up` | 어떻게 | 등록 순간 「계정에 동기화됨」(BS 플래그, true/false) | 핸들러 | `auth.passkey_register` (F-72) |
| `credentials_revoked` | 얼마나 | 한 번에 해제한 열쇠 수(정수) | 핸들러 | 자동 해제 요약 (F-72) |

> ★ F-72 새 키 이름에 `passkey` 를 쓰지 않는다 — `Audit::FORBIDDEN_DETAIL_KEYS` 의 `pass` 부분 일치로 값이 지워진다(`token`·`session`·`hash` 도 같음).

| `revoked_reason` | 왜 | 받기 해지 사유 `user`·`not_admin`·`gone` | 핸들러 · 배치 | `alert.push_unsubscribe`·`alert.push_revoke` (F-70) |
| `subscriptions` · `retry_after` | 얼마나 | 시험 알림을 받을 기기 수 · 다시 요청할 수 있을 때까지 초 | 핸들러 | `alert.push_test` (F-70) |
| `step` | 어디서 | 배치가 **멈춘 단계** 이름 (`pg_dump`·`pg_restore`·`files_extract`·`consistency_check`…). 경로가 아니라 단계 이름이며 인자·비밀값은 넣지 않는다 | 배치 | `backup.run error`·`backup.restore error` (9단계 · 16-2) |
| `window_minutes` | 어떻게 | 관리 기능 재인증 창의 길이(분, `Auth::REAUTH_MINUTES`). 나중에 창을 조정해도 "그때는 몇 분이었나" 를 로그만으로 알 수 있게 한다. 시각·세션 값이 아니다 | Auth::reauth | `auth.reauth allow` (16-1) |
| `lock_minutes` | 어떻게 | 잠금 길이(분, `Auth::LOCK_MINUTES` — 10). 창을 바꿔도 「그때는 몇 분 잠겼나」를 로그만으로 알 수 있게. 비밀·시각이 아니다 | Auth::countFailure | `auth.lock deny failed_limit` (디버깅 묶음 S2) |
| `chain_prev` · `chain_hash` | 무엇을 | 감사 로그 파일 해시 체인: 직전 일자 마지막 해시 · 봉인한 날 파일의 sha256 (R-11). `chain_hash` 는 이름에 `hash` 가 들어가 `FORBIDDEN_DETAIL_KEYS` 부분 일치에 걸려 2026-09-12 까지 `[제거됨]` 으로 남고 있었다(실측) — 공개돼야 의미가 있는 무결성 증거이므로 `Audit::ALLOWED_DETAIL_KEYS` 에 정확 일치로 등록한다(13단계) | 배치 | `audit.integrity` (9단계) |
| `resealed_from_hash` · `resealed_from_lines` · `resealed_from_bytes` · `relinked` | 왜·어떻게 | `--reseal` 이 덮어쓰기 **전**의 해시·줄수·바이트수와, 다시 이은 뒤따르는 항목의 개수. 재봉인은 "사람이 체인을 고친" 사건이므로 무엇이 무엇으로 바뀌었는지가 남아야 한다 (R-50). `resealed_from_hash` 도 `hash` 부분 일치 예외로 등록 | 배치 | `audit.integrity allow` (13단계) |
| `target_role` · `target_active` | 무엇을 | 대상 계정의 역할·활성 여부(변경 후 값) | 핸들러 | `user.*` (10단계) |
| `sessions_revoked` | 어떻게 | 끊은 세션 수 (R-10 전 기기 로그아웃). **`FORBIDDEN_DETAIL_KEYS` 의 `session` 부분 일치 예외** — `Audit::ALLOWED_DETAIL_KEYS` 에 정확 일치로 등록(값은 정수 개수, 토큰이 아니다) | 핸들러 | `auth.password_change`·`user.deactivate`·`user.role_change` (10단계) |
| `expires_at` | 무엇을 | 계정 만료일(Guest 필수). 생성·변경 행에 값 그대로 | 핸들러 | `user.create`·`user.expiry_change`·`user.role_change` (10단계) |
| `policy` | 어떻게 | 라우트에 붙은 정책 이름 | 라우터 | `authz.route` deny |
| `reason_text` | 왜 | 관리자가 입력한 사유 (500자) | 핸들러 | 역할 변경·비활성화·강제 삭제·레벨 하향 (10단계) |
| `changed_fields` | 무엇을 | 바뀐 **칸 이름** 목록(`role`·`team_id`·`is_active`·`expires_at`·`display_name`·`username`(d74)). 이름의 **값**은 어디에도 남기지 않는다(개인정보 · append-only). d74 아이디도 같다 — 새 아이디는 어디에도 없고 옛 아이디는 그 행의 `target_name` 이다 | 핸들러 | `user.update`·`user.role_change`·`user.activate`·`user.deactivate`·`user.expiry_change` (5-A″) |
| `display_name_set` | 무엇을 | `true`/`false` — 계정을 만들 때 이름을 넣었나(값은 남기지 않는다) | 핸들러 | `user.create` (5-A″) |
| `owner_id` | 무엇을 | 파일을 올린 사용자 id — 삭제·다운로드 판정에 쓰인 값 | 핸들러 | `file.delete`·`file.download` |
| `target_team` | 무엇을 | 파일의 `team_id` (team 레벨이 아니면 null) — `actor_team` 과 나란히 놓으면 `same_team`·`team_mismatch` 판정을 로그만으로 재검산할 수 있다 | 핸들러 | `file.download`·`file.delete`·`file.upload` |
| `ext` · `mime` | 무엇을 | 저장 확장자와 finfo 로 판별한 실제 MIME (DB `files.ext`·`files.mime` 과 같은 값) — 파일명이 아니라 내용 종류를 남긴다 (R-16) | 핸들러 | `file.upload`(allow) |
| `moved` | 어떻게 | 업로드 실패 시 임시파일이 이미 저장소로 옮겨진 뒤였는지(true/false) — 고아 파일 정리 여부를 로그로 확인 | 핸들러 | `file.upload`(error) |
| `share_id` | 무엇을 | 공유 링크 행의 id (`share_links.id`). **링크 원문도 그 해시도 남기지 않는다** — 남는 것은 "어떤 링크였나" 뿐이고, 그 값으로는 링크를 되살릴 수 없다 | 핸들러 | `share.create`·`share.revoke`·`share.download` (15-2) |
| `issued_by` | 누가 | 링크를 **만든 사람**의 계정명. 받는 쪽(`share.download`)에는 로그인한 사람이 없어 `actor_name` 이 비므로, "누가 열어 줬나" 는 이 칸이 답한다 | 핸들러 | `share.download` (15-2) |
| `remaining` | 무엇을 | 이번 다운로드 뒤 **남은 횟수**(정수). 회수 행에는 0 | 핸들러 | `share.download`·`share.revoke` (15-2) |
| `max_downloads` | 무엇을 | 그 링크의 허용 횟수(1~10) | 핸들러 | `share.*` (15-2) |
| `expires_at` (공유 링크) | 무엇을 | 링크 만료 시각. 계정 만료(`user.*`)와 **같은 키**를 쓴다 — 뜻이 같아 굳이 나누지 않는다 | 핸들러 | `share.create`·`share.download deny expired` (15-2) |
| `sensitive` | 무엇을 | 검출된 **패턴 이름과 건수만** (`{"rrn":1,"card":2}`). 검출된 숫자·문자열 **원문은 어떤 형태로도 넣지 않는다** (PRD S-07, SECURITY R-58). 빈 결과면 키 자체를 넣지 않는다 | 핸들러 | `file.upload` allow·deny `sensitive_level_required` (15-3) |
| `otp_verified` | 어떻게 | `true` — 요청에 실린 2단계 인증 코드를 **서버가 확인했다는 사실만**. 코드 6자리·비밀·스텝 번호는 어떤 형태로도 넣지 않는다(`temp_password_issued` 와 같은 성격). 이름에 `otp` 가 들어가 `FORBIDDEN_DETAIL_KEYS` 부분 일치에 걸리므로 `Audit::ALLOWED_DETAIL_KEYS` 에 정확 일치로 등록한다 | 핸들러 | `permission.change allow` (17-2) |
| `rule` (불변 규칙) | 왜 | 권한 매트릭스 변경이 어긴 불변 규칙의 글자 `a`~`e` (ARCH 5.15 ③). 경보 배치의 `rule`(AL-x)과 **같은 키를 다른 행에서** 쓴다 — 둘 다 "어느 규칙인가" 라는 뜻이라 나누지 않는다 | 핸들러 | `permission.change deny invariant` (17-2) |
| `folder_id` | 무엇을 | 폴더 행의 id (`folders.id`). 파일 쪽 행에서는 **그 파일이 든 폴더**의 id (null = 루트) | 핸들러 | `folder.*` · `file.upload`(allow) (18-1) |
| `parent_id` | 어디에 | 폴더의 부모 id (null = 루트). 만들 때·옮길 때의 **새 위치**다 | 핸들러 | `folder.create`·`folder.update` (18-1) |
| `depth` | 무엇을 | 폴더의 깊이 1~5 (부모 depth + 1) | 핸들러 | `folder.*` (18-1) |
| `folder_before` · `folder_after` | 어떻게 | 폴더가 바뀐 **이전값 → 새값**. 폴더 행에서는 바뀐 칸만 담은 객체 두 개(`name`·`parent_id`·`depth`·`access_level`·`team_id`), **파일 행에서는 옮기기 전후의 폴더 id**(정수 또는 null) | 핸들러 | `folder.update`·`folder.level_change` · `file.update`·`file.level_change`(파일 이동, 18-1) |
| `cascade` · `cascaded` | 어떻게 | 「하위에도 적용」을 썼나(`true`)와 **함께 바뀐 항목 수**(정수). 항목 하나하나는 자기 행(`file.level_change`·`folder.level_change`)으로 따로 남는다 | 핸들러 | `folder.level_change` (18-1) |
| `cascade_from` | 왜 | 이 항목이 **어느 폴더의** 「하위에도 적용」으로 바뀌었나 — 그 폴더의 id. 값 하나로 한 번의 일괄 변경을 묶어 볼 수 있다 | 핸들러 | `file.level_change`·`folder.level_change`(cascade 로 바뀐 행, 18-1) |
| `violations` | 왜 | 불변 규칙 ①을 어긴 하위 항목의 **개수만**. 어떤 파일·폴더인지는 넣지 않는다 — 요청한 사람이 못 보는 항목일 수 있다 (SECURITY R-63) | 핸들러 | `folder.level_change deny children_exceed`·`not_owner` (18-1) |
| `token_kind` | 어떻게 | 토큰 경로의 **종류** 분류어(`personal`·`shared`). 코드에 적힌 고정값이고 요청이 정하지 않는다. 이름에 `token` 이 들어 부분 일치 금지에 걸리므로 `ALLOWED_DETAIL_KEYS` 정확 일치 예외 | `Audit::autoDetail` | 토큰 경로의 모든 행 (F-59) |
| `token_id` | 어떻게 | 개인 토큰의 **행 번호**(정수, `api_tokens.id`). 토큰 값·hint 가 아니다 — 「어느 토큰으로」 를 사람이 목록에서 되짚는 열쇠. 정확 일치 예외 | `Audit::autoDetail`(개인 토큰 경로) · `api_token.issue`·`api_token.revoke` 핸들러 | F-59 |
| `token_name` | 무엇을 | 발급·회수한 토큰의 **라벨**(「사무실 PC」). 사람이 붙인 이름이고 비밀이 아니다. 정확 일치 예외 | 핸들러 | `api_token.issue`·`api_token.revoke` (F-59) |
| `token_scope` | 어떻게 | 토큰의 **범위** 분류어(`read`·`read+upload`·`read+upload+delete`). 코드가 정한 세 값 중 하나. `autoDetail()` 이 토큰 경로의 모든 행에, 발급·범위 거부 행에는 핸들러/Auth 가 붙인다. 이름에 `token` 이 들어 부분 일치 금지에 걸리므로 정확 일치 예외 | Audit·핸들러 | F-66 (ARCH 4.7) |
| `space_kind` | 어디서 | 커뮤니티 공간 종류 `personal`·`team`·`board` — 이름·아이디는 넣지 않는다 | 핸들러 | `page.create deny not_owner·not_member`(대기열 10번 A.1, 2026-09-24) |
| `via` | 어떻게 | 어느 입구로 받았나 — 지금은 `zip` 하나(5.34). 한 건 받기 행에는 없다(행이 전과 같다) | `files_zip` → `_download_common` | zip 요청의 `file.download` 모든 행 · `file.zip` 행 (Q:F-3a 라운드 C) |
| `requested` · `skipped` | 무엇을 | zip 요청의 (같은 id 를 하나로 센) 파일 수 · 뺀 수(없는 id·지운 것·거부·무결성 실패). **개수만** — 이름·id 목록은 넣지 않는다(파일마다의 행이 따로 있다) | `files_zip` | `file.zip` 행 |
| `added` | 무엇을 | (이미 있는 키 — 더한 줄 수) zip 에 넣은 파일 수 | `files_zip` | `file.zip allow` |
| `free_bytes` | 왜 | zip 을 못 만든 순간 앱 임시 폴더의 남은 바이트(`disk_free_space`) — `size_bytes`(합계)와 나란히 「왜 모자랐나」 | `files_zip` | `file.zip error no_space` |
| `reserved_bytes` | 왜 | 그 순간 **다른 슬롯이 잡아 둔** 바이트 합(쥐어진 슬롯 파일의 2 × 합계 · 모르면 최악) — 검토 L-C1 | `files_zip` | `file.zip error no_space` |
| `elapsed_ms` | 왜 | 요청 시작부터 [8] 감사 직전까지 걸린 밀리초 — 시간 한도(50초)를 넘어 멈춘 행 | `files_zip` | `file.zip error zip_failed`(why `time`) |

**검토 행동과 사용자 행동 가르기** — 두 조건 중 하나면 검토다. 둘 다 아니면 사용자(또는 침입자)다.

```sql
-- 사용자 행동만 (검토 계정·검토 UA 제외)
SELECT happened_at, actor_name, ip, action, result, summary
  FROM audit_logs
 WHERE COALESCE(actor_name, target_name) NOT LIKE 'qa\_%'
   AND COALESCE(user_agent, '') NOT LIKE 'tbox-review/%'
 ORDER BY id DESC LIMIT 20;
```

**조회 예 (누가·언제·어디서·무엇을·어떻게·왜 한 줄로)**

```sql
SELECT happened_at, actor_name, detail->>'login_ref' AS login_ref, ip, user_agent,
       action, target_name, access_level, result,
       COALESCE(reason, detail->>'granted_by') AS why
  FROM audit_logs ORDER BY id DESC LIMIT 20;
```

### 7.6 조회 — 8단계 `GET /api/audit` 와 화면 (PRD S-07 "조회 화면 필터", 10장 "감사 로그")

| 항목 | 설계 |
|---|---|
| 정책 | 라우트 정책 `audit.read` (Admin 만, Q-8). Employee 의 호출은 403 + `authz.route|deny` 기록 (T-13) |
| 조회 자체를 기록 | 핸들러 첫 줄에서 `Audit::allow('audit.view', [detail: 적용한 필터])` — 로그를 본 것도 로그에 남는다 (PRD 7.7 "감사 로그 조회") |
| 필터 (육하원칙 6개) | 누가 `actor` (`actor_name` 또는 `login_ref`), 언제 `from`·`to`, 어디서 `ip`, 무엇을 `target`(`target_name` 부분 일치)·`level`, 어떻게 `action`, 왜 `result`·`reason`·`granted_by`. 전부 바인딩, `action`·`result`·`level` 은 허용 목록 |
| 정렬·페이지 | `happened_at DESC` 고정, `page` 20건, `audit_time_idx` 사용 |
| 응답 | 구조화 컬럼 + `detail` + `summary`. 화면은 `summary` 를 `textContent` 로만 출력(7.4 "파일명이 문장을 흉내") |
| 내보내기 | 없음(비목표). 필요하면 서버에서 `psql \\copy` |
| `exec_ack` · `otp_verified` · `exec_entry_count` · `exec_entries` | F-65 (5.29) — 확인 문구를 받았나(true) · 2단계 코드를 확인했나(true) · zip 안의 차단 항목 수 · 그 이름 목록(≤ 20, 이름뿐 내용 아님). 비밀번호·코드 값은 **절대** 들어가지 않는다 |

**행동 목록 상태 (2026-09-11)**: 5단계 `file.update`·`file.level_change`, 7단계 `file.fetch`, 8단계 `audit.view`, 9단계 `file.purge`·`backup.run`·`backup.restore`·`audit.integrity`·`alert.raised`, 10단계 `file.restore`·`user.create`·`user.role_change`·`user.deactivate`·`user.activate`·`user.expired`·`auth.password_change`·`auth.mfa_enroll`·`auth.mfa_disable`·`auth.mfa` 의 문장 틀이 7.4 표에 **모두 등록**되었다(일괄 진행 모드에서 단계별 지시서 작성 전에 한 번에 추가). 이 목록이 PRD 7.7 "기록해야 하는 행동" 표와 1:1 이어야 하며, 새 행동은 여기와 7.4 표에 먼저 넣는다. `auth.revoke_sessions` 는 별도 행동이 아니라 `auth.password_change`·`user.deactivate` 의 결과(detail.sessions_revoked)로 기록한다.

---

## 8. nginx 설정 (핵심만)

> **실습 VM 에서는** 아래 예시의 `listen 443 ssl` · 인증서 · 80→443 리다이렉트 블록을 **쓰지 않는다.** TLS 는 강사 프록시가 종단하고 VM 에는 80 으로 들어온다(3장 배포 메모). 실제 파일은 `deploy/sites/tbox-v2.conf`(공개, `listen 80`)와 `deploy/sites/tbox-v2-local.conf`(`127.0.0.1:8082`) 이며, 그 외 항목(root·헤더·단일 입구·`internal`·숨김 파일 404)은 아래와 동일하다. 추가로 `set_real_ip_from 192.168.104.100; real_ip_header X-Forwarded-For;` 를 넣고, HSTS 는 공개용(`tbox-v2`)에만 넣는다(브라우저는 프록시를 통해 항상 https 로 보므로 유효). 운영 전환 시에는 아래 원문대로 VM 이 직접 443 을 받는다.


```nginx
server {
    listen 443 ssl http2;
    server_name tbox.internal;
    ssl_certificate     /etc/ssl/tbox/fullchain.pem;
    ssl_certificate_key /etc/ssl/tbox/privkey.pem;

    root  /var/www/tbox/public;          # ★ public/ 만 노출
    index index.php;

    client_max_body_size 101m;           # PRD S-06: 100MB 초과는 앱에 닿기 전 413.
                                         # 6단계 결정(2026-09-11): 100m 이면 multipart 오버헤드 때문에 정확히 100MB 파일이 413 이 된다
                                         # (3단계 재검토 실측). nginx 는 101m 으로 요청 전체를 받고, 정확한 100MB(104,857,600 B)
                                         # 경계는 PHP Upload::MAX_BYTES 가 판정한다. 101MB 파일은 여전히 nginx 413.

    # 보안 헤더 (PRD S-03, S-09-9)
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;   # 실습 VM: 공개용 tbox-v2 에만(프록시 뒤 https). 로컬 808N 포트 설정에는 넣지 않음

    # 로그인 IP 속도 제한 (4.0). zone 정의는 http 컨텍스트(deploy/sites/tbox-common.conf)
    # 실제 파일은 `= /api/login/`(뒤 슬래시) 블록도 같은 내용으로 둔다 — Http::path() 가 끝 `/` 를 떼므로 슬래시만 붙여 속도 제한을 우회하는 것을 막는다 (2단계 실측)
    location = /api/login {
        limit_req zone=tbox_login burst=10 nodelay;
        limit_req_status 429;
        include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.1-fpm-tbox.sock;
    }

    # 정적 파일
    location /assets/ { try_files $uri =404; expires 7d; }

    # 나머지는 전부 index.php 로 (단일 입구)
    location / { try_files $uri /index.php$is_args$args; }

    # PHP 는 index.php 하나만 실행 (다른 .php 요청은 404)
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm-tbox.sock;
    }
    location ~ \.php$ { return 404; }

    # 다운로드 가속: PHP가 권한 확인 후 X-Accel-Redirect 로 넘기는 내부 경로
    location /protected/ {
        internal;                        # ★ 외부 URL로는 절대 접근 불가
        alias /var/lib/tbox/storage/;
    }

    # 숨김 파일·백업 파일 차단
    location ~ /\. { return 404; }        # 403(deny all) 이 아니라 404 — 존재 여부 자체를 숨긴다 (PRD 13장 1단계 기준, S-09-5 취지)
}
server { listen 80; server_name tbox.internal; return 301 https://$host$request_uri; }   # ※ 실습 VM 에서는 생략 — 80 은 프록시가 전달해 오는 앱 트래픽이고, http→https 301 은 프록시가 한다
```

| 설정 | 어떤 요구사항 |
|---|---|
| `root … /public` | 소스·설정 노출 방지 |
| `client_max_body_size 100m` | S-06 크기 제한 (1차) |
| `location ~ \.php$ { return 404; }` | 단일 입구 강제 → 검사 우회 경로 없음 |
| `internal;` | 실제 파일 URL 직접 접근 차단 |
| CSP 등 헤더 | S-03 XSS 완화 |
| `gzip_types`(JSON 제외) · `map $arg_v` · `location = /api/health` 308 | Q:F-4 P-1 · U-4′ · P-5b — 아래 8.1 |

### 8.1 압축 · 자산 캐시 · 옛 헬스 주소 (Q:F-4 라운드 C, 2026-09-24)

- **압축(P-1)** — http 단 `deploy/sites/tbox-common.conf`(운영 `/etc/nginx/conf.d/`): `gzip_types` 는 CSS·JS·SVG·웹 매니페스트뿐이고
  `gzip_vary on` · `gzip_proxied any` · `gzip_comp_level 5` · `gzip_min_length 1024` 다. `/etc/nginx/nginx.conf` 는 배포판 파일이라 고치지 않는다(`gzip on` 만 켜져 있다).
  **`application/json` 은 넣지 않는다** — 창구 응답에 CSRF 토큰이 있어 BREACH 재료가 된다. JSON 응답에는 `Vary` 가 없는 것으로 확인한다.
  text/html 은 nginx 기본값이라 전부터 압축돼 나갔다. 화면 HTML 에는 토큰·세션 값이 없고, 되비추는 요청 값은 허용 목록의 키뿐이다.
  **http 단이라 같은 nginx 의 `tbox-v1-local`(8081, 취약점 보존 실습 사이트)에도 CSS·JS 압축과 `Vary` 가 생긴다.**
  보존하는 취약점과 무관한 전송 방식이라 그대로 둔다.
- **자산 캐시(U-4′)** — `map $arg_v $tbox_asset_expires { default 7d; "~^[0-9a-z]{6,40}$" 1y; }`. 두 사이트의 `location /tbox/assets/` 는
  `expires $tbox_asset_expires;` 한 줄만 쓰고 add_header 는 두지 않는다. 그래서 server 단 보안 헤더 상속이 그대로다.
  `immutable` 은 없다 — add_header 가 필요해서다. 새로 고침 때 304 확인 요청은 남는다.
  **전제**: 커뮤니티 부품 import 의 `?v=<내용 해시>` 는 `scripts/community_asset_version.php` 로 찍는다. 찍기를 잊으면 옛 부품이
  7일이 아니라 1년 브라우저에 갇힌다. 그래서 `release.sh` 와 nginx 반영 스크립트가 `--check` 를 하고, 점검 봇 주기 점검에도 둔다.
  셸이 붙이는 `?v=<파일 시각>`(app.js·app.css 등)은 checkout·직접 편집 모두 시각이 바뀌어 이 위험이 없다.
- **옛 헬스 주소(P-5b)** — `location = /api/health { absolute_redirect off; return 308 /tbox$request_uri; }`. 사용자 모니터 프로그램
  (`tbox-buddy/3.0`)이 옛 주소를 하루 약 7,500번 부르는데, 전에는 그때마다 PHP 워커가 돌고 DB 에 붙은 뒤 308 을 줬다.
  이제 nginx 가 바로 308 을 준다(본문은 nginx 기본 쪽 · 쿼리 원문 보존 · POST 도 처리 0 — Q:T-3 그대로).
  **access.log 에는 여전히 하루 약 7,500줄 남는다** — 모니터 프로그램이 새 주소(`/tbox/api/health`)로 바뀌어 옛 주소 사용이 언제
  끝나는지 보려고 일부러 둔다. 끝난 뒤 이 location 에 `access_log off;` 를 둘지 정한다.
- **반영** — 저장소 쪽은 `deploy/sites` 세 파일이다. /etc 는 사용자 sudo 로
  `scripts/apply-nginx-qf4c-2026-09-24.sh`(Qaredi 저장소)를 돌린다. 이 스크립트는 지금 /etc 세 파일이 알려진 판이고 원본이
  검토 통과 판(sha256)과 같을 때만 설치한다. 그 뒤 확인이 하나라도 틀리면 백업으로 되돌린다.

---

## 9. PHP 설정 (php-fpm 풀: `tbox`)

> 버전: **PHP 8.1** (실습 VM 설치본, Q-11). 코드는 8.1 문법만 사용. 8.1 은 보안 지원이 종료됐으므로 운영 전환 시 8.4 로 업그레이드 (SECURITY R-21). 필요 확장: `php8.1-pgsql php8.1-fpm php8.1-curl(F-14) php8.1-mbstring` — `php -m` 으로 확인.

```ini
; /etc/php/8.1/fpm/pool.d/tbox.conf
[tbox]
user  = tbox-php
group = tbox-php
listen = /run/php/php8.1-fpm-tbox.sock
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size]       = 105M
php_admin_value[max_file_uploads]    = 1          ; 요청당 파일 1개 (PRD S-06)
php_admin_value[display_errors]      = Off        ; PRD S-09-7
php_admin_value[log_errors]          = On
php_admin_value[error_log]           = /var/log/tbox-v2/app.log
php_admin_value[session.save_path]   = /var/lib/tbox-v2/sessions   ; 웹 루트 밖 (환경 분리 후 -v2, 2026-09-09)
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_secure]   = 1
php_admin_value[session.cookie_samesite] = Lax
php_admin_value[session.use_strict_mode] = 1      ; 세션 고정 공격 방지
php_admin_value[allow_url_fopen]     = Off        ; ★ file_get_contents('http://...') 자체를 막음. 외부 요청은 src/Fetch.php 의 cURL 만 (S-05 ⑫⑬)
php_admin_value[open_basedir]        = /var/www/tbox:/var/lib/tbox:/var/log/tbox:/etc/tbox:/tmp   ; /var/log/tbox 는 Audit 이 audit.log 를 직접 append 하므로 필요
; 소켓·프로세스 함수 차단 (SECURITY.md R-03). cURL 은 F-14 에 필요하므로 허용 — 대신 src/Fetch.php 밖의 curl_* 는 린트가 반려
php_admin_value[disable_functions]   = fsockopen,pfsockopen,stream_socket_client,stream_socket_server,exec,system,shell_exec,passthru,popen,proc_open
```

> `allow_url_fopen = Off` 와 `open_basedir` 는 "실수로 SSRF·경로 탈출 코드를 짜도" 실행 단계에서 한 번 더 막아주는 안전망이다.
> 위 블록은 요지다 — 실제 파일은 `deploy/php-fpm-pool.conf`(운영 `/etc/php/8.1/fpm/pool.d/tbox.conf` 와 바이트까지 같게 둔다. 경로는 `/var/www/qaredi/tbox-v2` · `/var/lib/tbox-v2` · `/var/log/tbox-v2` · `/etc/tbox-v2`).

### 9.1 워커 수 · 요청 시간 제한 · DB 문장 시간 제한 (Q:F-4 P-7, 2026-09-24 — SECURITY R-103)

**왜.** 풀이 `pm = ondemand` · 최대 5개였고 요청 시간에 끝이 없었다(`request_terminate_timeout` 없음, DB `statement_timeout = 0`). 큰 업로드·URL 가져오기 몇 건이
워커 다섯을 붙잡으면 다른 요청은 전부 줄을 섰고, 어딘가에서 멈춘 요청·쿼리는 워커를 영영 쥐고 있었다. ondemand 는 30초 쉬면 워커를 내려 P-2(5.31)의 연결 재사용이
한가할 때 사라졌다.

| 값 | 전 | 후 | 근거 (2026-09-24 실측 — 비root 임시 풀·임시 nginx(검토 포트 사이트 사본)·운영 복사 임시 DB, PHP 쪽 시간은 FPM 접근 로그) |
|---|---|---|---|
| `pm` | `ondemand` · 유휴 30초 | **`dynamic`** · 시작 2 · 쉬는 워커 2~4 | 쉬는 워커가 연결(P-2)을 들고 기다린다. 부하 뒤 쉬는 워커가 4 를 넘으면 1초에 하나씩 내린다(8 → 4, 12초) |
| `pm.max_children` | 5 | **8** | 워커 RSS 20~32MB, 8개 합 RSS 약 205MB · PSS 약 49MB(마스터 7MB). 운영 5개 합 RSS 약 97MB · `free` 사용 1.5GB/3.9GB. 이론 최악 8 × `memory_limit` 128M = 1GB. 같은 FPM 마스터의 **www 풀**(`www.conf` 기본값 dynamic · 최대 5, 옛 판 `tbox-v1-local` 이 쓴다)까지 더하면 (8 + 5) × 128M ≈ 1.6GB, 여기에 clamscan(최대 991MB, R-86) · PostgreSQL(실측 RSS 합 약 270MB, `shared_buffers` 128MB)을 겹쳐도 3GB 대 — RAM 3.9GB + 스왑 2GB 안이지만 빠듯해 더 늘리지 않는다. DB 상시 연결 = 워커 수(최대 8, + `perm` 1) |
| `request_terminate_timeout` | 없음 | **90s** | 가장 긴 정상 요청: URL 가져오기 리다이렉트 3번 × 9초 + 100MB **29.4초**, 최악(리다이렉트 3번 × 9.5초 + 10초에 끊기는 느린 본문) **38.7초 → 400**(Fetch 는 단계마다 연결 3초·전체 10초, 최대 4단계 — 약 40초 + 검증). 100MB 업로드 1.8~2.8초 · 미리보기 1.1~1.8초 · 다운로드 1.5~1.9초. nginx `fastcgi_read_timeout 60s` 보다 길게(nginx 가 기다리는 요청을 FPM 이 먼저 끊지 않게) · 가져오기 상한(약 40초)의 두 배 이상 · 60초를 넘는 정상 경로 없음. 504 뒤에도 워커를 쥐는 시간 최대 30초(독립 검토 L-1 — 처음 제안 120s 는 60초) |
| DB `statement_timeout` (역할 `tbox_app`) | 0 | **15s** | 10장 표 — tbox_app 으로 도는 cron 전부 스크립트 전체 1초 안 · 가장 긴 문장 64ms(첫 연결 포함) |
| DB `idle_in_transaction_session_timeout` (역할 `tbox_app`) | 0 | **60s** | 5.31 요청 끝 롤백·넘겨받을 때 정리가 못 돈 경우의 DB 쪽 안전망. 앱 트랜잭션 안에는 네트워크·스캐너 대기가 없다(내려받기·검증·clamscan 은 트랜잭션 밖) |

- **업로드는 nginx 가 본문을 다 받은 뒤 넘긴다**(`fastcgi_request_buffering` 기본 on). 느린 손님(10MB/s)의 100MB 업로드: 손님 12.9초 · nginx 12.8초 · **PHP 2.8초**.
- **다운로드는 X-Accel-Redirect 가 아니다** — `Storage::stream` 의 `readfile()`(6단계 결정, R-34). 그래도 PHP 가 오래 쥐지 않는 것은 nginx 가 FastCGI 응답을
  버퍼·임시 파일로 받아 두기 때문이다(`fastcgi_buffering` 기본 on · `fastcgi_max_temp_file_size` 기본 1024m — 설정에 두 값을 바꾸는 줄 없음). 느린 손님(5MB/s)의 100MB:
  손님 20.0초 · nginx 18.4초 · **PHP 1.7초**. 대가: 느린 손님 한 명당 nginx 임시 공간 최대 100MB(`/var/lib/nginx/fastcgi`).
- **시간이 다 되면**: FPM 마스터가 워커를 죽이고(로그 `execution timed out … terminating`) nginx 는 502 를 낸다. 워커가 들고 있던 DB 연결이 끊기며 열린 트랜잭션은
  서버가 되돌린다. 60초 뒤 nginx 가 먼저 504 를 낸 요청도 PHP 는 90초까지 돌 수 있다(그 사이 30초 동안 워커 하나를 쓴다).
- `max_execution_time`(php.ini 30초)은 리눅스에서 **CPU 시간**이라 네트워크·잠 대기를 세지 않는다 — 벽시계 상한은 `request_terminate_timeout` 하나다.
- **reload 로 적용한다**(`systemctl reload php8.1-fpm` = SIGUSR2 — 마스터가 설정을 다시 읽고 워커를 새로 띄운다. `pm` 종류 변경도 reload 로 된다). 적용 스크립트
  `review-scripts/apply-fpm-p7-2026-09-24.sh`(검토 뒤 `/var/www/qaredi/scripts/`). DB 쪽은 `review-scripts/apply-db-p7-2026-09-24.sh` — **DB 를 먼저** 적용하면
  뒤의 FPM reload 가 워커(와 영속 연결)를 새로 띄워 새 역할 설정이 바로 모든 워커에 걸린다(역할 설정은 새 연결에만 걸린다).
  FPM 스크립트는 /etc 가 이미 새 판이면 reload 하지 않고 확인만 한다.
- **reload 때 진행 중 요청이 끊긴다** — `php-fpm.conf` 의 `process_control_timeout` 이 기본 0 이라 reload 가 처리 중인 워커를 기다리지 않는다(큰 업로드 등).
  이 값은 이번 범위 밖이다(별 건). 그래서 사용자가 사이트를 쓰는 중이면 reload 를 미룬다(HANDOFF 7장 8).
- 시험 `tests/qf4_p7_timeouts.sh`(임시 DB · 임시 nginx + php-fpm, 운영에 쓰지 않음).

---

## 10. DB 계정 권한 (최소 권한 원칙)

| 계정 | 용도 | 권한 |
|---|---|---|
| `tbox_owner` | 스키마 생성·마이그레이션 (배포 시에만) | 테이블 소유 |
| `tbox_app` | PHP 앱 런타임 | `users teams files`: SELECT/INSERT/UPDATE · `sessions`: SELECT/INSERT/UPDATE/DELETE · `permissions`: **SELECT 만** · `audit_logs`: SELECT/INSERT 만 · DROP 없음 |
| `tbox_backup` | 백업 스크립트 | 전체 SELECT (읽기 전용) |
| `tbox_perm` | 권한 매트릭스 편집 핸들러 **하나**(`admin_permissions_update.php`, 17단계 F-25) | `permissions`: SELECT/UPDATE 만 · 다른 표 0 · INSERT/DELETE 없음(16행 고정). `.env` `DB_PERM_USER/PASS`, `Db::perm()` 지연 연결 · **비영속**(앱 연결 `tbox_app` 만 워커마다 재사용 — 5.31) |
| `tbox_app` (즐겨찾기 — 5.33) | 같은 앱 계정 | `file_favorites` · `folder_favorites`: SELECT · INSERT(사람·대상 칸만) · DELETE(빼기) — UPDATE 없음. 커뮤니티 `page_favorites`(+ position UPDATE) · `page_recent`(+ last_viewed_at UPDATE · DELETE — 기록이 아니라 화면 편의)는 커뮤니티 ARCH 21.24 |
| `tbox_push` | 휴대폰 경보 **보내는 배치** 하나(`scripts/alert_push.php`, OS 계정 tbox-push — F-70 · 5.32) | `alert_push_subscriptions`: SELECT · UPDATE(last_ok_at·fail_count·fail_since·revoked_at·revoked_reason) · `alert_push_outbox`: SELECT · UPDATE(sent_at) · `users`: **(id, role, is_active) 세 칸만** SELECT · `audit_logs`: INSERT 만 · 그 밖 0. 마이그레이션이 NOLOGIN 으로 만들고 로그인·비밀번호(`/etc/tbox-v2/push.pgpass`)는 사용자 sudo 반영 스크립트가 표준입력으로 정한다 · 문장 15s · 유휴 트랜잭션 60s |
| `tbox_app` (지문·얼굴 로그인 — 5.35, F-72) | 같은 앱 계정 | `passkeys`: SELECT·INSERT·UPDATE — **DELETE 없음**(해제는 `revoked_at`) · `passkey_challenges`: SELECT·INSERT·UPDATE·DELETE(일회용 값 — 만료 정리) · 두 시퀀스 USAGE·SELECT. `tbox_backup` 은 둘 다 SELECT(공개 키라 비밀 아님) · `tbox_perm`·`tbox_push` 0. `db/migrations/2026-10-06_passkeys.sql` · `db/schema.sql` · `db/grants.sql` · `deploy/install.sh` 4-4-1 확인 목록 |

> 파일 삭제가 논리 삭제(UPDATE)이므로 앱 계정에 `DELETE` 권한 자체가 필요 없다. 30일 실제 정리는 `tbox_owner` 권한의 배치 스크립트가 수행한다.
> (2026-09-24 코드 확인: t-box 휴지통 정리 `purge_trash.php` 는 **`tbox_app`** 으로 붙어 `files.purged_at` 만 UPDATE 하고 바이트는 파일 시스템에서 지운다 — 행은 남는다.
> `tbox_owner` 로 붙는 배치는 커뮤니티 휴지통 정리 `community_purge.php` 하나다(아래 표).)

**역할 시간 제한 (Q:F-4 P-7, 9.1 · SECURITY R-103).** `tbox_app`(그리고 F-70 의 `tbox_push` — 같은 두 값, `db/migrations/2026-09-30_f70_push.sql`) `statement_timeout = 15s` · `idle_in_transaction_session_timeout = 60s`
(`db/migrations/2026-09-29_app_role_timeouts.sql`, 역할 설정 `ALTER ROLE … SET` — 새 연결부터). `tbox_owner` · `tbox_backup` · `tbox_perm` 은 제한 없음(서버 기본 0),
시험 전용 읽기 역할 `tbox_testread` 는 자체 설정(`statement_timeout 10s` · `lock_timeout 1s` · `idle_in_transaction_session_timeout 30s` · `default_transaction_read_only on`) 그대로다.

| cron(`/etc/cron.d/tbox-v2`) · 실행 계정 | 스크립트 | DB 역할 (경로) | 15s 에 걸리나 — 실측(2026-09-24, 운영 복사 임시 DB, 역할 설정은 `IN DATABASE <임시>` 로만) |
|---|---|---|---|
| 00:05 root | `audit_chain.php --yesterday` | `tbox_app` — `cli_bootstrap` → `Db::boot(DB_USER)`. DB 는 감사 한 줄(INSERT)뿐, 나머지는 파일 해시 | 전체 0.13초 · 문장 1개 |
| 02:00 root | `consistency_check.php --quiet` | `tbox_app` — files 전체 SELECT 1 + 감사·경보 INSERT. 파일 sha256 은 트랜잭션 **밖** PHP 에서 | 전체 0.12~0.17초 · 가장 긴 문장 63ms(첫 연결 포함) |
| 03:00 root | `backup_db.sh` | `pg_dump` = **`tbox_backup`**(`backup.pgpass`) · 감사 한 줄은 `audit_cli.php` = `tbox_app` | pg_dump 는 제한 밖 · 감사 0.11초 |
| 03:30 root | `backup_files.sh` | DB 는 `audit_cli.php` 감사 한 줄 = `tbox_app`(tar·rsync 는 DB 없음) | 0.11초 |
| 04:00 root | `purge_trash.php` | `tbox_app` — 대상 SELECT 1 + 행마다 UPDATE·감사(짧은 트랜잭션) | 0.11초(대상 0) · 부하 50행 0.26초(문장 101개) |
| 04:10 root | `community_purge.php --run --max 500` | `community.purge_trash()` = **`tbox_owner`**(`owner.pgpass` — 함수가 소유자 전용) · 감사 한 줄은 `tbox_app` | 소유자 연결은 제한 밖(이번에 재지 않음) |
| 04:30 root | `verify_backup.sh` | DB 는 `audit_cli.php` 감사(`tbox_app`)뿐 — `pg_restore -l` 은 DB 에 붙지 않는다 | 0.11초 |
| 5분 root | `alerts.php` | `tbox_app` — `audit_logs` 판정 SQL 10개 | 0.52~0.71초 · 가장 긴 문장 53ms |
| 1분 tbox-php | `scan_pending.php` | `tbox_app` — 대기 SELECT 1 + 파일마다 짧은 트랜잭션. clamscan(최대 22초)은 트랜잭션 **밖** | 0.10~0.15초(대기 0 · 30건 dry-run) |
| 1분 tbox-push | `alert_push.php` (F-70) | **`tbox_push`** — `push_bootstrap` → `Db::boot`(비밀번호 칸 비움 · PGPASSFILE). 받기·대기표 SELECT 몇 줄 + 결과마다 UPDATE·감사 INSERT(자동 커밋 — 밖으로 나가는 동안 트랜잭션 없음) | 시험 임시 DB 에서 배치 한 번 1초 안(가짜 푸시 서버). 바깥 요청 한 번은 최대 10초이지만 문장이 아니다 |
| (웹 일부) | 권한 매트릭스 쓰기 `Db::perm()` | **`tbox_perm`** (비영속) | 제한 밖 |

스크립트 **전체**가 15초 안이면 그 안의 어떤 문장도 15초를 넘지 않는다 — 모두 1초 안이라 스크립트별 `SET statement_timeout` 은 두지 않았다(필요해지면 그 스크립트의
연결에서만 늘린다). 큰 표 행 수(운영 사본): `audit_logs` 23,312 · `files` 960 · `community.pages` 838.

---

## 11. 운영 — 백업과 복구 (PRD S-08)

### 11.1 백업 일정 (cron `/etc/cron.d/tbox-v2`, root 실행 — 9단계 확정 2026-09-11)

> **도구 결정(9단계, PRD S-10-C "배포판 패키지만·새 패키지 0")**: 실습 VM 에 `age`·`restic` 이 없고 새로 설치하지 않는다.
> 암호화는 `gpg --symmetric --cipher-algo AES256`(gnupg, 기본 설치), 파일 백업은 `tar` 전체 + `rsync` 로컬 미러로 대체한다.
> 키는 `/etc/tbox-v2/backup.key`(0600 root, `openssl rand -hex 32`) — **분실 = 백업 전체 무용**(R-08). 사본을 서버 밖 2인 보관.
> WAL 아카이브(15분 RPO)·원격 저장소(Q-4)·원격 로그(rsyslog TLS)는 대상 서버가 없어 **예시 설정만** 둔다(`deploy/postgresql/archive.conf.example`, `deploy/rsyslog/tbox-v2.conf.example`) — 실제 RPO 는 일 1회(24시간)이며 운영 전환 조건에 포함.

| 시각 | 스크립트 | 내용 | 산출물 · 감사 기록 |
|---|---|---|---|
| (미적용) 매 15분 | PostgreSQL `archive_command` | WAL 아카이브 → 원격 | 예시 설정만. 운영 전환 시 |
| 03:00 | `scripts/backup_db.sh` | `pg_dump -Fc`(역할 `tbox_backup`, 읽기 전용) → 파이프로 `gpg --symmetric` → sha256. 평문 덤프를 디스크에 남기지 않음 | `/var/backups/tbox-v2/db/db-YYYYMMDD-HHMM.dump.gpg` + `.sha256` · 보관 일 7·주 4·월 6 · `backup.run allow|error` |
| 03:30 | `scripts/backup_files.sh` | `tar -C /var/lib/tbox-v2 -cz storage \| gpg` + `rsync -a --delete` 로컬 미러 | `files/files-YYYYMMDD.tar.gz.gpg` + `.sha256`, `files-mirror/` · `backup.run` |
| 04:00 | `scripts/purge_trash.php` | 30일 지난 휴지통 파일 실제 삭제 → `files.purged_at` 기록(행은 남김, 감사 추적) | `file.purge allow|error` |
| 04:30 | `scripts/verify_backup.sh` | 최신 산출물 `sha256sum -c` + gpg 복호화 시험(`pg_restore -l` 목록만) | `audit.integrity allow|error`, 2회 연속 실패 → `alert.raised AL-8` |
| 00:05 | `scripts/audit_chain.php` | `audit.log` 일별 sha256 해시 체인(R-11) | `/var/lib/tbox-v2/audit-chain.txt`, `audit.integrity` |
| 매 5분 | `scripts/alerts.php` | AL-1·AL-2·AL-5·AL-7·AL-10 SQL 판정, 중복 억제 30분 | `/var/log/tbox-v2/alerts.log`, `alert.raised` |
| 매분 | `scripts/scan_pending.php` (`tbox-php`) | `pending` 파일이 있을 때만 `clamscan` → `clean`/`infected`·격리·감사 `file.scan` (F-54, 5.28) |
| 매분 | `scripts/alert_push.php` (**`tbox-push`** — F-70, L-3) | alerts.log 새 줄 · 시험 알림 대기표 → 푸시 서버 셋(5.32). 사람·시스템 곧바로 · Claude 18시 요약 | 감사 `alert.push_send`·`alert.push_revoke` · 자기 사본 `/var/log/tbox-push/audit.log` · 24시간 실패면 alerts.php 가 AL-22 |
| **매일 02:00** (16-2, 2026-09-12 — 주 1회에서 당김, SECURITY 8.4-6) | `scripts/consistency_check.php` | DB ↔ 스토리지 정합성 (고아·누락·sha256) | `audit.integrity`, 불일치 → `alert.raised AL-6` |
| **(PC) 매일 05:00** (F-55, 2026-09-16) | `deploy/pc/pull_backup.ps1` — **사용자 PC 의 작업 스케줄러**가 실행 | PC 가 `ssh changkwon@서버 "sudo -n tar -C / --newer-mtime='3 days ago' -cf - var/backups/tbox-v2/{db,files,weekly,monthly} var/log/tbox-v2"` 로 암호화 산출물·`.sha256`·로그를 **끌어와(pull)** `C:\tbox-backup\` 에 풀고 최신 db·files 를 sha256 대조, 45일 지난 사본 삭제. 서버 파일·cron 변경 0, 서버에 원격 자격증명 0(root 공격자가 원격 사본까지 지우지 못하게). `files-mirror/`·평문 덤프 제외. `backup.key` 는 `-Key` 로 한 번만 별도 장소에 | PC `pull.log` 마지막 줄 `OK\|WARN\|FAIL`. 서버 감사 기록 없음(읽기 전용 tar). 3-2-1 의 「원격 1」 임시 충족(Q-4) |

셸 스크립트의 감사 기록은 `scripts/audit_cli.php <action> <result> [reason] --target … --detail JSON` 로 남긴다(문장 표에 없는 조합은 실패). 복구는 `scripts/restore.sh`(11.2). 서버 기준선 점검은 `scripts/check_baseline.sh`(읽기 전용), 강화 적용은 `deploy/hardening.sh --apply`(기본은 출력만 — 원격 세션 차단 위험이 있어 사용자가 직접 실행).

### 11.2 복구 순서 (PRD 7.8 절차의 명령 대응)

```
 1. 서비스 중단     sudo systemctl stop php8.1-fpm ; nginx 점검 페이지 전환
 2. 범위 판단       "DB만" / "파일만" / "둘 다", 목표 시점 T 결정
 3. 무결성 확인     sha256sum -c db-YYYYMMDD-HHMM.dump.gpg.sha256
 4. DB 복구         gpg --batch --passphrase-file /etc/tbox-v2/backup.key -d db-YYYYMMDD-HHMM.dump.gpg | pg_restore -d tbox_re --clean --if-exists
                    (= scripts/restore.sh --db <파일>; 리허설은 --dbname tbox_re_restore 로 별도 DB 에)
                    (시점 복구 필요 시: 베이스 백업 + WAL 로 recovery_target_time = T)
 5. 파일 복구       gpg -d files-YYYYMMDD.tar.gz.gpg | tar -C /var/lib/tbox-v2 -xz   (= scripts/restore.sh --files <파일>)
 6. 정합성 검사     php scripts/consistency_check.php --strict
 7. 스모크 테스트   로그인 → 목록 → 업로드 → 다운로드 → 권한 매트릭스(PRD 5장) 확인
 8. 재개 + 기록     systemctl start php8.1-fpm ; 감사 기록 backup.restore (restore.sh 가 남김) ; 사고 보고서
```

> 복구 리허설은 **분기 1회**, 운영 서버가 아닌 **별도 서버**에서 한다. 소요 시간을 재서 RTO 4시간 안에 드는지 확인한다.

### 11.3 서버 보안 기준선 (Hardening) — PRD S-10-A

앱 코드와 무관하게 **서버를 세운 날** 적용하고, 매월 T-17~T-19 로 재확인한다.

```
 인터넷 ─✖─┐                                  (설계 목표 — **2026-09-26 d67: 실제는 강사 프록시 경유로 인터넷에서 닿는다**. SECURITY 6.7)
             │
 사내망/VPN ──┼──▶ 443/tcp  nginx            ← 허용 (사내 대역만)
             ├──▶ 22/tcp   sshd  (키 인증만)  ← 허용 (관리망 대역만)
             └──✖ 그 외 전부 거부

 서버 ──▶ 밖으로 나가는 연결: 기본 거부
          허용: DNS(53) · NTP(123) · 패키지 미러(443) · 백업 저장소 · ClamAV 시그니처 · 원격 로그 서버
                · F-14 허용 도메인(FETCH_ALLOWED_HOSTS)의 443 — 가능하면 해당 IP 대역만
          → SSRF·데이터 유출·C2 통신 통로를 처음부터 막는다
```

| 영역 | 설정 | 확인 |
|---|---|---|
| 방화벽 | `ufw default deny incoming; ufw default deny outgoing;` 그 뒤 위 허용 규칙만 | `ufw status verbose` |
| SSH | `/etc/ssh/sshd_config.d/`**`00-tbox.conf`**: `PasswordAuthentication no`, `PermitRootLogin no`, `AllowUsers <계정>@<관리망>`(옵션), `MaxAuthTries 3` + `fail2ban` sshd jail<br>**이름 순서 규칙** — `Include /etc/ssh/sshd_config.d/*.conf` 는 글꼴 순서로 읽고 sshd 는 **같은 키워드의 첫 값이 이긴다.** 그래서 우리 드롭인은 `00-` 접두(이 서버의 `50-cloud-init.conf` = `PasswordAuthentication yes` 보다 앞)이고, 충돌하는 남의 드롭인은 `<이름>.conf.disabled-by-tbox-<날짜>` 로 이름을 바꿔 Include 에서 뺀다(삭제 X). 검증은 파일이 아니라 **실효값** `sshd -T` 문자열 대조 (SECURITY 8.4-12) | `sshd -T \| grep -iE 'passwordauth\|permitroot\|maxauth\|allowusers'` |
| 계정 | 관리자는 개인 계정 + `sudo`(그룹 `tbox-admin`). `tbox-php`, `tbox-backup`, `postgres` 는 `nologin` | `getent passwd \| grep -v nologin` |
| PostgreSQL | `listen_addresses = 'localhost'`, `pg_hba.conf` 는 `scram-sha-256` + 로컬 소켓만 | `ss -tlnp \| grep 5432` → 127.0.0.1 만 |
| 불필요 서비스 | `avahi`, `cups`, `rpcbind` 등 제거. 리스닝 포트는 443·22·5432(로컬) 만 | `ss -tlnp` |
| 파일 시스템 | `/var/lib/tbox/storage` 를 `noexec,nosuid,nodev` 옵션의 별도 마운트로 | `findmnt /var/lib/tbox/storage` |
| 시간 | `chrony` 활성, 사내 NTP | `chronyc tracking` |
| 자동 패치 | `unattended-upgrades` 보안 채널, 재시작은 일요일 02:00 점검창 | `/var/log/unattended-upgrades/` |
| 무결성 감시 | `/var/www/tbox`, `/etc/nginx`, `/etc/php`, `/etc/tbox` 에 AIDE(또는 `debsums`) 일일 검사 → 변경 시 AL-9 | AIDE 보고서 |
| 웹 방화벽 (선택) | `libnginx-mod-security` + OWASP CRS, 1개월 탐지 모드 후 차단 모드 | nginx error log |

> **왜 아웃바운드까지 막나**: 제로데이로 앱이 뚫리는 것은 막을 수 없을 수 있다. 하지만 뚫린 뒤 공격자가 데이터를 **밖으로 보내거나** 추가 도구를 **내려받는** 연결을 막으면 피해가 "침입"에서 그친다.

### 11.4 감시와 경보 — PRD S-11-A

```
 PHP Audit ──▶ audit_logs (DB) ──▶ 관리 화면 (조회)
     │
     └──▶ /var/log/tbox/audit.log ──▶ rsyslog(TLS) ──▶ [원격 로그 서버]  ← 추가 전용, 서버 침해 시에도 남음
 nginx access/error ─────────────────┘                     │
 sshd / sudo / unattended-upgrades ──┘                     ▼
                                                    경보 규칙 AL-1 ~ AL-11 ──▶ 알림 채널(메신저/메일) ──▶ 운영 담당
```

| 구성 | 도구 (예) | 메모 |
|---|---|---|
| 전송 | `rsyslog` (omfwd, TLS) 또는 `vector`/`fluent-bit` | `audit.log` 는 한 줄 JSON 이라 파싱이 쉽다 |
| 저장·검색 | 원격 서버의 Loki / OpenSearch / 단순 파일 + `logrotate` (규모에 맞게) | **앱 서버와 다른 계정·다른 호스트**에 둘 것 |
| 경보 | Loki ruler / Grafana alert / `cron` + SQL 쿼리 스크립트 | 규모가 작으면 `scripts/alerts.php` 가 5분마다 `audit_logs` 를 SQL 로 훑어 조건 충족 시 알림을 보내도 충분 |
| "로그 끊김" 감시 | 원격 서버 쪽에서 30분 무수신 시 경보 (AL-7) | 앱 서버가 침해되면 앱 서버의 경보는 믿을 수 없으므로 **밖에서** 감시 |

경보 규칙 AL-1 ~ AL-11 의 조건은 PRD 7.11 표를 그대로 쓴다 (AL-10 계정 공유 의심·AL-11 admin-only 다운로드 요약은 2026-09-10 추가).

**AL-2 집계 대상 (13단계 보정 2026-09-12 — SECURITY R-45 · R-48)**

**서버 층 경보 — AL-3 확장·AL-9 (16단계 2026-09-12, SECURITY 8.4-5)**: `alerts.php`(root, 5분)가 `audit_logs` 외에 `/var/log/auth.log`(와 `.1`)도 읽는다. ① AL-3: `sshd … Accepted (publickey|password) for <계정> from <IP>` 중 **최근 30일 안에 본 적 없는 IP** → `AL-3|계정@IP`(상태 파일에 IP 별 첫 관측 시각을 기억) ② AL-9: `sudo: <계정> : … COMMAND=<명령>` 의 명령이 `psql|pg_dump|pg_restore|dropdb|rm |find .* -delete|truncate|chattr -a` 에 걸리면 ~~`AL-9|계정`~~ → **`AL-9|계정|<명령 요약>`**(디버깅 묶음 S-3 — 요약 = 첫 낱말 basename + 가린 명령 sha256 앞 12자, `scripts/lib/alerts_lib.php` `al_sudo_command_key` · 근거 `evidence.command_key`. 계정만 키로 두면 가벼운 `sudo psql` 한 번이 울린 뒤 30분 동안 같은 계정의 `pg_dump`·`rm -rf` 가 한 줄도 남지 않았다. 지금은 **다른 명령은 각각 한 줄**, 같은 명령 되풀이만 30분 억제. 휴대폰은 `push.conf` `DIGEST_RULES=AL-9,…` 라 18:00 하루 요약(개수)이므로 줄이 늘어도 푸시는 폭주하지 않는다)(명령은 앞 200자만, 인자 속 비밀값이 있을 수 있어 `password=`·`PGPASSWORD` 조각은 지운다). 파일 위치·읽기 오프셋은 상태 파일에 두고 회전(`auth.log.1`)을 따라간다. 시험은 `--auth-log <사본 경로>` 인자로. 정합성 검사(`consistency_check.php`)는 주 1회 → **매일 02:00**(8.4-6).

**휴대폰으로 보내기 · AL-22 (F-70, 2026-09-24~25 — 5.32)**: 경보 줄은 여전히 `alerts.log`·`alert.raised` 두 곳이 원본이고, 휴대폰 알림은 **덧붙은 전달 경로**다
(`scripts/alert_push.php`, tbox-push, 매분). origin 이 곧 갈래다 — `human`·`system`·origin 없는 옛 줄은 곧바로, `claude-*` 는 18시 하루 요약(개수만),
`push.conf` 의 `DIGEST_RULES`(기본 `AL-9` — 구현 기록 5, 사용자 결정 대기)도 요약. 알림 내용은 규칙 번호·짧은 이름·출처·시각·개수뿐이다(근거 글자 0).
**AL-22** = 살아 있는 받기 **전부**의 `fail_since`(연속 실패 시작)가 24시간을 넘음 → `AL-22|push` · 또는 `push.conf` 가 있는데 보내는 배치의 상태 파일
(`STATE_DIR/alert-push-state.json` — 배치가 매 실행 다시 쓴다)이 15분 넘게 그대로(없으면 설정 파일 시각부터) → `AL-22|stale`. origin `system`(Origin::ofFixedRule).
중복 억제는 **이 규칙만 6시간**(`AL22_DEDUP_MINUTES` — 막힌 동안 같은 줄이 30분마다 쌓이지 않게). 막혔다가(상태 키 `AL-22|down`) 둘 다 풀리면 `AL-22|recovered`
**한 줄만** — 보내는 배치는 그 줄을 갈래 이름 「휴대폰 경보 전달 복구」로 보낸다(코드 표 `PUSH_VARIANT_NAMES`, 근거 글자는 옮기지 않는다).
배치가 시작조차 못 하는 오류(설정·pgpass·잡히지 않은 예외)는 받기 표에 흔적이 없어 push 로는 안 보인다 — stale 이 그것을 잡고, 원인은 배치의 오류 기록
`/var/log/tbox-push/error.log`(push.conf `ERROR_LOG`, tbox-push 0640 — cron 은 출력을 버린다)에 남는다.
판정은 이 배치(alerts.php, root)가 한다 — 보내는 계정은 alerts.log 를 읽기만 한다(구현 기록 6). 받기 표가 없거나 읽을 수 없으면 AL-22 만 건너뛴다.
auth.log 오프셋 읽기는 F-70 부터 `scripts/lib/log_tail.php` 한 벌이다(보내는 배치가 alerts.log 에 같은 함수를 쓴다 — 동작은 전과 같다).

**AL-23 (F-72 2026-09-27 — 5.35)**: 5분 창에 `auth.passkey_register allow` 1건 이상 → 즉시(`AL-23|등록한 사람`) · 또는 `auth.login deny passkey_counter` 1건 → `AL-23|그 계정`. evidence 에 종류(register·counter)·건수·계정·기기 요약·열쇠 행 번호·기기 이름(열쇠 id 원문·공개 키 0 — 감사에 애초에 없다). AL-20 과 **같은 형태** — 새 로그인 수단은 세션이 끊겨도 남는다. origin 은 F-69 판정(`Origin::ofGroup` — 사람 → 휴대폰 즉시 · `claude_`+검토 UA → 18시 요약). **AL-2 로그인 뒤 실패 목록**에 `auth.reauth deny passkey_invalid·passkey_challenge·passkey_counter` 와 `auth.passkey_register deny bad_credentials·bad_code·code_reused` 를 더했다 (`auth.login deny passkey_*` 는 `action = 'auth.login'` IP 집계에 저절로 든다).

**AL-18 (F-43 2026-09-15)**: 5분 창에 `fetch_host.change allow` 1건 이상 → 즉시(`AL-18|행위자`, evidence 에 바뀐 호스트 이름·사유). AL-13 과 **같은 형태**이고 임계값이 없는 이유도 같다 — 허용 호스트가 바뀌면 그 순간부터 서버가 나갈 수 있는 곳이 달라진다(S-05).

**디버깅 묶음 S (2026-09-26 보안 점검 중간 3·4 · 토요일 휴대폰 AL-7 알림 4번).**
① **AL-7 의 「업무시간」 = 평일(월~금) 09:00~18:00**(서버 로컬 시각 Asia/Seoul). 전에는 요일을 보지 않아 토·일 낮에도 판정했고, 사람이 없는 주말의 「30분 0건」이 경보로 되풀이됐다. 판정은 `scripts/lib/alerts_lib.php` `al_is_business_hours`(순수 함수 — `php -r` 로 시험) 한 곳, 건너뛸 때 출력은 「AL-7 건너뜀 (주말 · …)」. **한계**: 공휴일은 모른다(달력 없음 — 공휴일 낮의 0건은 여전히 울린다). 규칙 문장 `rule_text` 도 「업무시간(평일 09~18시)」.
② **AL-9 억제 키 = `AL-9|계정|<명령 요약>`**(위 서버 층 절 ②). 다른 sudo 명령은 각각 한 줄, 같은 명령 되풀이만 30분 억제. 휴대폰은 `DIGEST_RULES` 로 18:00 하루 요약이라 줄이 늘어도 푸시 폭주는 없다(5.32).
③ **root 배치가 tbox-php 소유 폴더에 쓰는 자리**(`/var/lib/tbox-v2` 0750 · `/var/log/tbox-v2` tbox-php:adm): `cli_write_private`(경보 상태·해시 체인) — 대상이 심볼릭 링크면 거부 · 임시 파일은 같은 폴더의 무작위 이름을 `fopen(…, 'x')`(O_CREAT|O_EXCL · umask 0077 → 0600 — 링크를 따라가는 `chmod` 없음) · 다 쓴 뒤 보통 파일인지 보고 rename. `cli_append_line`(alerts.log) — 링크면 거부 · 없을 때만 `x` 로 새로 만들고(0644) 있으면 **만들지 않는** `r+` 로 열어 `fstat`(연 파일)·`lstat`(경로)의 장치·inode 가 같을 때만 LOCK_EX → 끝에 쓴다. 상태 파일이 링크면 읽지 않는다. 상태 파일의 **미래 시각**(지금 + 5분 초과 — 억제 칸·`ssh_seen`)은 읽을 때 버리고 경고 한 줄(`al_state_time`) — 억제 시각을 먼 미래로 적어 그 경보를 영영 끄는 길을 닫는다. **검토 반영(M-2·M-3·L-6)**: 덧붙이기는 `Audit::appendLineNoFollow` 한 벌 — 열기 전 lstat(보통 파일·하드 링크 하나·경보 로그는 주인 = 배치 계정) · 연 뒤 fstat/lstat 대조(S_ISREG · 장치·inode). root 로 도는 배치의 **감사 파일 사본**(`Audit::toFile` → `/var/log/tbox-v2/audit.log`)도 이 길로 쓴다(`chattr +a` 라 추가 모드 · 없으면 만들지 않음). 경보 로그 쓰기가 실패하면 경고 한 줄 · `alert.raised` 는 detail `alerts_log_failed` 를 붙여 남기고 **회차는 계속**(전에는 예외로 멈춰 링크 하나로 모든 경보를 껐다). 상태 파일은 읽기 전에 보통 파일 · 주인 = 배치 계정 · 그룹/다른 사람 쓰기 없음 · 하드 링크 하나 · 연 뒤 같은 파일인지를 보고, 아니면 **빈 상태로 판정**(억제 없음)하고 경고. **남는 것**(SECURITY R-105): 폴더 주인(tbox-php)은 rename 직전 임시 파일을 바꿔치기할 수 있다(root 가 다른 곳에 쓰지는 않는다) · 감사 파일은 O_APPEND(O_CREAT 함께)라 lstat 과 열기 사이에 끝없는 링크로 바뀌면 그 끝에 빈 파일이 생길 수 있다(쓰기는 막힌다) · `verify_backup.sh` 의 셸 `>>` 는 그대로(하루 한 번) · 근본 해결은 두 폴더의 주인을 root 로 옮기는 서버 설정(이번 범위 밖).
④ **주말 AL-7 은 꺼진다(한계 — 검토 L-5)**: 금 18:00 ~ 월 09:00 동안 감사 로그가 끊겨도 이 배치는 알리지 않는다(주말용 긴 창은 두지 않기로 함). 주말 동안의 끊김은 월요일 09:00 뒤 첫 30분 창에서야 보이고, 원격 로그 서버 쪽 감시(위 표 「로그 끊김」)가 없는 지금은 그 사이를 아무도 모른다.

**디버깅 묶음 S2 (2026-09-26 — 사용자 결정 d72 ① 로그인 시도 제한 점검 · 검토 낮음).**
① **AL-2 「계정 잠금 발생」 = 잠긴 순간도.** 전에는 잠긴 계정으로 다시 로그인을 시도한 행(`auth.login deny locked`)만 잠금으로 셌다 — 정확히 5번만 틀리고 10분 쉬는
잠금 DoS(한 IP 10분 5행, 20회 기준 밑)와 로그인 뒤 창구의 코드 추측 잠금(세션이 끊겨 다음 요청이 없다)은 조용했다. 이제 `Auth::countFailure` 가 잠금이 걸린 실패마다
`auth.lock deny failed_limit` 한 행을 남기고(4.0 「잠금」) AL-2 가 그 행 하나로도 발동한다. 잠금 행은 실패가 아니므로(검토 L-3) 20회 기준의 수(`count`)·
`locked`(잠긴 계정으로 다시 로그인한 행)·`after_login` 에는 넣지 않고 근거에 `lock_events` 로 따로 적는다. rule_text 에 「잠긴 순간·잠긴 계정 로그인 시도」.
감사 조회 화면의 「어떻게」 선택지·허용 목록에도 `auth.lock`(과 빠져 있던 `auth.reauth`)이 있다(검토 L-4).
② **root·posix 없는 CLI 의 감사 파일 사본 실패 → 경고 한 줄 뒤 계속**(`Audit::toFile` — DB 행은 이미 남았다). 전에는 그 예외가 경보 배치의 회차를 끝냈다
(감사 파일 자리에 링크 하나로 그 뒤 모든 규칙이 멈춤 — 경보 로그 쓰기 실패에 M-3(a) 로 막은 길과 같은 모양). 남는 것은 SECURITY R-105 ⑥.
③ **`getmyuid()` 대체 제거** — posix 확장이 없으면 상태 파일을 믿지 않고(빈 상태 · 경고) 경보 로그에도 쓰지 않는다(`cli_append_line` 거부 → `alerts_log_failed`).
`getmyuid()` 는 스크립트 **파일의 주인**(운영 changkwon)이지 배치를 돌리는 계정이 아니어서, root 로 도는 배치가 changkwon 이 주인인 상태 파일을 믿을 수 있었다.

**AL-13 · AL-2 보강 (17단계 2026-09-12)**: AL-13 = 5분 창에 `permission.change allow` 1건 이상 → 즉시(`AL-13|행위자`). AL-2 세는 행에 `auth.reauth deny bad_credentials`(16단계 재인증 창구)를 추가. AL-9 명령 가리기에 SQL `PASSWORD '…'` 형태 추가(첫 실행이 v1 설치 명령을 그대로 재생한 관찰).

| 항목 | 내용 |
|---|---|
| 조건 | IP 단위 **10분 내 인증 실패 20회 이상**, 또는 그 창에 **계정 잠금(`auth.login deny locked`)이 한 번이라도** — 임계값·창은 그대로다 |
| 세는 행 | `auth.login deny` 전부 · `auth.mfa deny bad_code`·`code_reused` · `auth.password_change deny bad_credentials` · `auth.mfa_disable deny bad_credentials`·`bad_code`·`code_reused` · `auth.mfa_enroll deny bad_code`·`code_reused` |
| 왜 넓혔나 | 전에는 `action = 'auth.login'` 만 셌다. 같은 "비밀번호·코드 추측" 이 **로그인 뒤** 창구(`/api/me/password`·`/api/me/mfa/*`)에서도 일어나고(R-48 실측), 13단계에서 그 실패가 계정 잠금 카운터에 들어갔다 — 잠금은 걸리는데 경보만 조용한 상태를 없앤다 |
| 세지 않는 행 | `auth.mfa deny code_required`(순차 2단계 로그인의 정상 1단계. `Auth::login` 도 실패로 세지 않는다 — 4.0) · `already_enabled` · `weak_password` · `same_password` |
| 근거 값 | `evidence.count`·`locked`·`after_login`(로그인 창구가 아닌 곳에서 난 실패 수 — 세션 탈취 뒤의 추측과 바깥의 무차별 대입을 가른다)·`actions`·`window`·`last_at` |

예: AL-1 을 SQL 로 쓰면

```sql
SELECT actor_id, count(*) FROM audit_logs
 WHERE result = 'deny' AND action IN ('file.download','file.read')
   AND happened_at > now() - interval '10 minutes'
 GROUP BY actor_id HAVING count(*) >= 5;
```

**경보의 출처 `origin` — 「누가 일으켰나」 (F-69, 2026-09-24 — 지시서 `docs/f69-alert-origin-directive.md` A·B, 결정 K-1~K-5, SECURITY R-100)**

`alerts.log` 889줄(2026-09-24 실측) 가운데 약 80% 가 검토 계정(`qa_`)·AI 세션의 `sudo psql` 에서 나와 진짜 경보가 묻혔다. 사용자 결정은 「지우지 않고 Claude 가 한 것임을 이름에 표기」다. 그래서 **경보를 지우거나 끄거나 문턱을 바꾸지 않는다** — 발동 조건·중복 억제(키 `규칙|대상` 그대로)는 전과 같고, 발동한 줄마다 `origin` 을 붙일 뿐이다. 판정은 `src/Origin.php` **한 곳**(순수 함수 — DB·파일·네트워크에 닿지 않아 `php -r` 로 단위 시험된다)이고, 배치(`alerts.php`·`consistency_check.php`·`audit_chain.php`)는 규칙마다 **재료**(대상 계정·UA 표식 집계·sudo 명령)만 SELECT·파싱에 더해 넘긴다.

| 값 | 뜻 | 화면 배지(5.14) | F-70(나중 — 이번엔 보내지 않는다) |
|---|---|---|---|
| `human` | 사람 — 또는 **가려낼 수 없는 것** | 없음(한쪽만 맞으면 「확인 필요」) | 즉시 |
| `system` | 행위자가 없는 배치 판정(정합성·백업·감사 끊김) — K-2 로 더함 | 「시스템」 | 즉시 |
| `claude-review` | 검토 계정 **그리고** 검토 표식으로 앱에서 한 행동 | 「Claude」 | 하루 요약 |
| `claude-session` | AI 세션이 서버에서 임시·시험 DB **만** 다룬 sudo 명령(AL-9) | 「Claude」 | 하루 요약 |

규칙별 판정 (구현한 대로 — 지시서 A-1 표 + 구현 보고 결정)

| 규칙 | 재료 | origin |
|---|---|---|
| AL-1 · AL-10 · AL-12 · AL-13 · AL-18 · AL-20 (묶음 = 행위자) | 묶음의 `actor_name` + 집계 SQL `bool_and(COALESCE(user_agent, '') LIKE :review_ua)`. `:review_ua` 는 `Origin::reviewMarkLikePattern()`(= `tbox-review/%`, LIKE 특수 글자 이스케이프)을 **바인딩**한다 — 접두를 SQL 에 베끼지 않는다. UA 가 NULL 인 행은 표식 없음으로 센다(`bool_and` 는 NULL 을 건너뛰므로 `COALESCE`) | `Origin::ofGroup()` → `classify()`: 계정이 `qa_`·`claude_` **그리고** 모든 행이 표식 → `claude-review` · 한쪽만 → `human` + `origin_hint: partial` · 둘 다 아님 → `human` |
| AL-2 (묶음 = 출발 IP) | 실패 행들이 겨눈 계정 **전부**(`actor_name`, 없으면 `target_name` — `string_agg(DISTINCT …, chr(10))`) + 모든 행의 UA 표식 + **출발지가 이 서버 자신(`127.0.0.1`·`::1`)인가** | 전부 검토 계정 그리고 전부 표식 **그리고 루프백**일 때만 `claude-review`. 실패 로그인의 대상 이름과 UA 는 둘 다 바깥 공격자가 정하므로, `claude_admin01` 을 겨누며 표식을 흉내 내는 바깥 무차별 대입은 `human` + partial 이다(검토 시험은 늘 127.0.0.1 로 들어온다). **이 루프백 조건은 구현 보고 결정 D-1 — 사용자 확인 대기** |
| AL-5 (감사 행 하나) | 그 행의 `actor_name`·`user_agent` | `Origin::ofAppAction()` — 같은 「그리고」 |
| AL-19 (감염 파일 하나) | 그 파일을 **올린** 감사 행(`file.upload`·`file.upload_exec`·`file.fetch` allow, 같은 `target_id` 의 첫 행 — `LEFT JOIN LATERAL`)의 actor·UA. 검사 행은 배치가 남겨 행위자가 없기 때문이다 | 같은 「그리고」. 올린 행을 못 찾으면(옛 파일) `human`. f54 의 EICAR 시험(`claude_employee01` + 표식) → `claude-review` |
| AL-21 (실행 파일 압축본 하나) | `file.upload_exec allow` 행들의 actor·UA | 같은 「그리고」 |
| AL-6 · AL-7 · AL-8 | 행위자 없음 | `system` (`Origin::ofFixedRule()`) |
| AL-3 (새 출처 SSH) | OS 계정@IP | **늘 `human`** — SSH 로 들어오는 것은 사람이다(AI 세션은 이 VM 안에서 돈다). 서버 침입의 첫 신호가 하루 요약으로 밀리지 않게 |
| AL-9 (위험한 sudo) | `al_scrub_command()` 로 가린 명령(앞 200자) — 사람과 AI 세션이 같은 OS 계정(`changkwon`)을 쓰므로 계정이 아니라 **명령이 겨눈 DB** 로만 가른다 | `Origin::ofSudoCommand()` — 겨눈 DB 가 **전부** 임시·시험 DB 이름일 때만 `claude-session`. 이름 규칙(코드 상수 한 줄): `_test`·`_test2`·`_scratch` 로 끝나거나 `qcheck_` 로 시작하거나 정확히 `comm_check`(소문자·숫자·밑줄, 63바이트 이하). 조금이라도 모호하면 `human`: 첫 낱말(basename)이 `psql`·`pg_dump`·`pg_restore`·`dropdb`·`createdb` 가 아님(`rm`·`find -delete`·`truncate`·`chattr`·`sh -c`·`env` 감싸개) · psql 에 `-d`/`--dbname` 이 없음 · psql 에 역슬래시 메타 명령(`\c`·`\!`·`\copy`)이나 DB 밖에 닿는 낱말(`DATABASE`·`ROLE`·`USER`·`COPY`·`DO`·`EXECUTE`·`dblink`·`pg_read_*` …) · `pg_restore -C` · `createdb -T <운영>` · 겨눈 DB 값이 이름 모양이 아님(`postgresql://…` 접속 문자열 등) · 200자에서 잘림. **`tbox_re`(운영)를 겨누면 읽기 쿼리라도 `human`**(K-3 — 판정을 넓히지 않는다). `psql -f 파일`·표준입력의 **내용**은 auth.log 에 없어 보지 못한다 — 그래서 psql 에 `-f`/`--file` 이 있거나 `-c`/`--command` 가 **없으면**(표준입력 스크립트) `human`(독립 검토 M-1) · psql `-o`/`-L`(postgres 권한으로 파일 쓰기) `human`(L-2) · 첫 낱말이 경로면 `/usr/bin/`·`/bin/`·`/usr/lib/postgresql/<판>/bin/` 일 때만 도구로 봄(`/tmp/psql` → `human`, L-1). 분류일 뿐 차단이 아니다 |
| 옛 규칙 번호(AL-14·16·17 …) | — | 판정하지 않는다(이제 발동하지 않는다) |

**줄 모양.** `{raised_at, rule, evidence, dispatch, request_id, origin, title}` — 앞 다섯 키는 그대로라 옛 파서가 그대로 읽는다. `title` 은 `claude-*` 면 `[Claude] ` + `evidence.rule_text`, 아니면 `rule_text`(없으면 규칙 번호) — `Origin::title()` 한 곳에서 만든다. `origin_hint`(`partial`)는 `evidence` 안에 둔다. 감사 `alert.raised` detail 에도 같은 `origin` 이 들어간다(`{rule, evidence, origin}`, 7.5). `cli_raise_alert(rule, evidence, origin, hint)` 는 값을 **고르지 않고** 모양만 본다 — 넷 밖의 값이나 `partial` 밖의 hint 는 예외다(조용히 `human` 으로 바꾸지 않는다). `verify_backup.sh` 가 직접 쓰는 AL-8 줄도 같은 키(`request_id` 만 없다) — `origin:"system"`, `title` = `rule_text`(「백업 검증 2회 연속 실패 — 복구 능력 상실」), 경보 로그 경로는 PHP 와 같은 규칙(저장소 `.env` 의 `AUDIT_LOG_FILE` 디렉터리 + `alerts.log`, 못 읽으면 `/var/log/tbox-v2/alerts.log`)이고, 시험용 인자 `--backup-root <절대 경로 디렉터리>` 하나가 생겼다(연속 실패 횟수 파일도 그 안 — 시험이 운영 횟수를 밀지 않게). `alerts.php --dry-run` 출력은 옛 줄 끝에 ` origin=…`(한쪽만이면 ` hint=partial`)만 붙는다 — 발동 목록이 옛 코드와 같은지 f69.sh T10 이 대조한다. **2026-09-24 전의 옛 줄(889줄)은 고치지 않는다**(append-only 증거) — 화면은 그 줄을 「출처 표기 전」으로 보이고, 옛 줄 분류 보고(`--classify-history`)는 K-4 로 넣지 않았다.

★ `origin` 은 **보여 주기와 분류(F-70 즉시/하루 요약)에만** 쓴다. 경보를 남길지·감사를 남길지·중복 억제는 origin 과 무관하고(어떤 값이든 두 곳에 똑같이 남는다), 권한·차단에도 쓰지 않는다 — Authz·Auth·Audit 은 `Origin.php` 를 읽지 않는다. 예외 하나는 아이디 규칙 한 벌 `src/handlers/_username_common.php`(계정 만들기 `users_create.php` · 아이디 바꾸기 `users_update.php` 가 부른다 — d74)가 `isReviewAccount()` **이름 판정만** 읽어 사람 계정 이름에 검토 접두를 못 쓰게 막고(대기열 2b, 7.4 `user.create deny reserved_prefix`·`user.update deny reserved_prefix`) 검토 계정의 아이디를 바꾸지 못하게 하는 것(`user.update deny review_account`) — 판정이 아니라 「이름 예약」이고, 접두 목록을 두 벌로 두지 않으려고 같은 함수를 쓴다.

**알려진 틈 — 이번에 고치지 않음(별도 과제).** AL-9 의 **발동** 정규식 `(^|/|\s)(psql|pg_dump|pg_restore|dropdb|truncate|chattr)\b|(^|\s)rm\s|find\b.*-delete` 는 auth.log 의 `COMMAND=` 가 늘 절대 경로라서 `COMMAND=/usr/bin/rm -rf …` 를 잡지 못한다(`rm` 앞 글자가 `/`). F-69 이전(16단계)부터의 모양이고 F-69 는 발동 조건을 바꾸지 않는다(경보 규칙 변경 금지). 그래서 f69.sh T3 은 「DB 이름 없는 명령 → `human`」 을 `find -delete` 로 확인하고, `rm` 줄은 발동했는지만 적는다.

**AL-6 — 격리된 감염 파일을 분실로 세지 않는다 (F-69 C, `consistency_check.php`).** 전에는 [1] 이 `files` 의 `stored_name`·`alive`·`purged` 만 읽고 [2] 가 저장소만 훑어서, `scan_pending.php` 가 격리 폴더로 **옮긴**(삭제 아님, F-54 ②) 살아 있는 `infected` 행을 「저장소에 없음(`missing`)」으로 셌다 — 운영 3건, 09-17·09-22·09-23 의 매일 02:00 오탐. 이제 격리 폴더 경로는 `cli_quarantine_dir()`(`scripts/lib/cli_bootstrap.php`) **한 곳**이다: `dirname(Storage::baseDir()) . '/quarantine'` — 운영에서는 옛 상수와 같은 `/var/lib/tbox-v2/quarantine`, 임시 인스턴스(`STORAGE_DIR` = 임시 폴더)에서는 그 옆이라 운영 밖에서 시험된다. `scan_pending.php`(옮기기 — 상수 `QUARANTINE_DIR` 을 없앴다)와 `consistency_check.php`(대조 — 읽기만)가 같은 함수를 쓴다. [1] SELECT 에 `scan_status` 를 더하고, [2-2] 격리 폴더의 파일 이름을 한 층만 훑는다(폴더가 없거나 못 읽으면 빈 것으로 본다 → 살아 있는 감염 행이 전부 `quarantine_missing` 이 되어 경보가 난다 — 조용히 넘어가지 않는다, detail `quarantine_readable:false`).

| 행 | 격리 폴더 | 저장소 | 결과 |
|---|---|---|---|
| 살아 있음 + `infected` | 있음 | 없음 | **정상** — `quarantined` 로 센다. sha256 을 대조한다(바이트를 읽기만, 열거나 실행하지 않는다) — 다르면 불일치 `quarantine_hash_mismatch`(격리 중 변조), 못 읽으면 `quarantine_unreadable` |
| 살아 있음 + `infected` | (무관) | 있음 | 불일치 `infected_in_storage` — 옮기기 실패, 감염 바이트가 서비스 폴더에 남았다 |
| 살아 있음 + `infected` | 없음 | 없음 | 불일치 `quarantine_missing` |
| 휴지통 + `infected` | 없음 | — | **보고만** `trash_quarantine_missing` 개수(휴지통 규칙과 같이) |
| `pending` · `clean` | — | — | 옛 규칙 그대로 |
| (행 없음) | 있음 | — | **보고만** `quarantine_orphan` 개수 — 격리 폴더는 증거 보관소라 지우지 않는다. [4] 고아 판정은 전처럼 저장소만 |

AL-6 줄에는 `rule_text`(「정합성 검사에서 누락·sha256 불일치·고아 파일 발견」)와 origin `system` 이 붙는다. `audit_chain.php` 의 AL-6 두 곳(검산 실패 · 이미 닫힌 날 해시가 달라짐)도 각자 `rule_text` + `system`. 감사 `audit.integrity` detail 에 `quarantined`·`quarantine_orphan`·`trash_quarantine_missing`·`quarantine_readable` 이 더해진다(7.5). 운영 확인은 쓰기 없이 한다 — 운영 DB 를 **읽어** 살아 있는 `infected` 행이 전부 격리 폴더에 있는지 SQL + `ls` 로 재현하고(f69.sh T8), 실제 결과는 적용 **다음 날 02:00** 배치의 `audit.integrity` 행으로 본다. `tests/f54.sh` 는 EICAR 격리 **뒤 운영에서 `consistency_check.php` 를 한 번** 돌려 그 파일이 `missing`·격리 불일치로 세어지지 않는지 본다 — 이 한 번은 운영 감사에 `audit.integrity` **1행**을 더하고, 그때 불일치가 나오면 남는 AL-6(`system`)은 시험 소음이 아니라 운영의 **진짜** 불일치다.

### 11.5 운영 캘린더 — PRD S-11-B

| 주기 | 자동/수동 | 작업 | 산출물 |
|---|---|---|---|
| 매일 | 자동 | 보안 패치 · 백업 + 검증(11.1) · ClamAV 시그니처 · AIDE · 경보 | 알림 채널 |
| 매주 (월) | 수동 30분 | 감사 로그 리뷰, 정합성 검사 결과, 실패 로그인 추이, 디스크 사용량 | 주간 점검표 |
| 매월 (첫 주) | 수동 2시간 | 전체 패치 + 재시작, 계정·권한 대사(퇴사자·Guest 만료), `security_lint.sh` + T-1~T-23 | `SECURITY.md` 7장 한 줄 |
| 분기 | 수동 반나절 | 복구 리허설(11.2), `SECURITY.md` 전면 재검토, EOL 잔여 확인, 의존성 목록 갱신 | `SECURITY.md` 개정 |
| 연 1회 | 외부 포함 | 모의해킹, 침해 대응 훈련(11.6), 백업 키·SSH 키·DB 비밀번호 회전 | 보고서 |

### 11.6 침해 대응 절차 (Incident Response) — PRD S-10-E

사고 당일에 절차를 만들 수는 없다. 아래를 인쇄해 두고 연 1회 연습한다.

| 단계 | 시한 | 할 일 | 하지 말 것 |
|---|---|---|---|
| 0. 인지 | — | 경보(AL-x)·사용자 신고·외부 통보. 사고 담당자 1인 지정, 시각 기록 시작 | 혼자 판단해 무시 |
| 1. 격리 | 1시간 내 | 사내망 스위치/방화벽에서 서버 격리(전원은 끄지 않음), 의심 계정 비활성화·세션 무효화 | **서버 재부팅·삭제** (증거 소멸) |
| 2. 증거 보전 | 격리 직후 | 디스크 스냅샷, 메모리 덤프(가능하면), 원격 로그 서버 사본 잠금, 타임라인 작성 | 원본에서 직접 조사 |
| 3. 범위 파악 | 24시간 내 | 감사 로그로 **어떤 파일이 누구에게 나갔나** 확정 (다운로드 로그가 여기서 쓰인다), 침입 경로 추정 | 추정을 확정처럼 보고 |
| 4. 근절·복구 | RTO 4시간 (격리 후) | 깨끗한 서버 재구축 + 11.2 절차로 백업 복구(침입 **이전** 시점), 비밀 전체 교체 | 침해된 서버를 "치료"해서 재사용 |
| 5. 통보 | 법·규정 시한 | 개인정보(급여 등) 유출 시 개인정보보호법상 통지·신고 기한(인지 후 72시간) 준수, 영향 사용자 안내 | 축소 보고 |
| 6. 사후 | 2주 내 | 원인·대응 보고서, `SECURITY.md` 에 R-xx 추가, 재발 방지 T-xx 추가, 훈련 시나리오 갱신 | 문서 없이 종결 |

연락망(`/etc/tbox/CONTACTS.md`, 저장소 밖): 사고 담당 · 개발 · 인프라 · 정보보호 책임자 · 법무.

---

## 12. 기존 t-box와 무엇이 달라졌나 (대응표)

| 기존 t-box (뚫린 버전) | 새 t-box | 관련 절 |
|---|---|---|
| `switch_role.php` 로 역할 자유 전환 | 삭제. 로그인 + 세션, 역할은 DB에서만 | 4장 |
| `api/files.php` GET 이 전체 파일 반환, JS 에서 필터 | `Authz::visibleFilesWhere` 가 SQL WHERE 생성 | 5.1 |
| DELETE 에 권한 검사 없음 | `canRead` → `canDelete` 2단계 + CSRF | 5.4 |
| `download.php` 권한 검사 없음 | `canRead` 통과 전엔 스토리지에 닿지 않음, 실패는 404 | 5.3 |
| `search.php` SQL 문자열 결합 | 값 바인딩 + 정렬 허용 목록 | 5.1 |
| `tr.innerHTML = ...` | `textContent` + 서버 이스케이프 + CSP | 4장 [7], 8장 |
| `fetch_url.php` (SSRF): `file_get_contents($url)` 무검증 | `src/Fetch.php` 한 곳: https·443 만, 화이트리스트, IP 검증 + 고정 접속, 리다이렉트 금지, `Upload::validate`, 응답 비노출 + `allow_url_fopen=Off` | 5.6, 9장 |
| 업로드 무검증, `uploads/` 가 웹 루트 안, `chmod 777` | 화이트리스트·MIME 검사, `/var/lib/tbox/storage` 0750 | 5.2, 6.3 |
| `audit_logs` 테이블만 있고 미기록 | 모든 민감 행동 DB+파일 이중 기록, Append-only | 7장 |
| 비밀번호 평문 | Argon2id 해시 | 10장 |
| 백업 없음 | 일일 DB/파일 백업 + WAL + 분기 리허설 | 11장 |
| 서버 설정 언급 없음 (`chmod 777`, 역할 전환 URL) | 방화벽 인·아웃바운드 기본 거부, SSH 키 인증, 서비스 계정 nologin | 11.3 |
| 로그를 보는 사람 없음 | 원격 로그 서버 + 경보 AL-1~AL-11 + 운영 캘린더 | 11.4, 11.5 |
| 사고 나면? (계획 없음) | 침해 대응 6단계 절차 | 11.6 |

---

## 13. 초보 개발자용 체크리스트 — 새 기능을 추가할 때

| ☐ | 확인 질문 |
|---|---|
| ☐ | 핸들러 첫 줄에서 `Auth::requireLogin()` 을 부르나? |
| ☐ | 권한 판단을 `Authz` 함수로 하나? (`if ($role == 'Admin')` 을 직접 쓰지 않았나?) |
| ☐ | 목록형 응답이면 권한 조건이 **SQL WHERE** 에 들어가 있나? |
| ☐ | SQL 에 `$변수` 가 문자열로 붙어 있는 곳이 있나? → 있으면 반려 |
| ☐ | `ORDER BY`·컬럼명 같이 바인딩 불가한 곳은 허용 목록으로 처리했나? |
| ☐ | HTML 출력에 `htmlspecialchars` 를 빠뜨린 변수가 있나? JS 에 `innerHTML` 이 있나? |
| ☐ | 파일 경로를 만들 때 사용자 입력이 한 글자라도 들어가나? → 들어가면 반려 |
| ☐ | 상태를 바꾸는 요청(POST/PATCH/DELETE)에 CSRF 검사가 있나? |
| ☐ | 성공·거부 모두 `Audit` 에 기록하나? |
| ☐ | `src/Fetch.php` 밖에 외부 요청 코드(`curl_*`, 소켓)가 생겼나? → 반려. Fetch.php 를 고쳤나? → S-05 13개 조건 재확인 + T-6 |
| ☐ | `CURLOPT_FOLLOWLOCATION` 이 `true` 로 바뀌었나? 리다이렉트 단계에서 `check()` 를 건너뛰나? → 반려 (F-43, 린트 규칙 (g)) |
| ☐ | 오류 메시지에 DB 오류·경로·스택 트레이스가 섞여 나가나? |
| ☐ | 새 패키지·라이브러리·외부 도구를 추가했나? → `deploy/DEPENDENCIES.md` 갱신 + EOL 확인 (S-10-C) |
| ☐ | 새 기능이 만드는 이벤트가 경보 규칙(AL-x)에 필요하진 않나? (S-11-A) |
