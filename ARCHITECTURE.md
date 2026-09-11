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
 [사용자 브라우저]                          사내망 / VPN 안
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
   ├─ [0] bootstrap.php     설정 로드, DB 연결, 보안 헤더(CSP 등) 출력
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
| 세션 쿠키 | `tbox_session=<64자 hex>`; `HttpOnly; Secure; SameSite=Lax; Path=/`; **Max-Age 없음**(브라우저 세션 쿠키). 유효성은 언제나 DB 가 판단(유휴 30분·절대 8시간, `.env`) | 쿠키 만료를 늘려도 서버가 거부 |
| 세션 재발급 | 로그인 성공 시 기존 쿠키의 세션 행 삭제 → `random_bytes(32)` 새 토큰 → DB 에는 SHA-256 만 | 세션 고정 공격 차단 (S-09-2) |
| 만료 정리 | 로그인 때 `DELETE FROM sessions WHERE expires_at < now() OR last_seen_at < now() - 유휴` | 배치 없이 자연 정리 |
| 잠금 | `users.failed_count` 5회 → `locked_until = now()+10분`, 그동안 423 `{"error":"로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요"}`. 성공 시 0 으로 | F-01, S-09-4 |
| 실패 응답 | 아이디 없음·비밀번호 틀림·비활성 계정 모두 401 동일 문구. 아이디 없음일 때도 더미 해시로 `password_verify` 실행 | 계정 존재 여부·응답 시간 차이 최소화 (PRD 10장) |
| IP 속도 제한 | nginx `limit_req_zone $binary_remote_addr zone=tbox_login:1m rate=30r/m; burst=10 nodelay` → `POST /api/login` 만, 초과 429 | 계정 잠금(R-06 DoS)과 무차별 대입을 함께 완화. 30r/m 은 "6회 시도 → 잠금" 실습이 429 에 먼저 걸리지 않게 잡은 값 (S-09-8) |
| CSRF 토큰 | `hash_hmac('sha256', 'csrf:'.세션해시, APP_SECRET)` — **저장하지 않고 세션마다 계산**. 클라이언트는 `GET /api/me` 로 받아 `X-CSRF-Token` 헤더로 보냄. `Http::requireCsrf` 가 `hash_equals` 비교, 불일치·누락 403 | 스키마 변경 없이 세션에 결속. `APP_SECRET` 은 `.env`(install.sh 가 생성) |
| 로그인 CSRF | `/api/login` 은 `Content-Type: application/json` 만 받음(폼 인코딩 거부) + SameSite=Lax | 크로스사이트 폼 전송 불가 |
| 미로그인 처리 | `/api/*` → 401 JSON + `Audit::deny(auth.required)`. 그 외 화면 경로 → **302 `/login`** (감사 로그 없음 — 세션 만료 후 첫 방문은 공격이 아님) | API 는 명확한 거부, 화면은 사용성 |
| 시드 계정 | `scripts/seed_users.php`(CLI, install.sh 가 users 가 비었을 때 실행): admin01(Admin) · manager01(Manager, 개발팀) · employee01(Employee, 개발팀) · employee02(Employee, 영업팀) · guest01(Guest) — 사용자의 자기 공격·시연용. **검토 계정** `qa_admin01` · `qa_manager01` · `qa_employee01` · `qa_employee02` · `qa_guest01` 을 같은 역할·팀으로 함께 만든다(2026-09-10, PRD S-07 "검토 요청 표식") — Fable·Opus 의 curl 검토는 이 계정만 쓴다. 감사 로그에서 `actor_name LIKE 'qa\_%'` 이면 검토 행동, 아니면 사용자 행동. 운영 배포는 `--no-qa` 로 검토 계정을 만들지 않는다. 비밀번호는 **무작위 16자**로 생성해 `/etc/tbox-v2/seed-accounts.txt`(0600 root) 에만 기록. 저장소·README 에 비밀번호를 적지 않는다 | 기본 비밀번호 커밋 금지 (S-09-6) |
| 비밀번호 해시 | `password_hash(…, PASSWORD_ARGON2ID)` 기본 파라미터 | S-09-4 |
| **2단계 인증 로그인 흐름 (10단계, 사용자 결정 2026-09-11)** | **순차 2단계**: ① 아이디·비밀번호만 보낸다 → 비밀번호가 맞고 그 계정에 TOTP 가 켜져 있으면 서버가 `401 {"error":"2단계 인증 코드가 필요합니다","mfa_required":true}` 로 답한다(세션 발급 없음, **실패 횟수에 세지 않음**, 감사 `auth.mfa deny code_required`) → ② 화면이 그때 코드 칸을 펼치고 아이디·비밀번호·코드를 다시 보낸다 → 코드가 틀리면 `401` 동일 문구 + `auth.mfa deny bad_code` + **실패 횟수 증가**(5회 잠금), 맞으면 세션 발급. 켜지 않은 계정은 ①에서 바로 로그인된다. 로그인 화면에는 코드 칸이 **처음엔 없다** | 비밀번호를 모르는 사람은 계정의 2단계 인증 여부를 알 수 없다(계정 정보 노출 방지, R-32 와 같은 원칙). 비밀번호가 맞은 사람에게만 다음 단계를 알려 준다 — 코드 칸을 모든 사용자에게 항상 보여 헷갈리게 하지 않는다(관문 ③ 피드백 ⑥) |
| **최초 로그인 강제 절차 (11단계, 사용자 결정 2026-09-11 · PRD F-16)** | `users.must_change_password`·`users.mfa_setup_required`(BOOLEAN NOT NULL DEFAULT false)·`users.password_changed_at`. **생성**: Admin 은 비밀번호를 보내지 않고 서버가 임시 비밀번호(16자, 헷갈리는 글자 `0O1lI` 제외, `random_int`)를 만들어 201 에 `temp_password` 로 한 번만 돌려준다 + 두 플래그 true. **로그인**: 기존 순차 2단계 그대로(TOTP 켜진 계정은 코드 필요) → 세션 발급 → 200 `{ok:true, next:"password_change"|"mfa_setup"|null}`. **관문(`public/index.php`, 인증 뒤·핸들러 앞)**: `must_change_password` 이면 허용 목록(`GET /`, `GET /api/me`, `POST /api/logout`, `POST /api/me/password`) 밖 요청은 `403 {"error":"비밀번호를 먼저 변경해야 합니다","next":"password_change"}` + `auth.gate deny password_change_required`(route 패턴); 아니고 `mfa_setup_required` 이면 허용 목록 + `POST /api/me/mfa/enroll`·`confirm` 밖은 `403 {…,"next":"mfa_setup"}` + `auth.gate deny mfa_setup_required`. **해제**: 비밀번호 변경 성공 → `must_change_password=false`, `password_changed_at=now()`(현재와 같은 값은 400 `same_password`); TOTP 확정 성공 → `mfa_setup_required=false`; TOTP 해제 → `mfa_setup_required=true`(필수 정책). **Admin 재발급/초기화**: `POST /api/users/{uid}/password/reset`(새 임시 비밀번호 한 번만 응답, `must_change_password=true`, 세션 전부 종료), `POST /api/users/{uid}/mfa/reset`(`mfa_secret=NULL`, `mfa_enabled=false`, `mfa_setup_required=true`, 세션 전부 종료) — 둘 다 사유 필수·자기 자신 `self_change` 거부. **이행**: 마이그레이션이 기존 활성 계정 전부에 `must_change_password=true`, `mfa_setup_required = NOT mfa_enabled` 를 넣는다 — 패치 뒤 첫 로그인이 강제 절차가 된다(행 삭제·재생성 없음) | 임시 비밀번호를 만든 Admin 이 그 값을 계속 아는 상태를 **첫 로그인에서 끝낸다**(R-33·R-39). "2단계 인증은 선택" 이던 10단계 결정을 사용자 결정으로 **필수**로 바꾼다 — 서버 관문이 막으므로 화면을 우회해도 절차를 건너뛸 수 없다. 관문을 라우터 한 곳에 두는 이유는 핸들러마다 검사하다 하나를 빠뜨리는 실수를 막기 위해서다(4단계 서버 인가와 같은 원칙) |

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
                                       [4] scan_status = 'pending'/'infected' → 403 (검사 중/격리, 선택)
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
| [5-raw] | `image`·`pdf` 만 파일을 `readfile` 로 흘린다. 헤더: `Content-Type: <DB mime>` · `Content-Disposition: inline; filename*=…` · **`Content-Security-Policy: sandbox; default-src 'none'`** · `X-Content-Type-Options: nosniff` · `Cache-Control: no-store` · `Referrer-Policy: no-referrer`. 다른 갈래는 400 `preview_not_available` | `sandbox` 가 붙은 응답은 브라우저가 **불투명 출처(opaque origin)** 로 다룬다 — PDF 안의 스크립트·폼이 t-box 출처·쿠키·DOM 에 닿을 수 없다. `nosniff` 는 형식 재해석을 막는다 |
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
| `team` 의 `can_read=true` 는 "같은 팀일 때" 라는 뜻 | 팀 일치 여부는 `Authz::canRead` 가 `users.team_id` 와 비교 |

