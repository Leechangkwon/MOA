# 지시서 — D-5 다크 모드 전환 단추 (해/달) · 쿠키 저장 · 서버가 `<html data-theme>` 그리기

CLAUDE.md 2장 형식. 작성 Fable 2026-09-21. 구현 Opus.

```
목표        : 사이드바 아래(버전 옆)와 로그인 화면 오른쪽 위에 해/달 아이콘 단추를 두어 밝은/어두운 화면을
              전환하고, 선택을 쿠키 tbox_theme 에 남겨 서버가 <html data-theme> 로 처음부터 그린다.
요구사항    : DESIGN.md 2.12(다크 모드) · 2.9(셸 아래 구성) · 2.1(아이콘 단추는 makeIconButton, aria-label 필수)
              · 6장 체크리스트, PRD S-03(인라인 script/style 0, 값은 htmlspecialchars), ARCHITECTURE 3장 src/views
              — 해당 절을 읽고 시작할 것. **CSS 는 이미 다 들어 있다**(app.css 끝 「다크 모드」 절).
              CSS 를 고치지 말고 클래스 이름을 그대로 쓴다: .sidebar__foot .theme-toggle .icon--sun .icon--moon
수정 파일   : src/views/shell_top.php, src/views/shell_bottom.php, src/handlers/login_page.php, public/assets/app.js
              (이 넷만. app.css · index.php · Auth/Authz/Http 등은 만들거나 고치지 않는다)
금지 패턴   : SQL 문자열 결합, innerHTML, 인라인 <script>·style=""·on* 속성, 사용자 입력이 들어간 파일 경로,
              curl_exec/file_get_contents('http…'), 역할 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role 값 사용,
              쿠키 값을 허용 목록 없이 HTML 속성에 넣는 것
완료 기준   : 아래 「확인」 1~8 전부 실행하고 명령·응답 원문을 보고에 붙인다. scripts/security_lint.sh 0건.
              scripts/ui_snapshot.sh <폴더> 1440 이 이전과 같은 6건·console=0.
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

## 무엇을 만드나

### 1. 서버 — 쿠키를 허용 목록으로 걸러 `<html data-theme>` 에 넣기

`shell_top.php` 와 `login_page.php` 둘 다, `<html lang="ko">` 를 그리기 전에:

```php
/* DESIGN.md 2.12 — 화면 밝기 취향. 쿠키 값은 허용 목록 두 개만 통과하고 그 밖은 속성 자체를 안 그린다.
   (그러면 CSS 가 OS 설정 prefers-color-scheme 을 따른다.) 값은 어차피 상수 두 개라 이스케이프할 것이
   없지만 규칙대로 htmlspecialchars 를 거친다 (PRD S-03). */
$themeCookie = $_COOKIE['tbox_theme'] ?? '';
$themeAttr   = in_array($themeCookie, ['dark', 'light'], true)
    ? ' data-theme="' . htmlspecialchars($themeCookie, ENT_QUOTES, 'UTF-8') . '"'
    : '';
```

그리고 `<html lang="ko"<?= $themeAttr ?>>`. 로그인 전 화면도 같은 규칙(로그인 화면에 버전이 안 나가는 S-09 규칙과 무관 — 취향 쿠키는 비밀이 아니다).

### 2. 아이콘 두 개 — `shell_bottom.php` 의 `<template>` 모음과 `login_page.php` 의 `<template>` 모음에 추가

lucide `sun` · `moon`, 다른 아이콘과 같은 규격(24×24, stroke 1.8, `aria-hidden="true"`):

```html
<template id="tpl-icon-sun"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></template>
<template id="tpl-icon-moon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg></template>
```

### 3. 단추 마크업

**셸 (`shell_top.php`)** — 지금 `.sidebar__version` 단추가 있는 자리를 `.sidebar__foot` 로 감싸고 오른쭉에 단추:

```html
<div class="sidebar__foot">
  <button type="button" class="sidebar__version" data-changelog-open …>(기존 그대로)</button>
  <button type="button" class="btn btn--icon btn--plain theme-toggle" data-theme-toggle
          aria-label="어두운 화면으로">
    <span class="icon icon--moon" data-icon="moon"></span><span class="icon icon--sun" data-icon="sun"></span>
  </button>
