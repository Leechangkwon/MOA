# 지시서 — C-7~C-11 카드형 링크 통합 · 줄 목록 · 위키 표 · 로그인 카드 (DESIGN.md 원칙 6 · 9장)

CLAUDE.md 2장 형식. 작성 Fable 2026-09-21. 구현 Opus.

```
목표        : 세 벌로 따로 있던 누를 수 있는 카드(관리자 허브 · 팀 · 위키 단계)를 공통 .card--link 한 벌로,
              경보 목록을 공통 .list 로, 위키 표를 table.files 로, 로그인 카드를 .panel.panel--narrow 로 바꾼다.
              보이는 결과는 「같은 꼴」이고 동작은 그대로다.
요구사항    : DESIGN.md 0장 원칙 6 · 2.3(누를 수 있는 카드 구조 · 카드 배치) · 2.13(줄 목록) · 2.5(.btn--block) ·
              2.10(로그인) · 9장 C-7~C-11 · 6장 체크리스트 — 해당 절을 읽고 시작할 것.
              **CSS 는 고치지 않는다** — .card--link .card__icon .card__body .card__head .card__title .card__desc
              .card__meta .card--warn .card--soon .is-selected .card-grid .card-stack .list .list--scroll .list__item
              .list__head .list__body .btn--block .nowrap 이 이미 app.css 에 있다(Fable 이 넣음).
수정 파일   : src/handlers/admin_page.php, src/handlers/org_page.php, src/handlers/login_page.php,
              src/handlers/reauth_page.php, src/Wiki.php, public/assets/app.js  (이 여섯만)
금지 패턴   : SQL 문자열 결합, innerHTML, 인라인 <script>·style=""·on* 속성, 사용자 입력이 들어간 파일 경로,
              curl_exec/file_get_contents('http…'), 역할 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role 값 사용,
              **id 변경 · data-* 변경 · 문구 변경 · Wiki.php 의 이스케이프(self::esc / self::inline) 경로 변경**
완료 기준   : 아래 「확인」 1~7 전부 실행하고 명령·응답 원문을 붙인다. scripts/security_lint.sh 0건 ·
              scripts/wiki_check.php 0건 · scripts/ui_snapshot.sh <폴더> 1440 결함이 이전(7건, 전부 기존 오탐)과
              같거나 적고 console=0.
보고 형식   : 변경 파일 목록(파일별 클래스 before → after) / 실행한 명령과 출력 원문 / 못 한 것과 이유 /
              **쓰이지 않게 된 클래스 목록**(Fable 이 CSS 에서 지운다)
```

## 바꾸는 법

### C-7a 관리자 허브 `admin_page.php`

```html
<!-- before -->
<section class="admin-hub">
  <a class="admin-card" href="/users">
    <span class="admin-card__icon"><span class="icon" data-icon="users"></span></span>
    <span class="admin-card__text">
      <strong class="admin-card__name">계정 관리</strong>
      <span class="admin-card__desc">…</span>
    </span>
  </a>
  … <a class="admin-card admin-card--warn" …>
<!-- after -->
<section class="card-grid">
  <a class="card card--link" href="/users">
    <span class="card__icon"><span class="icon" data-icon="users"></span></span>
    <span class="card__body">
      <strong class="card__title">계정 관리</strong>
      <span class="card__desc">…</span>
    </span>
  </a>
  … <a class="card card--link card--warn" …>
```
`.admin-hub` 가 가진 `margin-bottom: 18px` 은 `.card-grid` 에 없다 — 아래 패널과의 간격은 `.main` 의 `gap: 20px` 이 준다(허브 `<section>` 이 `.main` 직속이면 그대로, 아니면 보고에 적는다).

### C-7b 팀 카드 `app.js` (`card.className = team.id === state.selected ? 'team-card is-selected' : 'team-card'` 부근)

- `button.className` → `'card card--link is-selected'` / `'card card--link'`
- `head.className = 'team-card__head'` → `'card__head'`, `name.className = 'team-card__name'` → `'card__title'`
- `stats.className = 'team-card__stats'` → `'card__meta'`. `statBlock()` 이 만드는 조각의 `<strong>` 은 그대로(`.card__meta strong` 규칙이 받는다). 그 함수가 자체 클래스를 붙이면 보고에 적는다.
- `head`·`stats` 를 감싸는 `span.card__body` 를 만들어 `card` 의 유일한 자식으로 넣는다(아이콘 없음).
- 컨테이너 `org_page.php` `<div id="org-teams" class="org-cards">` → `class="card-stack"`. 선택 상태를 바꾸는 코드가 `'team-card is-selected'` 문자열을 다시 쓰면 그곳도 함께 바꾼다(grep `team-card`).

### C-7c 위키 단계 카드 `Wiki.php` (`wiki-card__num` 부근, 781행 `wiki-cards`)