> **`teams` 표 (12-3, 2026-09-12)** — `id, name(UNIQUE, 1~50자), is_active, created_at, updated_at`. 팀은 지우지 않고 `is_active=false` 로 내린다(5.9). `permissions` 는 12-3 뒤에도 **앱 계정 SELECT 만** — 조직·권한 화면의 매트릭스는 읽기 전용이다(PRD Q-12).

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

흐름: 로그인 성공 → `random_bytes(32)` → 쿠키에 hex 토큰(HttpOnly·Secure·SameSite=Lax) → DB 에는 `hash('sha256', 토큰)`. 요청마다 해시로 조회 → `expires_at` 확인 → `last_seen_at` 갱신. 로그아웃은 행 삭제.
DB 가 유출돼도 해시만 있으므로 세션을 위조할 수 없다.

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
| 지워졌는지 알 수 있게 | 일별 마지막 줄 sha256 을 다음 날 첫 줄에 기록(해시 체인) | 9단계 `scripts/audit_chain.php` | 체인 검증 스크립트 |

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
| `file.restore|allow` | `{actor} 이(가) 휴지통의 {level} 등급 파일 「{target}」 을(를) 복구했습니다` | 10단계 files_restore (Admin) |
| `file.restore|deny|not_found_in_trash` | `{actor} 이(가) 휴지통에 없는 파일을 복구하려 해서 거부했습니다` | 10단계 files_restore |
| `file.purge|allow` | `휴지통에 30일 넘게 있던 {level} 등급 파일 「{target}」 을(를) 배치가 영구 삭제했습니다` | 9단계 scripts/purge_trash.php (actor 없음 = 시스템) |
| `file.purge|error|purge_failed` | `휴지통 파일 「{target}」 영구 삭제 중 오류가 나서 건너뛰었습니다 (점검 필요)` | 9단계 purge_trash |
| `backup.run|allow` | `백업이 완료되었습니다 (대상·산출물·검증값은 상세 기록)` | 9단계 scripts/backup_*.sh → audit_cli.php (시스템) |
| `backup.run|error|backup_failed` | `백업이 실패했습니다 (사유는 상세 기록·app.log) — 2회 연속이면 경보 AL-8` | 9단계 |
| `backup.restore|allow` | `{actor} 이(가) 백업에서 복구를 실행했습니다 (대상 시점·범위는 상세 기록)` | 9단계 scripts/restore.sh (운영자, actor = 실행 계정명) |
| `audit.integrity|allow` | `정합성·무결성 점검을 마쳤습니다 (검사 건수·불일치 0)` | 9단계 consistency_check.php · audit_chain.php |
| `audit.integrity|error|mismatch` | `정합성·무결성 점검에서 불일치가 발견되었습니다 (목록은 상세 기록) — 경보 AL-6` | 9단계 |
| `alert.raised|allow` | `경보 {target} 조건이 충족되어 알림을 보냈습니다 (근거는 상세 기록)` | 9단계 scripts/alerts.php (target = AL-1 등 규칙 번호) |
| `user.create|allow` | `{actor} 이(가) 계정 「{target}」 을(를) 만들었습니다 (역할·팀·사유는 상세 기록)` | 10단계 users (Admin) |
| `user.create|deny|bad_request` | `{actor} 이(가) 계정 생성 요청을 잘못된 형식(아이디·비밀번호·역할·팀·사유)으로 보내 거부했습니다` | 10단계 |
| `user.create|deny|already_exists` | `{actor} 이(가) 이미 있는 아이디 「{target}」 로 계정을 만들려 해서 거부했습니다` | 10단계 |
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
| `user.expired|deny|session_expired` | `{actor} 의 Guest 계정이 만료되어 살아 있던 세션을 끊었습니다` | 10단계 재지시 — `Auth::currentUser()` 가 만료된 Guest 의 세션을 즉시 파기 (R-12) |
| `auth.mfa_enroll|deny|bad_code` | `{actor} 이(가) 2단계 인증을 켜려 했지만 확인 코드가 틀렸습니다` | 10단계 (검토 보완 B) |
| `auth.gate|deny|password_change_required` | `{target} 이(가) 비밀번호를 아직 바꾸지 않은 상태로 다른 기능을 요청해서 거부했습니다` | 11단계 관문 (target = 본인, route 패턴은 상세) |
| `auth.gate|deny|mfa_setup_required` | `{target} 이(가) 2단계 인증을 아직 설정하지 않은 상태로 다른 기능을 요청해서 거부했습니다` | 11단계 관문 |
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
| `file.update|allow` | `{actor} 이(가) 파일 「{target}」 의 설명을 수정했습니다` | 5단계 files_update (설명만 바뀜) |
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
| `http_status` | 왜 | 외부 응답 상태 코드 (거부 사유 `redirect`·`fetch_failed` 의 근거). 사용자에게는 보이지 않는다 (S-05 ⑩) | 핸들러 | `file.fetch` deny |
| `upload_reason` | 왜 | `invalid_file` 거부의 세부 사유(`ext_not_allowed`·`mime_mismatch`·`too_large`·`quota_exceeded`…) — Upload 검증 코드 그대로 | 핸들러 | `file.fetch` deny `invalid_file` (7단계 검토에서 등록, 8단계 이후 반영) |
| `filters` | 어떻게 | 감사 로그 조회에 적용한 필터(키·값 객체). 값은 500자 절단 | 핸들러 | `audit.view` (8단계) |
| `artifact` · `checksum` · `bytes` | 무엇을 | 백업 산출물 파일명·sha256·크기 | 배치 | `backup.run`·`backup.restore` (9단계) |
| `checked` · `mismatches` | 왜 | 정합성 검사 건수와 불일치 목록(`stored_name`·종류, 최대 50건) | 배치 | `audit.integrity` (9단계) |
| `rule` · `evidence` | 왜 | 경보 규칙 번호와 근거(대상 계정·건수·기간) | 배치 | `alert.raised` (9단계) |
| `chain_prev` · `chain_hash` | 무엇을 | 감사 로그 파일 해시 체인: 직전 일자 마지막 해시 · 오늘 파일 sha256 (R-11) | 배치 | `audit.integrity` (9단계) |
| `target_role` · `target_active` | 무엇을 | 대상 계정의 역할·활성 여부(변경 후 값) | 핸들러 | `user.*` (10단계) |
| `sessions_revoked` | 어떻게 | 끊은 세션 수 (R-10 전 기기 로그아웃). **`FORBIDDEN_DETAIL_KEYS` 의 `session` 부분 일치 예외** — `Audit::ALLOWED_DETAIL_KEYS` 에 정확 일치로 등록(값은 정수 개수, 토큰이 아니다) | 핸들러 | `auth.password_change`·`user.deactivate`·`user.role_change` (10단계) |
| `expires_at` | 무엇을 | 계정 만료일(Guest 필수). 생성·변경 행에 값 그대로 | 핸들러 | `user.create`·`user.expiry_change`·`user.role_change` (10단계) |
| `policy` | 어떻게 | 라우트에 붙은 정책 이름 | 라우터 | `authz.route` deny |
| `reason_text` | 왜 | 관리자가 입력한 사유 (500자) | 핸들러 | 역할 변경·비활성화·강제 삭제·레벨 하향 (10단계) |
| `owner_id` | 무엇을 | 파일을 올린 사용자 id — 삭제·다운로드 판정에 쓰인 값 | 핸들러 | `file.delete`·`file.download` |
| `target_team` | 무엇을 | 파일의 `team_id` (team 레벨이 아니면 null) — `actor_team` 과 나란히 놓으면 `same_team`·`team_mismatch` 판정을 로그만으로 재검산할 수 있다 | 핸들러 | `file.download`·`file.delete`·`file.upload` |
| `ext` · `mime` | 무엇을 | 저장 확장자와 finfo 로 판별한 실제 MIME (DB `files.ext`·`files.mime` 과 같은 값) — 파일명이 아니라 내용 종류를 남긴다 (R-16) | 핸들러 | `file.upload`(allow) |
| `moved` | 어떻게 | 업로드 실패 시 임시파일이 이미 저장소로 옮겨진 뒤였는지(true/false) — 고아 파일 정리 여부를 로그로 확인 | 핸들러 | `file.upload`(error) |

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

