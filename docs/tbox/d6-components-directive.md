# 지시서 — C-1~C-6 화면 전용 마크업을 공통 구성 요소로 (DESIGN.md 원칙 6 · 9장)

CLAUDE.md 2장 형식. 작성 Fable 2026-09-21. 구현 Opus.

```
목표        : 재인증 화면·재인증 모달·첫 로그인 관문·관리자 패널 머리·폴더 카드 머리·토큰 모달 머리가
              화면 전용 클래스 대신 공통 구성 요소(.panel .panel__head .panel__title .panel__sub .form
              .form-actions .modal--form .modal__head/__body/__foot)를 쓰게 한다. 보이는 결과는 「같은 꼴」이다.
요구사항    : DESIGN.md 0장 원칙 6 · 2.3(카드·패널 머리 꼴) · 2.5(폼) · 2.7(모달 세 구역) · 9장 C-1~C-6 · 6장 체크리스트
              — 해당 절을 읽고 시작할 것. 새 클래스·색값을 만들지 않는다. **CSS 는 고치지 않는다**(app.css 끝에
              .panel--narrow · .panel--tight 두 변형이 이미 있다 — Fable 이 넣음).
수정 파일   : src/handlers/reauth_page.php, src/handlers/home.php, src/handlers/admin_page.php,
              src/handlers/org_page.php, public/assets/app.js  (이 다섯만)
금지 패턴   : SQL 문자열 결합, innerHTML, 인라인 <script>·style=""·on* 속성, 사용자 입력이 들어간 파일 경로,
              curl_exec/file_get_contents('http…'), 역할 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role 값 사용,
              **id 변경**(app.js 가 id 로 찾는다 — id 는 하나도 바꾸지 않는다), data-* 속성 변경
완료 기준   : 아래 「확인」 1~6 전부 실행하고 명령·응답 원문을 붙인다. scripts/security_lint.sh 0건.
              scripts/ui_snapshot.sh <폴더> 1440 결함이 이전(7건, 전부 stage_text)과 같거나 적고 console=0.
보고 형식   : 변경 파일 목록(파일별 바뀐 클래스 before → after) / 실행한 명령과 출력 원문 / 못 한 것과 이유 /
              **쓰이지 않게 된 클래스 목록**(Fable 이 CSS 에서 지운다)
```

## 바꾸는 법 — 여섯 곳

### C-1 재인증 화면 `reauth_page.php`

```html
<!-- before -->
<section class="card reauth">
  <form id="reauth-form" class="reauth__form" …>
    <label class="field">…</label>
    <button id="reauth-submit" class="btn" type="submit">확인</button>
    <p id="reauth-msg" class="msg" …></p>
  </form>
</section>
<!-- after -->
<section class="panel panel--narrow">
  <form id="reauth-form" class="form" …>
    <label class="field">…(그대로)…</label>
    <div class="form-actions"><button id="reauth-submit" class="btn" type="submit">확인</button></div>
    <p id="reauth-msg" class="msg" …></p>
  </form>
</section>
```
`data-to` 속성·id·input 은 그대로. 단추 라벨 「확인」 은 DESIGN 5장의 유일한 예외(재인증)라 유지.

### C-2 재인증 모달 `app.js` (`dialog.className = 'modal reauth-modal'` 부근)

지금은 `dialog` 에 직접 제목(`.modal__title`)·문장(`.modal__lead`)·폼·`.modal__actions` 가 들어간다. 표준 세 구역으로:
- `dialog.className = 'modal modal--form'`
- `.modal__head` > `h2.modal__title`(기존 제목) — `.modal__lead` 문장은 `.modal__head` 안 `span.modal__meta` 로
- `.modal__body` > 기존 `form`(클래스 `form`) — `label.field` 그대로
- `.modal__foot` > 단추들(기존 `.modal__actions` 의 단추를 옮긴다: 「닫기」(`.btn--quiet`) 왼쪽, 「확인」(`.btn`) 오른쭉)
- `.msg` 는 `.modal__foot` 안 첫 자식(`.modal--stack .modal__foot .msg { margin-right: auto }` 규칙이 있다)
id·이벤트 핸들러·`showModal()` 호출은 그대로. `createElement` 로만 만든다(innerHTML 금지).

### C-3 첫 로그인 관문 `home.php` `<section class="gate" id="forced-gate" hidden>`

