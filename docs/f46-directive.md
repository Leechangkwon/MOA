# F-46 지시서 (Fable → Opus) — 2단계 코드 생성기 이름·휴대폰 화면·홈 화면 추가 · SharePoint 공개 링크 가져오기(쿠키 유지 리다이렉트)

작성 2026-09-15. 근거: 사용자 결정 2026-09-15 — ① 「오프라인」이라는 이름이 오해를 줌(인터넷에서 열림) → 「t-box 2단계 코드 생성기」 ② 휴대폰 인증 앱은 광고·결제 유도로 못 씀, APK 대신 **생성기를 휴대폰 브라우저에서** 쓰게(F-46) ③ SharePoint 「전체 공유」 링크가 「로그인이 필요합니다」로 거부됨 — 실측 결과 우리 쪽 결함 2개.
PRD F-46(신설)·12.2 T-58 ; ARCH 5.21(생성기)·5.22(URL 가져오기) 보충 ; SECURITY R-77 보충 ; STAGES 「F-46」.

## 0. 사실 (검토자 실측 2026-09-15)

- SharePoint 「링크가 있는 모든 사용자」 링크 `https://planlabs.sharepoint.com/:x:/s/.../IQAy...?e=hdg0gi`:
  - 그대로 → 302 → `_layouts/15/Doc.aspx`(웹 편집 화면, HTML).
  - `&download=1` → 302 → `/sites/.../<파일>.xlsx?ga=1`(같은 호스트) → **쿠키 없이** 따라가면 `403 FORBIDDEN`(text/plain), **첫 302 의 `Set-Cookie: FedAuth=…` 를 다음 요청에 실으면 200, `application/vnd.openxmlformats…sheet`, 355KB 엑셀**.
  - 즉 공개 링크가 맞고 로그인은 필요 없다. 막힌 원인은 ① `CloudLink` 가 `*.sharepoint.com` 을 무조건 `needs_login` 으로 분류 ② `Fetch` 리다이렉트 루프가 단계 사이에 쿠키를 넘기지 않음.
- `Fetch::request()` 는 hop 마다 `curl_init` 새 핸들(499~), `CURLOPT_RESOLVE` 로 IP 고정, `HEADERFUNCTION` 으로 헤더를 읽는다. 쿠키 관련 옵션 0.
- 생성기: `<meta viewport>` 있음, `.wrap max-width 560px`, `@media (max-width:420px)` 한 블록. 제목·h1 「t-box 오프라인 2단계 코드 생성기」, 상태 줄 문구 「오프라인 · …」(이건 동기화 상태를 뜻하므로 유지), README 1250·settings_page 153·help/screens 73 에 「오프라인 코드 생성기」.
- 허용 목록에 `planlabs.sharepoint.com` 이 이미 활성(관리자가 넣음).