---

## 10. DB 계정 권한 (최소 권한 원칙)

| 계정 | 용도 | 권한 |
|---|---|---|
| `tbox_owner` | 스키마 생성·마이그레이션 (배포 시에만) | 테이블 소유 |
| `tbox_app` | PHP 앱 런타임 | `users teams files`: SELECT/INSERT/UPDATE · `sessions`: SELECT/INSERT/UPDATE/DELETE · `permissions`: **SELECT 만** · `audit_logs`: SELECT/INSERT 만 · DROP 없음 |
| `tbox_backup` | 백업 스크립트 | 전체 SELECT (읽기 전용) |

> 파일 삭제가 논리 삭제(UPDATE)이므로 앱 계정에 `DELETE` 권한 자체가 필요 없다. 30일 실제 정리는 `tbox_owner` 권한의 배치 스크립트가 수행한다.

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
| 매주 일 02:00 | `scripts/consistency_check.php` | DB ↔ 스토리지 정합성 (고아·누락·sha256) | `audit.integrity`, 불일치 → `alert.raised AL-6` |

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
 인터넷 ─✖─┐                                  (사내망 밖에서는 아예 닿지 않음)
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

경보 규칙 AL-1 ~ AL-11 의 조건은 PRD 7.11 표를 그대로 쓴다 (AL-10 계정 공유 의심·AL-11 admin-only 다운로드 요약은 2026-09-10 추가). 예: AL-1 을 SQL 로 쓰면