`class="panel"` 로. 안의 `h2` 는 `class="panel__title"`, 바로 아래 설명 `p` 가 있으면 `class="panel__sub"`, 둘을 `<div class="panel__head">` 로 감싼다. 나머지(폼·임시 비밀번호 칸·2단계 묶음)는 그대로.

### C-4 관리자 패널 머리 `admin_page.php` 3곳 · `org_page.php` 1곳

```html
<!-- before (admin_page.php 115·140·164, org_page.php 118) -->
<div class="org-detail__text">
  <strong class="org-detail__name">역할로 보기</strong>
  <span class="muted">다른 역할·팀의 눈으로 …</span>
</div>
<!-- after -->
<div class="panel__head">
  <h2 class="panel__title">역할로 보기</h2>
  <p class="panel__sub">다른 역할·팀의 눈으로 …</p>
</div>
```
바깥 `.org-detail__head`(있으면) 는 그대로 두되, 그 안에 배지·단추가 함께 있는 머리(`org_page.php` 80~85 팀 상세: 이름 + 배지 + 구성원 수)는 **바꾸지 않는다** — 진짜 「상세 머리」다. `org_page.php` 118(권한 매트릭스 머리)은 오른쪽에 「읽기 전용」 배지가 있다: `.org-matrix__head` 안에서 `.panel__head` 와 배지가 나란히 서도 된다(그 컨테이너는 flex).

### C-5 폴더 카드 머리 `home.php` 117~122

```html
<!-- before -->
<aside class="foldertree" …>
  <div class="foldertree__head"><h2 class="foldertree__title">폴더</h2> <button …>새 폴더</button></div>
  <p class="foldertree__note">폴더 이름에는 …</p>
<!-- after -->
<aside class="panel panel--tight foldertree" …>
  <div class="foldertree__head"><h2 class="panel__title">폴더</h2> <button …>새 폴더</button></div>
  <p class="panel__sub">폴더 이름에는 …</p>
```
`.foldertree` 클래스는 **남긴다**(트리 항목·목록 규칙이 아직 그 아래 있다). 바뀌는 것은 카드 껍데기·제목·설명 세 클래스만.

### C-6 프로그램 토큰 모달 `app.js` (`tkBox.className = 'token-box'` 부근)

`title.className = 'panel__title'`, `lead.className = 'panel__sub'`, 둘을 감싸는 `div.panel__head` 를 만들어 `tkBox` 첫 자식으로 넣는다. `tkBox` 의 `token-box` 클래스와 표·폼 클래스는 그대로.

## 확인 (전부 실행, 원문 첨부 — 검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`)

1. `php -l` 네 PHP 파일 · `grep -nE 'onclick|style=|<script>'` 다섯 파일에서 주석 제외 0건 · `grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' public/assets/app.js` → 0
2. `grep -n 'class="card reauth"\|reauth__form\|class="gate"\|org-detail__text\|foldertree__title\|foldertree__note\|token-box__title\|token-box__lead\|reauth-modal\|modal__lead\|modal__actions' src/handlers/*.php public/assets/app.js` → C-4 의 팀 상세 머리(org_page.php 81) **한 곳만** 남는다
3. 재인증: 로그인 → `GET /reauth` 200 · 본문에 `panel panel--narrow` · Playwright 로 비밀번호 넣고 「확인」 → `/admin` 으로 이동(기존 동작 그대로)
4. 재인증 모달: `/users` 에서 15분 창을 흉내 낼 수 없으면 app.js 의 모달 생성 함수를 Playwright `page.evaluate` 로 직접 호출해 열고 스크린샷 — `.modal__head`·`.modal__body`·`.modal__foot` 셋이 있고 「닫기」「확인」 이 바닥에 있다
5. 첫 로그인 관문: `#forced-gate` 는 기본 hidden — `page.evaluate` 로 `hidden=false` 만 풀어 스크린샷(다른 것은 건드리지 않는다)
6. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` — 이전과 같거나 적은 결함, console=0

## 하지 않는 것

- CSS 수정. 쓰이지 않게 된 클래스는 **지우지 말고 보고에 적는다** — Fable 이 지운다.
- C-7 이후(카드형 링크 통합·줄 목록·미리보기 이름 정리·위키·로그인)는 이 지시서 범위 밖.
- id·data-* 변경, 문구 변경.
