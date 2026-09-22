# 구현 지시서 — Qaredi C: 앱 런처 `/` + t-box 를 `/tbox/` 아래로 (28단계 base-path) · 옛 주소 308

> Fable 작성 2026-09-22. 사용자 결정: Q-B 런처는 로그인 전에도 타일 · Q-E 이 세션이 진행 · Q-F 옛 `/api/*` 는 308 리다이렉트 · Q-G 발행 v2.0.0(발행은 Fable).
> **먼저 읽을 것**: `CLAUDE.md`(전부) · `docs/launcher/ARCHITECTURE.md`(이 지시서의 설계 원본 — 절 번호로 인용한다) · 사이트 `PRD.md` 3·4·9장 · `DESIGN.md` 2.3·2.8·2.9·2.10·2.11·2.12·6장 · `docs/tbox/ARCHITECTURE.md` 4.0(관문 순서)·5.19(도움말)·5.24(정적 파일).

## 목표
`GET /` 에 스크립트 0·쿠키 0·데이터 0 의 Qaredi 런처가 서고, t-box 의 모든 주소가 `/tbox` 접두를 갖고, 옛 t-box 주소는 308 로 새 주소로 간다. **t-box 의 기능·판정·감사·시험 통과 수는 전과 같다.**

## 요구사항
- 사이트 `PRD.md` Q:F-1(4장) · 수락 기준 **Q:T-1~Q:T-10**(9장) · `docs/launcher/ARCHITECTURE.md` 1~7장.
- t-box `docs/tbox/PRD.md` 13장 28단계 · S-09 · `docs/tbox/STAGES.md` 28단계 「개발 사항」 체크리스트.
- 화면: `DESIGN.md` 2.3(`.card--link`·`.card-grid`) · 2.9(셸 머리) · 2.10(독립 화면) · 2.11(아이콘) · 2.12(다크 모드) · 6장 체크리스트. **새 클래스·색값·간격을 만들지 않는다.** 런처 타일은 `.card-grid` + `.card.card--link`, 그리드 팝업은 `<details class="appbar__apps">`(DESIGN 2.9 에 등록됨) 안에 기존 `.btn.btn--icon.btn--plain`·`.panel`·`.list` 조합. `.launcher-*` 류 화면 전용 클래스 금지(원칙 6).