```sql
SELECT actor_id, count(*) FROM audit_logs
 WHERE result = 'deny' AND action IN ('file.download','file.read')
   AND happened_at > now() - interval '10 minutes'
 GROUP BY actor_id HAVING count(*) >= 5;
```

### 11.5 운영 캘린더 — PRD S-11-B

| 주기 | 자동/수동 | 작업 | 산출물 |
|---|---|---|---|
| 매일 | 자동 | 보안 패치 · 백업 + 검증(11.1) · ClamAV 시그니처 · AIDE · 경보 | 알림 채널 |
| 매주 (월) | 수동 30분 | 감사 로그 리뷰, 정합성 검사 결과, 실패 로그인 추이, 디스크 사용량 | 주간 점검표 |
| 매월 (첫 주) | 수동 2시간 | 전체 패치 + 재시작, 계정·권한 대사(퇴사자·Guest 만료), `security_lint.sh` + T-1~T-23 | `SECURITY.md` 5장 한 줄 |
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
| ☐ | 오류 메시지에 DB 오류·경로·스택 트레이스가 섞여 나가나? |
| ☐ | 새 패키지·라이브러리·외부 도구를 추가했나? → `deploy/DEPENDENCIES.md` 갱신 + EOL 확인 (S-10-C) |
| ☐ | 새 기능이 만드는 이벤트가 경보 규칙(AL-x)에 필요하진 않나? (S-11-A) |