</div>
```

두 아이콘을 **둘 다** 넣는다 — 어느 것을 보일지는 CSS(`:root[data-theme]`·`prefers-color-scheme`)가 정한다. JS 는 아이콘을 갈아 끼우지 않는다.

**로그인 (`login_page.php`)** — `<div class="login">` 바로 안 첫 자식으로 같은 단추(클래스 동일). CSS 가 오른쪽 위에 띄운다. `.login` 에 `position: relative` 가 필요하면 **CSS 를 고치지 말고** 보고에 적는다(Fable 이 CSS 를 고친다).

### 4. `app.js`

`makeIcon` 이 `data-icon` 자리에 `<template>` 을 채우는 기존 흐름이 두 아이콘도 채우는지 확인한다(`data-icon="sun"`·`"moon"` → `tpl-icon-sun`·`tpl-icon-moon`). 안 채우면 그 흐름에 이름만 더한다.

전환 로직 — 셸·로그인 공통이므로 `DOMContentLoaded` 안 화면 분기 **밖**에 둔다:

```js
/* DESIGN.md 2.12 — 해/달 단추. 상태는 <html data-theme> 하나뿐이고 CSS 가 그 값으로 그린다. */
function currentTheme() {
  var set = document.documentElement.getAttribute('data-theme');
  if (set === 'dark' || set === 'light') { return set; }
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
function applyThemeLabel(button) {
  button.setAttribute('aria-label', currentTheme() === 'dark' ? '밝은 화면으로' : '어두운 화면으로');
}
document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
  applyThemeLabel(button);
  button.addEventListener('click', function () {
    var next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'tbox_theme=' + next + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
    applyThemeLabel(button);
  });
});
```

`title` 속성은 넣지 않는다(CSS 툴팁이 `aria-label` 을 보인다 — DESIGN.md 2.8).

## 확인 (전부 실행, 원문 첨부)

검토 계정 `qa_admin01` 만 쓰고 `-A "tbox-review/$(date +%F)"` 를 붙인다 (CLAUDE.md 3장).

1. `curl -s -A … http://127.0.0.1:8082/login | grep -o '<html[^>]*>'` → `<html lang="ko">` (쿠키 없음 → 속성 없음)
2. `curl -s -A … -b 'tbox_theme=dark' http://127.0.0.1:8082/login | grep -o '<html[^>]*>'` → `<html lang="ko" data-theme="dark">`
3. `curl -s -A … -b 'tbox_theme=%22%3E%3Cscript%3E' … | grep -o '<html[^>]*>'` → `<html lang="ko">` (허용 목록 밖 → 속성 없음, 원문이 어디에도 안 나감: `grep -c script` 가 0 이어야 한다 — 단, 정당한 `<script src=` 한 줄은 제외하고 셈)
4. 로그인한 셸(`/`)에서 2·3 을 반복
5. `grep -nE 'onclick|style=|<script>' src/views/shell_top.php src/handlers/login_page.php` → 0건
6. Playwright(`scripts/ui_snapshot.py` 의 launch 방식)로 `/` 를 열어 `[data-theme-toggle]` 을 클릭 → `document.documentElement.dataset.theme === 'dark'`, `document.cookie` 에 `tbox_theme=dark`, 단추 `aria-label === '밝은 화면으로'`. 다시 클릭 → `light`. 스크린샷 두 장(밝음·어둠) 저장
7. `scripts/security_lint.sh` → 0건
8. `scripts/ui_snapshot.sh /tmp/…/snap-d5 1440` → 합계 6건(이전과 같음), console=0

## 하지 않는 것

- CSS 수정(전부 준비됨). 필요한 CSS 가 있으면 보고에 적는다.
- 서버에 취향을 DB 로 저장하는 것(쿠키로 충분, 계정과 무관).
- `localStorage` — 서버가 첫 그림을 그려야 깜빡임이 없고, 그러려면 서버가 읽을 수 있는 쿠키여야 한다.
