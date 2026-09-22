# Qaredi 런처와 사이트 라우팅 — 설계 (통합 C 단계, 2026-09-22)

> 요구사항: 사이트 `PRD.md` 3·4·9장(Q:F-1, Q:T-1~Q:T-10) · t-box `docs/tbox/PRD.md` 13장 **28단계**. 계획: `docs/integration-plan.md` C.
> 원칙: **t-box 의 기능·판정·감사는 한 글자도 바뀌지 않는다.** 바뀌는 것은 「주소 앞에 `/tbox` 가 붙는다」와 「`/` 에 런처가 선다」 둘이다.

## 1. 주소 지도

| 주소 | 무엇 | 정책 |
|---|---|---|
| `GET /` | **Qaredi 런처** — 앱 타일(t-box · 커뮤니티 …). 로그인 전에도 보인다(Q-B). 스크립트 0 · 쿠키 발행 0 · 데이터 0 | public |
| `/tbox` · `/tbox/` | t-box 홈(= 옛 `/`) | t-box 라우트 표 그대로 |
| `/tbox/<옛 경로>` | t-box 의 모든 화면·API·공유 링크·정적 자산 | t-box 라우트 표 그대로 |
| `/<옛 t-box 경로>` (예 `/api/health` `/login` `/s/{token}` `/assets/app.css`) | **308 Permanent Redirect** → `/tbox/<옛 경로>?<쿼리 원문>` | — |
| `/community/…` | 커뮤니티 모듈(다음 단계, 지금은 404) | — |
| 그 밖 | 404(기존 판정 그대로 — `/api/` 접두면 JSON, 아니면 HTML) | — |

- 308 은 **메서드와 본문을 보존**한다(301/302 는 POST 를 GET 으로 바꿀 수 있다). PC 업로드 프로그램·AI 비서가 `POST /api/upload` 를 옛 주소로 보내도 리다이렉트를 따르는 클라이언트면 그대로 된다. **따르지 않는 클라이언트는 주소를 바꿔야 한다** — `docs/tbox/API.md` 0장에 적고, 관문 ③에서 사용자가 자기 프로그램으로 확인한다(Q:T-10).
- 리다이렉트 대상은 **고정 접두 + 요청 경로·쿼리 원문**만이다. 요청 값으로 호스트·스킴을 만들지 않으므로 열린 리다이렉트가 아니다. `REQUEST_URI` 는 `/` 로 시작해야 하고 길이 상한(2048)을 넘으면 404.
- 쿠키 `path=/` 는 그대로다 — 한 앱·한 세션이라 런처·커뮤니티가 같은 세션을 읽는다. `Secure`·`HttpOnly`·`SameSite=Lax`·CSP·CSRF 는 무변경(Q:T-7).

## 2. 라우터 (`public/index.php`) — 관문 앞에 한 층

```
$reqPath = Http::path()                              // '/', '/tbox', '/tbox/files', '/api/health' …
if ($reqPath === '/')                                → 런처: GET·HEAD 는 src/site/launcher_page.php, 그 밖 405
elseif ($reqPath === TBOX_BASE || str_starts_with($reqPath, TBOX_BASE . '/'))
    $modulePath = substr($reqPath, strlen(TBOX_BASE)) ?: '/'
    → 기존 TBOX_ROUTES 매칭·정책·인증·CSRF·디스패치 **그대로**, 단 $reqPath 자리에 $modulePath
elseif (TBOX_ROUTES 의 경로 패턴 중 하나가 $reqPath 와 맞는다 — 메서드 무관)
    → Http::redirectPermanent(TBOX_BASE . $_SERVER['REQUEST_URI'])   // 308
else
    → 404 (기존 판정)
```

- `TBOX_BASE = '/tbox'` 는 **코드 상수**(`src/bootstrap.php`)다. `.env` 값이 아니다 — 로컬(8082)과 공개(80)가 같은 주소 구조여야 시험이 곧 운영 검증이 된다.
- 라우터 뒤의 `'/api/'` 접두 판단(401 JSON vs 302 로그인)은 **모듈 경로** 기준이다. 감사 로그가 요청 경로를 적는 자리가 있으면 **전체 경로**(`/tbox/...`)를 그대로 적는다(사실을 적는다).
- 런처는 public 이지만 `Auth::currentUser()` 로 **세션을 읽기만** 한다(있으면 사용자 칩). 세션을 만들거나 연장하지 않고 쿠키를 보내지 않는다.

## 3. 주소를 만드는 자리 — 한 함수

