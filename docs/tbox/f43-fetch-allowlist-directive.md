# F-43 지시서 (Fable → Opus) — URL 가져오기: 허용 사이트 관리 화면 · 사용 방법 안내 · 버튼 자동 숨김 · 드라이브/OneDrive 공개 링크 변환 · 통제된 리다이렉트

작성 2026-09-15. 근거: 사용자 결정 2026-09-15 — 「관리자 페이지에서 허용 URL 관리 · 사용 방법에 허용 목록 안내 · 구글 드라이브·OneDrive 문서를 가져올 수 있게. 지금 진행. F-44(OAuth) 가 안 되면 F-43 도 의미 없다 → 둘을 이어지는 설계로」. 회사는 구글·마이크로소프트 **둘 다** 쓴다.
PRD F-43(신설)·12.2 T-56 · 8장 API 행 ; ARCH 5.22(신설, F-44 자리 포함) ; SECURITY R-76(허용 목록이 DB 로)·R-77(통제된 리다이렉트)·S-05 보충 ; STAGES 「F-43」.

## 0. 사실 (검토자 실측)

- 허용 목록은 `.env` 의 `FETCH_ALLOWED_HOSTS`(`Fetch::allowedHosts()`, 정확 일치, 와일드카드 금지). **지금 비어 있다** → 모든 URL 가져오기가 `host_not_allowed`. 홈의 「URL 로 가져오기」 버튼은 `can_fetch`(Manager 이상)면 보인다 — 목록이 비어도 보인다.
- `Fetch::check()` — https·443·URL 2KB·호스트 정규식·허용 목록·DNS 뒤 사설 IP 차단(PRIVATE_CIDRS). `Fetch::download()` — `CURLOPT_FOLLOWLOCATION false`, 3xx 는 `FetchDenied('redirect')`. 최대 100MB. 결과는 `Upload::validate` 와 같은 검사를 지난다.
- 드라이브 공개 파일 내려받기 `https://drive.google.com/uc?export=download&id=<ID>` 는 `drive.usercontent.google.com` 등으로 **302** 한다. OneDrive 공유 링크 `https://1drv.ms/...` 는 `onedrive.live.com` 으로 302 하고, `?download=1` 을 붙이면 다시 내려받기 호스트로 302 한다. → 리다이렉트를 전부 거부하는 지금 규칙으로는 둘 다 불가능하다.
- 관리자 화면의 「사유 + 인증 앱 코드」 검증 본보기: `src/handlers/admin_permissions_update.php`(`Totp::verifyStep` + `mfa_last_step` 1회성, `reason_text` 1~500자, 감사 detail `otp_verified`).