## 수정 파일 — 허용 목록 (여기 없는 파일은 만들거나 고치지 않는다)
| 구분 | 파일 | 할 일 |
|---|---|---|
| 상수 | `src/bootstrap.php` | `const TBOX_BASE = '/tbox';` (`TBOX_REQUEST_ID` 정의 근처, 주석에 ARCH-launcher 2장) |
| 라우터 | `public/index.php` | ARCH-launcher 2장의 한 층: `/` → 런처(GET·HEAD, 그 밖 405) · `/tbox…` → 접두 떼고 기존 매칭 · 옛 t-box 경로 → `Http::redirectPermanent(TBOX_BASE . REQUEST_URI)` · 그 밖 404. `$reqPath` 를 쓰던 뒤쪽 판단(`'/api/'` 접두 등)은 **모듈 경로** 기준. 라우트 표 **무변경** |
| HTTP | `src/Http.php` | `base()` · `url(string $path)`(`/` 로 시작 안 하면 `RuntimeException`) · `redirectPermanent(string $location)`(308, `Location`, `Cache-Control: no-store` 없음) · 필요하면 `themeAttr()`(셸·로그인·런처의 `data-theme` 계산 한 벌) |
| 앱 목록 | `config/apps.json`(새) · `src/Apps.php`(새) | ARCH-launcher 5장. 항목 둘: `tbox`(이름 `t-box`, 설명 `사내 파일 공유`, `path` `/tbox/`, 아이콘 `shield`, order 1) · `community`(이름 `커뮤니티`, 설명 `개인 업무 공간 · 게시판 · 인계`, `path` `/community/`, 아이콘 `grid` 또는 새 아이콘 하나, order 2, `status: "soon"`) |
| 런처 | `src/site/launcher_page.php`(새) · `src/views/launcher.php`(새) | ARCH-launcher 5장. 세션은 `Auth::currentUser()` 읽기만. `Cache-Control: no-store`. `<script>` 0 |
| 셸 | `src/views/shell_top.php` · `src/views/shell_bottom.php`(아이콘 `<template>` 이 여기면) | 모든 `href="/…"`·`src="/…"`·`action="/…"` → `Http::url()`. `<body data-base="<?= TBOX_BASE ?>">`. 그리드 단추 `<details class="appbar__apps">`(ARCH-launcher 6장). `grid` 아이콘 `<template>` 추가 |
| 로그인 | `src/handlers/login_page.php` | 자산·`action` 경로 `Http::url()`, `data-base`, 머리에 「Qaredi 홈」 링크(`/`) |
| 핸들러·렌더러 | `src/handlers/*.php` 중 절대 경로를 **내보내는** 것만 · `src/Wiki.php`(`/wiki/{slug}` 링크) · `src/help/*.php`(문구 안 경로가 있으면) · `src/handlers/share_create.php`(`APP_URL . Http::url('/s/'.$token)`) · `src/handlers/reauth_page.php`·`reauth.php`(복귀 경로 내보낼 때 `Http::url`) | grep `href="/` `action="/` `'/login'` `'/reauth'` `'/s/'` `Http::redirect(` `Location` 로 전부 찾아 표로 보고한다. **판정·SQL·감사 문장은 한 글자도 건드리지 않는다** |
| JS | `public/assets/app.js` | 머리에 `APP_BASE`(ARCH-launcher 3장) · **모든** 절대 경로 리터럴(`'/api/…'` `'/login'` `'/'` `'/reauth'` `'/wiki/…'` `location.href = '/…'` 등)을 `APP_BASE + '…'` 로. 끝나면 `grep -nE "['\"]/(api|login|reauth|wiki|admin|settings|files|s)(/|['\"])" public/assets/app.js` 가 **APP_BASE 로 감싸인 것 외 0건** |
| CSS | `public/assets/app.css` | `.appbar__apps`(details 위치·펼침 패널 배치만 — 색·간격은 `:root` 토큰) 한 덩이. 런처는 기존 구성 요소만 써서 **CSS 추가 0** 을 목표로 한다. 다크 모드는 토큰이 따라오므로 별도 규칙 없음(2.12) |
| 정적 | `public/assets/totp-generator.html` · `public/assets/totp-generator.webmanifest` | 시각 창구는 **상대 경로** `../api/time`; 매니페스트 `start_url`·`scope` → `/tbox/assets/…`. 생성기의 CSP `<meta>`·오프라인 동작 무변경 |
| nginx | `deploy/sites/tbox-v2.conf` · `deploy/sites/tbox-v2-local.conf` | ARCH-launcher 4장 그대로. **CSP·limit_req 값 무변경.** `nginx -t` 는 root 필요 → 문법은 `nginx -t -c` 로 못 돌리므로 **두 파일의 diff 를 보고에 원문으로** 붙인다(적용은 사용자) |
| 문서 | `docs/tbox/API.md` 0장 + 새 절 「주소 변경 2026-09-22」 | 기본 주소 `https://changkwon.mso.so/tbox` · 로컬 `http://127.0.0.1:8082/tbox` · 옛 주소 308(메서드·본문 보존) · 「리다이렉트를 따르지 않는 프로그램은 주소를 바꾼다」 |
| 시험·도구 | `tests/*.sh` 의 `BASE="http://127.0.0.1:8082"` 18곳 · `scripts/ui_snapshot.sh` · `scripts/check_baseline.sh`(`LOCAL_HTTP` 뒤 `/tbox/api/health`) · `deploy/switch-site.sh` 260행 상태 줄 | 값만 `…/tbox`. 다른 줄 무변경 |
| 새 시험 | `tests/q_launcher.sh`(새) | 아래 「완료 기준」의 Q:T-1·3·4·5·6·7·8·9 를 실행 원문으로 남기는 스크립트. `tests/f68.sh` 의 골격(sub/expect/정리/UA `tbox-review/날짜`/qa 계정) 그대로. 로그인이 필요한 항목은 `qa_employee01`·`qa_admin01` 만 |

**만들지 않는 것**: `src/community/*` · DB 변경 · `.env` 키 · 새 외부 요청 · `deploy/install.sh` 변경 · `VERSION`·`CHANGELOG`(발행은 Fable) · 커밋.

## 추가 조건 (다른 세션 제보 2026-09-22 — 검토 항목에 그대로 들어간다)
- **DESIGN 원칙 6**: 화면 전용 CSS 금지. `.launcher-*` 같은 새 클래스 0. 꼭 필요하면 만들지 말고 보고에 「필요한 구성 요소」로 적어 Fable 이 DESIGN 2장에 먼저 등록한다.
- **DESIGN 원칙 2(CSP)**: `<details>` 팝업·런처에 인라인 `style=`·`on*=` 0. 아이콘은 2.11 인라인 SVG 규칙.
- **D-3**: 아이콘 단추에 브라우저 `title` 을 달지 않는다 — 2.8 CSS 툴팁. 검토 항목 `.btn--icon[title]` 0.
- **2.12**: 새 요소는 색을 직접 쓰지 않는다 — `:root` 토큰만.
- **시험 스크립트의 절대 경로 리터럴**: `BASE=` 한 줄 외에 `http://127.0.0.1:8082/…` 또는 `$BASE` 없이 `"/api/…"` 를 직접 쓴 곳이 있는지 `grep -nE '127\.0\.0\.1:8082/|"/api/|'"'"'/api/' tests/*.sh scripts/*.sh` 로 전부 찾아 고치고 표로 보고한다(예: `tests/f66.sh`·`f68.sh`).
- **`docs/tbox/API.md` 7~14장 curl 예시**와 F-66 문서 예시(`/api/me/api-tokens` 등)도 전부 `/tbox` 접두를 탄다 — 0장만 고치고 끝내지 않는다.