| 자리 | 전 | 후 |
|---|---|---|
| PHP 헬퍼 | — | `Http::base(): string` = `TBOX_BASE` · `Http::url(string $path): string` = `TBOX_BASE . $path`(`$path` 는 `/` 로 시작해야 한다, 아니면 예외) · `Http::redirectPermanent(string $location): void`(308, `Location` 만) |
| 서버 HTML 링크 (`shell_top.php`·`login_page.php`·핸들러 화면·`Wiki.php` 렌더러·도움말) | `href="/settings"` | `href="<?= Http::url('/settings') ?>"` |
| 리다이렉트 (`Http::redirect(TBOX_LOGIN_PATH)`·재인증 302·재인증 복귀 `TBOX_REAUTH_PATHS`) | `/login` `/reauth?to=admin` `/admin` | `Http::url(...)` 로 감싼다. 표(`TBOX_REAUTH_PATHS`)의 값은 모듈 경로로 두고 **내보낼 때** 접두 |
| 공유 링크 (`share_create.php`) | `APP_URL . '/s/' . $token` | `APP_URL . Http::url('/s/' . $token)` |
| 정적 자산 | `/assets/app.css?v=` | `Http::url('/assets/app.css?v=…')` |
| `<body>` | `data-page` | `data-page` + **`data-base="/tbox"`** (셸·로그인 화면 둘 다) |
| `app.js` | `fetch('/api/…')`, `LOGIN_PATH='/login'` … | 파일 머리 `var APP_BASE = (document.body && document.body.getAttribute('data-base')) \|\| '/tbox';` 뒤 **모든** 절대 경로 문자열을 `APP_BASE + '/…'` 로. `location.href`·`history`·`new URL` 도 같다 |
| `totp-generator.html`(자체 CSP, 오프라인 열림) | `fetch('/api/time')` | **상대 경로** `../api/time`(`/tbox/assets/` 에서 `/tbox/api/time`) — 절대 경로·`APP_BASE` 를 쓰지 않는다 |
| `totp-generator.webmanifest` | `start_url`·`scope` `/assets/…` | `/tbox/assets/…` |
| `docs/tbox/API.md` 0장 | 기본 주소 `https://changkwon.mso.so` | `https://changkwon.mso.so/tbox` · 로컬 `http://127.0.0.1:8082/tbox` + 「옛 주소는 308」 절 |
| 시험·도구 (`tests/*.sh` 18개 · `scripts/ui_snapshot.sh` · `scripts/check_baseline.sh` · `deploy/switch-site.sh` 상태 줄) | `BASE="http://127.0.0.1:8082"` | `BASE="http://127.0.0.1:8082/tbox"`(경로 조립 `$BASE/api/...` 은 그대로) |

「전부 바꿨는가」는 grep 으로 증명한다(Q:T-4): 렌더된 HTML 의 `href|src|action="/…"` 값이 `/tbox/` 로 시작하지 않는 것은 그리드 팝업의 `/`·`/community/` 와 런처 링크뿐이어야 한다. `app.js` 에 `'/api/` `"/api/` 리터럴 0건.

## 4. nginx (`deploy/sites/tbox-v2.conf` · `tbox-v2-local.conf`) — 정적 자산과 속도 제한만

- `location /tbox/assets/ { alias /var/www/tbox-v2/public/assets/; try_files $uri =404; (기존 /assets/ 의 캐시·헤더 그대로) }`
- 생성기 두 location(`= /assets/totp-generator.html`·`.webmanifest`, 자체 CSP)은 `= /tbox/assets/…` 로 옮기고 `alias` 로 파일을 가리킨다. **CSP 문자열 무변경.**
- 속도 제한 exact location(`= /api/login` 과 `/` 꼬리 짝, `/api/me/password`·`mfa/*`·`reauth`)은 `= /tbox/api/…` 로. `limit_req` 존·값 무변경.
- 옛 `/assets/` 는 `location /assets/ { return 308 /tbox$request_uri; }` (PHP 에 자산 라우트가 없으므로 nginx 가 답한다). 그 밖의 옛 경로는 `location /` → `index.php` 가 308.
- `location /protected/ { internal; }` 은 URL 이 아니라 `X-Accel-Redirect` 내부 경로 — **무변경.**
- 적용은 **사용자**: `sudo cp deploy/sites/tbox-v2.conf /etc/nginx/sites-available/tbox-v2 && sudo cp deploy/sites/tbox-v2-local.conf /etc/nginx/sites-available/tbox-v2-local && sudo nginx -t && sudo systemctl reload nginx`. 이 reload 전까지는 새 PHP 가 만드는 `/tbox/assets/...` 링크가 404 라 **화면이 깨진다** — Opus 완료 직후 바로 적용한다(창을 최소로).

## 5. 런처 화면 (Q:F-1)