```
목표        : (1) 허용 사이트 목록을 DB 로 옮겨 관리자 화면에서 넣고 뺀다 — 권한 매트릭스와 같은 급(재인증 + 사유 + 인증 앱 코드, 감사, 경보).
              (2) 홈의 「URL 로 가져오기」 버튼은 목록이 비면 숨기고, 「사용 방법」 도움말에 현재 허용 목록과 「파일 주소여야 한다」 예시를 보여 준다.
              (3) 드라이브·OneDrive 의 **공개 링크**(링크가 있는 모든 사용자)를 서버가 내려받기 주소로 바꿔 가져온다. 이를 위해 리다이렉트를 **통제된 방식**(최대 3번 · 매 단계 https·허용 호스트·사설 IP 재검사)으로만 따른다.
              (4) 로그인이 필요한 문서는 「이 문서는 로그인이 필요합니다 — F-44 계정 연결 뒤 가능」 문구로 거부한다(HTML 응답 감지).
              F-44(OAuth) 는 이 위에 얹는다: 같은 관리 화면에 「클라우드 연결」 절이 들어갈 자리, Fetch 의 커넥터 호스트(googleapis·graph) 고정 목록 자리만 마련한다(코드는 만들지 않는다).
요구사항    : PRD F-14(URL 가져오기)·F-43 · S-05(외부 요청) · S-07 · 5장(file.fetch = Manager 이상) · 12.2 T-56 ; ARCH 4.0·5.22·7.4 ; SECURITY R-76·R-77·S-05 보충·R-05(재인증)·R-45(OTP 1회성) ;
              기존 코드 src/Fetch.php 전체 · src/handlers/files_fetch.php · src/handlers/home.php(48~52 버튼, 248~ 대화상자) · public/assets/app.js(fetch 대화상자·initShell can_fetch) · src/handlers/admin_permissions_update.php(검증 본보기) · scripts/alerts.php(AL-13 형태) · src/help/screens.php(files·admin).
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-17_fetch_hosts.sql       — fetch_hosts(id bigserial PK, host text UNIQUE NOT NULL CHECK(host ~ '^[a-z0-9.-]{1,253}$' AND host NOT LIKE '%*%'), note text, kind text NOT NULL DEFAULT 'user' CHECK(kind IN ('user','connector')), is_active bool NOT NULL DEFAULT true, created_by bigint, created_at, updated_at). grants: tbox_app SELECT·INSERT·UPDATE(DELETE 없음 — 비활성화로). db/schema.sql·db/grants.sql 동기화.
                                                     초기 행(INSERT ... ON CONFLICT DO NOTHING): `.env` 의 FETCH_ALLOWED_HOSTS 값이 있으면 그것을 kind=user 로 옮김(지금은 비어 있어 0행). **드라이브·OneDrive 호스트는 기본으로 넣지 않는다** — 관리자가 화면에서 켠다(회사 결정).
    src/handlers/fetch_hosts_list.php              — GET /api/fetch-hosts (정책 role:Admin) → {hosts:[{id,host,note,kind,is_active,created_at}]}
    src/handlers/fetch_hosts_update.php            — POST /api/fetch-hosts  body {changes:[{host, is_active, note?}], reason_text, otp} — 권한 매트릭스와 같은 검증 순서(재인증 관문 자동 · Totp::verifyStep + mfa_last_step · reason 1~500). 한 트랜잭션. 호스트는 소문자·trim, 정규식·와일드카드 금지·`localhost`/IP 리터럴 금지. 감사 `fetch_host.change allow` detail {before:[…], after:[…], reason_text, otp_verified:true} / deny.
    src/handlers/admin_fetch_hosts_page.php + src/views 마크업(있는 패턴대로) — GET /admin/fetch-hosts (role:Admin) : 표(호스트 · 메모 · 상태 · 종류) · 「추가」 줄(호스트·메모) · 「저장」 → 사유·인증 앱 코드 모달(권한 매트릭스와 같은 모양). 「사용 방법」 버튼(help 키 fetch-hosts). 안내: 「https 만, 정확한 호스트 이름만(와일드카드 없음). 사내망 주소는 넣어도 서버가 거부합니다.」
    src/CloudLink.php                              — 공개 링크 → 내려받기 주소 변환(순수 함수, 네트워크 0):
                                                       · drive.google.com/file/d/<ID>/… · drive.google.com/open?id=<ID> · docs.google.com/(document|spreadsheets|presentation)/d/<ID> → `https://drive.google.com/uc?export=download&id=<ID>` (docs 3종은 export 형식을 붙인다: document→docx, spreadsheets→xlsx, presentation→pptx: `https://docs.google.com/<kind>/d/<ID>/export?format=<ext>`)
                                                       · 1drv.ms/<…> · onedrive.live.com/…?resid=…  → 원 주소에 `download=1` 쿼리 추가(1drv.ms 는 첫 302 를 따른 뒤 붙인다 — Fetch 의 리다이렉트 단계에서 처리)
                                                       · sharepoint.com/…/:x:/… 같은 회사 링크는 변환하지 않고 `needs_login` 으로 표시(F-44 대상)
                                                       반환 {url, provider:'google'|'microsoft'|null, needs_login:bool, note}. 판별에 정규식만, 호스트 정확 일치.
    tests/fetch_hosts.sh                           — T-56 (a)~(h). qa_admin01·qa_manager01. 픽스처 호스트 `s43-<n>.example.invalid`(실제 접속 안 함 — DNS 실패 → 기존 사유). 정리는 qa created_by 행 비활성화가 아니라 **시험이 만든 행만 DELETE** 가 필요하면 psql 로(앱 계정은 DELETE 없음) — 아니면 접두 행을 남기고 is_active=false 로 두는 쪽을 택하고 문서화.
  [고침]
    src/Fetch.php          — (1) allowedHosts(): DB fetch_hosts(is_active) ∪ `.env` 값(남아 있으면) ∪ **커넥터 고정 목록 상수** `CONNECTOR_HOSTS = []`(F-44 가 채운다 — 지금은 빈 배열, 주석으로 자리만) ; (2) download(): `FOLLOWLOCATION` 은 여전히 false 로 두고 **직접 루프** — 3xx 이면 Location 을 읽어 `check()` 를 **처음부터 다시**(https·443·허용 호스트·사설 IP) 통과해야 다음 단계로, 최대 3번, 넘으면 `FetchDenied('redirect')`. 매 단계 감사 detail `hops:[host…]` ; (3) 응답 Content-Type 이 text/html 이고 주소가 CloudLink 변환 결과였으면 `FetchDenied('needs_login')`(로그인 페이지를 파일로 저장하지 않게). 그 밖 규칙(크기·UA·타임아웃) 그대로.
    src/handlers/files_fetch.php — 요청 URL 을 먼저 `CloudLink::normalize()` 로 바꾸고(원 주소·변환 주소 둘 다 감사 detail), `needs_login` 이면 400 `needs_login`(문구: 「이 문서는 로그인이 필요합니다. 공개(링크가 있는 모든 사용자) 문서만 가져올 수 있고, 회사 계정 문서는 계정 연결(준비 중) 뒤 가능합니다.」). 그 밖 로직 그대로.
    src/handlers/me.php    — `can_fetch` 는 그대로, `fetch_enabled`(활성 허용 호스트 ≥ 1) 추가. 홈 버튼은 `can_fetch && fetch_enabled` 일 때만 열림(app.js).
    public/assets/app.js   — 홈 버튼 조건 · 가져오기 대화상자에 「드라이브·OneDrive 공개 링크는 그대로 붙여도 됩니다」 한 줄과 `needs_login` 문구 표시 · 관리 화면 fetch-hosts 의 표/추가/저장 모달(권한 매트릭스 JS 패턴 재사용). innerHTML 0.
    src/handlers/admin_page.php — 카드 「URL 가져오기 허용 사이트」(/admin/fetch-hosts). 26단계 「역할로 보기」 카드 옆.
    src/views/shell_top.php — 「관리」 묶음에 링크 1개 추가(/admin/fetch-hosts, 기본 hidden, can_admin).
    public/index.php       — 라우트 3줄(GET /admin/fetch-hosts · GET /api/fetch-hosts · POST /api/fetch-hosts, 정책 role:Admin). **핸들러 파일을 먼저 만든다.**
    scripts/alerts.php     — AL-18: `fetch_host.change` 1건이라도 → 즉시 경보(AL-13 과 같은 형태·억제 규칙). 문장에 호스트 이름은 넣어도 됨(개인정보 아님).
    src/Audit.php          — 문장 틀 `fetch_host.change` allow/deny, `file.fetch` 의 detail 허용 키에 `hops`·`source_url`·`provider`·`needs_login`.
    src/help/screens.php   — files 도움말 「URL 로 가져오기」 절: 「허용된 사이트만 · 파일 주소여야 함(공유 페이지 아님) · 드라이브/OneDrive 는 공개 링크만, 회사 계정 문서는 계정 연결 뒤」 + **현재 허용 목록은 화면이 /api/me 의 `fetch_hosts_public`(활성 호스트 이름 배열, Manager 이상에게만)로 채워 넣는다**(도움말 모달의 `<ul data-fetch-hosts>` 자리, textContent). 새 키 fetch-hosts(관리 화면 도움말).
    scripts/security_lint.sh — 규칙 (g): `CURLOPT_FOLLOWLOCATION` 이 `true` 이거나 `Fetch::check(` 없이 `curl_exec` 하는 코드 반려(리다이렉트 통제 유지).
    PRD.md(F-43 · T-56 · 8장 API 3행 · 5장 「허용 사이트 관리 = Admin」) · ARCHITECTURE.md 5.22(허용 목록 저장 위치 · 통제된 리다이렉트 순서도 · CloudLink 규칙 · F-44 자리) · SECURITY.md R-76·R-77 신설(◐, 검토자가 닫음)·S-05 보충 · STAGES.md 「F-43」 개발 사항 · README 관리자 절.