```php
// before
'<span class="wiki-card__num">' . $num . '</span><span class="wiki-card__body"><span class="wiki-card__title">…</span><span class="wiki-card__sub">…</span><span class="wiki-card__date">고친 날 …</span></span>'
'<span class="wiki-card wiki-card--soon">' . $in . '<span class="badge badge--off">준비 중</span></span>'
'<a class="wiki-card" href="…">' . $in . '</a>'
'<div class="wiki-cards">'
// after
'<span class="card__icon">' . $num . '</span><span class="card__body"><span class="card__head"><span class="card__title">…</span>' . ($soon ? '<span class="badge badge--off">준비 중</span>' : '') . '</span><span class="card__desc">…</span><span class="card__meta">고친 날 …</span></span>'
'<span class="card card--link card--soon">' . $in . '</span>'
'<a class="card card--link" href="…">' . $in . '</a>'
'<div class="card-grid">'
```
「준비 중」 배지는 `.card__head` 안(제목 옆)으로 옮긴다 — 기존엔 카드 끝에 있었다. `$num`·제목·요약·날짜의 **이스케이프 호출은 한 글자도 바꾸지 않는다**(`self::esc` · `self::inline`).

### C-8 경보 목록 `admin_page.php` 169행 · `app.js` (`item.className = 'alert-item'` 부근)

- `<ul id="admin-alerts" class="alert-list">` → `class="list list--scroll"`
- `item.className = 'alert-item'` → `'list__item'`, `head.className = 'alert-item__head'` → `'list__head'`, `body.className = 'alert-item__body muted'` → `'list__body'` (`.list__body` 가 이미 회색이다 — `muted` 뺀다)
- `when.className = 'muted'` 는 그대로.

### C-10 위키 표 `Wiki.php` 884 · 927행

- `class="files wiki-table"` → `class="files"`, `class="files wiki-table wiki-refs"` → `class="files"`
- 927행 참고 표의 **첫 열**(위치)만 줄이 바뀌지 않게: 그 표의 `<th>` 첫 칸과 각 행 첫 `<td>` 에 `class="nowrap"` 을 붙인다. 셀 내용·이스케이프는 그대로.

### C-11 로그인 카드 `login_page.php` · 재인증 단추 `reauth_page.php`

```html
<!-- login before -->
<form id="login-form" class="login__card" …>
  <div><h1 class="login__title">로그인</h1><p class="login__sub">아이디와 비밀번호를 입력하세요.</p></div>
  … <button id="login-submit" class="btn login__submit" type="submit">로그인</button>
<!-- after -->
<section class="panel panel--narrow">
  <form id="login-form" class="form" …(속성 그대로)>
    <div class="page-head__text"><h1 class="page-head__title">로그인</h1><p class="page-head__sub">아이디와 비밀번호를 입력하세요.</p></div>
    … <button id="login-submit" class="btn btn--block" type="submit">로그인</button>
```
`<section>` 은 `.login__pane` 안에서 `<form>` 을 감싼다. 폼의 id·action·method·autocomplete·`#otp-field hidden`·`#msg` 는 그대로.

재인증 `reauth_page.php`: C-1 에서 넣은 `<div class="form-actions">…</div>` 를 없애고 단추를 폼 직속 `class="btn btn--block"` 으로 — 로그인과 같은 꼴(DESIGN 2.5).

## 확인 (전부 실행, 원문 첨부 — 검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`)

1. `php -l` 다섯 PHP 파일 · `grep -nE 'onclick|style=|<script>'` 여섯 파일에서 주석·기존(`app.js:2467 trashedOk.onclick`) 제외 신규 0 · `grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' public/assets/app.js` → 0
2. `grep -n 'admin-card\|admin-hub\|team-card\|org-cards\|wiki-card\|wiki-cards\|wiki-table\|wiki-refs\|alert-item\|alert-list\|login__card\|login__title\|login__sub\|login__submit' src public` → **0건**
3. `php scripts/wiki_check.php` → 0건. `curl -s -b <jar> -A … http://127.0.0.1:8082/wiki | grep -c 'card card--link'` → 단계 카드 수(양수), `grep -c 'wiki-card'` → 0. 위키 글 하나(`/wiki/stage-01`)의 표에 `class="files"` 만 있고 `nowrap` 이 참고 표 첫 열에 있다.
4. `/admin` 본문: `card-grid` 1 · `card card--link` 7(그중 `card--warn` 1) · `list list--scroll` 1. Playwright 로 경보 목록이 채워진 뒤 `.list__item` 수 > 0, `.list__body` 가 회색인지(computed color 가 `--muted` 값).
5. `/org`: 팀 카드가 `button.card.card--link` 이고 하나를 누르면 `is-selected` 가 그 카드로 옮겨 가며 오른쪽 상세가 바뀐다(기존 동작). 스크린샷.
6. `/login`: `section.panel.panel--narrow > form#login-form.form`, 제목 `h1.page-head__title`, 단추 `btn btn--block`. 폭 1440·390 스크린샷(390 에서 카드 테두리가 사라지고 좌우 16px 여백). 로그인 실제 수행(qa_admin01, 코드 포함) → `{"ok":true…}`. `/reauth?to=admin` 단추가 `btn btn--block` 이고 확인 → `/admin` 이동.
7. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` 이전과 같거나 적음, console=0 · `UI_PAGES="/wiki,/login,/reauth" bash scripts/ui_snapshot.sh <폴더> 1440` 도 console=0

## 하지 않는 것

- CSS 수정. 쓰이지 않게 된 클래스는 지우지 말고 보고에 적는다(Fable 이 지운다).
- `.wiki-*` 의 나머지(글 본문 조판), `.preview-*`, `.login__brand*` `.login__pane`(배치), `.foldertree__*`(트리 항목) — 이번 범위 밖.
- id · data-* · 문구 · 이스케이프 변경.