- 핸들러 `src/site/launcher_page.php`, 화면 `src/views/launcher.php`. 셸(사이드바)을 쓰지 않는 **독립 화면** — 로그인 화면(DESIGN 2.10)과 같은 층. `<link>` 는 `Http::url('/assets/app.css')`(자산은 당분간 t-box 모듈 것을 공유). **`<script>` 0.**
- 다크 모드: `tbox_theme` 쿠키 → `<html data-theme>` 은 셸·로그인과 같은 규칙(DESIGN 2.12). 세 곳에 같은 코드가 생기면 `Http::themeAttr(): string` 하나로 모은다. 해/달 토글 단추는 스크립트가 필요하므로 런처에는 **없다**(OS·쿠키만 따른다).
- 내용: 머리(「Qaredi」 이름 + 한 줄), 타일 격자 `.card-grid` > `.card.card--link`(DESIGN 2.3) — 아이콘(36px)·이름·설명. `status: "soon"` 인 앱은 `<div>` 카드 + 배지 「준비 중」(2.2), 링크 없음. 로그인돼 있으면 머리 오른쪽에 사용자 칩(표시 이름·역할, 기존 `.chip`/배지 구성 요소). 로그아웃 단추는 **v1 에 없다** — CSRF 헤더가 필요해 스크립트 없이 안 되고, 로그아웃은 각 앱 안에 있다(사이트 PRD 4장 정정).
- 아이콘은 서버가 **인라인 SVG** 로 그린다(스크립트 0 이므로 `[data-icon]` 채움 JS 를 못 쓴다). 셸의 `<template>` 아이콘과 같은 path 데이터를 쓴다.
- 앱 목록은 **`config/apps.json`**(저장소 추적) — `[{ "key", "name", "desc", "path", "icon", "order", "status"? }]`. 읽기는 `src/Apps.php` 한 곳(`Apps::all(): array`): JSON 파싱·모양 검증(키 누락·모르는 키·`path` 가 `/` 로 시작하지 않음·`status` 가 허용값 밖 → `RuntimeException` → 500, 조용히 건너뛰지 않는다 — 위키 `Wiki::validate` 와 같은 태도), `order` 정렬, 정적 캐시. 요청 값은 어디에도 섞이지 않는다.
- 응답 헤더는 nginx 전역 보안 헤더(CSP `script-src 'self'` 등) 그대로. `Cache-Control: no-store`(사용자 칩 때문).

## 6. 셸 그리드 단추 (모든 t-box 화면)

- `header.appbar` 오른쪽에 `<details class="appbar__apps">` — `<summary class="btn btn--icon btn--plain" title="앱">` + 아이콘 `data-icon="grid"`(새 `<template>` 아이콘 하나) → 펼침 패널 `.appbar__apps-panel`: 「Qaredi 홈」(`/`) + `Apps::all()` 의 앱 목록(현재 앱은 `aria-current="page"`, `soon` 은 배지 「준비 중」). 스크립트 0 으로 열리고, 바깥 클릭 닫힘은 두지 않는다(D-10 모달과 다른 것 — `<details>` 의 기본 동작).
- 로그인 화면 머리에는 「Qaredi 홈」 텍스트 링크(`/`) 하나만.
- DESIGN 2.9(셸)·2.8(툴팁)·2.12(다크 모드) 를 따르고, 새 색값·새 간격을 만들지 않는다. 6장 체크리스트 그대로.

## 7. 바뀌지 않는 것 (검토가 확인할 것)

- `Authz`·`Auth`·`Audit`·`Db`·`Storage`·`Upload`·`Fetch`·DB 스키마·`.env` 키 — **무변경.** `Http.php` 는 헬퍼 3개(`base`·`url`·`redirectPermanent`, 필요하면 `themeAttr`)만 는다.
- 라우트 표(`TBOX_ROUTES`)의 경로·정책·핸들러 — **무변경.** 표 앞뒤로 접두를 떼고 붙일 뿐이다.
- 시험 통과 수: `stage26.sh` 108/0 · `help_button.sh` 16/0 · `api_whoami.sh` 15/0 · `f68.sh` 109/0 · 그 밖 `tests/*.sh` — **전과 같아야** 한다(BASE 만 바뀜).

## 8. 남는 위험 (SECURITY 에 R-98 로)

- 런처가 로그인 없이 **앱 목록**(이름·설명·경로)을 보인다 — 사용자 결정 Q-B. 데이터·계정·버전은 보이지 않는다. 사내망 안이며, 알려지는 것은 「t-box 와 커뮤니티가 있다」뿐.
- 옛 주소 308 은 「이 경로가 존재한다」를 알려 준다 — 존재 여부는 이미 `/tbox/...` 의 401/404 로 같은 정보가 나오므로 새 노출이 아니다.