## 금지 패턴
SQL 문자열 결합 · `innerHTML` · 문자열 → HTML 변환(런처 아이콘은 PHP 가 그리는 정적 SVG 마크업이며 요청 값이 섞이지 않는다) · 요청 값(Host·Referer·쿼리)으로 리다이렉트 대상 만들기(접두 + `REQUEST_URI` 원문만, ARCH-launcher 1장 검증) · `Authz` 밖 역할 판단 · 라우트 표의 경로·정책·핸들러 변경 · `TBOX_BASE` 를 `.env` 에서 읽기 · 자산을 두 경로로 동시에 서비스(옛 `/assets/` 는 308 만) · `git add -A`·커밋·운영 DB DDL/DML·nginx reload·시드 계정 사용.

## 완료 기준 — Opus 가 직접 실행할 것 (nginx 적용 전이라 **PHP 내장 서버**로)
로컬 검증 서버: `php -S 127.0.0.1:18082 -t public public/index.php` (배경 실행, 끝나면 종료). `.env` 는 읽힌다. 세션 폴더에 쓸 수 없어 **로그인은 안 된다** — 비로그인 항목만 여기서 실측하고, 로그인 항목은 스크립트에 넣되 「nginx 적용 뒤 Fable 실행」으로 표시한다.

| # | 항목 | 실행·기대 |
|---|---|---|
| Q:T-1 | 런처 | `curl -si :18082/` → 200 · `Content-Type: text/html` · 본문에 `t-box`·`커뮤니티`·`준비 중` · `<script` 0건 · `Set-Cookie` 0건 · 사용자 칩 없음. `curl -si -X POST :18082/` → 405 |
| Q:T-3 | 옛 주소 308 | `GET /api/health` `GET /login` `POST /api/login`(본문 `{}`) `GET /s/0123…(36자)` `GET /settings` → 전부 308 + `Location: /tbox<같은 경로>` · `GET /api/health?x=1` → `Location: /tbox/api/health?x=1` · **POST 308 뒤 감사 행 0·Set-Cookie 0**(내장 서버에선 DB 만 확인) · `GET /foo` → 404 HTML · `GET /api/nope` → 404 JSON · `GET /tbox/nope` → 404 · `GET /community/` → 404 |
| Q:T-4 | 링크 접두 | `curl -s :18082/tbox/login` 의 `href|src|action="/…"` 값 전부 `/tbox/` 로 시작(예외: `href="/"` Qaredi 홈). `grep -c "'/api/" public/assets/app.js` = 0 · `grep -nE "['\"]/(login|reauth|wiki|admin|settings)" app.js` 의 결과가 전부 `APP_BASE +` 뒤 |
| Q:T-9 | 정적 자산(내장 서버는 파일을 직접 준다) | `GET /tbox/assets/app.css` → 200 (내장 서버에선 라우터 스크립트가 처리 — `public/tbox/` 가 없어 404 가 나면 그 사실을 적고 nginx 적용 뒤 항목으로 넘긴다) |
| 정적 검사 | `php -l` 바뀐 PHP 전부 · `bash scripts/security_lint.sh` 0건 · `bash -n` 바뀐 sh 전부 · `php scripts/wiki_check.php` 통과 · `python3 -m json.tool config/apps.json` |
| 표 | 「바꾼 절대 경로」 표: 파일 · 줄 · 전 · 후 (핸들러·뷰·JS 전부). 「찾았지만 바꾸지 않은 것」 표와 이유(예: 감사 문장 안의 경로 문자열, 위키 글 본문) |

**nginx 적용 뒤 Fable 이 실행할 것**(스크립트에 포함, 보고에 「미실행」 표시): Q:T-2 회귀(`stage26.sh` 108/0 · `help_button.sh` 16/0 · `api_whoami.sh` 15/0 · `f68.sh` 109/0 · `api_read.sh`·`api_folders.sh`·`f66.sh`) · Q:T-5 302 경로(`/tbox/settings` 미로그인 → `Location: /tbox/login` · 재인증 → `/tbox/reauth?to=admin` → 복귀 `/tbox/admin`) · Q:T-6 공유 링크 `url` 이 `APP_URL/tbox/s/` · Q:T-7 `Set-Cookie` 속성·CSP 헤더 문자열 전후 동일 · Q:T-8 셸 화면 전부에 `appbar__apps` · Q:T-9 `/tbox/assets/totp-generator.html` 200 + 자체 CSP(`unsafe-inline`) · `/assets/app.css` 308 · Q:T-10 사용자 PC 프로그램(관문 ③).

## 보고 형식
① 변경 파일 목록(새 파일 표시) ② 실행한 명령과 출력 **원문**(위 표 순서) ③ 「바꾼 절대 경로」 표 · 「바꾸지 않은 것」 표 ④ nginx 두 파일 diff 원문 ⑤ 못 한 것과 이유(로그인 필요 항목 등) ⑥ 설계와 다르게 한 것이 있으면 그 이유(없으면 「없음」).