금지 패턴   : `CURLOPT_FOLLOWLOCATION true` · 리다이렉트 단계에서 check() 생략 · 와일드카드·IP 리터럴·http 허용 · 허용 목록을 요청 값에서 읽기 · 호스트 목록 DELETE(비활성화만) · 사유·OTP 없이 목록 변경 · HTML 응답을 파일로 저장 · 드라이브/OneDrive 호스트를 마이그레이션이 기본 등록 · OAuth·토큰 코드(F-44) · innerHTML · 인라인 style · 패키지 설치 · 운영 표 DELETE/TRUNCATE.
             **라이브 서버 규칙**: 마이그레이션을 먼저 적용하고 코드 저장 · 보안 핵심 파일(Fetch·Audit·index.php) 저장 직후 `php -l` + `/login` + 로그인 요청 1회 확인 · 라우트 전에 핸들러.
완료 기준   : tests/fetch_hosts.sh —
             (a) qa_manager01 → GET/POST /api/fetch-hosts 403 · qa_admin01 재인증 없이 403 next:reauth · OTP 없음/틀림 400 · 사유 없음 400 · 같은 OTP 재사용 400(R-45)
             (b) 추가 2건 → 201/200, 표 2행, 감사 `fetch_host.change allow` before/after · AL-18 경보 1건(`scripts/alerts.php --state <시험 파일>`) · 와일드카드·IP·대문자 정리(소문자 저장)·http 호스트 → 400
             (c) `/api/me`: 목록 0 → `fetch_enabled false`(qa_manager01) · 1건 활성 → true · 비활성화하면 다시 false ; `fetch_hosts_public` 은 Manager 이상에게만, Employee 응답엔 키 없음
             (d) 화면: 목록 비면 홈 「URL 로 가져오기」 버튼 hidden, 있으면 보임(Playwright) · 관리 화면 표·저장 모달 스크린샷 · 도움말 모달에 허용 목록 표시
             (e) CloudLink 단위 시험(`php -r`): 드라이브 file/d·open?id·docs 3종·1drv.ms·onedrive.live.com·sharepoint(needs_login)·일반 URL(변환 없음) 각 기대값 ; 잘못된 ID(비 base64url 문자) → 변환 안 함
             (f) 통제된 리다이렉트: 시험용 로컬 https 서버를 세울 수 없으므로 **단위 수준**으로 — Fetch 의 리다이렉트 판단 함수를 static 으로 분리해 `php -r` 로 「허용 호스트 → 허용 호스트 2번 → 통과」「허용 → 비허용 → redirect 거부」「4번째 → 거부」「Location 이 http → scheme 거부」를 확인 ; 실제 네트워크 시험은 사용자가 허용 목록에 넣은 뒤 관문 ③ 에서
             (g) 실제 드라이브 공개 파일 1개(Fable 이 검토 때 만든 공개 테스트 파일 주소를 준다 — Opus 는 임의 외부 주소로 시험하지 않는다) → 허용 목록에 drive.google.com·drive.usercontent.google.com 을 넣은 뒤 가져오기 200, 파일 저장, 감사 hops 2 ; 로그인 필요 문서 링크 → 400 needs_login
             (h) 린트 0(규칙 (g) 포함) · php -l · 회귀 tests/stage26.sh (관문 종료 3 이면 표기) · tests/help_button.sh · `\dp fetch_hosts` = tbox_app arw(DELETE 없음)
보고 형식   : 변경 파일 / 실행 명령·출력 원문 / 스크린샷 / 못 한 것(특히 (g)) / ARCH 13장 체크리스트.
```

## 1. F-44 와의 이음새 (이번에 코드로 만들지 않는 것)

- `fetch_hosts.kind='connector'` 와 `Fetch::CONNECTOR_HOSTS` — F-44 가 `www.googleapis.com`·`oauth2.googleapis.com`·`graph.microsoft.com`·`login.microsoftonline.com` 을 **화면에서 못 지우는 고정 행**으로 넣을 자리.
- `/admin/fetch-hosts` 화면 아래 「클라우드 계정 연결(준비 중)」 절 자리(마크업만, hidden).
- `files_fetch.php` 의 `needs_login` 분기가 F-44 에서 「연결된 계정으로 다시 시도」로 바뀐다.