```
목표        : (A) 생성기 이름을 「t-box 2단계 코드 생성기」로(문서·화면·링크 문구). (B) 휴대폰 브라우저에서 쓰기 좋게: 480px 이하 배치(입력칸·버튼 전체 폭, 코드 글자 크게, 탭 목표 44px), 홈 화면 추가용 웹 앱 매니페스트와 아이콘(data: SVG), 「홈 화면에 추가」 안내 한 줄. (C) SharePoint 공개 링크 가져오기: CloudLink 가 sharepoint 를 OneDrive 와 같이 `download=1` 로 변환(needs_login 은 HTML 응답 감지에 맡김), Fetch 리다이렉트 루프가 **같은 호스트로 가는 다음 단계에만** 직전 응답의 Set-Cookie 를 실어 보낸다.
요구사항    : PRD F-17·F-42·F-43·F-46 · S-05 · 12.2 T-58 ; ARCH 5.21·5.22 ; SECURITY R-40·R-77 ; 기존 코드 src/CloudLink.php(sharepoint 분기 92~110)·src/Fetch.php(download 루프 314~·request 499~)·public/assets/totp-generator.html·src/help/screens.php 73·src/handlers/settings_page.php 153·README 1250~1290·deploy/sites/tbox-v2.conf(생성기 location — 매니페스트 경로 추가 필요 여부).
수정 파일   :
  [새로 만듦]
    public/assets/totp-generator.webmanifest  — {"name":"t-box 2단계 코드","short_name":"t-box 코드","start_url":"/assets/totp-generator.html","display":"standalone","background_color":"#f4f6f9","theme_color":"#1d5fbf","icons":[{"src":"data:image/svg+xml,…","sizes":"any","type":"image/svg+xml"}]}. 정적 파일, 로그인 불필요(생성기와 같은 성격).
    tests/f46.sh                              — T-58 (a)~(f). 로그인 없음(생성기·매니페스트·CloudLink 단위·Fetch 단위). SharePoint 실링크는 (f) 에서 **읽기만**(파일을 저장하지 않는 dry-run 경로가 없으므로 qa_manager01 로 실제 가져오기 1회 → 저장된 파일은 시험이 qa uploaded_by 기준으로 정리).
  [고침]
    public/assets/totp-generator.html         — (A) title·h1 「t-box 2단계 코드 생성기」 ; (B) `<link rel="manifest" href="totp-generator.webmanifest">` · `<meta name="theme-color">` · `<link rel="apple-touch-icon" href="data:image/svg+xml,…">`(iOS 는 svg 를 못 쓰면 무시됨 — 그래도 둔다) · `@media (max-width:480px)` 로 .wrap 패딩 12px·입력/버튼 100%·코드 글자 44px·버튼 높이 44px·상태 줄 줄바꿈 ; 안내 한 줄(사용 문구): 「휴대폰에서는 브라우저 메뉴의 「홈 화면에 추가」로 앱처럼 열 수 있습니다.」 ; 상태 줄의 「오프라인 · …」 문구는 유지(동기화 상태).
    src/CloudLink.php                         — `<tenant>.sharepoint.com` · `<tenant>-my.sharepoint.com` → OneDrive 와 같이 `download=1` 쿼리 추가, provider microsoft, needs_login **false**(HTML 이 돌아오면 Fetch 가 needs_login 으로 거부). 머리 주석 표 갱신.
    src/Fetch.php                             — 리다이렉트 루프: 직전 응답의 `Set-Cookie` 를 모아(이름=값만, 속성 버림, 최대 20개·합계 8KB) **다음 단계 호스트가 직전 호스트와 같을 때만** `CURLOPT_COOKIE` 로 싣는다(다른 호스트로 가면 버림 — 쿠키가 다른 사이트로 새지 않게). 감사 detail 에 `cookie_hops`(쿠키를 실은 단계 수)만(값 금지). request() 에 `?string $cookie` 인자.
    src/handlers/settings_page.php 153 · src/help/screens.php 73·files 도움말 · README 1250~ — 「오프라인 코드 생성기」 → 「2단계 코드 생성기」, files 도움말에 「SharePoint 도 「링크가 있는 모든 사용자」 링크면 됩니다」 한 구.
    deploy/sites/tbox-v2.conf                 — `location = /assets/totp-generator.webmanifest { try_files $uri =404; expires 1h; add_header Content-Type "application/manifest+json"; }` 와 보안 헤더(생성기 location 과 같은 것). **/etc/nginx 복사·reload 는 Fable**.
    scripts/security_lint.sh                  — 규칙 (g) 유지 확인(변경 없음이면 그대로).
    PRD.md(F-46·T-58) · ARCHITECTURE.md(5.21 휴대폰·매니페스트 / 5.22 쿠키 규칙) · SECURITY.md(R-77 보충: 쿠키는 같은 호스트 다음 단계에만·값 미기록) · STAGES.md 「F-46」 · docs/totp-generator-internals.md(휴대폰·매니페스트 절).
금지 패턴   : 쿠키를 다른 호스트로 보내는 것 · 쿠키 값을 감사·로그에 남기는 것 · CURLOPT_FOLLOWLOCATION true · 리다이렉트 단계에서 check() 생략 · 생성기가 비밀 키·코드를 어디로든 보내는 코드 · 외부 아이콘/폰트/스크립트 URL(매니페스트 아이콘도 data: 만) · 생성기 소스·화면에 구조 설명 · APK/네이티브 · 패키지 설치 · 운영 표 변경.
             라이브 서버 규칙: Fetch.php 저장 직후 `php -l` + `/login` + 로그인 요청 1회.
완료 기준   : tests/f46.sh —
             (a) 생성기 200 · `<title>` 과 h1 에 「오프라인」 0회(상태 줄 문자열은 제외 — JS 안 문자열만 허용) · manifest 링크 1 · theme-color 1 · 「홈 화면에 추가」 안내 1 · 저장소 전체에서 「오프라인 코드 생성기」 0(grep)
             (b) 매니페스트 200 · JSON 유효 · icons[0].src 가 data: · start_url 이 생성기 경로 · nginx 배포본 적용 전엔 Content-Type 이 다를 수 있음을 적음
             (c) Playwright 390×844(휴대폰) 로 생성기: 가로 스크롤 없음 · 버튼 높이 ≥44 · 코드 글자 ≥40px · 자체 점검 9/9 · 콘솔 오류 0 · 스크린샷 ; 1440 도 결함 0(회귀)
             (d) CloudLink 단위: sharepoint(:x:/s/…?e=…) → 같은 URL + `download=1`, provider microsoft, needs_login false ; `-my.sharepoint.com` 같음 ; `a.b.sharepoint.com.evil.tld` 는 변환 없음 ; 기존 12케이스 회귀
             (e) Fetch 단위(`php -r`, 네트워크 없이 헬퍼 함수 직접): Set-Cookie 3개 → 이름=값 3개로 합침 · 속성(Path/Secure/HttpOnly) 제거 · 21개면 20개까지 · 8KB 초과면 잘라내지 않고 **쿠키 없이** 진행(감사 cookie_hops 0) · 다음 호스트가 다르면 빈 문자열
             (f) 실링크: qa_manager01 로 `POST /api/files/fetch` {url: 위 SharePoint 링크} → 200, 저장된 파일이 xlsx(Upload 검사 통과), 감사 `file.fetch allow` detail hops 2 · cookie_hops 1 · provider microsoft · 쿠키 값 없음 ; 같은 링크에서 `?e=` 를 뗀 것도 됨 ; 가짜 id 로 바꾼 링크 → 400 needs_login 또는 fetch_failed(HTML/403) — 어느 쪽인지 원문 첨부. 정리: 저장된 파일은 qa uploaded_by 로 휴지통 → 시험 스크립트가 psql 로 purge 하지 않고 **휴지통에 둔다**(30일 규칙, R-73)
             회귀: tests/fetch_hosts.sh · totp_time_sync.sh · help_button.sh · 린트 0 · php -l.
보고 형식   : 변경 파일 / 실행 명령·출력 원문 / 스크린샷(휴대폰 폭·데스크톱) / 못 한 것 / ARCH 13장 체크리스트.
```
